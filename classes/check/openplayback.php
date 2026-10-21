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
 * Open playback is off: otherwise the nodes serve media without a ticket.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openplayback extends base {
    /**
     * The check name in the report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('checkopenplayback', 'mod_agora');
    }

    /**
     * The check result.
     *
     * @return result
     */
    public function get_result(): result {
        try {
            $policy = connection::client()->auth_policy();
        } catch (agora_exception $e) {
            // Any refusal, including a rejected sign-in, leaves the check without
            // data: only Agora knows the admission policy. The details name the
            // cause of the refusal, and the network and role checks get their
            // own summary.
            return new result(result::UNKNOWN, get_string('checkunknown', 'mod_agora'), $e->getMessage());
        }
        if (!empty($policy['open_playback'])) {
            return new result(
                result::WARNING,
                get_string('checkopenplaybackwarning', 'mod_agora'),
                get_string('openplaybackwarning', 'mod_agora')
            );
        }
        return new result(result::OK, get_string('checkopenplaybackok', 'mod_agora'), '');
    }
}
