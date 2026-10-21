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
 * The broadcast is over: all completion actions are confirmed, the activity is terminal.
 *
 * For the scheduled task userid = 0. A recording closed by the system
 * (`stop_reason`) ends the activity with the same event with `by = system` and
 * the closing cause.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class broadcast_ended extends \core\event\base {
    /**
     * The event description.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['objecttable'] = 'agora';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventbroadcastended', 'mod_agora');
    }

    /**
     * The log entry description: how it ended and the cause of a system closing.
     *
     * @return string
     */
    public function get_description() {
        $by = (string) ($this->other['by'] ?? '');
        $desc = "The broadcast of the activity (stream '{$this->other['stream']}') was ended by '{$by}'";
        if ($by === 'system') {
            // The recording closed by itself (max_duration, no_data_close): the
            // cause comes from the recording card; without it the event would not
            // explain who closed it.
            $desc .= ", the recording was closed by the system: " . (string) ($this->other['stop_reason'] ?? '');
        }
        if ((int) $this->userid === 0) {
            $desc .= ' (scheduled task)';
        }
        return $desc;
    }

    /**
     * Checks the composition of the event.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['stream', 'by'] as $field) {
            if (!array_key_exists($field, $this->other ?? [])) {
                throw new \coding_exception('Event broadcast_ended requires the other[' . $field . '] field.');
            }
        }
    }
}
