// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Module instance page "Agora broadcast": comment field on top, "Show all"
 * and switching to the live broadcast without reload.
 *
 * Core renders the comment area markup and it must not be touched by
 * styles: core JS reads the computed `display` of the `.comment-ctrl`
 * container and for any value other than `none` takes the "hide" branch.
 * So the new comment field is moved above the feed by rearranging nodes,
 * not by a CSS rule.
 *
 * A page without the live player (the "broadcast has not started" stub, an
 * interruption, the recording of an ended broadcast) asks Moodle about the
 * broadcast state every 15–20 seconds (`mod_agora_get_stream_state`). When
 * the broadcast goes live, the stub is replaced by the live player from a
 * core fragment; a recording the learner is already watching is not pulled
 * away — a "Broadcast started" banner with a switch button appears above
 * it.
 *
 * Without this module the page still works: the course broadcast list is
 * just full, the comment field stands below the feed as core renders it,
 * and a started broadcast becomes visible after a reload.
 *
 * @module     mod_agora/watch
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/fragment', 'core/templates', 'core/str'], function(Ajax, Fragment, Templates, Str) {


    /**
     * Errors after which asking further is pointless: login expired,
     * permissions lost or the instance is gone. A retry won't fix them.
     *
     * @type {string[]}
     */
    var STOP_ERRORS = [
        'servicerequireslogin', 'requireloginerror', 'invalidsesskey', 'nopermissions',
        'invalidcoursemodule', 'invalidrecord', 'invalidparameter', 'coursehidden',
        'notenrolled', 'accessexception', 'servicenotavailable'
    ];

    /** @type {number} Backoff limit between polls on failures, ms. */
    var MAX_BACKOFF = 5 * 60 * 1000;

    /**
     * Update the state badge: in the metadata line and on the open instance
     * in the "In this course" side list.
     *
     * @param {Object} answer poll response: badge, badgeclass
     * @return {void}
     */
    var updateBadges = function(answer) {
        var inline = document.querySelector('.mod-agora-state-inline');
        if (inline) {
            inline.className = inline.className.replace(/mod-agora-state-badge-\S+/, answer.badgeclass);
            inline.textContent = answer.badge;
        }
        var card = document.querySelector('.mod-agora-row-current .mod-agora-preview-card');
        if (!card) {
            return;
        }
        var badge = card.querySelector('.mod-agora-state-badge');
        if (!badge) {
            // The row was rendered without a badge (state was not cached): add it.
            badge = document.createElement('span');
            badge.setAttribute('aria-hidden', 'true');
            card.appendChild(badge);
        }
        badge.className = 'mod-agora-state-badge ' + answer.badgeclass;
        badge.textContent = answer.badge;
        var sr = card.querySelector('.sr-only');
        if (!sr) {
            sr = document.createElement('span');
            sr.className = 'sr-only';
            card.appendChild(sr);
        }
        sr.textContent = answer.badge;
    };

    /**
     * Redraw the metadata line buttons from a poll response.
     *
     * Attributes repeat the server markup: a disabled button is a hint
     * instead of a link, "End broadcast" has a confirmation, "Start
     * broadcast" is primary. The poll must not offer actions softer than
     * the page (review R2).
     *
     * @param {Array} actions transitions: key, url, label, blank, primary,
     *     disabled, hint, confirm, form, sesskey
     * @param {number} cmid course module — for action POST forms
     * @return {void}
     */
    var updateActions = function(actions, cmid) {
        var line = document.querySelector('.mod-agora-metaline');
        if (!line) {
            return;
        }
        var box = line.querySelector('.mod-agora-actions');
        if (!actions || !actions.length) {
            if (box) {
                box.remove();
            }
            return;
        }
        if (!box) {
            box = document.createElement('span');
            box.className = 'mod-agora-actions';
            line.appendChild(box);
        }
        box.textContent = '';
        actions.forEach(function(action) {
            if (action.disabled) {
                var span = document.createElement('span');
                span.className = 'btn btn-outline-secondary btn-sm disabled';
                span.title = action.hint || '';
                span.textContent = action.label;
                box.appendChild(span);
                return;
            }
            if (action.form) {
                // Broadcast control actions are POST forms, as on the
                // server-side page: a GET link is no good for an
                // irreversible action.
                var form = document.createElement('form');
                form.method = 'post';
                form.action = '/mod/agora/view.php';
                form.className = 'd-inline';
                var addHidden = function(name, value) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                };
                addHidden('id', cmid);
                addHidden('sesskey', action.sesskey);
                addHidden('action', action.name || action.key);
                var submit = document.createElement('button');
                submit.type = 'submit';
                submit.className = 'btn btn-sm ' + (action.primary ? 'btn-primary' : 'btn-outline-secondary');
                submit.dataset.action = 'agora-' + action.key;
                submit.textContent = action.label;
                if (action.confirm) {
                    submit.onclick = function() {
                        // The text already arrived as a JSON string, as in the server markup.
                        return window.confirm(JSON.parse(action.confirm));
                    };
                }
                form.appendChild(submit);
                box.appendChild(form);
                return;
            }
            var link = document.createElement('a');
            link.href = action.url;
            link.className = 'btn btn-sm ' + (action.primary ? 'btn-primary' : 'btn-outline-secondary');
            link.dataset.action = 'agora-' + action.key;
            link.textContent = action.label;
            if (action.blank) {
                link.target = '_blank';
                link.rel = 'noreferrer noopener';
            }
            box.appendChild(link);
        });
    };

    /**
     * Broadcast state poll by a page that has no live player.
     *
     * @param {HTMLElement} scene scene with data-poll
     * @return {void}
     */
    var watchLive = function(scene) {
        var cmid = parseInt(scene.dataset.cmid, 10);
        var contextid = parseInt(scene.dataset.contextid, 10);
        var interval = parseInt(scene.dataset.poll, 10) * 1000;
        var jitter = parseInt(scene.dataset.jitter || '0', 10) * 1000;
        var paused = false;
        var done = false;
        var failures = 0;
        var schedule;

        /**
         * Swap the scene for the player — only if the fragment came with a player.
         *
         * The live broadcast started, or the recording clip matured under
         * the "recording will appear soon" stub. A fragment without a player
         * (the ticket was not issued, the broadcast already ended) leaves
         * the scene alone: the poll continues, and the next suitable
         * response will try again.
         *
         * @param {Object} answer poll response
         * @param {HTMLElement|null} button banner button, if the switch came from it
         * @param {string} kind which kind to set on the scene: live or record
         * @return {Promise}
         */
        var goLive = function(answer, button, kind) {
            done = true;
            /**
             * The attempt failed, the broadcast is not on the page yet.
             *
             * From the poll — backoff grows, the poll continues. From the
             * banner button — the poll is not restarted (it is already
             * stopped by the banner, and a second question would raise a
             * second banner): the button is available again.
             *
             * @return {void}
             */
            var retry = function() {
                if (button) {
                    button.disabled = false;
                    return;
                }
                done = false;
                failures++;
                schedule();
            };
            return Fragment.loadFragment('mod_agora', 'scene', contextid, {}).then(function(html, js) {
                var probe = document.createElement('div');
                probe.innerHTML = html;
                if (!probe.querySelector('iframe.mod-agora-player')) {
                    window.console.warn('mod_agora: the scene came without a live player');
                    retry();
                    return null;
                }
                Templates.replaceNodeContents(scene, html, js);
                failures = 0;
                scene.dataset.kind = kind || 'live';
                scene.removeAttribute('data-poll');
                updateBadges(answer);
                updateActions(answer.actions, cmid);
                return null;
            }).catch(function(error) {
                window.console.warn('mod_agora: ' + (error && error.message ? error.message : error));
                if (error && STOP_ERRORS.indexOf(error.errorcode) !== -1) {
                    // Final failure (login expired, no permissions, instance
                    // gone): let the regular page decide — it will show the error.
                    window.location.reload();
                    return;
                }
                // Network or server failure: no reload — backoff and retry.
                retry();
            });
        };

        /**
         * "Broadcast started" banner above the recording the learner is watching.
         *
         * @param {Object} answer poll response
         * @return {Promise}
         */
        var offerLive = function(answer) {
            done = true;
            return Str.get_strings([
                {key: 'livestarted', component: 'mod_agora'},
                {key: 'livestartedgo', component: 'mod_agora'}
            ]).then(function(strings) {
                var banner = document.createElement('div');
                banner.className = 'alert alert-info d-flex flex-wrap align-items-center mod-agora-livebanner';
                banner.setAttribute('role', 'status');
                banner.dataset.region = 'agora-livebanner';
                var text = document.createElement('span');
                text.className = 'mr-3 me-3';
                text.textContent = strings[0];
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-primary btn-sm';
                button.dataset.action = 'agora-golive';
                button.textContent = strings[1];
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    button.disabled = true;
                    goLive(answer, button);
                });
                banner.appendChild(text);
                banner.appendChild(button);
                scene.insertBefore(banner, scene.firstChild);
                updateBadges(answer);
                return null;
            });
        };

        /**
         * Set the "recording will appear soon" stub on the scene from a
         * server fragment.
         *
         * After "End broadcast" the live player must turn into a stub
         * without reload: the server fragment honestly distinguishes the
         * learner from the presenter (the presenter gets the export state
         * with a reason). The poll does not end: the scene waits for the
         * clip and sets the recording player on the very next step.
         *
         * @param {Object} answer poll response
         * @return {Promise}
         */
        var goRecordpending = function(answer) {
            return Fragment.loadFragment('mod_agora', 'scene', contextid, {}).then(function(html, js) {
                Templates.replaceNodeContents(scene, html, js);
                failures = 0;
                scene.dataset.kind = 'recordpending';
                updateBadges(answer);
                updateActions(answer.actions, cmid);
                schedule();
                return null;
            }).catch(function(error) {
                window.console.warn('mod_agora: ' + (error && error.message ? error.message : error));
                if (error && STOP_ERRORS.indexOf(error.errorcode) !== -1) {
                    // Login expired or permissions lost: the regular page decides.
                    window.location.reload();
                    return;
                }
                failures++;
                schedule();
            });
        };

        /**
         * One question about the state.
         *
         * @return {void}
         */
        var poll = function() {
            if (done) {
                return;
            }
            // A hidden tab does not ask: when it returns, it will ask right away.
            if (document.hidden) {
                paused = true;
                return;
            }
            // The poll does not extend the session: a tab left open must not
            // hold the login forever.
            Ajax.call([{methodname: 'mod_agora_get_stream_state', args: {cmid: cmid}}], true, true, true)[0]
                .then(function(answer) {
                    // The clip is ready: any non-"record" scene (including
                    // the live player) is replaced by the recording player.
                    if (answer.kind === 'record' && scene.dataset.kind !== 'record') {
                        return goLive(answer, null, 'record');
                    }
                    // The broadcast ended, the clip is still preparing: the
                    // live player and any other scene are replaced by the
                    // "recording will appear soon" stub.
                    if (answer.kind === 'recordpending' && scene.dataset.kind !== 'recordpending') {
                        return goRecordpending(answer);
                    }
                    if (answer.kind !== 'live') {
                        // The poll passed — backoff resets. On "live" — it
                        // doesn't: if the fragment fails to come again, the
                        // backoff must keep growing instead of starting over
                        // every round.
                        failures = 0;
                        schedule();
                        return null;
                    }
                    if (scene.dataset.kind === 'live') {
                        // The live player is already on screen: don't touch
                        // it (otherwise every round would re-read the
                        // fragment and break playback). The poll is alive,
                        // waiting for the broadcast to end.
                        updateActions(answer.actions, cmid);
                        schedule();
                        return null;
                    }
                    return scene.dataset.kind === 'record' ? offerLive(answer) : goLive(answer, null);
                })
                .catch(function(error) {
                    // The poll is a background task: an error changes nothing
                    // on the page, and the server response text (not
                    // transport) goes to the console.
                    window.console.warn('mod_agora: ' + (error && error.message ? error.message : error));
                    if (error && STOP_ERRORS.indexOf(error.errorcode) !== -1) {
                        done = true;
                        return;
                    }
                    // Network or server failure: backoff doubles up to five
                    // minutes, so a hundred open tabs don't hammer the site down.
                    failures++;
                    schedule();
                });
        };

        schedule = function() {
            var delay = Math.min(interval * Math.pow(2, failures), MAX_BACKOFF);
            window.setTimeout(poll, delay + Math.floor(Math.random() * jitter));
        };

        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && paused) {
                paused = false;
                poll();
            }
        });

        schedule();
    };


    /**
     * Move the new comment field above the feed.
     *
     * Core finds its nodes by IDs, so rearranging does not break it:
     * handlers are attached to the nodes themselves and move along with
     * them.
     *
     * @return {void}
     */
    var liftCommentForm = function() {
        var areas = document.querySelectorAll('.mod-agora-comments .comment-ctrl');
        Array.prototype.forEach.call(areas, function(ctrl) {
            var form = ctrl.querySelector('.comment-area');
            var list = ctrl.querySelector('.comment-list');
            // eslint-disable-next-line no-bitwise
            if (form && list && form.compareDocumentPosition(list) & Node.DOCUMENT_POSITION_PRECEDING) {
                ctrl.insertBefore(form, list);
            }
        });
    };

    /**
     * Collapse the course broadcast list on a narrow screen.
     *
     * JS sets the class, not the server: without JS the "Show all" button
     * wouldn't work, and the learner would be left with a cut list forever.
     *
     * @return {void}
     */
    var collapsePlaylist = function() {
        var sections = document.querySelectorAll('[data-region="agora-playlist"]');
        Array.prototype.forEach.call(sections, function(section) {
            var button = section.querySelector('[data-action="agora-showall"]');
            if (!button) {
                return;
            }
            section.classList.add('mod-agora-collapsed');
            button.addEventListener('click', function(event) {
                event.preventDefault();
                section.classList.remove('mod-agora-collapsed');
            });
        });
    };

    return {
        /**
         * Page entry point.
         *
         * @return {void}
         */
        init: function() {
            liftCommentForm();
            collapsePlaylist();
            var scene = document.querySelector('[data-region="agora-scene"][data-poll]');
            if (scene) {
                watchLive(scene);
            }
        }
    };
});
