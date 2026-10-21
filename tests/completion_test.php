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

namespace mod_agora;

use mod_agora\completion\custom_completion;
use mod_agora\local\connection;
use mod_agora\local\watch;
use mod_agora\task\sync;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * Completion by the watched duration: counting, marking, refusals, splitting the window.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\task\sync
 * @covers     \mod_agora\local\watch_counter
 * @covers     \mod_agora\completion\custom_completion
 */
final class completion_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the learner */
    private $student;

    /** @var \stdClass the activity record */
    private $agora;

    /**
     * A course with completion, a learner and an activity with the «at least 30 minutes» condition.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        connection::set_client(null);
        $CFG->enablecompletion = 1;

        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'stream_name' => 'optics-2026',
            'completion_minutes' => 30,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ]);
    }

    /**
     * Removes the stub client afterwards.
     *
     * @return void
     */
    protected function tearDown(): void {
        connection::set_client(null);
        parent::tearDown();
    }

    /**
     * The person's identifier in Agora of this test's learner.
     *
     * @return string
     */
    private function userref(): string {
        return 'hr-lms:' . $this->student->id . ':user' . $this->student->id;
    }

    /**
     * Puts the pair ticket through the shared generator helper.
     *
     * The task walks the pairs by the ticket registry, and the issuing itself
     * is a subject of another test: the ticket here is not a result of the
     * scenario, but its premise.
     *
     * @param array $overrides the ticket row fields
     * @return \stdClass
     */
    private function put_grant(array $overrides = []): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant(
            $this->agora,
            (int) $this->student->id,
            $overrides + ['jti' => 'jti-live']
        );
    }

    /**
     * The viewing history answer with sessions of the given lengths.
     *
     * The shape is as of the stand's live answer (checked 29.09.2026): the
     * `opened_at`/`closed_at` labels are whole unix seconds, there are
     * `duration` and `close_reason`. The sessions are closed explicitly
     * (`client_teardown`), so that the session length and the counted time
     * match; the idle close is checked by separate tests.
     *
     * @param array $durations the session lengths in minutes
     * @param string $reason the close reason
     * @return \GuzzleHttp\Psr7\Response
     */
    private function history(array $durations, string $reason = 'client_teardown'): \GuzzleHttp\Psr7\Response {
        $sessions = [];
        $at = time() - DAYSECS;
        foreach ($durations as $minutes) {
            $sessions[] = $this->session($at, $at + (int) round($minutes * MINSECS), $reason);
            $at += ((int) ceil($minutes) + 1) * MINSECS;
        }
        return \mod_agora_mock_central::json(200, ['sessions' => $sessions]);
    }

    /**
     * A history row in the shape of the live answer.
     *
     * @param int $opened the open time, unix seconds
     * @param int $closed the close time, unix seconds
     * @param string $reason the close reason
     * @return array
     */
    private function session(int $opened, int $closed, string $reason): array {
        return [
            'stream' => 'optics-2026',
            'user_id' => $this->userref(),
            'opened_at' => $opened,
            'closed_at' => $closed,
            'duration' => $closed - $opened,
            'close_reason' => $reason,
            'nodes' => ['3'],
        ];
    }

    /**
     * Runs the task and returns its output.
     *
     * @return string
     */
    private function run_task(): string {
        ob_start();
        (new sync())->execute();
        return (string) ob_get_clean();
    }

    /**
     * The condition state of the learner.
     *
     * @return int
     */
    private function completion_state(): int {
        $cm = get_fast_modinfo($this->course, $this->student->id)->get_cm($this->agora->cmid);
        return (new custom_completion($cm, (int) $this->student->id))->get_state('completionminutes');
    }

    /**
     * The completion mark in the core table — what the presenter will see.
     *
     * @return int|null
     */
    private function recorded_completion(): ?int {
        global $DB;

        $state = $DB->get_field('course_modules_completion', 'completionstate', [
            'coursemoduleid' => $this->agora->cmid,
            'userid' => $this->student->id,
        ]);
        return $state === false ? null : (int) $state;
    }

    /**
     * Two sessions add up and give a mark in the completion log.
     *
     * @return void
     */
    public function test_two_sessions_add_up_to_completion(): void {
        $this->put_grant();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([20, 15]),
        ]));

        $this->run_task();

        $this->assertSame(35 * MINSECS, watch::seconds((int) $this->agora->id, (int) $this->student->id));
        $this->assertSame(COMPLETION_COMPLETE, $this->completion_state());
        // The mark stands in the core table, and is not only computed on the fly.
        $this->assertSame(COMPLETION_COMPLETE, $this->recorded_completion());
    }

    /**
     * A session closed by idle timeout is counted without the minute of Agora's wait.
     *
     * The learner watched 25 minutes and closed the tab; Agora closed the
     * session after the TTL (60 seconds) from the last request. The counted
     * time is 25 minutes, not 26. The answer shape is as of the stand: the
     * history does not give `last_seen_at`.
     *
     * @return void
     */
    public function test_idle_timeout_tail_is_not_counted(): void {
        $this->put_grant();
        $opened = time() - DAYSECS;
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => \mod_agora_mock_central::json(200, ['sessions' => [
                $this->session($opened, $opened + 25 * MINSECS + 60, 'idle_timeout'),
            ]]),
        ]));

        $output = $this->run_task();

        $this->assertSame(25 * MINSECS, watch::seconds((int) $this->agora->id, (int) $this->student->id));
        // The subtracted idle time is named in the task log: otherwise «26
        // minutes in the Agora console, but 25 counted» would look like a loss.
        $this->assertStringContainsString('60 s of idle time after the last request left out', $output);
    }

    /**
     * An explicitly closed session is counted in full: it has no idle tail.
     *
     * A ticket revocation, a broadcast stop or a client close cut the session
     * at the moment of the last request, and there is nothing to subtract.
     *
     * @return void
     */
    public function test_explicitly_closed_session_counts_in_full(): void {
        $this->put_grant();
        $opened = time() - DAYSECS;
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => \mod_agora_mock_central::json(200, ['sessions' => [
                $this->session($opened, $opened + 10 * MINSECS, 'api'),
                $this->session($opened + HOURSECS, $opened + HOURSECS + 5 * MINSECS, 'stream_stopped'),
            ]]),
        ]));

        $this->run_task();

        $this->assertSame(15 * MINSECS, watch::seconds((int) $this->agora->id, (int) $this->student->id));
    }

    /**
     * A session shorter than the TTL, closed by idle, gives no negative time.
     *
     * @return void
     */
    public function test_short_idle_session_counts_zero(): void {
        $this->put_grant();
        $opened = time() - DAYSECS;
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => \mod_agora_mock_central::json(200, ['sessions' => [
                $this->session($opened, $opened + 40, 'idle_timeout'),
                $this->session($opened + HOURSECS, $opened + HOURSECS + 3 * MINSECS, 'client_teardown'),
            ]]),
        ]));

        $this->run_task();

        $this->assertSame(3 * MINSECS, watch::seconds((int) $this->agora->id, (int) $this->student->id));
    }

    /**
     * If the history gives `last_seen_at`, the end of watching is it.
     *
     * Today the history does not give this field, but the wired session type
     * of Agora carries it: once it appears, the count will go by it, not by
     * the guessed TTL.
     *
     * @return void
     */
    public function test_last_seen_at_wins_over_ttl(): void {
        $this->put_grant();
        $opened = time() - DAYSECS;
        $session = $this->session($opened, $opened + 20 * MINSECS + 65, 'idle_timeout');
        $session['last_seen_at'] = $opened + 20 * MINSECS;
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => \mod_agora_mock_central::json(200, ['sessions' => [$session]]),
        ]));

        $this->run_task();

        $this->assertSame(20 * MINSECS, watch::seconds((int) $this->agora->id, (int) $this->student->id));
    }

    /**
     * Labels as RFC 3339 strings also count: this is how the former builds gave them.
     *
     * @return void
     */
    public function test_rfc3339_marks_still_count(): void {
        $this->put_grant();
        $opened = time() - DAYSECS;
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => \mod_agora_mock_central::json(200, ['sessions' => [[
                'stream' => 'optics-2026',
                'user_id' => $this->userref(),
                'opened_at' => gmdate('c', $opened),
                'closed_at' => gmdate('c', $opened + 31 * MINSECS),
            ]]]),
        ]));

        $this->run_task();

        $this->assertSame(31 * MINSECS, watch::seconds((int) $this->agora->id, (int) $this->student->id));
        $this->assertSame(COMPLETION_COMPLETE, $this->recorded_completion());
    }

    /**
     * A dropped mark is not lost: the next pass sets it without the history.
     *
     * The first pass saves the seconds and fails on updating the mark; the
     * second pass sees the pair with the reached threshold and no mark and
     * sets it without asking the history again.
     *
     * @return void
     */
    public function test_failed_mark_is_retried_next_pass(): void {
        $this->put_grant();
        $client = \mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([35]),
        ]);
        connection::set_client($client);

        $broken = new class extends sync {
            /**
             * The mark fails, as it failed in cron without completionlib.
             *
             * @param \completion_info $completion the course completion
             * @param \stdClass $cm the course module
             * @param int $userid the learner
             * @return void
             */
            protected function mark(\completion_info $completion, \stdClass $cm, int $userid): void {
                throw new \coding_exception('completion broken by the test');
            }
        };
        ob_start();
        $broken->execute();
        $first = (string) ob_get_clean();

        $this->assertSame(35 * MINSECS, watch::seconds((int) $this->agora->id, (int) $this->student->id));
        $this->assertNull($this->recorded_completion(), 'The first pass set no completion');
        $this->assertStringContainsString('completion mark (activity ' . $this->agora->id . ', userid ' . $this->student->id
            . ') not set', $first);
        $this->assertStringContainsString('completion marks failed: 1', $first);
        $asked = count($this->history_requests($client));

        // The next pass — later: otherwise it would fall on the same second,
        // and «the pair was counted by this pass» could not be told apart from
        // the past one.
        $this->waitForSecond();
        $second = $this->run_task();

        $this->assertSame(COMPLETION_COMPLETE, $this->recorded_completion(), 'The second pass set the completion');
        $this->assertSame($asked, count($this->history_requests($client)), 'History not asked twice');
        $this->assertSame(
            35 * MINSECS,
            watch::seconds((int) $this->agora->id, (int) $this->student->id),
            'Seconds not doubled'
        );
        $this->assertStringContainsString('watching-time completion marks set: 1', $second);
    }

    /**
     * A mark that came while tracking was off is set after it is turned on.
     *
     * @return void
     */
    public function test_mark_waits_for_completion_to_be_enabled(): void {
        global $DB;

        $this->put_grant();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([35]),
        ]));
        $DB->set_field('course', 'enablecompletion', 0, ['id' => $this->course->id]);
        rebuild_course_cache((int) $this->course->id, true);

        $output = $this->run_task();
        $this->assertStringContainsString('completion is off in the course', $output);
        $this->assertNull($this->recorded_completion());

        $DB->set_field('course', 'enablecompletion', 1, ['id' => $this->course->id]);
        $this->waitForSecond();
        rebuild_course_cache((int) $this->course->id, true);
        $this->run_task();

        $this->assertSame(COMPLETION_COMPLETE, $this->recorded_completion());
    }

    /**
     * Completion was turned on three days after the ticket ended — the mark is set.
     *
     * The task deletes an extinguished ticket in a day. The pair's age is
     * computed by its row (`agora_watch.lastexpiry`), so a week after the
     * ticket is really a week, not a day.
     *
     * @return void
     */
    public function test_mark_set_three_days_after_the_ticket_expired(): void {
        global $DB;

        $this->put_grant(['expires_at' => time() - 3 * DAYSECS, 'status' => 'expired', 'token' => null]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([35]),
        ]));
        $DB->set_field('course', 'enablecompletion', 0, ['id' => $this->course->id]);
        rebuild_course_cache((int) $this->course->id, true);

        $this->run_task();

        $this->assertSame(35 * MINSECS, watch::seconds((int) $this->agora->id, (int) $this->student->id));
        $this->assertFalse($DB->record_exists('agora_grants', ['jti' => 'jti-live']), 'The redeemed ticket deleted');
        $this->assertNull($this->recorded_completion());

        $DB->set_field('course', 'enablecompletion', 1, ['id' => $this->course->id]);
        rebuild_course_cache((int) $this->course->id, true);
        $this->waitForSecond();
        $this->run_task();

        $this->assertSame(COMPLETION_COMPLETE, $this->recorded_completion(), 'The ticket is long gone, the completion stands');
    }

    /**
     * The ticket was issued through the real door, the extinguished tickets are
     * deleted, the learner was renamed — the task asks for the history under
     * the former `user_id` and sets the mark.
     *
     * The ticket is issued via `grant_manager::for_user()` (this is how the
     * activity page issues it), not by the generator: the issuing is what
     * writes the pair row.
     *
     * @return void
     */
    public function test_pair_found_by_its_own_row_after_tickets_are_gone(): void {
        global $DB;

        $original = $DB->get_field('user', 'username', ['id' => $this->student->id]);
        connection::set_client(\mod_agora_mock_central::client([
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-door',
                'token' => 'jwt-door',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            'GET /play-sessions/closed' => $this->history([35]),
        ]));
        $cm = get_fast_modinfo($this->course)->get_cm($this->agora->cmid);
        \mod_agora\local\grant_manager::for_user(
            $this->agora,
            (int) $this->student->id,
            (int) $this->course->id,
            (int) $cm->id,
            '203.0.113.7'
        );

        // Two days later: the ticket is extinguished and will be deleted by the
        // very first pass.
        $DB->set_field('agora_grants', 'expires_at', time() - 2 * DAYSECS, ['jti' => 'jti-door']);
        $DB->set_field('user', 'username', 'renamed' . $this->student->id, ['id' => $this->student->id]);

        $this->run_task();

        $this->assertFalse($DB->record_exists('agora_grants', ['jti' => 'jti-door']), 'The ticket deleted by the task');
        $asked = \mod_agora_mock_central::queries_for('/play-sessions/closed');
        $this->assertNotEmpty($asked, 'Pair history asked without tickets');
        $this->assertSame(
            'hr-lms:' . $this->student->id . ':' . $original,
            $asked[0]['user_id'],
            'Under the former user_id, not the new login'
        );
        $this->assertSame(COMPLETION_COMPLETE, $this->recorded_completion());
    }

    /**
     * A ticket issued during the history poll is not lost: the task adds the
     * seconds pointwise and does not overwrite the pair row with the copy read
     * earlier.
     *
     * @return void
     */
    public function test_ticket_issued_during_the_poll_is_not_lost(): void {
        global $DB;

        $this->put_grant();
        $later = time() + 5 * HOURSECS;
        $agoraid = (int) $this->agora->id;
        $userid = (int) $this->student->id;
        $history = $this->history([10]);
        connection::set_client(\mod_agora_mock_central::client([
            // While central answers, the learner opens the activity again and
            // gets a new ticket — the issuing writes the pair row.
            'GET /play-sessions/closed' => function () use ($agoraid, $userid, $later, $history) {
                \mod_agora\local\watch::note_grant($agoraid, $userid, 'hr-lms:x:y', $later);
                return $history;
            },
        ]));

        $this->run_task();

        $row = $DB->get_record('agora_watch', ['agoraid' => $agoraid, 'userid' => $userid]);
        $this->assertSame($later, (int) $row->lastexpiry, 'The new ticket lifetime not overwritten by the task');
        $this->assertSame(10 * MINSECS, (int) $row->watched_secs);
    }

    /**
     * The pair's `user_id` (with the login) is wiped when the pair is not
     * polled anymore; the accumulated seconds stay.
     *
     * @return void
     */
    public function test_user_ref_is_forgotten_after_the_tail_window(): void {
        global $DB;

        $this->put_grant(['expires_at' => time() - 8 * DAYSECS, 'status' => 'expired', 'token' => null]);
        $row = watch::row((int) $this->agora->id, (int) $this->student->id);
        $DB->set_field('agora_watch', 'watched_secs', 5 * MINSECS, ['id' => $row->id]);
        connection::set_client(\mod_agora_mock_central::client([]));

        $output = $this->run_task();

        $row = $DB->get_record('agora_watch', ['id' => $row->id]);
        $this->assertNull($row->user_ref);
        $this->assertSame(5 * MINSECS, (int) $row->watched_secs);
        $this->assertStringContainsString('Agora user references forgotten after the tail window: 1', $output);
    }

    /**
     * The week boundary: a pair whose ticket expired a bit less than seven
     * days ago is still polled and marked by the task.
     *
     * @return void
     */
    public function test_pair_inside_the_week_is_polled_and_marked(): void {
        $this->put_grant(['expires_at' => time() - 7 * DAYSECS + 120, 'status' => 'expired', 'token' => null]);
        $client = \mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([35]),
        ]);
        connection::set_client($client);

        $this->run_task();

        $this->assertCount(1, $this->history_requests($client));
        $this->assertSame(COMPLETION_COMPLETE, $this->recorded_completion());
    }

    /**
     * The week boundary: a pair whose ticket expired a bit more than seven
     * days ago is not polled and not marked, even if it already has seconds.
     *
     * @return void
     */
    public function test_pair_past_the_week_is_left_alone(): void {
        $this->put_grant(['expires_at' => time() - 7 * DAYSECS - 120, 'status' => 'expired', 'token' => null]);
        $row = watch::row((int) $this->agora->id, (int) $this->student->id);
        watch::add_seconds((int) $row->id, 35 * MINSECS, time() - DAYSECS);
        $client = \mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([35]),
        ]);
        connection::set_client($client);

        $this->run_task();

        $this->assertSame([], $this->history_requests($client));
        $this->assertNull($this->recorded_completion());
    }

    /**
     * A suspended learner gets no mark, and the log names it.
     *
     * @return void
     */
    public function test_suspended_learner_gets_no_mark(): void {
        global $DB;

        $this->put_grant();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([35]),
        ]));
        // The presenter switched the enrolment to «suspended».
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $this->student->id]);

        $output = $this->run_task();

        $this->assertNull($this->recorded_completion());
        $this->assertStringContainsString('learner not tracked (unenrolled or suspended), no mark: 1', $output);
    }

    /**
     * A failure when the pairs are already known is counted by pairs, not by the activity.
     *
     * @return void
     */
    public function test_failure_after_pairs_are_known_counts_pairs(): void {
        $second = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->put_grant();
        $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant(
            $this->agora,
            (int) $second->id,
            ['jti' => 'jti-second']
        );
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([35]),
        ]));

        $broken = new class extends sync {
            /**
             * The course completion is unavailable — after the pairs are found.
             *
             * @param \stdClass $agora the activity record
             * @return \completion_info
             */
            protected function completion(\stdClass $agora): \completion_info {
                throw new \coding_exception('completion broken by the test');
            }
        };
        ob_start();
        $broken->execute();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('completion marks not set for 2 pairs', $output);
        $this->assertStringContainsString('completion marks failed: 2', $output);
    }

    /**
     * A pair over the threshold is not touched by the task anymore.
     *
     * @return void
     */
    public function test_pair_over_the_threshold_is_not_polled_again(): void {
        $this->put_grant();
        $client = \mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([35]),
        ]);
        connection::set_client($client);

        $this->run_task();
        $first = count($this->history_requests($client));

        $this->run_task();

        $this->assertSame($first, count($this->history_requests($client)), 'The second pass does not ask history');
        $this->assertSame(COMPLETION_COMPLETE, $this->recorded_completion());
    }

    /**
     * A pair whose last ticket expired long ago is not polled by the task.
     *
     * @return void
     */
    public function test_long_dead_pair_is_not_polled(): void {
        $this->put_grant(['expires_at' => time() - 8 * DAYSECS, 'status' => 'expired', 'token' => null]);
        $client = \mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([35]),
        ]);
        connection::set_client($client);

        $this->run_task();

        $this->assertSame([], $this->history_requests($client));
    }

    /**
     * Not enough watched — the condition stays unfulfilled.
     *
     * @return void
     */
    public function test_not_enough_minutes_leaves_it_incomplete(): void {
        $this->put_grant();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([10]),
        ]));

        $this->run_task();

        $this->assertSame(10 * MINSECS, watch::seconds((int) $this->agora->id, (int) $this->student->id));
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion_state());
    }

    /**
     * Central is unavailable — the marks do not change, the reason is in the task log.
     *
     * @return void
     */
    public function test_central_failure_changes_nothing(): void {
        $this->put_grant();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => \mod_agora_mock_central::error(503, 'Internal', 'db is down'),
        ]));

        $output = $this->run_task();

        $this->assertSame(0, watch::seconds((int) $this->agora->id, (int) $this->student->id));
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion_state());
        $this->assertStringContainsString('viewing history unavailable', $output);
        $this->assertStringContainsString('503', $output);
        // The log carries the userid, not the person's login.
        $this->assertStringContainsString('userid ' . $this->student->id, $output);
    }

    /**
     * An empty history with a live ticket is distinguishable in the task log.
     *
     * @return void
     */
    public function test_empty_history_with_a_live_grant_is_named(): void {
        $this->put_grant();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => \mod_agora_mock_central::json(200, ['sessions' => []]),
        ]));

        $output = $this->run_task();

        $this->assertStringContainsString('no sessions for user_id, but a live ticket exists: jti=jti-live', $output);
    }

    /**
     * A full answer splits the window in half, and the halves' bounds meet.
     *
     * The sign of overflow is half of the merged sessions limit in the answer:
     * `limit` cuts the rows before merging, so «exactly limit rows» never happens.
     *
     * @return void
     */
    public function test_full_answer_splits_the_window(): void {
        $this->put_grant();

        $client = \mod_agora_mock_central::client([
            'GET /play-sessions/closed' => [
                // Exactly 500 merged sessions — the number from the contract,
                // not from the code constant: otherwise the test would fit its
                // edit.
                \mod_agora_mock_central::json(200, ['sessions' => array_fill(0, 500, [
                    'stream' => 'optics-2026',
                    'user_id' => $this->userref(),
                    'opened_at' => gmdate('c', time() - DAYSECS),
                    'closed_at' => gmdate('c', time() - DAYSECS + MINSECS),
                ])]),
                \mod_agora_mock_central::json(200, ['sessions' => []]),
                \mod_agora_mock_central::json(200, ['sessions' => []]),
            ],
        ]);
        connection::set_client($client);

        $output = $this->run_task();

        $windows = $this->history_windows();
        $this->assertCount(3, $windows, 'An overfull window causes a repeated request in halves');
        [$whole, $left, $right] = $windows;
        $this->assertSame($whole['from'], $left['from']);
        $this->assertSame($whole['to'], $right['to']);
        $this->assertSame($left['to'] + 1, $right['from'], 'The window halves meet without a gap');
        $this->assertStringContainsString('is full', $output);
    }

    /**
     * The first poll window is a day, and `limit` goes into the request.
     *
     * @return void
     */
    public function test_first_window_is_a_day(): void {
        $this->put_grant();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => \mod_agora_mock_central::json(200, ['sessions' => []]),
        ]));

        $this->run_task();

        $window = $this->history_windows()[0];
        $this->assertEqualsWithDelta(DAYSECS, $window['to'] - $window['from'], 5);
        // The answer rows limit is 1000 by the contract.
        $this->assertSame(1000, $window['limit']);
    }

    /**
     * The learner sees the accumulated time in the completion rule card.
     *
     * @return void
     */
    public function test_learner_sees_accumulated_minutes(): void {
        $this->put_grant();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([10]),
        ]));
        $this->run_task();

        $cm = get_fast_modinfo($this->course, $this->student->id)->get_cm($this->agora->cmid);
        $descriptions = (new custom_completion($cm, (int) $this->student->id))->get_custom_rule_descriptions();

        $this->assertStringContainsString(
            get_string('completionminutesprogress', 'mod_agora', (object) ['watched' => 10, 'minutes' => 30]),
            $descriptions['completionminutes']
        );
    }

    /**
     * The description of the enabled rule is given to the core for reports and the form.
     *
     * @return void
     */
    public function test_active_rule_description_is_reported(): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/agora/lib.php');
        $cm = get_fast_modinfo($this->course)->get_cm($this->agora->cmid);

        $this->assertSame(
            [get_string('completionminutesdesc', 'mod_agora', 30)],
            agora_get_completion_active_rule_descriptions($cm)
        );
    }

    /**
     * An activity without the duration condition is not touched by the task.
     *
     * @return void
     */
    public function test_element_without_the_rule_is_skipped(): void {
        global $DB;

        $DB->set_field('agora', 'completion_minutes', 0, ['id' => $this->agora->id]);
        $this->put_grant();
        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);

        $output = $this->run_task();

        $this->assertStringContainsString('no activities with a watching-time condition', $output);
        $this->assertSame([], $client->request_log());
    }

    /**
     * The task deletes extinguished ticket rows older than a day.
     *
     * @return void
     */
    public function test_old_spent_grants_are_deleted(): void {
        global $DB;

        $this->put_grant(['jti' => 'jti-fresh']);
        $this->put_grant(['jti' => 'jti-expired', 'status' => 'expired', 'token' => null,
            'expires_at' => time() - 2 * DAYSECS]);
        $this->put_grant(['jti' => 'jti-revoked', 'status' => 'revoked', 'token' => null,
            'expires_at' => time() - 2 * DAYSECS]);
        $this->put_grant(['jti' => 'jti-yesterday', 'status' => 'expired', 'token' => null,
            'expires_at' => time() - HOURSECS]);

        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => \mod_agora_mock_central::json(200, ['sessions' => []]),
        ]));
        $this->run_task();

        $this->assertFalse($DB->record_exists('agora_grants', ['jti' => 'jti-expired']));
        $this->assertFalse($DB->record_exists('agora_grants', ['jti' => 'jti-revoked']));
        $this->assertTrue($DB->record_exists('agora_grants', ['jti' => 'jti-yesterday']), 'The row lives for a day');
        $this->assertTrue($DB->record_exists('agora_grants', ['jti' => 'jti-fresh']));
    }

    /**
     * An expired ticket is extinguished and loses its JWT.
     *
     * @return void
     */
    public function test_expired_grant_loses_its_token(): void {
        global $DB;

        $this->put_grant(['jti' => 'jti-stale', 'expires_at' => time() - 60]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => \mod_agora_mock_central::json(200, ['sessions' => []]),
        ]));

        $this->run_task();

        $this->assertSame('expired', $DB->get_field('agora_grants', 'status', ['jti' => 'jti-stale']));
        $this->assertNull($DB->get_field('agora_grants', 'token', ['jti' => 'jti-stale']));
    }

    /**
     * A stub central that filters and merges the journal rows the same way as
     * the real one (the central code `play_sessions/journal.rs::history()`,
     * checked on the stand 30.09.2026): first the raw rows are selected by the
     * window — `closed_at >= from` and `opened_at <= to` — then the remaining
     * ones are merged with a pause of no more than 30 minutes.
     *
     * @param array $rows the raw journal rows: ['opened' => …, 'closed' => …]
     * @return \Closure
     */
    private function journal(array &$rows): \Closure {
        return function (\Psr\Http\Message\RequestInterface $request) use (&$rows) {
            parse_str($request->getUri()->getQuery(), $query);
            $from = (int) ($query['from'] ?? 0);
            $to = (int) ($query['to'] ?? PHP_INT_MAX);
            $picked = array_values(array_filter($rows, static function (array $row) use ($from, $to): bool {
                return $row['closed'] >= $from && $row['opened'] <= $to;
            }));
            usort($picked, static function (array $a, array $b): int {
                return $a['opened'] <=> $b['opened'];
            });
            $sessions = [];
            foreach ($picked as $row) {
                $last = count($sessions) - 1;
                if ($last >= 0 && $row['opened'] - $sessions[$last]['closed_at'] <= 30 * MINSECS) {
                    $sessions[$last]['closed_at'] = max($sessions[$last]['closed_at'], $row['closed']);
                    $sessions[$last]['duration'] = $sessions[$last]['closed_at'] - $sessions[$last]['opened_at'];
                    continue;
                }
                $sessions[] = $this->session($row['opened'], $row['closed'], 'client_teardown');
            }
            return \mod_agora_mock_central::json(200, ['sessions' => $sessions]);
        };
    }

    /**
     * The seam of two poll windows: the second pass asks for the history from
     * the second after the last close, and the already counted piece does not
     * get into it — even when Agora merges it with the next one (a pause of
     * less than half an hour).
     *
     * There is no double counting because central selects the window rows
     * before merging (checked on the stand 30.09.2026). If it merged before
     * selecting, the second pass would get a piece with the first's
     * `opened_at`, and the first ten minutes would be counted twice — this is
     * what the test holds.
     *
     * @return void
     */
    public function test_two_windows_meet_without_double_count(): void {
        $this->put_grant();
        $now = time();
        $rows = [['opened' => $now - 3000, 'closed' => $now - 2400]];
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->journal($rows),
        ]));

        $this->run_task();
        $this->assertSame(10 * MINSECS, watch::seconds((int) $this->agora->id, (int) $this->student->id));

        // The learner came back in five minutes and watched fifteen more.
        $rows[] = ['opened' => $now - 2100, 'closed' => $now - 1200];
        $this->waitForSecond();
        $this->run_task();

        $windows = $this->history_windows();
        $this->assertSame($now - 2400 + 1, $windows[1]['from'], 'The second window — from the second after the last close');
        $this->assertSame(
            25 * MINSECS,
            watch::seconds((int) $this->agora->id, (int) $this->student->id),
            'Counted 10 + 15 minutes, not 10 + 5 + 15 + 10'
        );
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion_state());
    }

    /**
     * A pair that still needs counting but has no `user_id` in Agora does not
     * fall out silently: the task log names their number.
     *
     * @return void
     */
    public function test_pairs_without_user_id_are_named(): void {
        global $DB;

        $this->put_grant();
        // The update step did not guess the `user_id` (there was no site label).
        $DB->set_field('agora_watch', 'user_ref', null, ['agoraid' => $this->agora->id]);
        $client = \mod_agora_mock_central::client(['GET /play-sessions/closed' => $this->history([35])]);
        connection::set_client($client);

        $output = $this->run_task();

        $this->assertSame([], $this->history_requests($client));
        $this->assertStringContainsString('pairs without an Agora user_id, history not asked: 1', $output);
    }

    /**
     * The history cursor `next` is read: the second page sessions are counted,
     * and the second request carries the cursor.
     *
     * @return void
     */
    public function test_history_next_page_is_read(): void {
        $this->put_grant();
        $at = time() - DAYSECS + HOURSECS;
        $client = \mod_agora_mock_central::client([
            'GET /play-sessions/closed' => [
                \mod_agora_mock_central::json(200, [
                    'sessions' => [$this->session($at, $at + 20 * MINSECS, 'client_teardown')],
                    'next' => 'page-2',
                ]),
                \mod_agora_mock_central::json(200, [
                    'sessions' => [$this->session($at + HOURSECS, $at + HOURSECS + 15 * MINSECS, 'client_teardown')],
                ]),
            ],
        ]);
        connection::set_client($client);

        $this->run_task();

        $queries = \mod_agora_mock_central::queries_for('/play-sessions/closed');
        $this->assertCount(2, $queries);
        $this->assertSame('page-2', $queries[1]['cursor'] ?? null);
        $this->assertSame(35 * MINSECS, watch::seconds((int) $this->agora->id, (int) $this->student->id));
        $this->assertSame(COMPLETION_COMPLETE, $this->recorded_completion());
    }

    /**
     * A cursor that comes a second time is an explicit refusal: the pair is not
     * counted, the poll bound does not move, the log names the reason.
     *
     * @return void
     */
    public function test_repeated_history_cursor_is_refused(): void {
        $this->put_grant();
        $at = time() - DAYSECS + HOURSECS;
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => \mod_agora_mock_central::json(200, [
                'sessions' => [$this->session($at, $at + 20 * MINSECS, 'client_teardown')],
                'next' => 'same',
            ]),
        ]));

        $output = $this->run_task();

        $row = watch::row((int) $this->agora->id, (int) $this->student->id);
        $this->assertSame(0, (int) $row->watched_secs, 'Page repeats not counted');
        $this->assertSame(0, (int) $row->polled_until, 'The poll boundary did not move');
        $this->assertStringContainsString('viewing history unavailable', $output);
        $this->assertStringContainsString(get_string('errhistorycursor', 'mod_agora'), $output);
    }

    /**
     * More than `MAX_PAGES` pages — an explicit refusal with the page count.
     *
     * @return void
     */
    public function test_too_many_history_pages_are_refused(): void {
        $this->put_grant();
        $page = 0;
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => function () use (&$page) {
                $page++;
                return \mod_agora_mock_central::json(200, ['sessions' => [], 'next' => 'page-' . $page]);
            },
        ]));

        $output = $this->run_task();

        $this->assertSame(10, $page, 'Exactly ten pages read');
        $this->assertStringContainsString(get_string('errhistorypages', 'mod_agora', 10), $output);
        $this->assertSame(0, (int) watch::row((int) $this->agora->id, (int) $this->student->id)->polled_until);
    }

    /**
     * A central refusal does not touch the pair's count, and marks by the
     * already saved seconds are set in the same pass.
     *
     * @return void
     */
    public function test_central_failure_still_marks_by_saved_seconds(): void {
        $this->put_grant();
        // This learner's threshold was already reached in a past pass, and the
        // mark did not stand then.
        $row = watch::row((int) $this->agora->id, (int) $this->student->id);
        watch::add_seconds((int) $row->id, 35 * MINSECS, time() - HOURSECS);
        // The second learner has not watched enough — the task will ask their history.
        $second = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant(
            $this->agora,
            (int) $second->id,
            ['jti' => 'jti-second']
        );
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => \mod_agora_mock_central::error(503, 'Internal', 'db is down'),
        ]));

        $output = $this->run_task();

        $this->assertSame(COMPLETION_COMPLETE, $this->recorded_completion(), 'Completion from stored seconds stands');
        $this->assertSame(0, watch::seconds((int) $this->agora->id, (int) $second->id), 'The second pair count untouched');
        $this->assertSame(0, (int) watch::row((int) $this->agora->id, (int) $second->id)->polled_until);
        $this->assertStringContainsString('viewing history unavailable', $output);
        $this->assertStringContainsString('watching-time completion marks set: 1', $output);
    }

    /**
     * A broken pair does not take the pass down: the neighbour pair is
     * counted, and the log names the skipped one.
     *
     * @return void
     */
    public function test_one_broken_pair_does_not_stop_the_pass(): void {
        $this->put_grant();
        $second = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant(
            $this->agora,
            (int) $second->id,
            ['jti' => 'jti-second', 'user_id' => 'hr-lms:' . $second->id . ':second']
        );
        $good = $this->history([35]);
        $userref = $this->userref();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => function (\Psr\Http\Message\RequestInterface $request) use ($good, $userref) {
                parse_str($request->getUri()->getQuery(), $query);
                if (($query['user_id'] ?? '') === $userref) {
                    // An answer the task will not digest: sessions not a list.
                    return \mod_agora_mock_central::json(200, ['sessions' => 'broken']);
                }
                return $good;
            },
        ]));

        $output = $this->run_task();

        $this->assertStringContainsString('pair (activity ' . $this->agora->id . ', userid ' . $this->student->id
            . ') skipped', $output);
        $this->assertSame(
            35 * MINSECS,
            watch::seconds((int) $this->agora->id, (int) $second->id),
            'The neighboring pair counted in the same pass'
        );
    }

    /**
     * The threshold is reached, but the activity waits for other conditions
     * («viewed the activity»): the log counts such pairs separately and does
     * not call them marks.
     *
     * @return void
     */
    public function test_threshold_reached_but_waiting_is_named(): void {
        global $DB;

        $DB->set_field('course_modules', 'completionview', COMPLETION_VIEW_REQUIRED, ['id' => $this->agora->cmid]);
        rebuild_course_cache((int) $this->course->id, true);
        $this->put_grant();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([35]),
        ]));

        $output = $this->run_task();

        $this->assertStringContainsString('threshold reached, activity waits for other completion conditions: 1', $output);
        $this->assertStringContainsString('watching-time completion marks set: 0', $output);
        $this->assertNotSame(COMPLETION_COMPLETE, $this->recorded_completion());
    }

    /**
     * A week is a week: the ticket expired six days ago and is already deleted
     * by the task, and the pair is polled and marked (the spec scenario).
     *
     * @return void
     */
    public function test_pair_six_days_after_the_ticket_is_polled_and_marked(): void {
        global $DB;

        $this->put_grant(['expires_at' => time() - 6 * DAYSECS, 'status' => 'expired', 'token' => null]);
        $client = \mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $this->history([35]),
        ]);
        connection::set_client($client);

        $this->run_task();

        $this->assertFalse($DB->record_exists('agora_grants', ['jti' => 'jti-live']), 'The redeemed ticket deleted');
        $this->assertCount(1, $this->history_requests($client));
        $this->assertSame(COMPLETION_COMPLETE, $this->recorded_completion());
    }

    /**
     * The history requests in the client log.
     *
     * @param \mod_agora\local\agora_client $client the client
     * @return array
     */
    private function history_requests(\mod_agora\local\agora_client $client): array {
        return array_values(array_filter($client->request_log(), function (string $line): bool {
            return $line === 'GET /central/api-v4/play-sessions/closed';
        }));
    }

    /**
     * The window bounds with which the task asked for the history.
     *
     * @return array the list of ['from' => …, 'to' => …, 'limit' => …]
     */
    private function history_windows(): array {
        $windows = [];
        foreach (\mod_agora_mock_central::requests() as $request) {
            if ($request->getUri()->getPath() !== '/central/api-v4/play-sessions/closed') {
                continue;
            }
            parse_str($request->getUri()->getQuery(), $query);
            $windows[] = [
                'from' => (int) ($query['from'] ?? 0),
                'to' => (int) ($query['to'] ?? 0),
                'limit' => (int) ($query['limit'] ?? 0),
            ];
        }
        return $windows;
    }
}
