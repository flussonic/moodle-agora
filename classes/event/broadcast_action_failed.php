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
 * A broadcast action against Agora failed: the cause is named, the completion will retry.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class broadcast_action_failed extends \core\event\base {
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
        return get_string('eventbroadcastactionfailed', 'mod_agora');
    }

    /**
     * The log entry description: the action and the cause from the response body.
     *
     * @return string
     */
    public function get_description() {
        return "Broadcast action '{$this->other['action']}' failed for the activity "
            . "(stream '{$this->other['stream']}'): {$this->other['reason']}";
    }

    /**
     * Checks the composition of the event.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['action', 'reason', 'stream'] as $field) {
            if (!array_key_exists($field, $this->other ?? [])) {
                throw new \coding_exception('Event broadcast_action_failed requires the other[' . $field . '] field.');
            }
        }
    }
}
