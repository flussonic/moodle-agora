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
 * A ticket issued without address binding, although binding is on.
 *
 * The learner address as Moodle saw it did not parse as an IP or is unknown
 * (`0.0.0.0`): binding to it would not have worked from any address, and the
 * ticket was issued without it. This is a warning to the LMS administrator: it
 * is fixed by the Moodle settings `reverseproxy` and `getremoteaddrconf`, and
 * until the fix a forwarded player link plays from any address. The event
 * carries `jti` and the address as the request delivered it (trimmed, without
 * markup); the ticket JWT is a secret and does not end up here.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grant_unbound extends \core\event\base {
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
        return get_string('eventgrantunbound', 'mod_agora');
    }

    /**
     * The log entry description — in English, like the core events.
     *
     * @return string
     */
    public function get_description() {
        return "The ticket jti='{$this->other['jti']}' for the user with id '{$this->relateduserid}' was issued "
            . "without address binding although binding is on: the listener address '{$this->other['address']}' "
            . "is not an IP address or is unknown. Fix the Moodle settings reverseproxy and getremoteaddrconf.";
    }

    /**
     * Checks the composition of the event.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['jti', 'address'] as $field) {
            if (!array_key_exists($field, $this->other ?? [])) {
                throw new \coding_exception('Event grant_unbound requires the other[' . $field . '] field.');
            }
        }
    }
}
