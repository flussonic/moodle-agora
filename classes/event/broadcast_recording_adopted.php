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

namespace mod_agora\event;

/**
 * The recording was already running when the broadcast started: the plugin adopted the open recording of the stream.
 *
 * Logs the owner of the recording: the plugin renames only its own recording, a
 * foreign one is adopted as is. This is not a refusal and not
 * `broadcast_action_failed`.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class broadcast_recording_adopted extends \core\event\base {
    /**
     * The event description.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['objecttable'] = 'agora';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventbroadcastrecordingadopted', 'mod_agora');
    }

    /**
     * The log entry description: whose recording was already running.
     *
     * @return string
     */
    public function get_description() {
        $owner = (string) ($this->other['owner'] ?? '');
        $renamed = !empty($this->other['renamed']) ? ' and renamed it to the activity name' : '';
        return "The recording '{$this->other['recording_id']}' of the stream '{$this->other['stream']}' was already "
            . "running (owner '{$owner}') and was adopted as the recording of the activity{$renamed}";
    }

    /**
     * Checks the composition of the event.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['stream', 'recording_id', 'owner'] as $field) {
            if (!array_key_exists($field, $this->other ?? [])) {
                throw new \coding_exception('Event broadcast_recording_adopted requires the other[' . $field . '] field.');
            }
        }
    }
}
