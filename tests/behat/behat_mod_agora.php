<?php
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
 * Behat steps for «Agora broadcast».
 *
 * @package    mod_agora
 * @category   test
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat steps for «Agora broadcast».
 *
 * @package    mod_agora
 * @category   test
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_agora extends behat_base {
    /**
     * Switches the connection to the central stub from `tests/fixtures`.
     *
     * @Given /^the Agora stand is faked$/
     */
    public function the_agora_stand_is_faked() {
        global $CFG;

        $fake = $CFG->wwwroot . '/mod/agora/tests/fixtures/fake_central.php';
        set_config('centralurl', $fake, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
        set_config('playerlogin', 'moodle-player', 'mod_agora');
        set_config('playerpassword', \core\encryption::encrypt('player-secret'), 'mod_agora');
        set_config('adminlogin', 'moodle-admin', 'mod_agora');
        set_config('adminpassword', \core\encryption::encrypt('admin-secret'), 'mod_agora');
        set_config('publisherlogin', 'moodle-publisher', 'mod_agora');
        set_config('publisherpassword', \core\encryption::encrypt('publisher-secret'), 'mod_agora');
        set_config('grantttl', 7200, 'mod_agora');
        set_config('maxconcurrent', 2, 'mod_agora');
        set_config('allowuntagged', 0, 'mod_agora');
        set_config('srtportrange', '30000:39999', 'mod_agora');
        set_config('publishticketttl', 4 * HOURSECS, 'mod_agora');
        set_config('whipaddress', 'https://node.agora.example.com', 'mod_agora');

        // The stub state and the token cache start from a clean sheet.
        @unlink($CFG->dataroot . '/agora_fake_central.json');
        cache_helper::purge_by_definition('mod_agora', 'tokens');
        cache_helper::purge_by_definition('mod_agora', 'streamstate');
        cache_helper::purge_by_definition('mod_agora', 'stateattempt');
        cache_helper::purge_by_definition('mod_agora', 'previews');
        cache_helper::purge_by_definition('mod_agora', 'policy');
        cache_helper::purge_by_definition('mod_agora', 'recordrouting');
        cache_helper::purge_by_definition('mod_agora', 'recordscope');
    }

    /**
     * Enables open playback in the central stub.
     *
     * @Given /^the Agora stand has open playback enabled$/
     */
    public function the_agora_stand_has_open_playback_enabled() {
        global $CFG;

        $statefile = $CFG->dataroot . '/agora_fake_central.json';
        $state = is_readable($statefile) ? json_decode((string) file_get_contents($statefile), true) : [];
        $state = is_array($state) ? $state : [];
        $state['open_playback'] = true;
        file_put_contents($statefile, json_encode($state), LOCK_EX);
        cache_helper::purge_by_definition('mod_agora', 'policy');
    }

    /**
     * Changes the broadcast state in the central stub — the way the Agora
     * operator changes it by giving the broadcast a source or removing it.
     *
     * The plugin cache is not touched: the page must learn about the change itself.
     *
     * @Given /^the Agora stream "(?P<name_string>(?:[^"]|\\")*)" becomes "(?P<state_string>not_started|live|signal_lost|ended_recorded|ended_no_record)"$/
     * @param string $name the broadcast name
     * @param string $state the new state
     * @return void
     * @throws ExpectationException
     */
    public function the_agora_stream_becomes($name, $state) {
        global $CFG;

        $statefile = $CFG->dataroot . '/agora_fake_central.json';
        $fake = is_readable($statefile) ? json_decode((string) file_get_contents($statefile), true) : null;
        // What is missing from the file the stub takes from the default
        // composition — by the same merge as the stub itself (`agora_fake_state()`).
        require_once($CFG->dirroot . '/mod/agora/tests/fixtures/fake_central_defaults.php');
        $fake = (is_array($fake) ? $fake : []) + mod_agora_fake_central_defaults();
        $found = false;
        foreach ($fake['streams'] as $index => $stream) {
            if (($stream['name'] ?? '') === $name) {
                $fake['streams'][$index]['state'] = $state;
                $found = true;
            }
        }
        if (!$found) {
            throw new ExpectationException('The Agora stub has no broadcast ' . $name, $this->getSession());
        }
        file_put_contents($statefile, json_encode($fake), LOCK_EX);
    }

    /**
     * The central stub refuses to issue tickets (503) or issues them again.
     *
     * @Given /^the Agora stand (?P<what_string>refuses|issues) tickets$/
     * @param string $what refuses — to refuse, issues — to issue
     * @return void
     */
    public function the_agora_stand_tickets($what) {
        global $CFG;

        $statefile = $CFG->dataroot . '/agora_fake_central.json';
        $fake = is_readable($statefile) ? json_decode((string) file_get_contents($statefile), true) : null;
        require_once($CFG->dirroot . '/mod/agora/tests/fixtures/fake_central_defaults.php');
        $fake = (is_array($fake) ? $fake : []) + mod_agora_fake_central_defaults();
        $fake['refuse_grants'] = $what === 'refuses';
        file_put_contents($statefile, json_encode($fake), LOCK_EX);
    }

    /**
     * Marks the open page so that a reload can be detected later.
     *
     * @Given /^I mark the Agora page to detect a reload$/
     * @return void
     */
    public function i_mark_the_agora_page_to_detect_a_reload() {
        $this->getSession()->executeScript('window.agoraNoReload = "kept";');
    }

    /**
     * The page is the same as the marked one: there was no reload.
     *
     * @Then /^the Agora page has not been reloaded$/
     * @return void
     * @throws ExpectationException
     */
    public function the_agora_page_has_not_been_reloaded() {
        $mark = $this->getSession()->evaluateScript('return window.agoraNoReload || null;');
        if ($mark !== 'kept') {
            throw new ExpectationException('The page was reloaded: no label', $this->getSession());
        }
    }

    /**
     * The activity page is laid out in one column and does not scroll sideways.
     *
     * The layout is checked, not the class: the `mod_agora/watch` module sets the
     * collapsed-list class at any width, while only the CSS of the narrow
     * container collapses the list. The list stands under the scene (not to its
     * right), from the same edge and of the same width, collapsed by height, and
     * the document width is not greater than the window width. Caveat: the
     * stand's headless Chrome does not give a window narrower than 485 px, and
     * the «change window size to 360x720» step gets 485 — the narrow layout is
     * checked on it; the 360 width is dropped by a WebDriver quirk on the stand
     * (protocol `mvp-spec-sweep`, task 6.4).
     *
     * @Then /^the Agora page is laid out in one column without horizontal scroll$/
     * @return void
     * @throws ExpectationException
     */
    public function the_agora_page_is_one_column() {
        $layout = $this->getSession()->evaluateScript(<<<'JS'
            return (function() {
                var scene = document.querySelector('[data-region="agora-scene"]');
                var list = document.querySelector('[data-region="agora-playlist"]');
                var rows = list ? list.querySelector('.mod-agora-rows') : null;
                if (!scene || !list || !rows) {
                    return {missing: true};
                }
                var s = scene.getBoundingClientRect();
                var l = list.getBoundingClientRect();
                return {
                    missing: false,
                    scroll: document.documentElement.scrollWidth,
                    width: document.documentElement.clientWidth,
                    // One column: the list under the scene, from the same left
                    // edge and of the same width, and the scene is not a
                    // collapsed strip.
                    below: s.width > 0.5 * document.documentElement.clientWidth
                        && l.top >= s.bottom - 1
                        && Math.abs(l.left - s.left) <= 2
                        && Math.abs(l.width - s.width) <= 2,
                    rows: rows.getBoundingClientRect().height,
                    rem: parseFloat(getComputedStyle(document.documentElement).fontSize)
                };
            })();
JS
        );
        if (!empty($layout['missing'])) {
            throw new ExpectationException('The page has neither the scene nor the course broadcast list', $this->getSession());
        }
        if ((int) $layout['scroll'] > (int) $layout['width']) {
            throw new ExpectationException(
                "The page scrolls sideways: document width {$layout['scroll']} at window {$layout['width']}",
                $this->getSession()
            );
        }
        if (empty($layout['below'])) {
            throw new ExpectationException('The course broadcast list is not under the scene but next to it', $this->getSession());
        }
        // 15rem — the collapsed list height in styles.css; half a line of slack
        // for rounding.
        if ((float) $layout['rows'] > 15.5 * (float) $layout['rem']) {
            throw new ExpectationException(
                "The list is not collapsed in height: {$layout['rows']} px",
                $this->getSession()
            );
        }
    }

    /**
     * Waits until the open page changes the scene by itself — up to 35 seconds.
     *
     * The page asks the state every 15–20 seconds, and a value younger than 15
     * seconds is taken from the cache: the worst case is 35 seconds, and exactly
     * that is what the spec promises. Waiting longer would mean missing the
     * «player arrived, but later than promised» regression. The usual Behat
     * waiting limit (10 seconds) does not cover it, and shortening the poll
     * interval for the test would mean checking a different page than the
     * learner has.
     *
     * @Then /^I wait until the Agora page shows the (?P<what_string>live player|live banner)$/
     * @param string $what what to wait for: the live player or the «The broadcast has started» banner
     * @return void
     * @throws ExpectationException
     */
    public function i_wait_until_the_agora_page_shows($what) {
        $selector = $what === 'live player'
            ? '[data-region="agora-scene"][data-kind="live"] iframe.mod-agora-player'
            : '[data-region="agora-livebanner"]';
        $deadline = time() + 35;
        while (time() < $deadline) {
            if ($this->getSession()->getPage()->find('css', $selector)) {
                return;
            }
            usleep(500000);
        }
        throw new ExpectationException('Nothing appeared on the page in 35 seconds: ' . $what, $this->getSession());
    }

    /**
     * The player on the stage plays the recording (`dvr=true` in the address) or the live broadcast.
     *
     * @Then /^the Agora stage plays the (?P<what_string>recording|live broadcast)$/
     * @param string $what the recording or the live broadcast
     * @return void
     * @throws ExpectationException
     */
    public function the_agora_stage_plays($what) {
        $frame = $this->getSession()->getPage()->find('css', '[data-region="agora-scene"] iframe.mod-agora-player');
        if (!$frame) {
            throw new ExpectationException('No player on the scene', $this->getSession());
        }
        $record = strpos((string) $frame->getAttribute('src'), 'dvr=true') !== false;
        if ($record !== ($what === 'recording')) {
            throw new ExpectationException('The player plays the wrong thing: ' . $frame->getAttribute('src'), $this->getSession());
        }
    }

    /**
     * The recording on the stage is opened within its own window: the player address has `from` and `to`.
     *
     * The central stub gives the bounds only to a ticket not bound to an
     * address, like the real node: if the Moodle server asks for them with a
     * learner's ticket, there will be no window.
     *
     * @Then /^the Agora recording plays within its archive window$/
     * @return void
     * @throws ExpectationException
     */
    public function the_agora_recording_plays_within_its_archive_window() {
        $frame = $this->getSession()->getPage()->find('css', '[data-region="agora-scene"] iframe.mod-agora-player');
        $src = $frame ? (string) $frame->getAttribute('src') : '';
        if (strpos($src, 'dvr=true') === false || !preg_match('/[?&]from=\d+/', $src) || !preg_match('/[?&]to=\d+/', $src)) {
            throw new ExpectationException('Recording without archive bounds: ' . $src, $this->getSession());
        }
    }

    /**
     * There is exactly one «The broadcast has started» banner on the page.
     *
     * @Then /^there is exactly one Agora live banner$/
     * @return void
     * @throws ExpectationException
     */
    public function there_is_exactly_one_agora_live_banner() {
        $banners = $this->getSession()->getPage()->findAll('css', '[data-region="agora-livebanner"]');
        if (count($banners) !== 1) {
            throw new ExpectationException('«Broadcast started» banners: ' . count($banners), $this->getSession());
        }
    }

    /**
     * Replaces the browser confirm(): dialogs are considered confirmed.
     *
     * The broadcast lead buttons ask for a confirmation about reversibility (for
     * example, «End the broadcast?»), and Selenium does not close dialogs itself.
     *
     * @Given /^the browser confirms all dialogs$/
     * @return void
     */
    public function the_browser_confirms_all_dialogs() {
        $this->getSession()->executeScript('window.confirm = function() { return true; };');
    }

    /**
     * Breaks the scene fragment requests in the browser: this is what a network
     * or server failure on the path to the player looks like. The request goes
     * to a non-existent address.
     *
     * @Given /^the browser (?P<what_string>breaks|repairs) requests for the Agora scene$/
     * @param string $what breaks — to break, repairs — to repair
     * @return void
     */
    public function the_browser_breaks_scene_requests($what) {
        if ($what === 'breaks') {
            $this->getSession()->executeScript(
                'if (!window.agoraOpen) { window.agoraOpen = XMLHttpRequest.prototype.open;'
                . ' XMLHttpRequest.prototype.open = function(m, u) { var a = Array.prototype.slice.call(arguments);'
                . ' if (String(u).indexOf("core_get_fragment") !== -1) {'
                . ' (window.agoraFragmentTimes = window.agoraFragmentTimes || []).push(Date.now()); }'
                . ' if (window.agoraBreakScene && String(u).indexOf("core_get_fragment") !== -1) {'
                . ' a[1] = M.cfg.wwwroot + "/mod/agora/no-such-endpoint.php"; }'
                . ' return window.agoraOpen.apply(this, a); }; }'
                . ' window.agoraBreakScene = true;'
            );
        } else {
            $this->getSession()->executeScript('window.agoraBreakScene = false;');
        }
    }

    /**
     * Scene request retries under failures come with a growing interval.
     *
     * The «the browser breaks requests for the Agora scene» step records the
     * fragment request moments. The backoff doubles: the second gap must be
     * noticeably longer than the first (15–20 s poll × 2 versus × 4).
     *
     * @Then /^the Agora scene was retried with a growing interval$/
     * @return void
     * @throws ExpectationException
     */
    public function the_agora_scene_was_retried_with_a_growing_interval() {
        $times = (array) $this->getSession()->evaluateScript('return window.agoraFragmentTimes || [];');
        if (count($times) < 3) {
            throw new ExpectationException('Fewer than three scene requests: ' . count($times), $this->getSession());
        }
        $first = ($times[1] - $times[0]) / 1000;
        $second = ($times[2] - $times[1]) / 1000;
        if ($second < $first + 10) {
            throw new ExpectationException(
                sprintf('The interval does not grow: %.1f s, then %.1f s', $first, $second),
                $this->getSession()
            );
        }
    }

    /**
     * Makes the course caches cold: the next page will assemble modinfo anew.
     *
     * This is exactly what the stand looks like after a course edit. With a warm
     * cache the activity data is already assembled, and the implicit `$PAGE`
     * does not surface on this path — the «$PAGE->context was not set» defect
     * survived to the stand exactly this way.
     *
     * @Given /^the Agora course caches are stale$/
     * @return void
     */
    public function the_agora_course_caches_are_stale() {
        global $DB;

        foreach ($DB->get_fieldset_sql('SELECT DISTINCT course FROM {agora}') as $courseid) {
            rebuild_course_cache((int) $courseid, true);
        }
    }

    /**
     * Runs the scheduled broadcast states warming.
     *
     * Without it the sidebar on the Behat site is always empty: it takes the
     * neighbour states only from the cache.
     *
     * @Given /^the Agora stream states are refreshed$/
     * @return void
     */
    public function the_agora_stream_states_are_refreshed() {
        ob_start();
        (new \mod_agora\task\refresh_states())->execute();
        ob_end_clean();
    }

    /**
     * Puts the accumulated watched duration of the activity on the learner.
     *
     * @Given /^user "(?P<username_string>(?:[^"]|\\")*)" has watched "(?P<minutes_number>\d+)" minutes of "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $username the learner login
     * @param int $minutes the watched minutes
     * @param string $name the activity name
     * @return void
     * @throws ExpectationException
     */
    public function user_has_watched_minutes_of($username, $minutes, $name) {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['username' => $username]);
        $agoraid = $DB->get_field('agora', 'id', ['name' => $name]);
        if (!$userid || !$agoraid) {
            throw new ExpectationException(
                'No user ' . $username . ' or activity ' . $name,
                $this->getSession()
            );
        }
        // The same door as real watching: the ticket issue creates the pair row
        // (`note_grant`), the task adds the seconds (`add_seconds`). Nobody
        // overwrites the row as a whole — the plugin has no such path.
        \mod_agora\local\watch::note_grant(
            (int) $agoraid,
            (int) $userid,
            \mod_agora\local\grant_manager::user_ref((int) $agoraid, (int) $userid),
            time() + HOURSECS
        );
        $row = \mod_agora\local\watch::row((int) $agoraid, (int) $userid);
        \mod_agora\local\watch::add_seconds((int) $row->id, (int) $minutes * MINSECS, time());
    }

    /**
     * Checks that a broadcast with this name and tag appeared in the central stub.
     *
     * @Then /^the Agora stand has stream "(?P<name_string>(?:[^"]|\\")*)" tagged "(?P<tag_string>(?:[^"]|\\")*)"$/
     * @param string $name the broadcast name
     * @param string $tag the content tag
     * @return void
     * @throws ExpectationException
     */
    public function the_agora_stand_has_stream_tagged($name, $tag) {
        global $CFG;

        $statefile = $CFG->dataroot . '/agora_fake_central.json';
        $state = is_readable($statefile) ? json_decode((string) file_get_contents($statefile), true) : [];
        foreach (($state['streams'] ?? []) as $stream) {
            if (($stream['name'] ?? '') === $name && in_array($tag, $stream['labels'] ?? [], true)) {
                return;
            }
        }
        throw new ExpectationException(
            'The Agora stub has no broadcast ' . $name . ' with tag ' . $tag,
            $this->getSession()
        );
    }
}
