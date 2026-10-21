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
use core_external\external_single_structure;
use core_external\external_value;
use mod_agora\local\broadcast_phase;
use mod_agora\local\grant_manager;

/**
 * The publish ticket for the publisher page: getUserMedia → WHIP.
 *
 * Returns a fresh ticket issued by `lms-publisher` and the WHIP destination
 * address. The publisher page calls the function on opening and then once per
 * half of the ticket lifetime: while more than half of the lifetime remains,
 * the live ticket is reused; closer to expiry a new one is issued.
 *
 * @package    mod_agora
 * @category   external
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_publish_ticket extends external_api {
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
     * The publish ticket and the WHIP destination address.
     *
     * @param int $cmid course module
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'agora');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/agora:broadcast', $context);

        $agora = $DB->get_record('agora', ['id' => $cm->instance], '*', MUST_EXIST);
        if (empty($agora->has_phases) || empty($agora->created_by_plugin)) {
            throw new \moodle_exception('errpublishunavailable', 'mod_agora');
        }
        $phase = broadcast_phase::of($agora);
        if ($phase !== broadcast_phase::PLANNED && $phase !== broadcast_phase::ONGOING) {
            throw new \moodle_exception('errpublishunavailable', 'mod_agora');
        }

        $whipbase = trim((string) get_config('mod_agora', 'whipaddress'));
        if ($whipbase === '') {
            // The ingest node address is not configured: we make no call to
            // Agora — the page honestly explains what is missing.
            throw new \moodle_exception(
                'errpublishunavailable',
                'mod_agora',
                '',
                get_string('settingwhipaddress', 'mod_agora')
            );
        }

        $grant = grant_manager::for_publish($agora, (int) $USER->id, (int) $cm->id, getremoteaddr());
        $ttl = (int) get_config('mod_agora', 'publishticketttl') ?: 4 * HOURSECS;
        $whipurl = rtrim($whipbase, '/') . '/streaming/whip/' . rawurlencode((string) $agora->stream_name)
            . '?token=' . rawurlencode((string) $grant->token);

        return [
            'token' => (string) $grant->token,
            'expiresat' => (int) $grant->expires_at,
            'ttl' => $ttl,
            'whipurl' => $whipurl,
        ];
    }

    /**
     * Response: the ticket, its lifetime and the WHIP destination address.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'token' => new external_value(PARAM_RAW, 'Publish ticket (JWT)'),
            'expiresat' => new external_value(PARAM_INT, 'Ticket expiry, unix time'),
            'ttl' => new external_value(PARAM_INT, 'Ticket lifetime from the site setting, seconds'),
            'whipurl' => new external_value(PARAM_URL, 'WHIP endpoint with the ticket'),
        ]);
    }
}
