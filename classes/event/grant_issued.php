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
 * A ticket is issued. The event carries `jti` and the stream name; the ticket JWT is a secret and does not end up here.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grant_issued extends \core\event\base {
    /**
     * The event description.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'agora_grants';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventgrantissued', 'mod_agora');
    }

    /**
     * The log entry description.
     *
     * @return string
     */
    public function get_description() {
        return "The plugin mod_agora issued the ticket jti='{$this->other['jti']}' for the stream '{$this->other['stream']}' " .
            "to the user with id '{$this->relateduserid}'.";
    }

    /**
     * Checks the composition of the event.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['jti']) || !isset($this->other['stream'])) {
            throw new \coding_exception('Event grant_issued requires other[jti] and other[stream].');
        }
    }
}
