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

namespace mod_agora\check;

use core\check\result;
use mod_agora\local\agora_exception;
use mod_agora\local\connection;

/**
 * The `player` account issues tickets.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class playerrole extends base {
    /** Roles allowed to issue tickets. */
    const ALLOWED = ['player', 'admin', 'superadmin'];

    /**
     * The check name in the report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('checkplayerrole', 'mod_agora');
    }

    /**
     * The check result.
     *
     * @return result
     */
    public function get_result(): result {
        try {
            $who = connection::client()->whoami('player');
        } catch (agora_exception $e) {
            // The phrase "The role does not allow" is only a 403 answer; password, licence,
            // sign-in rate, an Agora failure and garbage instead of an answer
            // are named in their own words.
            return $this->account_refusal($e, 'player', 'checkplayerroleerror');
        }
        // An answer without a role (`{}`, a foreign service) is an answer not
        // understood, not "the role does not allow": the role is unknown here.
        if (!isset($who['role']) || !is_string($who['role']) || $who['role'] === '') {
            return new result(
                result::ERROR,
                get_string('checkbadresponse', 'mod_agora'),
                get_string('checknorole', 'mod_agora', s((string) json_encode($who)))
            );
        }
        $role = $who['role'];
        if (!in_array($role, self::ALLOWED, true)) {
            return new result(
                result::ERROR,
                get_string('checkplayerroleerror', 'mod_agora'),
                get_string('checkrolegot', 'mod_agora', $role === '' ? '-' : $role)
            );
        }
        return new result(result::OK, get_string('checkplayerroleok', 'mod_agora'), get_string('checkrolegot', 'mod_agora', $role));
    }
}
