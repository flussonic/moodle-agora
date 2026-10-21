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
 * Counting the viewing duration from the Agora history.
 *
 * Runs under the `admin` account: `player` is not admitted to the history. The
 * unit of counting is the pair (`stream`, `user_id`): the history does not
 * return `jti`, there is nothing to glue a session to a ticket with.
 *
 * With filters the history does not paginate today, but the `next` cursor, if
 * it arrives, is read (`pages()`); `limit` is at most 1000. A window must not
 * be judged overfull by "the answer is exactly `limit` rows": `limit` cuts
 * rows *before* gluing, while the answer carries already glued sessions, and
 * there are always fewer of them. So the sign is half of the glued session
 * limit in the answer.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class watch_counter {
    /** The row limit of one history answer. */
    const LIMIT = 1000;

    /** How many glued sessions in an answer reads as "the window did not fit". */
    const SPLIT_AT = 500;

    /** The window splitting depth: splitting further is pointless. */
    const MAX_DEPTH = 12;

    /**
     * How many pages of one window to read by the `next` cursor.
     *
     * A window with 500 glued sessions gets split in half anyway, so ten pages
     * of `LIMIT` rows are plenty; more is no longer one learner's history but a
     * broken cursor.
     */
    const MAX_PAGES = 10;

    /**
     * The closing cause of a session abandoned by the viewer: no requests for longer than the TTL.
     *
     * Such a session closes not at the moment of the last request but a TTL
     * after it, and `closed_at − opened_at` includes the idle minute.
     */
    const IDLE_REASON = 'idle_timeout';

    /**
     * The Agora session TTL, seconds: how long the node waits for the next
     * request before closing the session with the `idle_timeout` cause.
     *
     * The Agora spec (`play-sessions`) names the TTL but not its value; the
     * value is taken from the node code (`SESSION_TTL_SECS = 60`, a check every
     * 5 seconds) and verified against the stand history. Once Agora starts
     * returning `last_seen_at` in the history, counting will follow it, and
     * this constant will stop participating.
     */
    const IDLE_TTL = 60;

    /**
     * The closed sessions of a pair over a window, splitting the window on a full answer.
     *
     * @param agora_client $client central client
     * @param string $stream stream name
     * @param string $userref the person identifier in Agora
     * @param int $from left bound, unix seconds
     * @param int $to right bound, unix seconds
     * @param int $depth current splitting depth
     * @return array session rows
     * @throws agora_exception when central refused
     */
    public static function sessions(
        agora_client $client,
        string $stream,
        string $userref,
        int $from,
        int $to,
        int $depth = 0
    ): array {
        $sessions = self::pages($client, [
            'stream' => $stream,
            'user_id' => $userref,
            'from' => $from,
            'to' => $to,
            'limit' => self::LIMIT,
        ]);

        if (count($sessions) < self::SPLIT_AT || $depth >= self::MAX_DEPTH || $to - $from < 2) {
            return $sessions;
        }

        // The window is overfull: split it in half and write this to the task
        // log — otherwise the missed sessions would look like "the learner did
        // not watch to the end".
        mtrace('mod_agora: history window ' . $from . '-' . $to . ' is full ('
            . count($sessions) . ' sessions), splitting in half (' . $stream . ')');
        $middle = intdiv($from + $to, 2);
        $left = self::sessions($client, $stream, $userref, $from, $middle, $depth + 1);
        $right = self::sessions($client, $stream, $userref, $middle + 1, $to, $depth + 1);
        return array_merge($left, $right);
    }

    /**
     * All answer pages of a history over one window — by the `next` cursor.
     *
     * Today central in the filter mode does not return `next` (the central
     * code, `play_sessions/api.rs`: `next: None`), but its spec
     * (`play-session-history`) promises cursor pagination. Silently reading
     * only the first page would one day undercount a learner — "did not watch"
     * where in fact "not read". So the cursor is read up to `MAX_PAGES` pages;
     * more pages or a cursor arriving a second time (the server did not
     * understand it and serves the same page) is an explicit refusal: the pair
     * is not counted in this pass, its polling bound does not move, and the
     * task log names the cause.
     *
     * @param agora_client $client central client
     * @param array $query the window filters
     * @return array session rows of all pages
     * @throws agora_exception when there are more pages than the limit or the cursor repeated
     */
    private static function pages(agora_client $client, array $query): array {
        $sessions = [];
        $seen = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $client->closed_sessions($query);
            $sessions = array_merge($sessions, $answer['sessions'] ?? []);
            $next = (string) ($answer['next'] ?? '');
            if ($next === '') {
                return $sessions;
            }
            if (isset($seen[$next])) {
                $error = new agora_exception('errhistorycursor', null, 'badresponse');
                $error->operation = 'opclosedsessions';
                $error->role = 'admin';
                throw $error;
            }
            $seen[$next] = true;
            $query['cursor'] = $next;
        }
        $error = new agora_exception('errhistorypages', self::MAX_PAGES, 'badresponse');
        $error->operation = 'opclosedsessions';
        $error->role = 'admin';
        throw $error;
    }

    /**
     * The sum of session durations in seconds and the right bound by `closed_at`.
     *
     * A session duration runs from opening to the viewer's last request, not to
     * closing: Agora closes an abandoned session a TTL after the last request
     * (`idle_timeout`), and that idle minute is not viewing. The viewing end is
     * `last_seen_at`, when the history returns it; otherwise, for a session
     * closed on idle — `closed_at − IDLE_TTL`, not earlier than the opening;
     * for one closed otherwise (revocation, broadcast stop, client exit) —
     * `closed_at`.
     *
     * A caveat: an explicit closing does not necessarily coincide with the last
     * request. If the viewer left and the session was closed within the TTL by
     * a ticket revocation (`api`, for example on an address change) or by a
     * broadcast stop (`stream_stopped`), the gap between leaving and closing —
     * up to a minute — is counted: without `last_seen_at` it cannot be told
     * from viewing. The end-to-end run of 29.09.2026 saw this as +14 s for a
     * learner whose ticket was revoked on an address change. The price is
     * stated in the rule help.
     *
     * @param array $sessions session rows
     * @return array ['seconds' => sum, 'lastclosed' => unix seconds or 0,
     *     'idletail' => how many idle seconds were subtracted]
     */
    public static function total(array $sessions): array {
        $seconds = 0;
        $lastclosed = 0;
        $idletail = 0;
        foreach ($sessions as $session) {
            // Downloading a file is not viewing: such a session has an empty
            // `proto`. The field being absent (answers of old builds) is
            // "unknown", and we do not cancel the count: we cut only an
            // explicitly empty string.
            if (array_key_exists('proto', (array) $session) && (string) $session['proto'] === '') {
                continue;
            }
            $opened = self::seconds($session['opened_at'] ?? null);
            $closed = self::seconds($session['closed_at'] ?? null);
            $idle = ($session['close_reason'] ?? null) === self::IDLE_REASON;
            if ($opened !== null && $closed !== null && $closed >= $opened) {
                $end = self::watched_until($session, $opened, $closed, $idle);
                $seconds += $end - $opened;
                $idletail += $closed - $end;
            } else if (isset($session['duration'])) {
                $duration = (int) $session['duration'];
                $counted = $idle ? max(0, $duration - self::IDLE_TTL) : $duration;
                $seconds += $counted;
                $idletail += $duration - $counted;
            }
            if ($closed !== null && $closed > $lastclosed) {
                $lastclosed = $closed;
            }
        }
        return ['seconds' => $seconds, 'lastclosed' => $lastclosed, 'idletail' => $idletail];
    }

    /**
     * The moment of the viewer's last request in a session, unix seconds.
     *
     * @param array $session session row
     * @param int $opened opening
     * @param int $closed closing
     * @param bool $idle closed on idle
     * @return int
     */
    private static function watched_until(array $session, int $opened, int $closed, bool $idle): int {
        $lastseen = self::seconds($session['last_seen_at'] ?? null);
        if ($lastseen !== null && $lastseen >= $opened && $lastseen <= $closed) {
            return $lastseen;
        }
        if ($idle) {
            return max($opened, $closed - self::IDLE_TTL);
        }
        return $closed;
    }

    /**
     * A history timestamp in unix seconds.
     *
     * @param mixed $value a value from the response
     * @return int|null
     */
    private static function seconds($value): ?int {
        $ms = stream_facts::to_ms($value);
        if ($ms === null) {
            return null;
        }
        // The live history returns timestamps as whole unix seconds (verified
        // against the stand on 29.09.2026): `to_ms()` returns the number as is,
        // there is nothing to divide. RFC 3339 strings of previous builds are
        // converted by `to_ms()` into milliseconds — those we divide by a
        // thousand. The 10^11 threshold tells one from the other: that many
        // seconds is the year 5138, that many milliseconds is 1973.
        return $ms > 100000000000 ? (int) floor($ms / 1000) : (int) $ms;
    }
}
