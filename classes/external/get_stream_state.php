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

namespace mod_agora\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_agora\local\player_view;
use mod_agora\local\stream_state;

/**
 * The broadcast state of an activity — for polling from the open activity page.
 *
 * Returns only the state and what the stage would show right now; no ticket, no
 * player address. The access rule is the same as the activity page: course
 * login, `mod/agora:view` and activity availability (`validate_context()` calls
 * `require_login()` with the module, and the core checks `uservisible` inside).
 * The state is taken the same way as on the page (`stream_state::for_page()`):
 * from the cache, and to central at most once per `stream_state::PAGE_FRESH`
 * per stream.
 *
 * @package    mod_agora
 * @category   external
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_stream_state extends external_api {
    /**
     * Parameters: the course module.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * The broadcast state of the activity.
     *
     * @param int $cmid course module
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB;

        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'agora');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/agora:view', $context);

        $agora = $DB->get_record('agora', ['id' => $cm->instance], '*', MUST_EXIST);
        $state = stream_state::for_page((string) $agora->stream_name);
        // A central refusal goes to the event log, as on the page: `error` is
        // present only for the process that went to central itself, that is, at
        // most once per `stream_state::PAGE_FRESH` per stream; from the cache it
        // comes without one.
        if ($state->error !== null) {
            \mod_agora\local\failure::log($state->error, $context, 'opstreamstats', 'admin');
        }

        // The metadata line transitions are the same the page would draw: after
        // switching to the broadcast, "watch the recording" and "back to the
        // broadcast" become stale. The button attributes we carry are the same
        // as the server markup: otherwise polling would offer "End broadcast"
        // without the irreversibility confirmation (review R2).
        $actions = [];
        $cminfo = get_fast_modinfo($course)->get_cm((int) $cm->id);
        foreach (player_view::actions($agora, $cminfo, false, $state) as $action) {
            $actions[] = [
                'key' => $action['key'],
                // An inactive button ("Start broadcast" without a signal) carries no link.
                'url' => $action['url'] !== null ? $action['url']->out(false) : '',
                'label' => $action['label'],
                'blank' => $action['key'] === 'console',
                'primary' => !empty($action['primary']),
                'disabled' => !empty($action['disabled']),
                'hint' => (string) ($action['hint'] ?? ''),
                'confirm' => (string) ($action['confirm'] ?? ''),
                // Broadcast control actions are returned by polling as POST
                // forms, mirroring the server markup (Ф-1).
                'form' => !empty($action['form']),
                'name' => (string) ($action['name'] ?? $action['key']),
                'sesskey' => !empty($action['form']) ? sesskey() : '',
            ];
        }

        // Polling respects the phase: "planned" answers with a placeholder, not
        // a live player; the one leading the broadcast sees `live` while the
        // signal is up, because they watch the verification player.
        $canmanage = has_capability('mod/agora:broadcast', $context);
        $badge = player_view::badge($agora, $state);

        return [
            'state' => $state->state,
            'kind' => player_view::kind($agora, $state, false, $canmanage),
            'badge' => $badge['text'],
            'badgeclass' => $badge['class'],
            'fetchedat' => $state->fetchedat,
            'actions' => $actions,
        ];
    }

    /**
     * Response: the state, what the stage would show, the badge and the value age.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'state' => new external_value(PARAM_ALPHAEXT, 'Stream state'),
            'kind' => new external_value(PARAM_ALPHA, 'What the stage would show now'),
            'badge' => new external_value(PARAM_TEXT, 'State badge text'),
            'badgeclass' => new external_value(PARAM_ALPHAEXT, 'State badge CSS class'),
            'fetchedat' => new external_value(PARAM_INT, 'When the state was read from Agora, unix time'),
            'actions' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHA, 'Action key: record, live, console, start or end'),
                'url' => new external_value(PARAM_URL, 'Action link'),
                'label' => new external_value(PARAM_TEXT, 'Button label'),
                'blank' => new external_value(PARAM_BOOL, 'Open in a new tab'),
                'primary' => new external_value(PARAM_BOOL, 'Primary button style'),
                'disabled' => new external_value(PARAM_BOOL, 'Button is inactive (no link)'),
                'hint' => new external_value(PARAM_TEXT, 'Why the button is inactive'),
                'confirm' => new external_value(PARAM_RAW, 'Confirmation text as JSON for confirm()'),
                'form' => new external_value(PARAM_BOOL, 'Action must be sent as POST (a form, not a link)'),
                'name' => new external_value(PARAM_ALPHA, 'The POST action name: startbroadcast or endbroadcast'),
                'sesskey' => new external_value(PARAM_RAW, 'Session key for the POST form'),
            ]), 'Buttons of the metadata line'),
        ]);
    }
}
