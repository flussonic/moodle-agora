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
 * A ready recording waits for an export for over an hour: auto_export is silent.
 *
 * Once per recording: noticed by the scheduled task `refresh_states`, the
 * counter of such recordings lives in a setting and is read by the
 * "Publication is set up" check.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_export_missing extends \core\event\base {
    /**
     * The event description.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'agora';
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventexportmissing', 'mod_agora');
    }

    /**
     * The log entry description.
     *
     * @return string
     */
    public function get_description() {
        return "The recording '{$this->other['recording_id']}' of the stream '{$this->other['stream']}' has been ready "
            . "for more than an hour without a finished export";
    }

    /**
     * Checks the composition of the event.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['stream', 'recording_id'] as $field) {
            if (!array_key_exists($field, $this->other ?? [])) {
                throw new \coding_exception('Event recording_export_missing requires the other[' . $field . '] field.');
            }
        }
    }
}
