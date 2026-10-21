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
 * A publish ticket (WHIP) issued to the presenter.
 *
 * The ticket value does not end up in the event — only the fact: to whom, when
 * and for which broadcast, plus `jti` for analysing revocations.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class publish_ticket_issued extends \core\event\base {
    /**
     * The event description.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'agora_grants';
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventpublishissued', 'mod_agora');
    }

    /**
     * The log entry description.
     *
     * @return string
     */
    public function get_description() {
        return "User {$this->userid} was issued a publish ticket (stream '{$this->other['stream']}', "
            . "jti '{$this->other['jti']}')";
    }

    /**
     * Checks the composition of the event.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['stream', 'jti'] as $field) {
            if (!array_key_exists($field, $this->other ?? [])) {
                throw new \coding_exception('Event publish_ticket_issued requires the other[' . $field . '] field.');
            }
        }
    }
}
