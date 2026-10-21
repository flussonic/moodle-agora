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

namespace mod_agora\task;

use mod_agora\local\agora_exception;
use mod_agora\local\connection;
use mod_agora\local\failure;
use mod_agora\local\stream_facts;
use mod_agora\local\stream_state;

/**
 * Scheduled warming of the state cache: all activity streams in one pass.
 *
 * The side column of the activity page never goes to central — it reads the
 * neighbour states only from the cache. The freshness of this cache is kept
 * by this task, and its lag is visible from the outside: the time of the last
 * pass and the text of the last error lie in the plugin settings, and the
 * "Stream states refresh" check in the "System status" report judges by them.
 *
 * The cheapest way is paged lists: two sweeps for the whole fleet instead of
 * two requests per stream. The config list is needed on equal terms with the
 * stats list: a stream missing from the configs is deleted from Agora, and a
 * stream without a stats row has simply never been observed.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_states extends \core\task\scheduled_task {
    /** The page size of the central lists. */
    const PAGE_LIMIT = 100;

    /**
     * How many streams in Agora still make sense to sweep with lists.
     *
     * Past this boundary the paged sweep of the fleet is more expensive than
     * by-name requests for the plugin streams: 20 pages of two lists is 40
     * requests, and courses usually hold fewer activities. Past the boundary
     * the task drops the sweep and asks the state of each of its streams by
     * name.
     */
    const MAX_LISTED = 2000;

    /** The page sweep safety catch: the central cursor must not loop the pass. */
    const MAX_PAGES = 50;

    /** How many seconds a ready recording waits for the export before it is noticed. */
    const EXPORT_STALE = HOURSECS;

    /** @var array The recording list cache for one pass: stream name => recordings. */
    private array $recordinglists = [];

    /**
     * The task name in "Scheduled tasks".
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskrefreshstates', 'mod_agora');
    }

    /**
     * The task pass.
     *
     * @return void
     */
    public function execute() {
        $names = $this->stream_names();
        if (!$names) {
            // No streams — and nothing to refresh: the pass counts as
            // successful, otherwise the check would go red on a site without a
            // single activity.
            $this->record(time(), 0, 0, 0, '');
            mtrace('mod_agora: no activity streams, nothing to refresh');
            return;
        }

        $now = time();
        try {
            $facts = $this->collect($names);
        } catch (agora_exception $error) {
            // A central refusal does not perform the pass: the previous cache
            // values keep living until their freshness window expires, and the
            // cause lies in the settings — both the check and the activity
            // page will show it.
            $this->record(null, 0, 0, count($names), $this->reason($error));
            mtrace('mod_agora: stream states not refreshed: ' . $error->httpstatus . ' ' . $error->getMessage());
            return;
        }

        $seenlive = $this->seen_live_names();
        $refreshed = 0;
        $missing = 0;
        $failed = 0;
        $live = [];
        $bystream = [];
        foreach ($names as $name) {
            if (!array_key_exists($name, $facts)) {
                $failed++;
                continue;
            }
            $state = $this->state_of($name, $facts[$name], $now, isset($seenlive[$name]));
            $bystream[$name] = $state;
            stream_state::store($name, $state);
            if ($state->state === stream_state::LIVE) {
                $live[] = $name;
            }
            // A stream that is not in Agora is not a refreshed stream. Without
            // a separate counter a substituted central address or another API
            // version would look like a successful pass: "refreshed 20", while
            // in fact all twenty were declared missing.
            if ($state->state === stream_state::UNKNOWN) {
                $missing++;
            } else {
                $refreshed++;
            }
        }
        $this->mark_live($live, $now);
        $finished = $this->finish_broadcasts($bystream, $now);
        $this->notice_exportless($now);

        $this->record($now, $refreshed, $missing, $failed, '');
        mtrace('mod_agora: stream states refreshed: ' . $refreshed
            . ', missing in Agora: ' . $missing . ', no answer: ' . $failed
            . ', broadcasts finished: ' . $finished);
    }

    /**
     * The stream names of all activities — with one database query.
     *
     * @return string[]
     */
    private function stream_names(): array {
        global $DB;

        $names = $DB->get_fieldset_sql("SELECT DISTINCT stream_name FROM {agora} WHERE stream_name <> ''");
        return array_values(array_filter(array_map('strval', $names), static function (string $name): bool {
            return $name !== '';
        }));
    }

    /**
     * The streams the plugin has already seen live.
     *
     * @return array stream name => true
     */
    private function seen_live_names(): array {
        global $DB;

        $names = $DB->get_fieldset_sql('SELECT DISTINCT stream_name FROM {agora} WHERE last_live_at > 0');
        return array_fill_keys(array_map('strval', $names), true);
    }

    /**
     * Remembers that the streams were observed live — with one query per pass.
     *
     * @param string[] $names names of the live streams
     * @param int $now the moment of observation
     * @return void
     */
    private function mark_live(array $names, int $now): void {
        global $DB;

        if (!$names) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($names);
        $DB->execute('UPDATE {agora} SET last_live_at = ? WHERE stream_name ' . $insql, array_merge([$now], $params));
    }

    /**
     * Facts for each stream: by the fleet lists or by name.
     *
     * @param string[] $names the stream names of the activities
     * @return array stream name => the `streams/stats` body or null when the stream is not in Agora
     * @throws agora_exception when the lists could not be obtained
     */
    private function collect(array $names): array {
        $wanted = array_fill_keys($names, true);

        $configs = $this->page_configs($wanted);
        if ($configs === null) {
            return $this->collect_by_name($names);
        }
        $stats = $this->page_stats($wanted);
        if ($stats === null) {
            return $this->collect_by_name($names);
        }

        $facts = [];
        foreach ($names as $name) {
            if (isset($stats[$name])) {
                $facts[$name] = $stats[$name];
            } else if (isset($configs[$name])) {
                // The stream is created, but the plane returned nothing about
                // it: it has never been observed. This is "not started yet",
                // not a connectivity loss.
                $facts[$name] = ['name' => $name, 'status' => 'idle'];
            } else {
                // The stream is not in Agora at all: the state is unknown
                // with the 404 cause, same as with a by-name request.
                $facts[$name] = null;
            }
        }
        return $facts;
    }

    /**
     * Sweeps the config list pages, keeping only the needed names.
     *
     * @param array $wanted the stream names of the activities
     * @return array|null name => true; null — the fleet is beyond the sweep boundary
     * @throws agora_exception when a page could not be obtained
     */
    private function page_configs(array $wanted): ?array {
        $client = connection::client();
        $found = [];
        $seen = 0;
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $client->list_streams(null, null, $cursor, self::PAGE_LIMIT);
            foreach (($answer['streams'] ?? []) as $stream) {
                $seen++;
                $name = (string) ($stream['name'] ?? '');
                if ($name !== '' && isset($wanted[$name])) {
                    $found[$name] = true;
                }
            }
            if ($seen > self::MAX_LISTED) {
                return null;
            }
            $cursor = (string) ($answer['next'] ?? '');
            if ($cursor === '') {
                return $found;
            }
        }
        return $found;
    }

    /**
     * Sweeps the state list pages, keeping only the needed names.
     *
     * @param array $wanted the stream names of the activities
     * @return array|null name => stats body; null — the fleet is beyond the sweep boundary
     * @throws agora_exception when a page could not be obtained
     */
    private function page_stats(array $wanted): ?array {
        $client = connection::client();
        $found = [];
        $seen = 0;
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $client->list_stream_stats($cursor, self::PAGE_LIMIT);
            foreach (($answer['streams'] ?? []) as $stats) {
                $seen++;
                $name = (string) ($stats['name'] ?? '');
                if ($name !== '' && isset($wanted[$name])) {
                    $found[$name] = $stats;
                }
            }
            if ($seen > self::MAX_LISTED) {
                return null;
            }
            $cursor = (string) ($answer['next'] ?? '');
            if ($cursor === '') {
                return $found;
            }
        }
        return $found;
    }

    /**
     * Facts by name: the fleet is too large to sweep it with lists.
     *
     * @param string[] $names the stream names of the activities
     * @return array name => the `streams/stats` body or null
     */
    private function collect_by_name(array $names): array {
        $client = connection::client();
        $facts = [];
        foreach ($names as $name) {
            try {
                $facts[$name] = $client->stream_stats($name);
            } catch (agora_exception $error) {
                // A refusal for one stream does not take down the whole pass:
                // this one stays unrefreshed and lands in the failure counter.
                $facts[$name] = $error->reason === 'notfound' ? null : false;
            }
        }
        return array_filter($facts, static function ($value): bool {
            return $value !== false;
        });
    }

    /**
     * The stream state from the facts; the stream being absent from Agora is `unknown`.
     *
     * @param string $name stream name
     * @param array|null $stats the `streams/stats` body or null
     * @param int $now the moment of the pass
     * @param bool $seenlive the plugin has seen this stream live
     * @return stream_state
     */
    private function state_of(string $name, ?array $stats, int $now, bool $seenlive): stream_state {
        if ($stats === null) {
            // The stream is not in Agora. For a freshly created activity with
            // phases this is not "unknown" yet: central materialises stats
            // seconds after creation — until then it is an honest "not started
            // yet" (user requirement). For older activities and the legacy the
            // 404 stays "unknown AG-404", so that "stream deleted" is not
            // masked.
            if (stream_state::fresh_phase_element($name, $now)) {
                return new stream_state(stream_state::NOT_STARTED, null, null, $now);
            }
            $state = new stream_state(stream_state::UNKNOWN, null, 'AG-404', $now);
            $state->failure = [
                'reason' => 'notfound',
                'status' => 404,
                'code' => null,
                'message' => get_string('errstreammissing', 'mod_agora', $name),
            ];
            return $state;
        }
        $facts = new stream_facts($stats);
        return new stream_state(stream_state::from_facts($facts, $now, $seenlive), $facts, null, $now);
    }

    /**
     * The refusal cause in one line — the LMS administrator will see it.
     *
     * @param agora_exception $error refusal
     * @return string
     */
    private function reason(agora_exception $error): string {
        $status = $error->httpstatus > 0 ? $error->httpstatus . ' ' : '';
        return $status . $error->getMessage();
    }

    /**
     * Auto-ending broadcasts: silence longer than the threshold, a recording
     * closed by the system and retries of the button's unconfirmed completion.
     *
     * Silence before the "running" phase ends nothing: the teacher has not
     * announced the broadcast yet, and a killed encoder before the lecture is
     * the norm, not a reason to close the activity. "No signal" is counted by
     * the last live observation (`last_live_at`, the pass itself updates it):
     * for an interrupted stream it stops moving, and the age from it is the
     * silence duration.
     *
     * @param array $bystream stream name => stream_state of this pass
     * @param int $now the moment of the pass
     * @return int how many broadcasts were ended (including completion retries)
     */
    private function finish_broadcasts(array $bystream, int $now): int {
        global $DB;

        $rows = $DB->get_records_select('agora', 'has_phases = 1 AND started_at > 0 AND ended_at = 0');
        if (!$rows) {
            return 0;
        }

        $threshold = (int) get_config('mod_agora', 'endofsilence');
        if ($threshold <= 0) {
            $threshold = 600;
        }

        $finished = 0;
        foreach ($rows as $agora) {
            $state = $bystream[(string) $agora->stream_name] ?? null;
            if (!empty($agora->end_pending)) {
                // The button started the completion, the actions did not
                // confirm: the task retries the completion the same way it
                // was started.
                $this->notice_stuck_end($agora, $now);
                if ($this->finish_element($agora, (string) ($agora->ended_by ?? 'system'), null)) {
                    $finished++;
                }
                continue;
            }
            if ($state !== null && $state->state === stream_state::LIVE) {
                continue;
            }
            $lastsignal = max((int) $agora->last_live_at, (int) $agora->started_at);
            if ($now - $lastsignal > $threshold) {
                if ($this->finish_element($agora, 'silence', null)) {
                    $finished++;
                    mtrace('mod_agora: broadcast ended by silence: ' . $agora->stream_name
                        . ' (silent for ' . ($now - $lastsignal) . ' s)');
                }
                continue;
            }
            // The silence is shorter than the threshold, but the recording is
            // already closed — the system closed it (max_duration,
            // no_data_close): we complete the activity to "ended" with the
            // cause from the recording card.
            $reason = null;
            if (!empty($agora->recording_id) && $this->recording_of($agora, $reason) !== null) {
                $reason = (string) ($reason ?? '');
                if ($this->finish_element($agora, 'system', $reason)) {
                    $finished++;
                    mtrace('mod_agora: broadcast ended, the recording was closed by the system: '
                        . $agora->stream_name . ' (' . $reason . ')');
                }
            }
        }
        return $finished;
    }

    /**
     * How many seconds an unconfirmed completion counts as stuck.
     *
     * A permanent refusal (the stream was deleted by hand → 404 on every
     * action) would otherwise complete the activity forever: the threshold
     * separates "temporarily" from "forever" and gives the escalation once
     * (review R2, К-2).
     */
    const END_STUCK = 24 * HOURSECS;

    /**
     * The escalation of a stuck completion: one event when the completion is
     * not confirmed longer than the threshold; the retry spam is redeemed by
     * the `action_failed` dedup inside the loop.
     *
     * @param \stdClass $agora the activity record
     * @param int $now the moment of the pass
     * @return void
     */
    private function notice_stuck_end(\stdClass $agora, int $now): void {
        $since = (int) ($agora->end_pending_at ?? 0);
        if ($since <= 0 || $now - $since <= self::END_STUCK) {
            return;
        }
        $cache = \cache::make('mod_agora', 'endpendinglog');
        $key = 'stuck_' . (int) $agora->id;
        if ($cache->get($key)) {
            return;
        }
        $cache->set($key, $now);
        mtrace('mod_agora: completion of ' . $agora->stream_name . ' is stuck since '
            . userdate($since) . ': ' . (string) ($agora->end_failure ?? ''));
        \mod_agora\event\broadcast_action_failed::create([
            'objectid' => (int) $agora->id,
            'context' => \context_system::instance(),
            'other' => [
                'stream' => (string) $agora->stream_name,
                'action' => 'actionstoprecording',
                'reason' => get_string('eventendstuck', 'mod_agora', userdate($since)),
            ],
        ])->trigger();
    }

    /**
     * Ends the activity through the same lifecycle as the button.
     *
     * @param \stdClass $agora the activity record
     * @param string $by silence | system | the way left by the button
     * @param string|null $stopreason the cause of the system closing of the recording
     * @return bool true — the activity is completed to "ended"
     */
    private function finish_element(\stdClass $agora, string $by, ?string $stopreason): bool {
        $cm = get_coursemodule_from_instance('agora', (int) $agora->id, (int) $agora->course, false, IGNORE_MISSING);
        $context = $cm ? \context_module::instance((int) $cm->id) : \context_system::instance();
        // A recording stop refusal leaves `end_pending` again: the next pass
        // will retry the completion, the divergence is visible on the page and
        // in the log.
        return \mod_agora\local\broadcast_lifecycle::finish($agora, $context, $by, 0, $stopreason) === null;
    }

    /**
     * Notices ready recordings without a finished export for longer than an
     * hour: auto_export is silent. The event fires once per recording while
     * the recording is in this state; the counter is put into a setting — the
     * "Publishing is configured" check reads it.
     *
     * A refusal to read the cards and the exports does not break the pass: the
     * counter simply will not update this pass.
     *
     * @param int $now the moment of the pass
     * @return void
     */
    private function notice_exportless(int $now): void {
        global $DB;

        $elements = $DB->get_records_select('agora', "recording_id IS NOT NULL AND recording_id <> ''");
        if (!$elements) {
            set_config('recordexportless', 0, 'mod_agora');
            return;
        }

        $stale = 0;
        $seen = [];
        $dedup = \cache::make('mod_agora', 'exportlesslog');
        try {
            foreach ($elements as $agora) {
                $recordingid = (string) $agora->recording_id;
                if (isset($seen[$recordingid])) {
                    continue;
                }
                $seen[$recordingid] = true;
                if ($dedup->get($recordingid) === 'done') {
                    // The export finished long ago: the recording is forever
                    // ready and forever playable — central is no longer asked
                    // about it (S-10).
                    continue;
                }
                if ($this->exportless((string) $agora->stream_name, $recordingid, $now)) {
                    $stale++;
                }
            }
        } catch (\Throwable $error) {
            // One broken recording must not take down the whole counter.
            mtrace('mod_agora: recordings without export not counted this pass: ' . $error->getMessage());
        }
        set_config('recordexportless', $stale, 'mod_agora');
        if ($stale > 0) {
            mtrace('mod_agora: recordings ready for over an hour without a finished export: ' . $stale);
        }
    }

    /**
     * The recording has been ready for longer than an hour and has no finished export with a file.
     *
     * @param string $streamname stream name
     * @param string $recordingid recording identifier
     * @param int $now the moment of the pass
     * @return bool
     */
    private function exportless(string $streamname, string $recordingid, int $now): bool {
        $recordings = $this->list_recordings($streamname);
        $card = null;
        foreach ($recordings as $recording) {
            if ((string) ($recording['id'] ?? '') === $recordingid) {
                $card = $recording;
                break;
            }
        }
        if ($card === null || ($card['phase'] ?? '') !== 'ready') {
            return false;
        }

        $readyat = strtotime((string) ($card['ready_at'] ?? $card['ended_at'] ?? '')) ?: 0;
        if ($readyat <= 0 || $now - $readyat <= self::EXPORT_STALE) {
            return false;
        }

        foreach ($this->list_exports($recordingid) as $export) {
            if (\mod_agora\local\recording_scope::export_playable($export)) {
                // The export has finished: the "noticed" mark is lifted, and
                // "ready" is set for future passes — a ready recording is not
                // polled at all anymore: the counter cannot regress while the
                // export with a file exists (review R2, S-10).
                $dedup = \cache::make('mod_agora', 'exportlesslog');
                $dedup->set($recordingid, 'done');
                return false;
            }
        }

        $dedup = \cache::make('mod_agora', 'exportlesslog');
        if ($dedup->get($recordingid) === 'done') {
            // Already noticed as ready earlier: not asked again.
            return false;
        }
        if (!$dedup->get($recordingid)) {
            $dedup->set($recordingid, $now);
            \mod_agora\event\recording_export_missing::create([
                'objectid' => 0,
                'context' => \context_system::instance(),
                'other' => ['stream' => $streamname, 'recording_id' => $recordingid],
            ])->trigger();
        }
        return true;
    }

    /**
     * The stream recording list for one pass; null in the cache means "could not ask".
     *
     * @param string $streamname stream name
     * @return array
     */
    private function list_recordings(string $streamname): array {
        if (!array_key_exists($streamname, $this->recordinglists)) {
            $this->recordinglists[$streamname] = null;
            $answer = connection::client()->list_recordings($streamname);
            $this->recordinglists[$streamname] = is_array($answer) ? ($answer['recordings'] ?? []) : [];
        }
        return (array) $this->recordinglists[$streamname];
    }

    /** @var array The export list cache for one pass: recording => exports. */
    private array $exportlists = [];

    /**
     * The recording export list for one pass.
     *
     * @param string $recordingid recording identifier
     * @return array
     */
    private function list_exports(string $recordingid): array {
        if (!array_key_exists($recordingid, $this->exportlists)) {
            $answer = connection::client()->list_recording_exports($recordingid);
            $this->exportlists[$recordingid] = is_array($answer) ? ($answer['exports'] ?? []) : [];
        }
        return $this->exportlists[$recordingid];
    }

    /**
     * The bound recording card of the activity; null — the recording is not in
     * the list or it is still running. $reason — the stop_reason of a closed
     * recording.
     *
     * A refusal of the recording list must not take down the whole pass: this
     * read is better skipped (a retry on the next pass) than leaving all
     * streams without a state refresh. The refusal itself is logged once per
     * cause per pass — with central down, every activity with a recording must
     * not write its own event every minute (review R2, К-1-obs).
     *
     * @param \stdClass $agora the activity record
     * @param string|null $reason the stop_reason of the closed recording is written here
     * @return array|null the recording card or null
     */
    private function recording_of(\stdClass $agora, ?string &$reason): ?array {
        try {
            $recordings = $this->list_recordings((string) $agora->stream_name);
        } catch (agora_exception $error) {
            $this->log_recording_list_failure_once($error);
            return null;
        }
        foreach ($recordings as $recording) {
            if ((string) ($recording['id'] ?? '') === (string) $agora->recording_id) {
                if (($recording['phase'] ?? '') === 'recording') {
                    return null;
                }
                $reason = (string) ($recording['stop_reason'] ?? '');
                return $recording;
            }
        }
        return null;
    }

    /** @var array|null The recording list refusals of this pass: cause => true. */
    private ?array $recordinglistfailures = null;

    /**
     * Logs a recording list refusal once per cause per pass.
     *
     * @param agora_exception $error refusal
     * @return void
     */
    private function log_recording_list_failure_once(agora_exception $error): void {
        $this->recordinglistfailures = $this->recordinglistfailures ?? [];
        $key = $error->httpstatus . ':' . $error->reason;
        if (isset($this->recordinglistfailures[$key])) {
            return;
        }
        $this->recordinglistfailures[$key] = true;
        // The cause is in the event log (`central_failed`); the pass
        // continues, the check will repeat on the next one.
        failure::log($error, \context_system::instance(), 'oplistrecordings', 'admin');
    }

    /**
     * Writes the pass summary into the plugin settings.
     *
     * @param int|null $refreshedat the time of the successful pass; null — keep the previous one
     * @param int $refreshed how many states were refreshed
     * @param int $missing how many streams were not found in Agora
     * @param int $failed how many streams were left unrefreshed
     * @param string $failure the last error text; an empty string — no errors
     * @return void
     */
    private function record(?int $refreshedat, int $refreshed, int $missing, int $failed, string $failure): void {
        if ($refreshedat !== null) {
            set_config('statesrefreshedat', $refreshedat, 'mod_agora');
        }
        set_config('statesrefreshed', $refreshed, 'mod_agora');
        set_config('statesmissing', $missing, 'mod_agora');
        set_config('statesfailed', $failed, 'mod_agora');
        set_config('statesfailure', $failure, 'mod_agora');
    }
}
