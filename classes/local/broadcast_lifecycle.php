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
 * The lifecycle of the activity broadcast: "Start broadcast", "End broadcast" and their completion.
 *
 * The actions are idempotent and are driven to a confirmed end: a refusal of
 * any completion action does not make the activity terminal — the page shows
 * "could not end: <action>, <cause>", the retry is available with the same
 * button and the scheduled task `refresh_states`, and the "ended" phase is set
 * only when all actions of the slice are confirmed.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class broadcast_lifecycle {
    /**
     * "Start broadcast": admitting learners and starting the recording with one button.
     *
     * The "running" phase is set at once — even when the recording did not
     * start (a 409 of readiness, network): the teacher admitted the learners by
     * the button decision, and after the broadcast ends playback goes through
     * the archive window. The recording start refusal is named to the teacher
     * and written to the log.
     *
     * The buttons live only on plugin activities: the plugin does not lead a
     * "take an existing" stream (D3), and a direct request bypassing the button
     * is refused before any call to Agora (review R2, К-1).
     *
     * @param \stdClass $agora the activity record
     * @param \context $context activity context
     * @param int $userid who pressed the button
     * @return string|null the recording refusal message for the teacher; null — everything succeeded
     */
    public static function start(\stdClass $agora, \context $context, int $userid): ?string {
        global $DB;

        if (empty($agora->created_by_plugin)) {
            throw new \moodle_exception('errunknownaction', 'mod_agora');
        }
        if (broadcast_phase::of($agora) !== broadcast_phase::PLANNED) {
            return null;
        }

        // The button is active only with a live signal; a direct request
        // without a signal is refused by the same rule instead of setting the
        // "running" phase blindly.
        $state = stream_state::for_page((string) $agora->stream_name);
        if ($state->state !== stream_state::LIVE) {
            return get_string('startbroadcastnosignal', 'mod_agora');
        }

        $now = time();
        $DB->set_field('agora', 'started_at', $now, ['id' => $agora->id]);
        $agora->started_at = $now;
        \mod_agora\event\broadcast_started::create([
            'objectid' => (int) $agora->id,
            'context' => $context,
            'relateduserid' => $userid ?: null,
            'other' => ['stream' => (string) $agora->stream_name],
        ])->trigger();

        return self::start_recording($agora, $context, $userid);
    }

    /**
     * Starts the recording and binds it to the activity.
     *
     * @param \stdClass $agora the activity record (with a fresh `started_at`)
     * @param \context $context activity context
     * @param int $userid who pressed the button
     * @return string|null the refusal message for the teacher; null — the recording started or was adopted
     */
    private static function start_recording(\stdClass $agora, \context $context, int $userid): ?string {
        global $DB;

        // Agora without the recordings facet: no start at all — otherwise every
        // press would drop a 404 refusal. The log gets one entry per activity;
        // playback after the broadcast ends goes through the archive window
        // (a degradation, the `record-playback` spec).
        if (
            recording_routing::available((string) $agora->stream_name, $context) === recording_routing::MISSING
        ) {
            $noted = \cache::make('mod_agora', 'recordfacetlog');
            if (!$noted->get((int) $agora->id)) {
                $noted->set((int) $agora->id, time());
                $missing = new agora_exception('errrecordfacetmissing', null, 'notfound');
                $missing->apicode = 'RECORDING_FACET_MISSING';
                $missing->operation = 'opstartrecording';
                $missing->role = 'admin';
                self::action_failed($agora, $context, 'actionstartrecording', $missing, $userid);
            }
            return null;
        }

        $client = connection::client();
        try {
            $answer = $client->start_recording((string) $agora->stream_name);
        } catch (agora_exception $error) {
            if ($error->apicode === 'RECORDING_ACTIVE') {
                // The recording is already running: adopt the open recording
                // of the stream instead of refusing the broadcast. The race
                // with the recording closing between the refusal and the list
                // is handled inside the adoption.
                return self::adopt_open_recording($agora, $context, $userid);
            }
            // A 409 of readiness (RECORDING_NO_DVR, NOT_PLACED,
            // NODE_UNREACHABLE, STREAM_NOT_RUNNING, ARCHIVE_NOT_WRITING) and
            // network: the "running" phase is already set, the recording is not
            // bound — playback after the end goes through the archive window.
            // The cause from the response body goes to the teacher and to the
            // log.
            failure::log($error, $context, 'opstartrecording', 'admin');
            self::action_failed($agora, $context, 'actionstartrecording', $error, $userid);
            return failure::message($error, $context);
        }

        $id = (string) ($answer['id'] ?? '');
        if ($id === '') {
            // An answer without an identifier is a "success without an effect":
            // there is no recording that could be stopped and shown, and there
            // is nothing to silently point at.
            $error = new agora_exception('errbadresponse', (object) [
                'operation' => get_string('opstartrecording', 'mod_agora'),
                'head' => '',
            ], 'badresponse');
            $error->operation = 'opstartrecording';
            $error->role = 'admin';
            failure::log($error, $context, 'opstartrecording', 'admin');
            self::action_failed($agora, $context, 'actionstartrecording', $error, $userid);
            return get_string('recordingstartfailed', 'mod_agora', $error->short_code());
        }
        $DB->set_field('agora', 'recording_id', $id, ['id' => $agora->id]);

        // The recording name is the activity name: in the central recording
        // list the broadcast is recognised by its session. A rename refusal
        // does not break the live recording — the cause is in the log, the
        // recording is already being written.
        try {
            $client->rename_recording($id, (string) $agora->name);
        } catch (agora_exception $error) {
            failure::log($error, $context, 'oprenamerecording', 'admin');
        }
        return null;
    }

    /**
     * Adopts the already running recording of the stream as bound to the activity.
     *
     * Renames only its own recording (the owner is the plugin admin account):
     * a foreign recording started by the operator is adopted as is, and the
     * owner is logged. If there is no open recording in the list (it closed in
     * the race between the refusal and the list), the recording is not bound —
     * same as on a readiness refusal.
     *
     * @param \stdClass $agora the activity record
     * @param \context $context activity context
     * @param int $userid who pressed the button
     * @return string|null a message for the teacher; null — the recording was adopted
     */
    private static function adopt_open_recording(\stdClass $agora, \context $context, int $userid): ?string {
        global $DB;

        $client = connection::client();
        try {
            $answer = $client->list_recordings((string) $agora->stream_name);
        } catch (agora_exception $error) {
            failure::log($error, $context, 'oplistrecordings', 'admin');
            self::action_failed($agora, $context, 'actionstartrecording', $error, $userid);
            return failure::message($error, $context);
        }

        $open = null;
        foreach (($answer['recordings'] ?? []) as $recording) {
            if (($recording['phase'] ?? '') === 'recording') {
                $open = $recording;
                break;
            }
        }
        if ($open === null) {
            return get_string('recordingstartfailed', 'mod_agora', 'RECORDING_ACTIVE');
        }

        $id = (string) ($open['id'] ?? '');
        $owner = (string) ($open['owner'] ?? '');
        if ($id === '') {
            return get_string('recordingstartfailed', 'mod_agora', 'RECORDING_ACTIVE');
        }
        $DB->set_field('agora', 'recording_id', $id, ['id' => $agora->id]);

        // Renaming — only our own recording: the owner of a foreign one has
        // their own plans for the name, and the plugin does not touch it (D4).
        $renamed = false;
        if ($owner !== '' && $owner === (string) get_config('mod_agora', 'adminlogin')) {
            try {
                $client->rename_recording($id, (string) $agora->name);
                $renamed = true;
            } catch (agora_exception $error) {
                failure::log($error, $context, 'oprenamerecording', 'admin');
            }
        }

        \mod_agora\event\broadcast_recording_adopted::create([
            'objectid' => (int) $agora->id,
            'context' => $context,
            'relateduserid' => $userid ?: null,
            'other' => [
                'stream' => (string) $agora->stream_name,
                'recording_id' => $id,
                'owner' => $owner,
                'renamed' => $renamed,
            ],
        ])->trigger();
        return null;
    }

    /**
     * "End broadcast": stop the recording, revoke publish tickets, the "ended" phase.
     *
     * A recording stop refusal leaves the activity in the "running" phase with
     * `end_pending = 1` and the refusal text: the retry is available with the
     * same button and the scheduled task. The "ended" phase is set only after
     * all actions are confirmed.
     *
     * @param \stdClass $agora the activity record
     * @param \context $context activity context
     * @param string $by who ends it: button | silence | system
     * @param int $userid who pressed the button; 0 — the scheduled task
     * @param string|null $stopreason the cause of the system closing of the recording
     * @return string|null the refusal message; null — the activity is ended
     */
    public static function finish(
        \stdClass $agora,
        \context $context,
        string $by,
        int $userid = 0,
        ?string $stopreason = null
    ): ?string {
        global $DB;

        // A lock on the activity: the button and the scheduled task may reach
        // "End broadcast" at the same time — the `broadcast_ended` event must
        // be one per phase transition, not one per arriver (review R2, М-8).
        // Without the lock the state would still converge by idempotence, but
        // the log would double.
        $factory = \core\lock\lock_config::get_lock_factory('mod_agora_endbroadcast');
        $lock = $factory->get_lock('agora_' . (int) $agora->id, 30);
        if (!$lock) {
            // A neighbouring process is already completing this broadcast: our
            // repeat changes nothing, and a second run of HTTP actions in a row
            // is a needless risk.
            return null;
        }
        try {
            // While we waited for the lock, the neighbour may have ended the
            // broadcast entirely: re-read the phase and complete only an
            // unfinished activity.
            $fresh = $DB->get_record('agora', ['id' => $agora->id]);
            if ($fresh !== false) {
                $agora = $fresh;
            }
            return self::finish_locked($agora, $context, $by, $userid, $stopreason);
        } finally {
            $lock->release();
        }
    }

    /**
     * Completion under a lock on the activity (see `finish()`).
     *
     * @param \stdClass $agora the activity record
     * @param \context $context activity context
     * @param string $by who ends it: button | silence | system
     * @param int $userid who pressed the button; 0 — the scheduled task
     * @param string|null $stopreason the cause of the system closing of the recording
     * @return string|null the refusal message; null — the activity is ended
     */
    private static function finish_locked(
        \stdClass $agora,
        \context $context,
        string $by,
        int $userid,
        ?string $stopreason
    ): ?string {
        global $DB;

        $phase = broadcast_phase::of($agora);
        if ($phase === null || ($phase !== broadcast_phase::ONGOING && empty($agora->end_pending))) {
            return null;
        }

        // The intention is recorded at once: a task retry ends the broadcast
        // the same way it was started to be ended.
        if ((string) ($agora->ended_by ?? '') !== $by) {
            $DB->set_field('agora', 'ended_by', $by, ['id' => $agora->id]);
            $agora->ended_by = $by;
        }

        $failure = null;
        $failedaction = null;
        try {
            connection::client()->stop_recording((string) $agora->stream_name);
        } catch (agora_exception $error) {
            // The "No recording" answer is "already stopped", not a refusal: the action is
            // idempotent and confirms its own state.
            if ($error->apicode !== 'RECORDING_NOT_RUNNING') {
                $failure = $error;
                $failedaction = 'actionstoprecording';
            }
        }

        // The publish section removal comes after the recording stop: the port
        // is freed only after a confirmed removal, so a refusal here leaves the
        // activity in the "running" phase with a pending completion, and the
        // port occupied (О-2/О-4).
        if ($failure === null && !empty($agora->srt_port)) {
            try {
                publish_manager::remove_section($agora);
            } catch (agora_exception $error) {
                $failure = $error;
                $failedaction = 'actionremovepublish';
            }
        }

        if ($failure !== null) {
            failure::log($failure, $context, $failure->operation !== '' ? $failure->operation : 'opstoprecording', 'admin');
            self::action_failed($agora, $context, $failedaction, $failure, $userid);
            $why = get_string($failedaction, 'mod_agora') . ', ' . $failure->editor_details();
            $pendingsince = (int) ($agora->end_pending_at ?? 0);
            $DB->update_record('agora', (object) [
                'id' => (int) $agora->id,
                'end_pending' => 1,
                'end_pending_at' => $pendingsince > 0 ? $pendingsince : time(),
                'end_failure' => \core_text::substr($why, 0, 250),
            ]);
            return get_string('endpendingline', 'mod_agora', $why);
        }

        // Publish tickets are always revoked: WHIP broadcasting goes past the
        // publish section, and removing the section would not have stopped it
        // (D10). A revocation refusal is logged by the manager itself and does
        // not block terminality: the ticket will die of its own expiry anyway.
        grant_manager::revoke_where([
            'agoraid' => (int) $agora->id,
            'purpose' => grant_manager::PURPOSE_PUBLISH,
        ]);

        $now = time();
        $DB->update_record('agora', (object) [
            'id' => (int) $agora->id,
            'ended_at' => $now,
            'end_pending' => 0,
            'end_pending_at' => 0,
            'end_failure' => null,
        ]);
        // The completion is done: lift the "stuck completion" marks, so the
        // escalation does not live in the cache if the divergence repeats
        // later.
        \cache::make('mod_agora', 'endpendinglog')->delete((int) $agora->id);
        \mod_agora\event\broadcast_ended::create([
            'objectid' => (int) $agora->id,
            'context' => $context,
            'relateduserid' => $userid ?: null,
            'other' => [
                'stream' => (string) $agora->stream_name,
                'by' => $by,
                'endedat' => $now,
                'stop_reason' => (string) ($stopreason ?? ''),
            ],
        ])->trigger();
        return null;
    }

    /**
     * Logs the refusal of a broadcast action with a separate event class.
     *
     * Deduplication by the "activity + action" pair: while Agora refuses the
     * completion every minute, a repeat with the same action writes no new
     * events — an hour of central downtime must not produce ~60 identical log
     * entries (review R2, К-1-obs). The cause changed — a new event: "stuck" is
     * distinguishable from "was temporary".
     *
     * @param \stdClass $agora the activity record
     * @param \context $context activity context
     * @param string $action the action string key
     * @param agora_exception $error refusal
     * @param int $userid initiator; 0 — the scheduled task
     * @return void
     */
    private static function action_failed(
        \stdClass $agora,
        \context $context,
        string $action,
        agora_exception $error,
        int $userid = 0
    ): void {
        $reason = ($error->apicode ?? '') !== '' ? $error->apicode : $error->reason;
        $key = (int) $agora->id;
        $cache = \cache::make('mod_agora', 'endpendinglog');
        $last = $cache->get($key);
        if (is_array($last) && ($last['action'] ?? '') === $action && ($last['reason'] ?? '') === $reason) {
            // The same refusal in a row: the entry already exists, a repeat is just noise.
            return;
        }
        $cache->set($key, ['action' => $action, 'reason' => $reason, 'at' => time()]);

        \mod_agora\event\broadcast_action_failed::create([
            'objectid' => (int) $agora->id,
            'context' => $context,
            'relateduserid' => $userid ?: null,
            'other' => [
                'stream' => (string) $agora->stream_name,
                'action' => $action,
                'reason' => $reason,
            ],
        ])->trigger();
    }
}
