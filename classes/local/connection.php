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

namespace mod_agora\local;

/**
 * The site connection to Agora: the single place where the client comes from.
 *
 * The test seam lives here too: a substitute client is set with `set_client()`.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection {
    /** @var agora_client|null The client for the request duration, or a test substitute. */
    private static ?agora_client $client = null;

    /**
     * Sets the client — for tests and for reuse within one request.
     *
     * @param agora_client|null $client a client or null to restore the regular one
     * @return void
     */
    public static function set_client(?agora_client $client): void {
        self::$client = $client;
    }

    /**
     * The central client from the site settings.
     *
     * @return agora_client
     */
    public static function client(): agora_client {
        if (self::$client === null) {
            self::$client = agora_client::instance();
        }
        return self::$client;
    }

    /**
     * The site label from the settings.
     *
     * @return string empty string when the label is not set
     */
    public static function label(): string {
        return trim((string) get_config('mod_agora', 'sitelabel'));
    }

    /**
     * The binding tag `moodle.<label>`.
     *
     * @return string
     */
    public static function tag(): string {
        return 'moodle.' . self::label();
    }

    /**
     * Checks that the label is set; otherwise names the settings field.
     *
     * The refusal carries the operation it stopped and the account it would
     * have run under: an event log entry without them would read as
     * "unknown operation", and the administrator would not learn what exactly
     * did not happen.
     *
     * @param string $operation key of the operation string that needs the label
     * @param string $role the account of the operation or `service`
     * @return void
     * @throws agora_exception
     */
    public static function require_label(string $operation, string $role): void {
        if (self::label() === '') {
            $error = new agora_exception('errnositelabel', null, 'notconfigured');
            $error->operation = $operation;
            $error->role = $role;
            throw $error;
        }
    }
}
