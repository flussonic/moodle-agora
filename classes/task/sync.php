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
use mod_agora\local\grant_manager;
use mod_agora\local\recording_scope;
use mod_agora\local\tombstones;
use mod_agora\local\watch;
use mod_agora\local\watch_counter;

/**
 * The plugin background pass on the schedule from `db/tasks.php`.
 *
 * Does three things: redeems expired tickets and wipes their JWT; counts the
 * viewing duration by the Agora history and marks completion; deletes streams
 * by ripe tombstones.
 *
 * Central being unreachable is not counted as a failure of the pass: the
 * completion marks are then untouched at all, and the cause is written to the
 * task log.
 *
 * The task log is in English, like the core tasks': it is read by the LMS
 * administrator in "Scheduled tasks", and `mtrace` has no translations. The
 * log lines are listed in the administrator documentation
 * (`docs/{ru,en}/admin/08-…`).
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync extends \core\task\scheduled_task {
    /**
     * How much history to ask of a pair nothing is known about yet.
     *
     * The history answer is limited by `limit`, so a "from the epoch" window
     * is senseless: a day back is taken, then the boundary moves by itself.
     */
    const FIRST_WINDOW = DAYSECS;

    /**
     * How much longer to poll a pair and set its mark after its last ticket
     * expired.
     *
     * Counted by the pair row (`agora_watch.lastexpiry`), not by the tickets:
     * redeemed tickets are deleted after `GRANT_KEEP`, and a week-long window
     * by them would shrink to a day.
     */
    const TAIL_WINDOW = 7 * DAYSECS;

    /** How long after `expires_at` to delete redeemed ticket rows. */
    const GRANT_KEEP = DAYSECS;

    /**
     * The task name in "Scheduled tasks".
     *
     * @return string
     */
    public function get_name() {
        return get_string('tasksync', 'mod_agora');
    }

    /**
     * The task pass.
     *
     * @return void
     */
    public function execute() {
        $this->expire_grants();
        $this->count_watching();
        $this->forget_old_refs();
        $this->collect_tombstones();
    }

    /**
     * Wipes the Agora `user_id` of pairs that are no longer polled.
     *
     * `user_ref` contains the learner login and is needed only for the history
     * request; after `lastexpiry + TAIL_WINDOW` the task no longer touches the
     * pair, and there is no reason to keep the login. The accumulated seconds
     * stay: the rule card shows the learner their time by them. A new ticket
     * will assemble the `user_id` anew.
     *
     * @return void
     */
    private function forget_old_refs(): void {
        global $DB;

        $cutoff = time() - self::TAIL_WINDOW;
        $old = $DB->count_records_select('agora_watch', 'lastexpiry < ? AND user_ref IS NOT NULL', [$cutoff]);
        if ($old) {
            $DB->execute('UPDATE {agora_watch} SET user_ref = NULL WHERE lastexpiry < ? AND user_ref IS NOT NULL', [$cutoff]);
        }
        mtrace('mod_agora: Agora user references forgotten after the tail window: ' . $old);
    }

    /**
     * Redeems expired tickets and wipes their secret.
     *
     * @return void
     */
    private function expire_grants(): void {
        global $DB;

        $now = time();
        $stale = $DB->count_records_select('agora_grants', "status = 'issued' AND expires_at <= ?", [$now]);
        if ($stale) {
            $DB->execute(
                "UPDATE {agora_grants} SET status = 'expired', token = NULL WHERE status = 'issued' AND expires_at <= ?",
                [$now]
            );
        }
        mtrace('mod_agora: expired tickets closed: ' . $stale);

        // Redeemed rows live one more day: during this time they are visible
        // in the privacy export and in complaint analysis; keeping them longer
        // is pointless. The countdown is from `expires_at`: after it the row
        // is useless, the revoked one too. The pair age for completion is not
        // counted by them — it is in `agora_watch`.
        $cutoff = $now - self::GRANT_KEEP;
        $old = $DB->count_records_select(
            'agora_grants',
            "status IN ('expired', 'revoked') AND expires_at <= ?",
            [$cutoff]
        );
        if ($old) {
            $DB->delete_records_select(
                'agora_grants',
                "status IN ('expired', 'revoked') AND expires_at <= ?",
                [$cutoff]
            );
        }
        mtrace('mod_agora: closed ticket rows deleted: ' . $old);
    }

    /**
     * Counts the viewing duration and sets the completion marks.
     *
     * Two steps, and their order matters. First the viewing history: the
     * seconds and the pair poll boundary are saved at once — the same sessions
     * will not come from central again. Then the marks: a separate pass over
     * all pairs whose threshold is reached but whose mark is not in the
     * completion log sets them. The mark is derived from the saved seconds, so
     * a refusal on it loses nothing: the next pass will recompute it without a
     * history request.
     *
     * @return void
     */
    private function count_watching(): void {
        global $DB;

        $elements = $DB->get_records_select('agora', 'completion_minutes > 0');
        if (!$elements) {
            mtrace('mod_agora: no activities with a watching-time condition');
            return;
        }

        $now = time();
        $marks = ['complete' => 0, 'waiting' => 0, 'failed' => 0, 'untracked' => 0, 'elementsfailed' => 0];
        foreach ($elements as $agora) {
            // The pairs it still makes sense to ask: the threshold is not
            // reached and the last ticket expired no earlier than a week ago —
            // by the pair row, which survives the deletion of redeemed
            // tickets. The task no longer asks the history of a pair that
            // reached the threshold; its mark is set by `mark_due()` below —
            // by the saved seconds.
            $due = [
                'agoraid' => $agora->id,
                'threshold' => (int) $agora->completion_minutes * MINSECS,
                'since' => $now - self::TAIL_WINDOW,
            ];
            $pairs = $DB->get_recordset_select(
                'agora_watch',
                'agoraid = :agoraid AND watched_secs < :threshold AND lastexpiry >= :since AND '
                    . $DB->sql_isnotempty('agora_watch', 'user_ref', true, false),
                $due,
                'userid ASC',
                'id, userid, user_ref'
            );
            // A pair that still has to be counted but has no `user_id` in
            // Agora cannot ask for the history: its `user_ref` missed the
            // update step (no site label, the user is deleted). It must not
            // silently drop out — that would be "did not watch enough" where
            // in fact it is "was not asked". The number of such pairs — as a
            // separate line; the next ticket of the pair will assemble the
            // `user_id` anew.
            $norefs = $DB->count_records_select(
                'agora_watch',
                // The `sql_isempty()` function would skip NULL: `NULL = ''` is not true,
                // and a wiped and unguessed `user_ref` is exactly NULL.
                "agoraid = :agoraid AND watched_secs < :threshold AND lastexpiry >= :since
                    AND (user_ref IS NULL OR user_ref = '')",
                $due
            );
            if ($norefs > 0) {
                mtrace('mod_agora: activity ' . $agora->id . ': pairs without an Agora user_id, history not asked: '
                    . $norefs . '; the next ticket of the pair restores it');
            }
            $counted = ['seconds' => 0, 'idletail' => 0, 'sessions' => 0];
            // The media identities of the activity: the stream name and the
            // vod assets of the bound recording. The composition is read once
            // per activity: it is the same for all pairs of the activity, and
            // central is not visited for every pair.
            $identities = null;
            foreach ($pairs as $pair) {
                try {
                    if ($identities === null) {
                        $identities = $this->identities_of($agora);
                    }
                    $got = $this->count_pair($agora, $identities, (int) $pair->userid, (string) $pair->user_ref, $now);
                    foreach ($got as $key => $value) {
                        $counted[$key] += $value;
                    }
                } catch (\Throwable $error) {
                    // One broken pair must not take down the whole pass:
                    // neither a central refusal nor a broken activity row.
                    mtrace('mod_agora: pair (activity ' . $agora->id . ', userid ' . (int) $pair->userid
                        . ') skipped: ' . $error->getMessage());
                }
            }
            $pairs->close();
            if (count($identities ?? []) > 1) {
                mtrace('mod_agora: activity ' . $agora->id . ': counting by ' . count($identities)
                    . ' media identities (stream and vod assets of the recording)');
            }
            if ($counted['sessions'] > 0) {
                // The subtracted idle time is named separately: otherwise the
                // discrepancy "credited less than the session lasted in the
                // Agora console" would be a riddle.
                mtrace('mod_agora: activity ' . $agora->id . ': ' . $counted['sessions'] . ' sessions, '
                    . $counted['seconds'] . ' s counted, ' . $counted['idletail']
                    . ' s of idle time after the last request left out');
            }

            try {
                foreach ($this->mark_due($agora, $now) as $key => $value) {
                    $marks[$key] += $value;
                }
            } catch (\Throwable $error) {
                // A refusal before the pairs are known (the library, the
                // activity, the database query). The seconds are already
                // saved: the next pass will set the marks without a history
                // request.
                mtrace('mod_agora: activity ' . $agora->id . ': completion marks not set: ' . $error->getMessage());
                $marks['elementsfailed']++;
            }
        }
        mtrace('mod_agora: watching-time completion marks set: ' . $marks['complete']);
        if ($marks['waiting'] > 0) {
            mtrace('mod_agora: threshold reached, activity waits for other completion conditions: ' . $marks['waiting']);
        }
        if ($marks['untracked'] > 0) {
            mtrace('mod_agora: threshold reached, learner not tracked (unenrolled or suspended), no mark: '
                . $marks['untracked']);
        }
        if ($marks['failed'] > 0) {
            mtrace('mod_agora: completion marks failed: ' . $marks['failed']
                . '; seconds are saved, the next pass retries');
        }
        if ($marks['elementsfailed'] > 0) {
            mtrace('mod_agora: activities whose marks failed before the pairs were known: ' . $marks['elementsfailed']
                . '; the next pass retries');
        }
    }

    /**
     * Sets the marks for the activity pairs whose threshold is reached but whose mark is missing.
     *
     * The selection goes by the saved seconds and the core completion log, not
     * by the result of this pass: so a pair whose mark fell last time (or fell
     * on the time when completion in the course was off) gets it on the next
     * pass. There are no requests to central here. Pairs with a manually
     * removed mark (`overrideby`) are not touched, nor are pairs whose last
     * ticket expired more than a week ago. Only a tracked learner gets the
     * mark (`completion_info::is_tracked_user()`: an active enrolment in the
     * course and the right to appear in completion reports) — an unenrolled or
     * suspended one does not get it. A refusal after the pairs are known is
     * counted per pair.
     *
     * @param \stdClass $agora the activity record
     * @param int $now the moment of the pass
     * @return array ['complete' => …, 'waiting' => …, 'failed' => …, 'untracked' => …]
     */
    private function mark_due(\stdClass $agora, int $now): array {
        global $CFG, $DB;

        // The `completion_info` class and the `COMPLETION_*` constants live in
        // this library, and cron does not include it by itself: without the
        // line below the task in cron failed on every pair, while under
        // PHPUnit it did not (see tests/sync_cron_test.php).
        require_once($CFG->libdir . '/completionlib.php');

        $result = ['complete' => 0, 'waiting' => 0, 'failed' => 0, 'untracked' => 0];
        $cm = get_coursemodule_from_instance('agora', $agora->id, $agora->course, false, IGNORE_MISSING);
        if (!$cm || (int) $cm->completion !== COMPLETION_TRACKING_AUTOMATIC) {
            return $result;
        }

        $due = $DB->get_fieldset_sql(
            "SELECT w.userid
               FROM {agora_watch} w
          LEFT JOIN {course_modules_completion} c ON c.coursemoduleid = :cmid AND c.userid = w.userid
              WHERE w.agoraid = :agoraid AND w.watched_secs >= :threshold AND w.lastexpiry >= :since
                    AND (c.id IS NULL OR (c.completionstate = :incomplete AND c.overrideby IS NULL))",
            [
                'cmid' => $cm->id,
                'agoraid' => $agora->id,
                'threshold' => (int) $agora->completion_minutes * MINSECS,
                'incomplete' => COMPLETION_INCOMPLETE,
                'since' => $now - self::TAIL_WINDOW,
            ]
        );
        if (!$due) {
            return $result;
        }

        try {
            $completion = $this->completion($agora);
            $enabled = $completion->is_enabled($cm);
        } catch (\Throwable $error) {
            // The pairs are already known: the refusal is counted per pair,
            // not per activity.
            mtrace('mod_agora: activity ' . $agora->id . ': completion marks not set for ' . count($due)
                . ' pairs: ' . $error->getMessage());
            $result['failed'] += count($due);
            return $result;
        }
        if (!$enabled) {
            // Not a refusal and not "did not watch enough": tracking is off.
            // The marks will appear on the next pass after it is enabled.
            mtrace('mod_agora: activity ' . $agora->id . ': completion is off in the course, '
                . count($due) . ' pairs reached the threshold, no marks set');
            return $result;
        }

        foreach ($due as $userid) {
            try {
                if (!$completion->is_tracked_user((int) $userid)) {
                    $result['untracked']++;
                    continue;
                }
                $this->mark($completion, $cm, (int) $userid);
                $state = (int) $completion->get_data($cm, false, (int) $userid)->completionstate;
            } catch (\Throwable $error) {
                mtrace('mod_agora: completion mark (activity ' . $agora->id . ', userid ' . (int) $userid
                    . ') not set: ' . $error->getMessage());
                $result['failed']++;
                continue;
            }
            $result[$state === COMPLETION_INCOMPLETE ? 'waiting' : 'complete']++;
        }
        return $result;
    }

    /**
     * The course completion of the activity.
     *
     * A separate method — so that a test can drop the step where the pairs are
     * already known but the marks have not been set yet, and check the refusal
     * count per pair.
     *
     * @param \stdClass $agora the activity record
     * @return \completion_info
     */
    protected function completion(\stdClass $agora): \completion_info {
        return new \completion_info(get_course($agora->course));
    }

    /**
     * Recomputes the learner's mark with the regular core call.
     *
     * A separate method — so that a test can drop exactly this step and check
     * that a fallen mark is not lost.
     *
     * @param \completion_info $completion course completion
     * @param \stdClass $cm course module
     * @param int $userid learner
     * @return void
     */
    protected function mark(\completion_info $completion, \stdClass $cm, int $userid): void {
        $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
    }

    /**
     * The media identities of the activity for viewing counting.
     *
     * The stream and the `vod/{asset_id}` of the bound recording: the learner
     * could watch both the live broadcast before the recording closed and the
     * clip after — the Agora history lives by both. A refusal to read the
     * exports must not invisibly zero the count: we count at least by the
     * stream, and write the cause to the pass log.
     *
     * @param \stdClass $agora the activity record
     * @return string[]
     */
    private function identities_of(\stdClass $agora): array {
        $scope = [(string) $agora->stream_name];
        if (empty($agora->has_phases) || empty($agora->recording_id)) {
            return $scope;
        }
        try {
            // All playable assets of the recording, not only the latest one:
            // sessions on the previous clip in the same window must sum up
            // (review R2, Н-2).
            return array_merge($scope, recording_scope::assets_cached((string) $agora->recording_id));
        } catch (agora_exception $error) {
            mtrace('mod_agora: activity ' . $agora->id . ': recording exports unreadable ('
                . $error->httpstatus . ' ' . $error->getMessage() . '), counting by the stream name only');
            return $scope;
        }
    }

    /**
     * Counts the duration of one pair by the viewing history and saves it.
     *
     * Does not set the completion mark: `mark_due()` does that by the saved
     * seconds.
     *
     * @param \stdClass $agora the activity record
     * @param array $identities the media identities of the activity (the stream and the vod assets of the recording)
     * @param int $userid learner
     * @param string $userref the person's identifier in Agora
     * @param int $now the moment of the pass
     * @return array ['seconds' => credited, 'idletail' => idle time subtracted, 'sessions' => sessions]
     */
    private function count_pair(\stdClass $agora, array $identities, int $userid, string $userref, int $now): array {
        global $DB;

        $none = ['seconds' => 0, 'idletail' => 0, 'sessions' => 0];
        $threshold = (int) $agora->completion_minutes * MINSECS;
        $row = watch::row((int) $agora->id, $userid);
        if (empty($row->id)) {
            // The pair row was deleted between the selection and the poll
            // (user deletion, privacy): there is nowhere to count.
            return $none;
        }
        if ((int) $row->watched_secs >= $threshold) {
            // The threshold is already reached: the history is no longer
            // asked, the mark is set by `mark_due()`.
            return $none;
        }
        $from = (int) $row->polled_until ?: $now - self::FIRST_WINDOW;
        if ($from >= $now) {
            return $none;
        }

        try {
            $sessions = [];
            foreach ($identities as $identity) {
                $sessions = array_merge(
                    $sessions,
                    watch_counter::sessions(connection::client(), $identity, $userref, $from, $now)
                );
            }
        } catch (agora_exception $error) {
            // A central refusal does not mean "not completed": the marks are
            // untouched, the poll boundary is not moved, the cause is written
            // to the task log.
            // In the log — `userid`, not the login: the login is redundant there.
            mtrace('mod_agora: viewing history unavailable (' . $agora->stream_name . ', userid ' . $userid . '): '
                . $error->httpstatus . ' ' . $error->getMessage());
            return $none;
        }

        if (!$sessions) {
            $live = $DB->get_field_select(
                'agora_grants',
                'jti',
                'agoraid = :agoraid AND userid = :userid AND purpose = :purpose AND status = :status AND expires_at > :now',
                [
                    'agoraid' => $agora->id,
                    'userid' => $userid,
                    'purpose' => grant_manager::PURPOSE_VIEW,
                    'status' => 'issued',
                    'now' => $now,
                ],
                IGNORE_MULTIPLE
            );
            if (!empty($live)) {
                mtrace('mod_agora: no sessions for user_id, but a live ticket exists: jti=' . $live
                    . ' (' . $agora->stream_name . ', userid ' . $userid . ')');
            }
        }

        $total = watch_counter::total($sessions);
        // The boundary moves by the last closed session: otherwise a session
        // closed exactly at the moment of the poll would fall into the next
        // window too.
        $polleduntil = $total['lastclosed'] > 0 ? (int) $total['lastclosed'] + 1 : $now;
        // Surgically: while the history request ran, the learner could get a
        // new ticket, and `note_grant()` already wrote the new expiry into
        // this row — rewriting the row from the copy read earlier would lose
        // it.
        watch::add_seconds((int) $row->id, (int) $total['seconds'], $polleduntil);

        return [
            'seconds' => (int) $total['seconds'],
            'idletail' => (int) $total['idletail'],
            'sessions' => count($sessions),
        ];
    }

    /**
     * Deletes streams by ripe tombstones.
     *
     * @return void
     */
    private function collect_tombstones(): void {
        global $DB;

        $due = tombstones::due();
        $done = 0;
        foreach ($due as $row) {
            try {
                connection::client()->delete_stream($row->streamname);
            } catch (agora_exception $error) {
                mtrace('mod_agora: stream ' . $row->streamname . ' could not be deleted: '
                    . $error->httpstatus . ' ' . $error->getMessage());
                continue;
            }
            $DB->delete_records('agora_tombstones', ['id' => $row->id]);
            $done++;
        }
        mtrace('mod_agora: streams deleted by tombstones: ' . $done);
    }
}
