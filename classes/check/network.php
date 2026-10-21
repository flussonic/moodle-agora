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
 * Network to central: the address answers and the `admin` account sees broadcasts.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class network extends base {
    /**
     * The check name in the report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('checknetwork', 'mod_agora');
    }

    /**
     * The check result.
     *
     * @return result
     */
    public function get_result(): result {
        try {
            $page = connection::client()->list_streams();
        } catch (agora_exception $e) {
            // There are five causes of "it did not work" here, and each is fixed
            // differently. The summary must tell them apart: "the server does not
            // answer" on a rejected sign-in or on a 500 sends the operator to
            // edit an address that is fine.
            //
            // Empty settings are not a network refusal: nothing to judge by.
            if ($this->is_notconfigured($e)) {
                return new result(result::UNKNOWN, get_string('checkunknown', 'mod_agora'), $e->getMessage());
            }
            // Sign-in rejected (401): the server answered, fixed by the account password.
            if ($this->is_password($e)) {
                return $this->password_result($e, 'admin');
            }
            // Signed in, but the role does not allow the stream list (403): fixed
            // by the account role in Agora, and the details lead to the role check.
            if ($e->reason === 'role') {
                return new result(
                    result::ERROR,
                    get_string('checknetworkdenied', 'mod_agora', get_string('accountadmin', 'mod_agora')),
                    $e->getMessage()
                );
            }
            // A licence without accounts (404 on sign-in): the server answers,
            // and it is fixed by the Agora licence, not by the address or password.
            if ($this->is_license($e)) {
                return $this->license_result($e);
            }
            // Sign-in rate (429): fixed by waiting, there is no settings field here.
            if ($this->is_ratelimited($e)) {
                return $this->ratelimited_result($e);
            }
            // Code 2xx with garbage instead of an answer: a proxy or a foreign service at the address.
            if ($e->reason === 'badresponse') {
                return $this->badresponse_result($e);
            }
            // A refusal of Agora itself (5xx): neither the address nor the password is at fault.
            if ($this->is_serverfault($e)) {
                return $this->serverfault_result($e);
            }
            // Network and timeout — the only case fixed by the address.
            if ($e->reason === 'network') {
                return new result(result::ERROR, get_string('checknetworkerror', 'mod_agora'), $e->getMessage());
            }
            // Everything else: the refusal message names the cause, and the summary does not guess.
            return new result(result::ERROR, get_string('checknetworkfailed', 'mod_agora'), $e->getMessage());
        }
        $count = count($page['streams'] ?? []);
        return new result(
            result::OK,
            get_string('checknetworkok', 'mod_agora'),
            get_string('checknetworkdetails', 'mod_agora', $count)
        );
    }
}
