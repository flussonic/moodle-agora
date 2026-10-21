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
 * The stream state: a pure function of the facts of one `streams/stats` answer.
 *
 * Six values. Five are computed from facts; the sixth — `unknown` — is set
 * when the answer could not be obtained: without it a connectivity failure
 * would silently look like "the broadcast has not started yet".
 *
 * The cache is shared by all users, the key is the stream name. The expiry is
 * soft and truly soft: a stale value is served at once, and the refresh is
 * scheduled as a background adhoc task. A synchronous request is made only
 * when there is no value at all, and with a short `connect_timeout`:
 * otherwise the course page with Agora down would wait out a timeout per
 * stream.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stream_state {
    /** The stream is created, the source has never appeared. */
    const NOT_STARTED = 'not_started';

    /** The source is alive, the broadcast is running. */
    const LIVE = 'live';

    /** No source, the archive exists and is fresher than the threshold — an interruption. */
    const SIGNAL_LOST = 'signal_lost';

    /** No source, the archive exists and is older than the threshold — the broadcast is over. */
    const ENDED_RECORDED = 'ended_recorded';

    /** No source, no archive, but there was a source — it was not recorded. */
    const ENDED_NO_RECORD = 'ended_no_record';

    /** The answer about the state could not be obtained. */
    const UNKNOWN = 'unknown';

    /** The interruption threshold: how many seconds from `written_until` counts as "just now". */
    const SIGNAL_LOST_THRESHOLD = 180;

    /** How many seconds a value counts as fresh. */
    const FRESH = 60;

    /**
     * How many seconds a value is good enough for the side column of the activity page.
     *
     * Three missed passes of the scheduled task `refresh_states` (it runs once
     * a minute). The side column treats an older value as absent: neighbours
     * never call central, and a row without a badge beats a yesterday's badge.
     */
    const SIDEBAR_MAXAGE = 180;

    /** How many seconds `unknown` is kept: a connectivity failure is not cached for long. */
    const UNKNOWN_FRESH = 15;

    /**
     * How many seconds after creation an activity with phases counts as fresh.
     *
     * Central does not materialise a new stream in `streams/stats` instantly
     * (confirmed on the stand: ~4 seconds of 404 after creation). For a fresh
     * activity a 404 from stats reads as "the broadcast has not started yet",
     * not as "unknown AG-404": the regular polling will pull the state itself
     * once stats appear. For older activities a 404 stays "unknown" — a real
     * "stream deleted" must not be masked.
     */
    const PHASE_ELEMENT_FRESH = 120;

    /** How many seconds to wait for a connection on a synchronous state request. */
    const CONNECT_TIMEOUT = 3;

    /**
     * How many seconds a value is good enough for the activity page.
     *
     * The activity page, unlike the course page, asks central for the state of
     * its own stream itself when the value is older than this threshold: a
     * learner arriving at the start of a lecture must not spend a minute
     * looking at "the broadcast has not started yet". Fifteen seconds is the
     * same period as `unknown`; for central this is no more than four requests
     * a minute per stream, however many learners open the page (see
     * `for_page()`).
     */
    const PAGE_FRESH = 15;

    /** The central response limit on a synchronous request from the activity page, seconds. */
    const PAGE_FETCH_TIMEOUT = 5;

    /**
     * How many seconds the page waits for a neighbouring request for the same
     * stream to bring the state; beyond that it serves the previous value.
     */
    const PAGE_LOCK_WAIT = 2;

    /** @var string The state. */
    public string $state;

    /** @var stream_facts|null The facts of the answer; null — the state is `unknown`. */
    public ?stream_facts $facts;

    /** @var string|null The short cause code for `unknown`. */
    public ?string $reason;

    /** @var int When the facts were obtained, unix seconds. */
    public int $fetchedat;

    /**
     * @var agora_exception|null The refusal the state is `unknown` because of.
     *
     * Not stored in the cache: the activity page parses the refusal in detail,
     * while the course card is fine with the cause code, which is in the cache.
     */
    public ?agora_exception $error = null;

    /**
     * @var array|null The parsed refusal: cause, code, `error.code`, `error.message`.
     *
     * Stored in the cache, unlike the refusal itself: the activity page must
     * name the cause to someone who opened it half a minute after the failure
     * too. There is no response body here — it goes to the event log once, at
     * the request.
     */
    public ?array $failure = null;

    /**
     * Builds the state from the facts, the cause and the moment of obtaining.
     *
     * @param string $state state
     * @param stream_facts|null $facts the facts of the answer
     * @param string|null $reason the short cause code for `unknown`
     * @param int $fetchedat when obtained
     */
    public function __construct(string $state, ?stream_facts $facts, ?string $reason, int $fetchedat) {
        $this->state = $state;
        $this->facts = $facts;
        $this->reason = $reason;
        $this->fetchedat = $fetchedat;
    }

    /**
     * The state from the facts of one `streams/stats` answer.
     *
     * @param stream_facts $facts the facts of the answer
     * @param int|null $now the reference moment, unix seconds
     * @param bool $seenlive the plugin itself has seen this stream live
     * @return string one of the five values; `unknown` is never returned from here
     */
    public static function from_facts(stream_facts $facts, ?int $now = null, bool $seenlive = false): string {
        $now = $now ?? time();

        if ($facts->is_live()) {
            return self::LIVE;
        }

        $writtenuntil = $facts->written_until_ms();
        if ($writtenuntil !== null) {
            $age = $now - (int) floor($writtenuntil / 1000);
            return $age <= self::SIGNAL_LOST_THRESHOLD ? self::SIGNAL_LOST : self::ENDED_RECORDED;
        }

        // No archive. "Ended without a recording" is told from "has not started
        // yet" by the observation fact: `status_since` of a stream nobody has
        // seen is empty, and a stopped stream has no counters in the answer at
        // all. The plugin's own observation (`agora.last_live_at`) counts on
        // equal terms with it.
        //
        // The `recording` flag is irrelevant here: it says recording is on, not
        // that there is an archive. A stream that ran with recording on and
        // whose `written_until` central did not return is "ended without a
        // recording", not "has not started yet": the latter would mix it up
        // with a stream that never was.
        $observed = $facts->observed() || $seenlive;
        return $observed ? self::ENDED_NO_RECORD : self::NOT_STARTED;
    }

    /**
     * The stream state: from the cache or with one request to central.
     *
     * A stale value is served at once, and the refresh is scheduled as an adhoc
     * task: a synchronous request is made only when there is no value at all.
     *
     * @param string $name stream name
     * @return self
     */
    public static function for_stream(string $name): self {
        $cache = \cache::make('mod_agora', 'streamstate');
        $now = time();

        $entry = $cache->get($name);
        if (is_array($entry) && isset($entry['fetched_at'])) {
            $fresh = ($entry['state'] ?? '') === self::UNKNOWN ? self::UNKNOWN_FRESH : self::FRESH;
            if ($now - (int) $entry['fetched_at'] >= $fresh) {
                self::queue_refresh($name);
            }
            return self::from_entry($entry);
        }

        $fetched = self::fetch($name, $now);
        $cache->set($name, self::to_entry($fetched));
        return $fetched;
    }

    /**
     * The stream state for the activity page: no older than `PAGE_FRESH` seconds.
     *
     * A value younger than the threshold is served from the cache. Older — the
     * page asks central itself, but under a lock on the stream: while one
     * process asks, the rest wait for its answer up to `PAGE_LOCK_WAIT` seconds
     * and take the result from the cache. The attempt is remembered separately
     * from the value (the `stateattempt` cache), and a central refusal counts
     * as an attempt too: otherwise, with central down, everyone opening the
     * page would knock at it anew, and the previous value, which a refusal does
     * not harm, would stay old forever. The bottom line — at most one request
     * to central per stream per `PAGE_FRESH` seconds, however many learners
     * open the page and however many open pages ask for the state.
     *
     * @param string $name stream name
     * @return self
     */
    public static function for_page(string $name): self {
        $cache = \cache::make('mod_agora', 'streamstate');
        $entry = $cache->get($name);
        if (self::page_can_use($name, $entry, time())) {
            return self::from_entry($entry);
        }

        $factory = \core\lock\lock_config::get_lock_factory('mod_agora_streamstate');
        $lock = $factory->get_lock(sha1($name), self::PAGE_LOCK_WAIT);
        if (!$lock) {
            // A neighbouring request for this stream is taking longer than the
            // page is ready to wait: serve what there is. Without a value — an
            // honest "unknown".
            $entry = $cache->get($name);
            if (is_array($entry) && isset($entry['fetched_at'])) {
                return self::from_entry($entry);
            }
            return new self(self::UNKNOWN, null, 'AG-BUSY', time());
        }

        try {
            // While we waited for the lock, a neighbouring process may have
            // already brought everything.
            $now = time();
            $entry = $cache->get($name);
            if (self::page_can_use($name, $entry, $now)) {
                return self::from_entry($entry);
            }
            \cache::make('mod_agora', 'stateattempt')->set($name, $now);
            return self::store($name, self::fetch($name, $now, self::PAGE_FETCH_TIMEOUT));
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the cache value is good enough for the activity page without a central request.
     *
     * It is, if it is younger than `PAGE_FRESH` or if this stream was asked for
     * less than `PAGE_FRESH` seconds ago (and so the previous value is the best
     * there is).
     *
     * @param string $name stream name
     * @param mixed $entry the state cache value
     * @param int $now the moment of checking
     * @return bool
     */
    private static function page_can_use(string $name, $entry, int $now): bool {
        if (!is_array($entry) || !isset($entry['fetched_at'])) {
            return false;
        }
        if ($now - (int) $entry['fetched_at'] < self::PAGE_FRESH) {
            return true;
        }
        $attempt = \cache::make('mod_agora', 'stateattempt')->get($name);
        return $attempt !== false && $now - (int) $attempt < self::PAGE_FRESH;
    }

    /**
     * Orders a background state refresh, at most once per stream per
     * deduplication window.
     *
     * The window width is set by the `ttl` of the `staterefresh` cache in
     * `db/caches.php`: the mark lives exactly as long as written there, and the
     * code has no constant of its own.
     *
     * @param string $name stream name
     * @return void
     */
    private static function queue_refresh(string $name): void {
        $dedup = \cache::make('mod_agora', 'staterefresh');
        if ($dedup->get($name)) {
            return;
        }
        $dedup->set($name, time());
        $task = new \mod_agora\task\refresh_state();
        $task->set_custom_data(['stream' => $name]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Refreshes the cache value with a request to central — from the adhoc task.
     *
     * @param string $name stream name
     * @return self what ended up in the cache after the refresh
     */
    public static function refresh(string $name): self {
        return self::store($name, self::fetch($name, time()));
    }

    /**
     * Puts the state into the cache — the shared write path of `refresh()` and
     * the scheduled task `refresh_states`.
     *
     * A refusal does not harm the previous value: `unknown` is set only when
     * there is no value at all.
     *
     * @param string $name stream name
     * @param self $state what was obtained
     * @return self what ended up in the cache after the write
     */
    public static function store(string $name, self $state): self {
        $cache = \cache::make('mod_agora', 'streamstate');

        if ($state->state === self::UNKNOWN) {
            $entry = $cache->get($name);
            if (is_array($entry) && ($entry['state'] ?? self::UNKNOWN) !== self::UNKNOWN) {
                return self::from_entry($entry);
            }
        }

        $cache->set($name, self::to_entry($state));
        return $state;
    }

    /**
     * States from the cache in one read, without a single request to central.
     *
     * This is how the activity page learns the neighbours' states: a value
     * older than `$maxage` counts as absent — the cache freshness is kept by
     * the scheduled task `refresh_states`, not by opening the page.
     *
     * @param array $names stream names
     * @param int $maxage the limiting age of a value, seconds
     * @return array stream name => self; names without a fresh value are not in the answer
     */
    public static function cached(array $names, int $maxage = self::SIDEBAR_MAXAGE): array {
        $names = array_values(array_unique(array_filter($names, static function ($name): bool {
            return (string) $name !== '';
        })));
        if (!$names) {
            return [];
        }

        $cache = \cache::make('mod_agora', 'streamstate');
        $now = time();
        $found = [];
        foreach ($cache->get_many($names) as $name => $entry) {
            if (!is_array($entry) || !isset($entry['fetched_at'])) {
                continue;
            }
            if ($now - (int) $entry['fetched_at'] > $maxage) {
                continue;
            }
            $found[(string) $name] = self::from_entry($entry);
        }
        return $found;
    }

    /**
     * Asks central for the state; a refusal turns into `unknown`.
     *
     * @param string $name stream name
     * @param int $now the moment of the request
     * @param int|null $timeout the response limit, seconds; null — the general client limit
     * @return self
     */
    private static function fetch(string $name, int $now, ?int $timeout = null): self {
        try {
            $facts = new stream_facts(connection::client()->stream_stats($name, self::CONNECT_TIMEOUT, $timeout));
            $state = self::from_facts($facts, $now, self::seen_live($name));
            if ($state === self::LIVE) {
                self::mark_live($name, $now);
            }
            return new self($state, $facts, null, $now);
        } catch (agora_exception $error) {
            // A 404 for a freshly created activity with phases — central has
            // not yet materialised the stream in stats, not "the stream is
            // deleted": an honest "not started yet", the regular polling will
            // pull the real state itself.
            if ($error->reason === 'notfound' && self::fresh_phase_element($name, $now)) {
                return new self(self::NOT_STARTED, null, null, $now);
            }
            $state = new self(self::UNKNOWN, null, $error->short_code(), $now);
            $state->error = $error;
            $state->failure = [
                'reason' => $error->reason,
                'status' => $error->httpstatus,
                'code' => $error->apicode,
                'message' => $error->apimessage ?? $error->getMessage(),
            ];
            return $state;
        } catch (\Throwable $error) {
            // The settings are not filled in, there is no cache, the client
            // could not be built — all this is "the state could not be
            // obtained", not "the broadcast has not started".
            return new self(self::UNKNOWN, null, 'AG-CFG', $now);
        }
    }

    /**
     * A recently created activity with phases, by stream name.
     *
     * Stream names are unique across the cluster, but in courses one stream may
     * sit in several activities — we take the freshest. Legacy (`has_phases = 0`)
     * never counts as fresh: its 404 is a real "stream deleted".
     *
     * @param string $name stream name
     * @param int $now the moment of checking
     * @return bool
     */
    public static function fresh_phase_element(string $name, int $now): bool {
        global $DB;

        $row = $DB->get_record_sql(
            'SELECT has_phases, timecreated FROM {agora} WHERE stream_name = ? ORDER BY id DESC',
            [$name],
            IGNORE_MULTIPLE
        );
        return $row !== false
            && (int) $row->has_phases === 1
            && $now - (int) $row->timecreated <= self::PHASE_ELEMENT_FRESH;
    }

    /**
     * The plugin has already seen this stream live.
     *
     * @param string $name stream name
     * @return bool
     */
    private static function seen_live(string $name): bool {
        global $DB;

        return $DB->record_exists_select('agora', 'stream_name = ? AND last_live_at > 0', [$name]);
    }

    /**
     * Remembers that the stream was observed live.
     *
     * @param string $name stream name
     * @param int $now the moment of observation
     * @return void
     */
    private static function mark_live(string $name, int $now): void {
        global $DB;

        $DB->execute('UPDATE {agora} SET last_live_at = ? WHERE stream_name = ?', [$now, $name]);
    }

    /**
     * The cache value from a state.
     *
     * @param self $state state
     * @return array
     */
    private static function to_entry(self $state): array {
        return [
            'state' => $state->state,
            'stats' => $state->facts !== null ? $state->facts->raw() : null,
            'reason' => $state->reason,
            'failure' => $state->failure,
            'fetched_at' => $state->fetchedat,
        ];
    }

    /**
     * The state from a cache value.
     *
     * @param array $entry cache value
     * @return self
     */
    private static function from_entry(array $entry): self {
        $stats = $entry['stats'] ?? null;
        $state = new self(
            (string) ($entry['state'] ?? self::UNKNOWN),
            is_array($stats) ? new stream_facts($stats) : null,
            $entry['reason'] ?? null,
            (int) $entry['fetched_at']
        );
        $state->failure = is_array($entry['failure'] ?? null) ? $entry['failure'] : null;
        return $state;
    }

    /**
     * The key of the string with the placeholder in words for this state.
     *
     * @return string
     */
    public function placeholder_key(): string {
        return 'state' . str_replace('_', '', $this->state);
    }

    /**
     * The state name for the badge.
     *
     * @return string
     */
    public function badge_text(): string {
        if ($this->state === self::UNKNOWN) {
            return get_string('badgeunknown', 'mod_agora', $this->reason ?? '');
        }
        return get_string('badge' . str_replace('_', '', $this->state), 'mod_agora');
    }

    /**
     * The refusal text according to the viewer's rights.
     *
     * The learner gets a phrase and a short code, the course editor — the HTTP
     * code, `error.code` and `error.message`; there is no response body in
     * either.
     *
     * @param \context $context activity context
     * @return string
     */
    public function failure_message(\context $context): string {
        $failure = $this->failure ?? [];
        if (has_capability('moodle/course:manageactivities', $context)) {
            return failure::details(
                (int) ($failure['status'] ?? 0),
                $failure['code'] ?? null,
                $failure['message'] ?? null
            );
        }
        return get_string('errforviewer', 'mod_agora', $this->reason ?? 'AG-NET');
    }

    /**
     * The refusal cause as a word, when the state `unknown` is caused by a refusal.
     *
     * @return string|null
     */
    public function failure_reason(): ?string {
        return $this->failure['reason'] ?? null;
    }

    /**
     * The stream has a recording that can be watched.
     *
     * @return bool
     */
    public function has_record(): bool {
        return $this->facts !== null && $this->facts->has_record();
    }
}
