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
 * The publication port registry: choosing a free port from the settings range.
 *
 * A port is a cluster-wide resource, so occupancy is measured against two sets
 * at once: the plugin activity ports from its table and the cluster ports from
 * the `GET /srt-ports` summary. The choice runs under a lock over the whole
 * range: two parallel creations will not pick one port. A conflict seen only
 * on write (the port is occupied outside the summary — by the console, by
 * another site) marks the port occupied and takes the next one, up to three
 * retries; beyond that a refusal with its own cause.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class port_registry {
    /** How many stream creation attempts an allocation makes before refusing. */
    const MAX_ATTEMPTS = 3;

    /**
     * How many seconds to wait for the range lock held by a neighbouring creation.
     *
     * The lock embraces the central HTTP calls (up to three PUTs with a 20 s
     * timeout), so a neighbouring creation needs time to finish, not an
     * `errservicebusy` after half a minute (review R2, К-3). The calls cannot
     * be moved outside the lock without a port reservation table: the chosen
     * port would be invisible to the summary as occupied.
     */
    const LOCK_WAIT = 90;

    /**
     * Parses the stored "from:to" value; null — not set or wrong.
     *
     * The parser lives here, not at the setting (`setting_portrange`), because
     * the setting class extends `\admin_setting`: loading it outside the admin
     * pages pulls adminlib, which is absent there, and drops the activity
     * creation (caught by Behat: "Class admin_setting not found").
     *
     * @param string|null $value the stored value
     * @return array|null ['from' => int, 'to' => int]
     */
    public static function parse_range(?string $value): ?array {
        if ($value === null || !preg_match('/^(\d+):(\d+)$/', trim($value), $matches)) {
            return null;
        }
        return ['from' => (int) $matches[1], 'to' => (int) $matches[2]];
    }

    /**
     * The port range from the site settings.
     *
     * @return array ['from' => int, 'to' => int]
     * @throws agora_exception not set or invalid — the cause names the setting
     */
    public static function range(): array {
        $parsed = self::parse_range((string) get_config('mod_agora', 'srtportrange'));
        if ($parsed === null || $parsed['from'] >= $parsed['to']) {
            $error = new agora_exception('errsrtportrangenotset', null, 'notconfigured');
            $error->operation = 'opcreatestream';
            $error->role = 'admin';
            throw $error;
        }
        return $parsed;
    }

    /**
     * Chooses a free port and creates the stream on it.
     *
     * Retries happen only on a port conflict (422 with the `srt_publish.port`
     * path); any other refusal is raised at once. The lock is held for the
     * whole allocation: the summary and the write do not diverge from a
     * neighbouring process creating an activity in the same second.
     *
     * @param callable $put creates the stream on a port, takes the port; throws
     *     agora_exception — the port conflict is read from apifields
     * @return int the allocated port
     * @throws agora_exception range exhaustion or another refusal
     */
    public function allocate(callable $put): int {
        $range = self::range();
        $factory = \core\lock\lock_config::get_lock_factory('mod_agora_srtports');
        $lock = $factory->get_lock('range', self::LOCK_WAIT);
        if (!$lock) {
            throw new agora_exception('errservicebusy', null, 'busy');
        }
        try {
            $occupied = self::occupied();
            $foreign = null;
            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
                $port = $this->first_free($range, $occupied);
                if ($port === null) {
                    throw $this->exhausted($range, $foreign);
                }
                try {
                    $put($port);
                    return $port;
                } catch (agora_exception $error) {
                    if (!$this->is_port_conflict($error)) {
                        throw $error;
                    }
                    // The port is occupied outside the registry: mark it in
                    // this allocation and take the next one. Whose port it is —
                    // in the cause of the final refusal.
                    $occupied[$port] = true;
                    $foreign = $this->conflict_stream($error) ?? $foreign;
                }
            }
            throw $this->exhausted($range, $foreign);
        } finally {
            $lock->release();
        }
    }

    /**
     * Frees the activity port: called only after the confirmed removal of the
     * `srt_publish` section from the stream.
     *
     * @param \stdClass $agora the activity record
     * @return void
     */
    public static function release(\stdClass $agora): void {
        global $DB;

        $DB->set_field('agora', 'srt_port', 0, ['id' => $agora->id]);
        $DB->set_field('agora', 'srt_key', null, ['id' => $agora->id]);
    }

    /**
     * The first free port of the range; null — all are occupied.
     *
     * @param array $range ['from' => …, 'to' => …]
     * @param array $occupied port => true
     * @return int|null
     */
    private function first_free(array $range, array $occupied): ?int {
        for ($port = $range['from']; $port <= $range['to']; $port++) {
            if (!isset($occupied[$port])) {
                return $port;
            }
        }
        return null;
    }

    /**
     * Occupied ports: the plugin activity ports and the cluster ports from the summary.
     *
     * Public: the same occupancy is needed by the "Publication is set up"
     * check (review R2, S-5 — not two copies).
     *
     * @return array port => true
     * @throws agora_exception the summary could not be obtained
     */
    public static function occupied(): array {
        global $DB;

        $occupied = array_fill_keys(
            array_map('intval', $DB->get_fieldset_select('agora', 'srt_port', 'srt_port > 0')),
            true
        );
        $answer = connection::client()->get_srt_ports();
        foreach (($answer['ports'] ?? []) as $info) {
            $port = (int) ($info['port'] ?? 0);
            if ($port > 0) {
                $occupied[$port] = true;
            }
        }
        return $occupied;
    }

    /**
     * A 422 refusal over a port conflict: the path leads into srt_publish.port.
     *
     * @param agora_exception $error refusal
     * @return bool
     */
    private function is_port_conflict(agora_exception $error): bool {
        if ($error->reason !== 'validation') {
            return false;
        }
        foreach ($error->apifields as $field) {
            if ($field['path'] === 'srt_publish.port') {
                return true;
            }
        }
        return false;
    }

    /**
     * The stream that occupied a foreign port: from the summary/422 field message.
     *
     * @param agora_exception $error refusal
     * @return string|null stream name
     */
    private function conflict_stream(agora_exception $error): ?string {
        foreach ($error->apifields as $field) {
            if (preg_match('/taken by stream "([^"]+)"/', $field['message'], $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * The "ports exhausted" refusal; a conflict with a foreign port is
     * distinguishable from plain exhaustion — it names the occupying stream and
     * advises moving the range.
     *
     * @param array $range ['from' => …, 'to' => …]
     * @param string|null $foreign the stream that occupied a port outside the registry
     * @return agora_exception
     */
    private function exhausted(array $range, ?string $foreign): agora_exception {
        if ($foreign !== null) {
            return new agora_exception('errpublishportconflict', (object) [
                'port' => $range['from'] . '–' . $range['to'],
                'stream' => s($foreign),
            ], 'validation');
        }
        return new agora_exception('errsrtportsexhausted', (object) [
            'from' => $range['from'],
            'to' => $range['to'],
        ], 'validation');
    }
}
