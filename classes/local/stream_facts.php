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
 * Facts about a stream from a single `GET {central}/streams/stats/{name}` response.
 *
 * Only facts here: whether the broadcast is running, whether it was ever
 * observed, whether there is an archive and what its bounds are. The card
 * state is computed from these facts by `stream_state`; the cache lives there
 * too.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stream_facts {
    /** @var array the `streams/stats` response body */
    private array $stats;

    /**
     * Wraps the `streams/stats` response body.
     *
     * @param array $stats the `streams/stats` response body
     */
    public function __construct(array $stats) {
        $this->stats = $stats;
    }

    /**
     * The broadcast is running right now.
     *
     * @return bool
     */
    public function is_live(): bool {
        if (($this->stats['status'] ?? '') === 'running') {
            return true;
        }
        foreach (($this->stats['inputs'] ?? []) as $input) {
            if (!empty($input['current']) && ($input['status'] ?? '') === 'working') {
                return true;
            }
        }
        return false;
    }

    /**
     * The response body as is — so the facts survive the cache without losses.
     *
     * @return array
     */
    public function raw(): array {
        return $this->stats;
    }

    /**
     * The broadcast was observed: central saw it transition into the current status.
     *
     * Tells "the broadcast is over, no recording" from "the broadcast has not
     * started yet". Judging by the counters is impossible: for a stopped stream
     * `streams/stats` returns neither `inputs` nor the counters — the contract
     * omits zeroed fields. What remains is `status_since`: it is empty (or the
     * epoch) exactly for a row central assembled from the config, observed by
     * no one.
     *
     * @return bool
     */
    public function observed(): bool {
        $since = self::to_ms($this->stats['status_since'] ?? null);
        return $since !== null && $since > 0;
    }

    /**
     * The stream has a recorded archive.
     *
     * We judge only by `written_until`: the scheduled window `dvr.from`/`dvr.to`
     * exists even for a stream that never ran, and `dvr.to` there is the end of
     * the current hour, that is, the future. An empty archive player on such a
     * window is acceptance defect A2.
     *
     * @return bool
     */
    public function has_record(): bool {
        return $this->written_until_ms() !== null;
    }

    /**
     * The bound of the written archive in UTC milliseconds.
     *
     * @return int|null
     */
    public function written_until_ms(): ?int {
        return self::to_ms($this->stats['dvr']['written_until'] ?? null);
    }

    /**
     * The archive window end in UTC milliseconds — the bound of the written part.
     *
     * There is deliberately no `dvr.to` fallback here: it lies in the future
     * (the end of the current hour), and the player would get a window the
     * archive does not have.
     *
     * @return int|null
     */
    public function record_to_ms(): ?int {
        return $this->written_until_ms();
    }

    /**
     * An Agora timestamp in UTC milliseconds.
     *
     * The contract returns timestamps as RFC 3339 strings; numbers are accepted
     * too — they occur in the responses of old builds.
     *
     * @param mixed $value a value from the response
     * @return int|null
     */
    public static function to_ms($value): ?int {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value))) {
            return (int) $value;
        }
        if (is_string($value) && $value !== '') {
            $parsed = strtotime($value);
            if ($parsed !== false) {
                $fraction = 0;
                if (preg_match('/\.(\d{1,3})/', $value, $m)) {
                    $fraction = (int) str_pad($m[1], 3, '0');
                }
                return $parsed * 1000 + $fraction;
            }
        }
        return null;
    }
}
