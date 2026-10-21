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
 * Broadcast publication: the key, the connection string, removing the `srt_publish` section.
 *
 * The key (the SRT passphrase) is the only lock of publication: 24 Latin
 * letters and digits, so the connection string needs no encoding. It is stored
 * in the DB encrypted (`\core\encryption`) — secret hygiene in exports and
 * backups; the central API returns the key back, so a key lost in the DB is
 * repaired by reading the stream config with the admin account, and rotation
 * is needed only on compromise.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class publish_manager {
    /** The publish key length: the requirement of a connection string without encoding. */
    const KEY_LENGTH = 24;

    /**
     * Generates a publish key.
     *
     * @return string 24 Latin letters and digits
     * @throws agora_exception generator failure: an empty key would leave the port open
     */
    public static function generate_key(): string {
        try {
            $key = random_string(self::KEY_LENGTH);
        } catch (\Throwable $error) {
            throw new agora_exception('errpublishkeygen', null, 'internal');
        }
        if (!preg_match('/^[A-Za-z0-9]{' . self::KEY_LENGTH . '}$/', $key)) {
            throw new agora_exception('errpublishkeygen', null, 'internal');
        }
        return $key;
    }

    /**
     * The activity key from the DB, decrypted; null — there is no key or it does not read.
     *
     * @param \stdClass $agora the activity record
     * @return string|null
     */
    public static function key_of(\stdClass $agora): ?string {
        $stored = (string) ($agora->srt_key ?? '');
        if ($stored === '') {
            return null;
        }
        try {
            $key = \core\encryption::decrypt($stored);
        } catch (\Throwable $error) {
            // The key does not read from the DB — that is no reason to drop
            // the page: the connection string is read from the stream config
            // (admin account), see D2.
            return null;
        }
        return $key !== '' ? $key : null;
    }

    /**
     * Saves the activity key encrypted.
     *
     * @param int $agoraid activity
     * @param string $key the plain key
     * @return void
     */
    public static function store_key(int $agoraid, string $key): void {
        global $DB;

        $DB->set_field('agora', 'srt_key', \core\encryption::encrypt($key), ['id' => $agoraid]);
    }

    /**
     * Key rotation: first a PATCH to Agora, on success — a DB write.
     *
     * A PATCH refusal changes nothing: the previous key and the previous
     * string stay, and the cause goes to the caller.
     *
     * @param \stdClass $agora the activity record (with srt_port)
     * @return string the new plain key
     * @throws agora_exception an Agora refusal or a generator failure
     */
    public static function rotate_key(\stdClass $agora): string {
        $key = self::generate_key();
        connection::client()->patch_srt_publish(
            (string) $agora->stream_name,
            (int) $agora->srt_port,
            $key
        );
        self::store_key((int) $agora->id, $key);
        return $key;
    }

    /**
     * Removes the `srt_publish` section from the stream and frees the port in the registry.
     *
     * Idempotent: a repeat with the section already removed is the same
     * success. The port is freed only here (a confirmed removal) or by the
     * tombstone deleting the stream — not earlier, otherwise the plugin
     * registry and the cluster would diverge (О-4).
     *
     * @param \stdClass $agora the activity record
     * @return void
     * @throws agora_exception the removal is not confirmed — the port stays occupied
     */
    public static function remove_section(\stdClass $agora): void {
        connection::client()->remove_srt_publish((string) $agora->stream_name);
        port_registry::release($agora);
    }

    /**
     * The publish address for the connection string and the setup check.
     *
     * From the central port summary; when central does not know it — the site
     * setting. Empty — there is no address at all, and the block honestly says
     * so. The summary may be passed by hand (the block already holds it) — then
     * central is not called again (review R2, S-4: one "summary → fallback"
     * logic).
     *
     * @param array|null $summary the `GET /srt-ports` body, when the summary is already at hand
     * @return string empty string — the address is not configured
     * @throws agora_exception the summary could not be obtained
     */
    public static function publish_address(?array $summary = null): string {
        if ($summary === null) {
            $summary = connection::client()->get_srt_ports();
        }
        $address = (string) ($summary['publish_address']['address'] ?? '');
        if ($address !== '') {
            return $address;
        }
        return trim((string) get_config('mod_agora', 'publishaddress'));
    }

    /**
     * The connection string for the encoder.
     *
     * @param string $address publish address
     * @param int $port port
     * @param string|null $key the key; null — the string gets a placeholder (the key is hidden)
     * @return string
     */
    public static function connection_string(string $address, int $port, ?string $key): string {
        $shown = $key ?? '•••••••••••••••••••••••';
        return 'srt://' . $address . ':' . $port . '?passphrase=' . $shown;
    }
}
