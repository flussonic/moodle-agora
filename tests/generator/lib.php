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
 * The generator of «Agora broadcast» activities for tests.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The generator of «Agora broadcast» activities.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_agora_generator extends testing_module_generator {
    /**
     * Creates a course module.
     *
     * @param array|stdClass|null $record the activity fields
     * @param array|null $options the generator settings
     * @return stdClass the activity record
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;

        $defaults = [
            'stream_name' => 'optics-2026',
            'display_mode' => 'auto',
            'completion_minutes' => 0,
            'created_by_plugin' => 0,
            'timestart' => 0,
            // MVP activities have no phases: the existing tests describe the legacy.
            // Phase behaviour is set explicitly with 'has_phases' => 1.
            'has_phases' => 0,
            // The value is set explicitly so that the generator does not fetch the
            // broadcast config from Agora: null means «the template decides».
            'ondemand' => null,
        ];
        foreach ($defaults as $field => $value) {
            if (!property_exists($record, $field)) {
                $record->{$field} = $value;
            }
        }

        // The form enables the duration condition with a checkbox; the generator
        // prefers minutes directly, so the checkbox is derived from them.
        if (!property_exists($record, 'completionminutesenabled')) {
            $record->completionminutesenabled = (int) $record->completion_minutes > 0 ? 1 : 0;
        }

        // The publish key from a Behat table comes as plain text, while in the
        // database it is stored encrypted (`\core\encryption`) — as after a real
        // activity creation.
        if (!empty($record->srt_key)) {
            try {
                \core\encryption::decrypt((string) $record->srt_key);
            } catch (\Throwable $error) {
                $record->srt_key = \core\encryption::encrypt((string) $record->srt_key);
            }
        }

        return parent::create_instance($record, (array) $options);
    }

    /**
     * Puts a ticket row straight into the table — a state that issuing cannot produce.
     *
     * The only place with a direct INSERT into `agora_grants`: an expired or a
     * revoked ticket cannot be created through the user door, and such a row
     * must not spread across tests — the fields must match the production ones.
     *
     * @param stdClass $agora the activity record
     * @param int $userid the learner; 0 — the preview service ticket
     * @param array $overrides the fields to override
     * @return stdClass the stored row
     */
    public function create_grant(stdClass $agora, int $userid, array $overrides = []): stdClass {
        global $DB;

        $now = time();
        $record = (object) ($overrides + [
            'agoraid' => (int) $agora->id,
            'userid' => $userid,
            'purpose' => \mod_agora\local\grant_manager::PURPOSE_VIEW,
            'stream_name' => (string) $agora->stream_name,
            'jti' => 'jti-' . $DB->count_records('agora_grants'),
            'token' => 'jwt-token',
            'user_id' => 'hr-lms:' . $userid . ':user' . $userid,
            // Bind address: empty — a ticket without binding. A test that needs a
            // live ticket with binding enabled sets the address explicitly — the
            // same one it later opens the activity from.
            'bind_ip' => null,
            'expires_at' => $now + HOURSECS,
            'status' => 'issued',
            'timecreated' => $now,
        ]);
        $record->id = $DB->insert_record('agora_grants', $record);
        // The same pair record that a real ticket issue (`grant_manager::issue()`)
        // creates: the task uses it to decide whether to poll the pair.
        if ($record->purpose === \mod_agora\local\grant_manager::PURPOSE_VIEW && $userid > 0) {
            \mod_agora\local\watch::note_grant(
                (int) $agora->id,
                $userid,
                (string) $record->user_id,
                (int) $record->expires_at
            );
        }
        return $record;
    }
}
