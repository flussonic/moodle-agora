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

/**
 * What connection checks share: a link to the settings page and refusal parsing.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base extends \core\check\check {
    /**
     * The "fix it" link: the plugin settings page.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/admin/settings.php', ['section' => 'modsettingagora']),
            get_string('settingspagelink', 'mod_agora')
        );
    }

    /**
     * A refusal with nothing to judge by: no connectivity or connection not configured.
     *
     * @param agora_exception $error refusal
     * @return bool
     */
    protected function is_unknown(agora_exception $error): bool {
        return in_array($error->reason, ['network', 'notconfigured'], true);
    }

    /**
     * The connection is not filled in: even the network check has nothing to judge by.
     *
     * @param agora_exception $error refusal
     * @return bool
     */
    protected function is_notconfigured(agora_exception $error): bool {
        return $error->reason === 'notconfigured';
    }

    /**
     * Sign-in rejected: the server answered, but the account password is wrong.
     *
     * Every check that calls Agora under an account must distinguish this:
     * otherwise the network check calls a sign-in refusal "the server does not
     * answer", and the role check calls it "the role does not allow" — and both
     * name the wrong cause out of several.
     *
     * @param agora_exception $error refusal
     * @return bool
     */
    protected function is_password(agora_exception $error): bool {
        return $error->reason === 'password';
    }

    /**
     * Sign-in rate: central refused neither over the password nor over the network.
     *
     * @param agora_exception $error refusal
     * @return bool
     */
    protected function is_ratelimited(agora_exception $error): bool {
        return $error->reason === 'ratelimited';
    }

    /**
     * A failure on the central side: 5xx. Neither the address nor the password is at fault here.
     *
     * @param agora_exception $error refusal
     * @return bool
     */
    protected function is_serverfault(agora_exception $error): bool {
        return $error->httpstatus >= 500;
    }

    /**
     * The "too many sign-ins" result: fixed by waiting, not by a settings field.
     *
     * @param agora_exception $error refusal
     * @return result
     */
    protected function ratelimited_result(agora_exception $error): result {
        return new result(result::ERROR, get_string('checkratelimited', 'mod_agora'), $error->getMessage());
    }

    /**
     * The "Agora server error" result: the response code in the summary, the body in the details.
     *
     * @param agora_exception $error refusal
     * @return result
     */
    protected function serverfault_result(agora_exception $error): result {
        return new result(
            result::ERROR,
            get_string('checkserverfault', 'mod_agora', $error->httpstatus),
            $error->getMessage()
        );
    }

    /**
     * An Agora licence without accounts: central answered the sign-in with 404.
     *
     * @param agora_exception $error refusal
     * @return bool
     */
    protected function is_license(agora_exception $error): bool {
        return $error->reason === 'license';
    }

    /**
     * The "licence without accounts" result: fixed by the Agora licence, not by
     * a settings field — neither the address, nor the password, nor the role is
     * at fault here.
     *
     * @param agora_exception $error refusal
     * @return result
     */
    protected function license_result(agora_exception $error): result {
        return new result(result::ERROR, get_string('checklicense', 'mod_agora'), $error->getMessage());
    }

    /**
     * The "answer not understood" result: code 2xx, yet the body is not a JSON
     * object — most often a proxy page or the address of a foreign service.
     *
     * @param agora_exception $error refusal
     * @return result
     */
    protected function badresponse_result(agora_exception $error): result {
        return new result(result::ERROR, get_string('checkbadresponse', 'mod_agora'), $error->getMessage());
    }

    /**
     * Refusal parsing for a check that calls Agora under one account.
     *
     * There are many causes for "it did not work", and the summary must name the
     * one the refusal is fixed by: password, licence, sign-in rate, a failure of
     * Agora itself, garbage instead of an answer. "The role does not allow" is
     * only a 403 answer: in the other cases the role is unknown, and naming it
     * would send the administrator to fix what is fine. Everything else (404 on
     * the request, 409, an unexpected code) gets the generic summary, and the
     * refusal message carries the cause in the details.
     *
     * @param agora_exception $error refusal
     * @param string $account the account of the check: player or admin
     * @param string $roleerror key of the "the role does not allow …" string of this check
     * @return result
     */
    protected function account_refusal(agora_exception $error, string $account, string $roleerror): result {
        if ($this->is_unknown($error)) {
            // Nothing to judge by — but the summary says why: the server does
            // not answer or the connection is not filled in. They are fixed by
            // different fields.
            $summary = $error->reason === 'network' ? 'checkunknownnetwork' : 'checkunknown';
            return new result(result::UNKNOWN, get_string($summary, 'mod_agora'), $error->getMessage());
        }
        if ($this->is_password($error)) {
            return $this->password_result($error, $account);
        }
        if ($this->is_license($error)) {
            return $this->license_result($error);
        }
        if ($this->is_ratelimited($error)) {
            return $this->ratelimited_result($error);
        }
        if ($this->is_serverfault($error)) {
            return $this->serverfault_result($error);
        }
        if ($error->reason === 'badresponse') {
            return $this->badresponse_result($error);
        }
        if ($error->reason === 'role') {
            return new result(result::ERROR, get_string($roleerror, 'mod_agora'), $error->getMessage());
        }
        return new result(
            result::ERROR,
            $error->httpstatus > 0
                ? get_string('checkfailed', 'mod_agora', $error->httpstatus)
                : get_string('checkfailednoresponse', 'mod_agora'),
            $error->getMessage()
        );
    }

    /**
     * The "the server answers, sign-in rejected" result: the account in the
     * summary, the field it is fixed by in the details.
     *
     * @param agora_exception $error refusal
     * @param string $fallbackrole the account of the check, when the refusal did not name it
     * @return result
     */
    protected function password_result(agora_exception $error, string $fallbackrole): result {
        $role = in_array($error->role, ['player', 'admin'], true) ? $error->role : $fallbackrole;
        return new result(
            result::ERROR,
            get_string('checkpassworderror', 'mod_agora', get_string('account' . $role, 'mod_agora')),
            $error->getMessage()
        );
    }
}
