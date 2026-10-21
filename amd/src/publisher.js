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
 * Publisher: broadcasting from the browser to the broadcast over WHIP.
 *
 * The signal starts right away when the page opens (D8): camera and mic are
 * captured automatically, a non-trickle SDP offer goes to the WHIP address
 * with the ticket, and the local frame is a live preview of the publication
 * (D11). There is no separate "Start" button: the signal is a technical
 * precondition of the broadcast, not an action by the presenter. A denied
 * access is reported as a state with a reason; the device toggles allow
 * retrying the capture after permission is granted.
 *
 * Devices are three on/off toggles (D9). Camera and mic are switched by
 * replacing the track in the sender (`replaceTrack`): the m-line set does
 * not change, the WHIP session is untouched. Screen sharing changes the
 * track set, and Agora does not support PATCH renegotiation (confirmed
 * 405): the session is recreated — DELETE of the old one and POST of a new
 * offer with the new track set. Cancelling the screen picker changes
 * nothing and does not drop the broadcast.
 *
 * The broadcast is a single "Broadcast" toggle (D10): lifecycle actions go
 * as an AJAX POST to publish.php (204 response), the page is not reloaded,
 * the signal and devices are unaffected. Switching off asks for
 * confirmation about irreversibility. Every 15 seconds the page polls the
 * broadcast state (`mod_agora_get_stream_state`) and updates the toggle and
 * the phase banner (D5) — including when the broadcast was switched from
 * the module instance page in another window.
 *
 * The page gets the publish ticket from Moodle (`mod_agora_get_publish_ticket`)
 * and reissues it every half of its lifetime while open. Leaving the page
 * (`pagehide`) sends a DELETE of the session with `keepalive` — a best
 * effort, not a guarantee (D6); device tracks are always stopped. Without a
 * secure context (HTTP installation) getUserMedia is unavailable — the page
 * explains this in words, SRT broadcasting works regardless.
 *
 * @module mod_agora/publisher
 */
define(['core/ajax', 'core/str'], function(Ajax, Str) {
    // Core errors / permissions: the page reloads and decides what to do next.
    var FATAL = ['servicerequireslogin', 'requireloginerror', 'invalidsesskey', 'nopermissions',
        'invalidcoursemodule', 'invalidrecord', 'invalidparameter', 'coursehidden', 'notenrolled',
        'accessexception', 'servicenotavailable'];

    /** @type {number} Broadcast state poll interval, ms (D5). */
    var PHASE_POLL = 15000;

    /**
     * Gather all ICE: a promise resolved with the full SDP.
     *
     * Agora accepts a single non-trickle offer, so we wait for
     * `icegatheringstate === complete` (or a timeout — an offer without a
     * tail of candidates is better than waiting forever for a broken
     * gathering).
     *
     * @param {RTCPeerConnection} pc connection
     * @return {Promise<string>} SDP offer
     */
    var gatherCompleteOffer = function(pc) {
        return new Promise(function(resolve, reject) {
            var done = false;
            var finish = function() {
                if (!done) {
                    done = true;
                    resolve(pc.localDescription.sdp);
                }
            };
            pc.addEventListener('icegatheringstatechange', function() {
                if (pc.iceGatheringState === 'complete') {
                    finish();
                }
            });
            pc.createOffer()
                .then(function(offer) {
                    return pc.setLocalDescription(offer);
                })
                .catch(reject);
            window.setTimeout(finish, 5000);
        });
    };

    /**
     * Control panel of a single publisher node.
     *
     * @param {HTMLElement} root root element of the template
     * @constructor
     */
    var Publisher = function(root) {
        this.root = root;
        this.cmid = parseInt(root.dataset.cmid, 10);
        this.stream = root.dataset.stream;
        this.ttl = parseInt(root.dataset.ticketttl, 10) || 14400;
        this.publishurl = root.dataset.publishurl;
        this.sesskey = root.dataset.sesskey;
        this.ticket = null; // {token, expiresat, ttl, whipurl}
        this.timer = null;
        this.phasetimer = null;
        this.pc = null;
        this.camera = null; // Camera track while the toggle is on.
        this.mic = null; // Microphone track while the toggle is on.
        this.screen = null; // Screen stream while the toggle is on.
        this.videosender = null; // Camera sender in the session.
        this.audiosender = null; // Microphone sender in the session.
        this.whipLocation = null; // WHIP resource address from the 201 response Location.
        this.state = 'idle';
        this.phase = 'planned';
        this.signallive = false;
        this.strings = {};
    };

    /**
     * Control element by action name.
     *
     * @param {string} name data-action="agora-toggle-<name>"
     * @return {HTMLElement}
     */
    Publisher.prototype.button = function(name) {
        return this.root.querySelector('[data-action="agora-toggle-' + name + '"]');
    };

    /**
     * Set a toggle to on/off: active — btn-primary and aria-pressed=true,
     * inactive — btn-outline-secondary.
     *
     * @param {string} name toggle name
     * @param {boolean} on enabled
     * @return {void}
     */
    Publisher.prototype.setToggle = function(name, on) {
        var button = this.button(name);
        if (button) {
            button.className = 'btn ' + (on ? 'btn-primary' : 'btn-outline-secondary');
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        }
    };

    /**
     * Status line: a word + a colouring class.
     *
     * @param {string} key string key
     * @param {string} level alert-info|alert-warning|alert-danger|alert-success
     * @param {string} extra extra text
     * @return {Promise<void>}
     */
    Publisher.prototype.setState = function(key, level, extra) {
        var status = this.root.querySelector('[data-region="agora-publisher-status"]');
        this.state = key;
        return Str.get_string(key, 'mod_agora').then(function(text) {
            status.className = 'mod-agora-publisher-status alert ' + level;
            status.textContent = extra ? text + ' ' + extra : text;
            return null;
        }).catch(function() {
            status.className = 'mod-agora-publisher-status alert ' + level;
            status.textContent = extra || '';
            return null;
        });
    };

    /**
     * Capture or connection failure — as a state with a reason (D8).
     *
     * @param {Object} error getUserMedia or connection failure
     * @return {Promise<void>}
     */
    Publisher.prototype.showFailure = function(error) {
        if (error && error.name === 'NotAllowedError') {
            return this.setState('publisherdenied', 'alert-danger');
        }
        if (error && error.name === 'NotSupportedError') {
            return this.setState('publisherneedshttps', 'alert-warning');
        }
        if (error && (error.name === 'NotFoundError' || error.name === 'OverconstrainedError')) {
            return this.setState('publishernodevices', 'alert-danger');
        }
        if (error && error.errorcode) {
            return this.setState('publisherfailed', 'alert-danger', '(' + error.errorcode + ')');
        }
        return this.setState('publisherfailed', 'alert-danger',
            error && error.message ? '(' + error.message + ')' : '');
    };

    /**
     * A fresh ticket: while more than half of its lifetime remains, the
     * server returns the live one; closer to expiry — a new one; a refusal
     * with a fatal code drops the page.
     *
     * @return {Promise<void>}
     */
    Publisher.prototype.refreshTicket = function() {
        var self = this;
        return Ajax.call([{
            methodname: 'mod_agora_get_publish_ticket',
            args: {cmid: self.cmid}
        }], true, true, true)[0].then(function(ticket) {
            self.ticket = ticket;
            return null;
        }).catch(function(error) {
            if (error && FATAL.indexOf(error.errorcode) !== -1) {
                window.location.reload();
                return null;
            }
            throw error;
        });
    };

    /**
     * Scheduled reissue: every half of the ticket lifetime while the page
     * is open.
     *
     * @return {void}
     */
    Publisher.prototype.armHeartbeat = function() {
        var self = this;
        if (self.timer) {
            window.clearTimeout(self.timer);
        }
        self.timer = window.setTimeout(function() {
            self.refreshTicket().catch(function() {
                // A reissue failure does not drop the running session: the
                // next attempt will try again.
            }).finally(function() {
                self.armHeartbeat();
            });
        }, Math.max(self.ttl, 600) / 2 * 1000);
    };

    /**
     * Capture camera and microphone.
     *
     * @return {Promise<MediaStream>}
     */
    Publisher.prototype.acquireDevices = function() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Promise.reject({name: 'NotSupportedError'});
        }
        return navigator.mediaDevices.getUserMedia({video: true, audio: true});
    };

    /**
     * Local preview: screen while it is active, otherwise camera (D11).
     *
     * @return {void}
     */
    Publisher.prototype.showPreview = function() {
        var video = this.root.querySelector('[data-region="agora-publisher-video"]');
        if (this.screen) {
            video.srcObject = this.screen;
        } else if (this.camera) {
            video.srcObject = new MediaStream([this.camera]);
        } else {
            video.srcObject = null;
        }
    };

    /**
     * New WHIP session with the current set of live tracks (D9): ticket,
     * connection, non-trickle offer, POST. Used both by the start on page
     * open and by the session recreation when screen sharing starts.
     *
     * @return {Promise<void>}
     */
    Publisher.prototype.openSession = function() {
        var self = this;
        return self.refreshTicket().then(function() {
            self.pc = new RTCPeerConnection();
            [self.camera, self.mic].forEach(function(track) {
                if (track) {
                    var sender = self.pc.addTrack(track, new MediaStream([track]));
                    if (track.kind === 'video') {
                        self.videosender = sender;
                    } else {
                        self.audiosender = sender;
                    }
                }
            });
            if (self.screen) {
                self.screen.getTracks().forEach(function(track) {
                    self.pc.addTrack(track, self.screen);
                });
            }
            return gatherCompleteOffer(self.pc);
        }).then(function(sdp) {
            return fetch(self.ticket.whipurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/sdp'},
                body: sdp
            });
        }).then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            // 201 response: answer SDP from the body — on to ICE/DTLS.
            // Location — resource address for DELETE.
            self.whipLocation = response.headers.get('Location') || self.ticket.whipurl;
            return response.text();
        }).then(function(answer) {
            if (answer.trim() === '') {
                throw new Error('empty answer');
            }
            return self.pc.setRemoteDescription({type: 'answer', sdp: answer});
        });
    };

    /**
     * Close the WHIP session: DELETE the resource with the token and close
     * the connection. Device tracks are not stopped — camera and mic
     * survive the session; their own toggles and leaving the page switch
     * them off.
     *
     * @return {Promise<void>}
     */
    Publisher.prototype.closeSession = function() {
        var self = this;
        var close = function() {
            if (self.pc) {
                self.pc.close();
                self.pc = null;
            }
            self.videosender = null;
            self.audiosender = null;
            self.whipLocation = null;
        };
        if (!self.whipLocation) {
            close();
            return Promise.resolve();
        }
        // The token must be in DELETE too: the node accepts it as in POST,
        // otherwise the session on the node would live until its own TTL
        // instead of a proper close.
        var separator = self.whipLocation.indexOf('?') === -1 ? '?' : '&';
        return fetch(self.whipLocation + separator + 'token=' + encodeURIComponent(self.ticket.token), {
            method: 'DELETE'
        }).catch(function() {
            // The node may have already forgotten the session: the local close is still honest.
        }).then(close);
    };

    /**
     * Recreate the session with the current track set (screen sharing, D9):
     * the signal pause is shown honestly as a state.
     *
     * @return {Promise<void>}
     */
    Publisher.prototype.recreateSession = function() {
        var self = this;
        self.setState('publisherconnecting', 'alert-info');
        return self.closeSession().then(function() {
            return self.openSession();
        }).then(function() {
            self.setState('publisherlive', 'alert-success');
            return null;
        }).catch(function(error) {
            self.showFailure(error);
        });
    };

    /**
     * Auto-publish on page open (D8): capture camera and microphone and
     * start the session right away, without a button. Failure — as a state
     * with a reason; the device toggles allow retrying.
     *
     * @return {void}
     */
    Publisher.prototype.start = function() {
        var self = this;
        self.setState('publisherconnecting', 'alert-info');
        self.acquireDevices().then(function(stream) {
            self.camera = stream.getVideoTracks()[0] || null;
            self.mic = stream.getAudioTracks()[0] || null;
            if (self.camera) {
                self.camera.addEventListener('ended', function() {
                    self.camera = null;
                    self.setToggle('camera', false);
                    self.showPreview();
                });
            }
            if (self.mic) {
                self.mic.addEventListener('ended', function() {
                    self.mic = null;
                    self.setToggle('mic', false);
                });
            }
            self.setToggle('camera', self.camera !== null);
            self.setToggle('mic', self.mic !== null);
            self.showPreview();
            return self.openSession();
        }).then(function() {
            self.setState('publisherlive', 'alert-success');
            self.armHeartbeat();
            self.pollPhase();
            return null;
        }).catch(function(error) {
            self.closeSession();
            self.showFailure(error);
        });
    };

    /**
     * Camera or mic toggle (D9): replace the track in the sender without
     * recreating the session. Off — replaceTrack(null) and stop the track,
     * on — capture and replaceTrack. If there is no session yet (the capture
     * on page open was denied), enabling the toggle repeats the whole
     * auto-publish.
     *
     * @param {string} which camera | mic
     * @return {void}
     */
    Publisher.prototype.toggleDevice = function(which) {
        var self = this;
        var track = which === 'camera' ? self.camera : self.mic;
        var sender = which === 'camera' ? self.videosender : self.audiosender;

        if (track) {
            // Off: frames stop, the session stays alive.
            track.stop();
            if (which === 'camera') {
                self.camera = null;
            } else {
                self.mic = null;
            }
            if (sender) {
                sender.replaceTrack(null);
            }
            self.setToggle(which, false);
            self.showPreview();
            return;
        }

        // On. No session — repeat the full capture: after a denied access
        // the toggle is the "retry" (D8).
        if (!self.pc) {
            self.start();
            return;
        }
        var constraints = which === 'camera' ? {video: true} : {audio: true};
        self.acquireDevice(constraints).then(function(device) {
            if (which === 'camera') {
                self.camera = device;
            } else {
                self.mic = device;
            }
            device.addEventListener('ended', function() {
                if (which === 'camera') {
                    self.camera = null;
                } else {
                    self.mic = null;
                }
                self.setToggle(which, false);
                self.showPreview();
            });
            var target = which === 'camera' ? self.videosender : self.audiosender;
            if (target) {
                target.replaceTrack(device);
            }
            self.setToggle(which, true);
            self.showPreview();
            return null;
        }).catch(function(error) {
            self.showFailure(error);
        });
    };

    /**
     * Capture a single device for a toggle.
     *
     * @param {Object} constraints getUserMedia constraints
     * @return {Promise<MediaStreamTrack>}
     */
    Publisher.prototype.acquireDevice = function(constraints) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Promise.reject({name: 'NotSupportedError'});
        }
        return navigator.mediaDevices.getUserMedia(constraints).then(function(stream) {
            return stream.getTracks()[0];
        });
    };

    /**
     * Screen toggle (D9): screen sharing changes the track set, so the
     * session is recreated — DELETE of the old one and POST of a new offer
     * with the new set. Cancelling the screen picker (NotAllowedError)
     * changes nothing: the toggle stays off, the broadcast continues with
     * the previous set.
     *
     * @return {void}
     */
    Publisher.prototype.toggleScreen = function() {
        var self = this;
        if (self.screen) {
            // Off: screen tracks stop, the session is recreated without them.
            var screen = self.screen;
            self.screen = null;
            screen.getTracks().forEach(function(track) {
                track.stop();
            });
            self.setToggle('screen', false);
            self.showPreview();
            self.recreateSession();
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
            return;
        }
        navigator.mediaDevices.getDisplayMedia({video: true}).then(function(stream) {
            self.screen = stream;
            // The user stopped the sharing via the browser — same as switching off.
            stream.getVideoTracks()[0].addEventListener('ended', function() {
                if (self.screen === stream) {
                    self.screen = null;
                    self.setToggle('screen', false);
                    self.showPreview();
                    self.recreateSession();
                }
            });
            self.setToggle('screen', true);
            self.showPreview();
            self.recreateSession();
            return null;
        }).catch(function(error) {
            if (error && error.name === 'NotAllowedError') {
                return; // The user closed the screen picker: nothing changes.
            }
            self.showFailure(error);
        });
    };

    /**
     * The "Broadcast" toggle (D10): the lifecycle action as an AJAX POST to
     * publish.php without reload — the signal and devices are unaffected.
     * After the response the state is polled right away, without waiting
     * for the next tick.
     *
     * @return {void}
     */
    Publisher.prototype.toggleBroadcast = function() {
        var self = this;
        var button = self.button('broadcast');
        if (!button || button.disabled) {
            return;
        }
        var action = self.phase === 'ongoing' ? 'endbroadcast' : 'startbroadcast';
        // Ending the broadcast is irreversible — same confirmation as the
        // button on the module instance page.
        if (action === 'endbroadcast' && !window.confirm(self.strings.endbroadcastconfirm)) {
            return;
        }
        button.disabled = true;
        var body = new URLSearchParams();
        body.set('id', self.cmid);
        body.set('sesskey', self.sesskey);
        body.set('action', action);
        body.set('ajax', '1');
        fetch(self.publishurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        }).then(function(response) {
            if (response.status === 204) {
                self.pollPhase();
                return null;
            }
            // Lifecycle refusal (409) arrives as text — we name it.
            return response.text().then(function(text) {
                self.setState('publisherfailed', 'alert-danger', text);
                self.pollPhase();
                return null;
            });
        }).catch(function() {
            self.setState('publisherfailed', 'alert-danger');
            self.pollPhase();
        });
    };

    /**
     * The "Broadcast" toggle and the phase banner from a poll response
     * (D5, D10).
     *
     * The phase is read from poll transitions — the same ones the module
     * instance page renders: "start" exists in the "planned" phase, "end" —
     * in the "ongoing" phase, neither — the broadcast is over (in another
     * window): the toggle hides, the link to the recording is shown.
     *
     * @param {Object} answer response of `mod_agora_get_stream_state`
     * @return {void}
     */
    Publisher.prototype.applyPhase = function(answer) {
        var self = this;
        var bkey = null;
        (answer.actions || []).forEach(function(action) {
            if (['start', 'startdisabled', 'end'].indexOf(action.key) !== -1) {
                bkey = action.key;
            }
        });
        self.phase = bkey === 'end' ? 'ongoing' : (bkey !== null ? 'planned' : 'ended');
        self.signallive = answer.state === 'live';

        var banner = self.root.querySelector('[data-region="agora-broadcast-phase"]');
        banner.textContent = self.strings[
            self.phase === 'ongoing' ? 'publisherphaseongoing'
                : (self.phase === 'planned' ? 'badgeplanned' : 'badgeended')
        ];

        var button = self.button('broadcast');
        var ended = self.root.querySelector('[data-region="agora-broadcast-ended"]');
        if (self.phase === 'ended') {
            button.hidden = true;
            ended.hidden = false;
            return;
        }
        button.hidden = false;
        ended.hidden = true;
        var on = self.phase === 'ongoing';
        button.textContent = on ? self.strings.publisherbroadcastoff : self.strings.publisherbroadcaston;
        button.className = 'btn ' + (on ? 'btn-primary' : 'btn-outline-secondary');
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        // "Start broadcast" is enabled when the signal is live; without it —
        // the same hint the server-side render gives.
        button.disabled = !on && !self.signallive;
        button.title = button.disabled ? self.strings.startbroadcastnosignal : '';
    };

    /**
     * One-shot broadcast state poll: after the "Broadcast" toggle action
     * and on session start, so that "Start broadcast" activity picks up the
     * live signal.
     *
     * @return {void}
     */
    Publisher.prototype.pollPhase = function() {
        var self = this;
        Ajax.call([{methodname: 'mod_agora_get_stream_state', args: {cmid: self.cmid}}], true, true, true)[0]
            .then(function(answer) {
                self.applyPhase(answer);
                return null;
            }).catch(function() {
                // A one-off poll failure does not drop the broadcast: the
                // next tick of the scheduled poll will ask again.
            });
    };

    /**
     * Broadcast state poll every 15 seconds: the "Broadcast" toggle and the
     * phase banner without reload — including when the broadcast was
     * started or ended from the module instance page in another window. A
     * fatal failure (login expired, no permissions) stops the poll; it does
     * not touch the broadcast or the ticket reissue.
     *
     * @return {void}
     */
    Publisher.prototype.armPhasePoll = function() {
        var self = this;
        var tick = function() {
            // A hidden tab does not ask: when it returns, it will ask on the
            // next tick; the poll does not extend the session.
            if (document.hidden) {
                self.phasetimer = window.setTimeout(tick, PHASE_POLL);
                return;
            }
            Ajax.call([{methodname: 'mod_agora_get_stream_state', args: {cmid: self.cmid}}], true, true, true)[0]
                .then(function(answer) {
                    self.applyPhase(answer);
                    self.phasetimer = window.setTimeout(tick, PHASE_POLL);
                    return null;
                }).catch(function(error) {
                    // Network or server failure — no reason to drop the broadcast.
                    if (!error || FATAL.indexOf(error.errorcode) === -1) {
                        self.phasetimer = window.setTimeout(tick, PHASE_POLL);
                    }
                    // A fatal failure (login expired, no permissions) won't
                    // be fixed by a retry: the poll stops, it does not touch
                    // the broadcast or tickets.
                });
        };
        self.phasetimer = window.setTimeout(tick, PHASE_POLL);
    };

    /**
     * Leaving the page: DELETE of the WHIP session resource with the token
     * and `keepalive` — a best effort, not a guarantee (D6); protection
     * from zombie sessions remains the server-side session replacement.
     * Device tracks are always stopped.
     *
     * @return {void}
     */
    Publisher.prototype.armPagehide = function() {
        var self = this;
        window.addEventListener('pagehide', function() {
            if (self.whipLocation && self.ticket && self.ticket.token) {
                var separator = self.whipLocation.indexOf('?') === -1 ? '?' : '&';
                fetch(self.whipLocation + separator + 'token=' + encodeURIComponent(self.ticket.token), {
                    method: 'DELETE',
                    keepalive: true
                }).catch(function() {
                    // Best effort: the browser may not have sent it — do nothing.
                });
            }
            [self.camera, self.mic].forEach(function(track) {
                if (track) {
                    track.stop();
                }
            });
            if (self.screen) {
                self.screen.getTracks().forEach(function(track) {
                    track.stop();
                });
            }
        });
    };

    /**
     * Wire up the toggles and labels.
     *
     * @return {Promise<void>}
     */
    Publisher.prototype.bind = function() {
        var self = this;
        return Str.get_strings([
            {key: 'publisherdenied', component: 'mod_agora'},
            {key: 'publisherneedshttps', component: 'mod_agora'},
            {key: 'publishernodevices', component: 'mod_agora'},
            {key: 'badgeplanned', component: 'mod_agora'},
            {key: 'publisherphaseongoing', component: 'mod_agora'},
            {key: 'badgeended', component: 'mod_agora'},
            {key: 'startbroadcastnosignal', component: 'mod_agora'},
            {key: 'publisherbroadcaston', component: 'mod_agora'},
            {key: 'publisherbroadcastoff', component: 'mod_agora'},
            {key: 'endbroadcastconfirm', component: 'mod_agora'}
        ]).then(function(strings) {
            self.strings = {
                publisherdenied: strings[0],
                publisherneedshttps: strings[1],
                publishernodevices: strings[2],
                badgeplanned: strings[3],
                publisherphaseongoing: strings[4],
                badgeended: strings[5],
                startbroadcastnosignal: strings[6],
                publisherbroadcaston: strings[7],
                publisherbroadcastoff: strings[8],
                endbroadcastconfirm: strings[9]
            };
            self.button('camera').addEventListener('click', function(event) {
                event.preventDefault();
                self.toggleDevice('camera');
            });
            self.button('mic').addEventListener('click', function(event) {
                event.preventDefault();
                self.toggleDevice('mic');
            });
            self.button('screen').addEventListener('click', function(event) {
                event.preventDefault();
                self.toggleScreen();
            });
            self.button('broadcast').addEventListener('click', function(event) {
                event.preventDefault();
                self.toggleBroadcast();
            });
            // Secure context is the gate before capture: getUserMedia is
            // unavailable outside HTTPS, explain right away, the device
            // toggles stay disabled.
            if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                self.setState('publisherneedshttps', 'alert-warning');
            } else {
                // The signal starts right away on page open (D8), without a "Start" button.
                self.start();
            }
            self.armPhasePoll();
            self.armPagehide();
            return null;
        });
    };

    return {
        /**
         * Entry point of the publisher page.
         *
         * @return {void}
         */
        init: function() {
            var root = document.querySelector('[data-region="agora-publisher"]');
            if (!root) {
                return;
            }
            var publisher = new Publisher(root);
            publisher.bind();
        }
    };
});
