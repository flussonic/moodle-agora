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

use mod_agora\local\connection;
use mod_agora\local\playback;
use mod_agora\local\player_view;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/fixtures/view_script.php');

/**
 * The pair ticket and the player page: the right to watch, reuse, address, errors.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\grant_manager
 * @covers     \mod_agora\local\player_view
 * @covers     \mod_agora\local\playback
 */
final class playback_test extends \advanced_testcase {
    /**
     * The activity page markup as assembled by `view.php`.
     *
     * The scene plus the `watch_page` body: the transitions there are already
     * buttons, and the test looks at the markup a human sees, not at an
     * intermediate glue.
     *
     * @param \stdClass $agora the activity record
     * @param \cm_info $cm the course module
     * @param int $userid the learner
     * @param bool $wantrecord the learner pressed «watch the recording»
     * @return string HTML
     */
    private function page(\stdClass $agora, \cm_info $cm, int $userid, bool $wantrecord = false): string {
        global $OUTPUT;

        $scene = player_view::scene($agora, $cm, $userid, $wantrecord);
        return $OUTPUT->render(new \mod_agora\output\watch_page($agora, $cm, $scene, $wantrecord));
    }
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the learner */
    private $student;

    /** @var \stdClass the activity record */
    private $agora;

    /** @var \cm_info the course module */
    private $cm;

    /**
     * A course, a learner and an activity for a broadcast; a connection to the stub Agora.
     *
     * @return void
     */
    protected function setUp(): void {
        global $PAGE;

        parent::setUp();
        $this->resetAfterTest();
        // The learner address: it is always present in the browser request, and
        // the ticket is bound to it.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        connection::set_client(null);

        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
        set_config('grantttl', 7200, 'mod_agora');
        set_config('maxconcurrent', 2, 'mod_agora');

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user(['username' => 'ivanov']);
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'stream_name' => 'optics-2026',
            'display_mode' => 'auto',
        ]);
        $this->cm = get_fast_modinfo($this->course)->get_cm($this->agora->cmid);

        // The activity page reads the admission policy from central once in 60
        // seconds. Tests that do not check it do not need the extra request.
        \cache::make('mod_agora', 'policy')->set(
            \mod_agora\local\policy::KEY,
            ['open' => false, 'fetched_at' => time()]
        );

        $PAGE->set_url('/mod/agora/view.php', ['id' => $this->agora->cmid]);
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
     * The routes of the stub Agora: the broadcast state and the ticket issue.
     *
     * @param mixed $stats the `streams/stats` answer or a list of answers
     * @param mixed $grant the ticket issue answer or a list of answers
     * @return array
     */
    private function routes($stats, $grant = null): array {
        $routes = ['GET /streams/stats/optics-2026' => $stats];
        if ($grant !== null) {
            $routes['POST /play-sessions/grants'] = $grant;
        }
        return $routes;
    }

    /**
     * The answer «broadcast is running».
     *
     * @return \GuzzleHttp\Psr7\Response
     */
    private function live_stats(): \GuzzleHttp\Psr7\Response {
        return \mod_agora_mock_central::json(200, [
            'name' => 'optics-2026',
            'status' => 'running',
            'status_since' => gmdate('c', time() - 600),
            'inputs' => [['current' => true, 'status' => 'working']],
        ]);
    }

    /**
     * The answer «broadcast ended, there is an archive».
     *
     * @return \GuzzleHttp\Psr7\Response
     */
    private function recorded_stats(): \GuzzleHttp\Psr7\Response {
        return \mod_agora_mock_central::json(200, [
            'name' => 'optics-2026',
            'status' => 'idle',
            'status_since' => '2026-09-20T11:45:30Z',
            'dvr' => [
                'recording' => false,
                'from' => '2026-09-20T10:00:00Z',
                'to' => '2026-09-20T12:00:00Z',
                'written_until' => '2026-09-20T11:45:30.250Z',
            ],
        ]);
    }

    /**
     * The answer «ticket issued».
     *
     * @param string $jti the ticket identifier
     * @return \GuzzleHttp\Psr7\Response
     */
    private function grant(string $jti = 'jti-1'): \GuzzleHttp\Psr7\Response {
        return \mod_agora_mock_central::json(201, [
            'jti' => $jti,
            'token' => 'jwt-' . $jti,
            'user_id' => 'hr-lms:1:ivanov',
            'streams' => ['optics-2026'],
            'status' => 'issued',
            'created_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + 7200),
        ]);
    }

    /**
     * An unenrolled user does not open the activity, and there are no requests to central.
     *
     * The run goes through `mod/agora/view.php` itself: the gate lives there,
     * and a test calling `require_login()` with its own hands would check the
     * Moodle core.
     *
     * @return void
     */
    public function test_outsider_gets_no_grant_and_no_central_calls(): void {
        global $DB;

        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $client = \mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant()));
        connection::set_client($client);

        $error = \mod_agora_view_script::refusal((int) $this->agora->cmid);

        $this->assertNotNull($error, 'The page must not open for a user without a recording');
        // It is exactly the course login that wraps: in the browser it is a
        // redirect to the enrolment page, in CLI it arrives as the
        // `redirecterrordetected` refusal. Take `require_login()` off the page
        // — the refusal will come from the activity capability
        // (`required_capability_exception`), and the assertions will go red.
        $this->assertInstanceOf(\moodle_exception::class, $error);
        $this->assertNotInstanceOf(\required_capability_exception::class, $error);
        $this->assertSame('redirecterrordetected', $error->errorcode);
        $this->assertSame([], $client->request_log(), 'Not a single request must go to central');
        $this->assertSame(0, $DB->count_records('agora_grants'));
    }

    /**
     * A guest does not get to the activity page, and no ticket is issued to them.
     *
     * @return void
     */
    public function test_guest_is_closed(): void {
        global $DB;

        $this->setGuestUser();
        $client = \mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant()));
        connection::set_client($client);

        $error = \mod_agora_view_script::refusal((int) $this->agora->cmid);

        $this->assertNotNull($error, 'The activity page does not open for a guest');
        $this->assertInstanceOf(\moodle_exception::class, $error);
        $this->assertNotInstanceOf(\required_capability_exception::class, $error);
        $this->assertSame('redirecterrordetected', $error->errorcode);
        $this->assertSame([], $client->request_log());
        $this->assertSame(0, $DB->count_records('agora_grants'));
    }

    /**
     * An enrolled learner opens the same page, and a ticket is issued to them.
     *
     * The second half of the pair: without it a «refuse everyone» would also
     * pass green.
     *
     * @return void
     */
    public function test_enrolled_learner_opens_the_page(): void {
        global $DB;

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant())));

        $html = \mod_agora_view_script::run((int) $this->agora->cmid);

        $this->assertStringContainsString('/playback/embed/optics-2026', $html);
        $this->assertSame(1, $DB->count_records('agora_grants', ['agoraid' => $this->agora->id]));
        // The ticket rides in the iframe address: there is nowhere to give it
        // in the Referer header.
        $this->assertMatchesRegularExpression('#<iframe[^>]+referrerpolicy="no-referrer"#', $html);
    }

    /**
     * A broadcast that ran without a recording and ended: the «this broadcast
     * was not recorded» placeholder, no player and no ticket.
     *
     * @return void
     */
    public function test_ended_without_record_says_so(): void {
        global $DB;

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes(
            \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_ended_no_record()),
            $this->grant()
        )));

        $html = \mod_agora_view_script::run((int) $this->agora->cmid);

        $this->assertStringContainsString(get_string('stateendednorecord', 'mod_agora'), $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertSame(0, $DB->count_records('agora_grants', ['agoraid' => $this->agora->id]));
    }

    /**
     * Two opens in a row — one POST for the ticket issue.
     *
     * @return void
     */
    public function test_second_view_reuses_the_grant(): void {
        global $DB;

        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant()));
        connection::set_client($client);

        $this->page($this->agora, $this->cm, (int) $this->student->id);
        $this->page($this->agora, $this->cm, (int) $this->student->id);

        $posts = array_filter($client->request_log(), function (string $line): bool {
            return $line === 'POST /central/api-v4/play-sessions/grants';
        });
        $this->assertCount(1, $posts);
        $this->assertSame(1, $DB->count_records('agora_grants', ['agoraid' => $this->agora->id]));
    }

    /**
     * The ticket composition: the scope, the person, the lifetime, the screens,
     * the note, the bind address.
     *
     * @return void
     */
    public function test_grant_body_matches_settings(): void {
        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant())));

        $this->page($this->agora, $this->cm, (int) $this->student->id);

        $body = \mod_agora_mock_central::last_body();
        $this->assertSame(['optics-2026'], $body['streams']);
        $this->assertSame('hr-lms:' . $this->student->id . ':ivanov', $body['user_id']);
        $this->assertSame(7200, $body['ttl_secs']);
        $this->assertSame(2, $body['max_concurrent']);
        $this->assertStringContainsString('cmid=' . $this->cm->id, $body['note']);
        $this->assertSame('203.0.113.7', $body['bind_ip'], 'Address binding enabled by default');
    }

    /**
     * The binding to the address switched off removes `bind_ip` from the request.
     *
     * @return void
     */
    public function test_grant_body_has_no_bind_ip_when_disabled(): void {
        set_config('bindip', 0, 'mod_agora');

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant())));

        $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertArrayNotHasKey('bind_ip', \mod_agora_mock_central::last_body());
    }

    /**
     * An expired ticket is replaced with a new one, the former is marked `expired`.
     *
     * @return void
     */
    public function test_expired_grant_is_replaced(): void {
        global $DB;

        // An expired ticket cannot be obtained by issuing: the shared generator
        // helper puts the row.
        $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant(
            $this->agora,
            (int) $this->student->id,
            ['jti' => 'jti-old', 'token' => 'jwt-old', 'expires_at' => time() - 60]
        );

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant('jti-new'))));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString('token=jwt-jti-new', $html);
        $this->assertSame('expired', $DB->get_field('agora_grants', 'status', ['jti' => 'jti-old']));
        $this->assertNull($DB->get_field('agora_grants', 'token', ['jti' => 'jti-old']));
    }

    /**
     * A ticket with a second left is not considered live: the clocks diverge.
     *
     * @return void
     */
    public function test_grant_about_to_expire_is_replaced(): void {
        $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant(
            $this->agora,
            (int) $this->student->id,
            ['jti' => 'jti-almost', 'expires_at' => time() + 30]
        );

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant('jti-fresh'))));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString('token=jwt-jti-fresh', $html);
    }

    /**
     * A revoked ticket is replaced with a new one.
     *
     * @return void
     */
    public function test_revoked_grant_is_replaced(): void {
        global $DB;

        $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant(
            $this->agora,
            (int) $this->student->id,
            ['jti' => 'jti-revoked', 'token' => null, 'status' => 'revoked']
        );

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant('jti-fresh'))));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString('token=jwt-jti-fresh', $html);
        $this->assertSame(2, $DB->count_records('agora_grants', ['agoraid' => $this->agora->id]));
    }

    /**
     * Renaming the user does not change the pair's `user_id`.
     *
     * @return void
     */
    public function test_rename_does_not_change_user_ref(): void {
        global $DB;

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes(
            $this->live_stats(),
            [$this->grant('jti-1'), $this->grant('jti-2')]
        )));

        $this->page($this->agora, $this->cm, (int) $this->student->id);
        $first = $DB->get_field('agora_grants', 'user_id', ['jti' => 'jti-1']);
        $this->assertSame('hr-lms:' . $this->student->id . ':ivanov', $first);

        // The pair ticket has expired — this is a state in the table, it cannot
        // be obtained by issuing.
        $DB->set_field('agora_grants', 'expires_at', time() - 1, ['jti' => 'jti-1']);
        $DB->set_field('user', 'username', 'ivanov2', ['id' => $this->student->id]);

        $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertSame($first, $DB->get_field('agora_grants', 'user_id', ['jti' => 'jti-2']));
    }

    /**
     * One broadcast in two courses: the learner has two tickets with one scope.
     *
     * @return void
     */
    public function test_one_stream_in_two_courses(): void {
        global $DB;

        $second = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($this->student->id, $second->id, 'student');
        $other = $this->getDataGenerator()->create_module('agora', [
            'course' => $second->id,
            'stream_name' => 'optics-2026',
        ]);
        $othercm = get_fast_modinfo($second)->get_cm($other->cmid);

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes(
            $this->live_stats(),
            [$this->grant('jti-a'), $this->grant('jti-b')]
        )));

        $this->page($this->agora, $this->cm, (int) $this->student->id);
        $this->page($other, $othercm, (int) $this->student->id);

        $this->assertSame(2, $DB->count_records('agora_grants', ['userid' => $this->student->id]));
        $this->assertSame(1, $DB->count_records('agora_grants', ['agoraid' => $this->agora->id]));
        $this->assertSame(1, $DB->count_records('agora_grants', ['agoraid' => $other->id]));
        $this->assertSame(['optics-2026'], \mod_agora_mock_central::last_body()['streams']);
    }

    /**
     * The event log carries `jti` and does not carry the ticket JWT.
     *
     * @return void
     */
    public function test_event_carries_jti_but_not_token(): void {
        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant('jti-log'))));

        $sink = $this->redirectEvents();
        $this->page($this->agora, $this->cm, (int) $this->student->id);
        $events = $sink->get_events();
        $sink->close();

        $issued = null;
        foreach ($events as $event) {
            if ($event instanceof \mod_agora\event\grant_issued) {
                $issued = $event;
            }
        }
        $this->assertNotNull($issued, 'The ticket-issue event must exist');
        $this->assertSame('jti-log', $issued->other['jti']);
        $this->assertStringNotContainsString('jwt-jti-log', json_encode($issued->get_data()));
        $this->assertStringNotContainsString('jwt-jti-log', $issued->get_description());
        // The description is in English, like the core events and the other
        // plugin events.
        $this->assertStringContainsString("issued the ticket jti='jti-log'", $issued->get_description());
    }

    /**
     * A planned archive window without `written_until` gives no record player.
     *
     * Acceptance defect A2: an empty archive player for a broadcast that never ran.
     *
     * @return void
     */
    public function test_planned_window_without_written_until_gives_no_player(): void {
        global $DB;

        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes(\mod_agora_mock_central::json(200, [
            'name' => 'optics-2026',
            'status' => 'idle',
            'dvr' => [
                'recording' => false,
                'from' => gmdate('c', time() - 3600),
                'to' => gmdate('c', time() + 3600),
            ],
        ]), $this->grant()));
        connection::set_client($client);

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString(get_string('statenotstarted', 'mod_agora'), $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertNotContains('POST /central/api-v4/play-sessions/grants', $client->request_log());
        $this->assertSame(0, $DB->count_records('agora_grants'));
    }

    /**
     * An ondemand broadcast shows the live player before the start: a viewer
     * must raise it.
     *
     * @return void
     */
    public function test_ondemand_stream_offers_the_player_before_it_starts(): void {
        global $DB;

        $DB->set_field('agora', 'ondemand', 1, ['id' => $this->agora->id]);
        $this->agora->ondemand = 1;

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes(
            \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
            $this->grant('jti-ondemand')
        )));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringContainsString('token=jwt-jti-ondemand', $html);
        $this->assertStringContainsString(get_string('placeholderondemand', 'mod_agora'), $html);
    }

    /**
     * An ondemand broadcast with a record in the «auto» mode has the live
     * player and the way to the recording.
     *
     * Otherwise a broadcast with a ready archive cannot be started from the
     * course at all: a viewer must raise the pull source, and the archive
     * would forbid it forever.
     *
     * @return void
     */
    public function test_ondemand_with_a_record_still_starts_on_demand(): void {
        global $DB;

        $DB->set_field('agora', 'ondemand', 1, ['id' => $this->agora->id]);
        $this->agora->ondemand = 1;

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client(
            $this->routes($this->recorded_stats(), $this->grant('jti-ondemand-auto'))
        ));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString('token=jwt-jti-ondemand-auto', $html);
        $this->assertStringContainsString(get_string('placeholderondemand', 'mod_agora'), $html);
        $this->assertStringNotContainsString('dvr=true', $html);
        $this->assertStringContainsString(get_string('watchrecord', 'mod_agora'), $html);
        $this->assertStringContainsString('record=1', $html);
    }

    /**
     * The «watch the recording» transition of an ondemand broadcast opens the record player.
     *
     * @return void
     */
    public function test_ondemand_record_request_opens_the_archive(): void {
        global $DB;

        $DB->set_field('agora', 'ondemand', 1, ['id' => $this->agora->id]);
        $this->agora->ondemand = 1;

        $this->setUser($this->student);
        // The node knows the archive bounds: without this route the stub
        // central answered 501, and the test was green for any page without
        // bounds.
        connection::set_client(\mod_agora_mock_central::client(
            $this->routes($this->recorded_stats(), $this->grant('jti-ondemand-dvr')) + [
                'GET /playback/dvr-range/optics-2026' => \mod_agora_mock_central::json(200, [
                    'from' => 1790022900000,
                    'to' => 1790023080000,
                ]),
            ]
        ));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id, true);

        // The spec scenario: «the page has the record player with the archive bounds».
        $this->assertStringContainsString('dvr=true', $html);
        $this->assertStringContainsString('from=1790022900000', $html);
        $this->assertStringContainsString('to=1790023080000', $html);
        $this->assertStringNotContainsString(get_string('placeholderondemand', 'mod_agora'), $html);
    }

    /**
     * In the «recording only» mode ondemand changes nothing: the record player.
     *
     * @return void
     */
    public function test_record_only_ondemand_shows_the_record(): void {
        global $DB;

        $DB->set_field('agora', 'ondemand', 1, ['id' => $this->agora->id]);
        $DB->set_field('agora', 'display_mode', 'record_only', ['id' => $this->agora->id]);
        $this->agora->ondemand = 1;
        $this->agora->display_mode = 'record_only';

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client(
            $this->routes($this->recorded_stats(), $this->grant('jti-ondemand-rec'))
        ));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString('dvr=true', $html);
        $this->assertStringNotContainsString(get_string('placeholderondemand', 'mod_agora'), $html);
    }

    /**
     * In the «live only» mode a record does not replace the ondemand start.
     *
     * @return void
     */
    public function test_live_only_ondemand_starts_even_with_a_record(): void {
        global $DB;

        $DB->set_field('agora', 'ondemand', 1, ['id' => $this->agora->id]);
        $DB->set_field('agora', 'display_mode', 'live_only', ['id' => $this->agora->id]);
        $this->agora->ondemand = 1;
        $this->agora->display_mode = 'live_only';

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client(
            $this->routes($this->recorded_stats(), $this->grant('jti-ondemand-live'))
        ));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString('token=jwt-jti-ondemand-live', $html);
        $this->assertStringContainsString(get_string('placeholderondemand', 'mod_agora'), $html);
        $this->assertStringNotContainsString('dvr=true', $html);
        // There is no recording in this mode at all: no transition to it either.
        $this->assertStringNotContainsString(get_string('watchrecord', 'mod_agora'), $html);
    }

    /**
     * With an unknown `ondemand` the placeholder stays: guessing for the
     * template is not allowed.
     *
     * @return void
     */
    public function test_unknown_ondemand_keeps_the_placeholder(): void {
        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes(
            \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
            $this->grant()
        ));
        connection::set_client($client);

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString(get_string('statenotstarted', 'mod_agora'), $html);
        $this->assertNotContains('POST /central/api-v4/play-sessions/grants', $client->request_log());
    }

    /**
     * A foreign answer instead of JSON: the editor sees its beginning, escaped
     * exactly once — the markup is not executed, but there is no «&amp;lt;» on
     * the page either.
     *
     * @return void
     */
    public function test_proxy_markup_is_escaped_once_on_the_page(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        connection::set_client(\mod_agora_mock_central::client($this->routes(
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/html'], '<!DOCTYPE html><b id="proxy">proxy</b>')
        )));

        $html = $this->page($this->agora, $this->cm, (int) $teacher->id);

        $this->assertStringNotContainsString('<b id="proxy">', $html);
        $this->assertStringContainsString('&lt;b id=', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
    }

    /**
     * A signal loss: the placeholder and the «watch the recording» transition.
     *
     * @return void
     */
    public function test_signal_lost_offers_the_record(): void {
        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes(
            \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_recorded(60)),
            $this->grant()
        )));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString(get_string('statesignallost', 'mod_agora'), $html);
        $this->assertStringContainsString(get_string('watchrecord', 'mod_agora'), $html);
        $this->assertStringContainsString('record=1', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    /**
     * «Watch the recording» on a running broadcast without an archive — the
     * placeholder and the way back.
     *
     * @return void
     */
    public function test_record_request_without_archive_offers_the_way_back(): void {
        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant()));
        connection::set_client($client);

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id, true);

        $this->assertStringContainsString(get_string('placeholdernorecordyet', 'mod_agora'), $html);
        $this->assertStringContainsString(get_string('backtolive', 'mod_agora'), $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertNotContains('POST /central/api-v4/play-sessions/grants', $client->request_log());
    }

    /**
     * In the «live only» mode the recording is not offered even by hand-crafted address.
     *
     * @return void
     */
    public function test_live_only_mode_ignores_record_request(): void {
        global $DB;

        $DB->set_field('agora', 'display_mode', 'live_only', ['id' => $this->agora->id]);
        $this->agora->display_mode = 'live_only';

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant())));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id, true);

        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringNotContainsString('dvr=true', $html);
        $this->assertStringNotContainsString(get_string('watchrecord', 'mod_agora'), $html);
    }

    /**
     * The player language is taken from the Moodle user's language.
     *
     * @return void
     */
    public function test_player_language_follows_the_user(): void {
        // The mapping of the Moodle language code to the player language:
        // there are no language packs on the stand except en, so the mapping
        // is checked by itself.
        $this->assertSame('ru', playback::lang_from('ru'));
        $this->assertSame('pt', playback::lang_from('pt_br'));
        $this->assertSame('es', playback::lang_from('es_mx'));
        $this->assertSame('en', playback::lang_from('de'));

        // The language the learner sees the course in is Russian. The site
        // language is English, and a test comparing with `current_language()`
        // without this would be green even for a player that always gets `en`.
        //
        // The language is set by the course («Force language»), not the
        // profile: the core cleans the profile to the installed language packs
        // (`PARAM_LANG`), and only `en` is installed in the test environment.
        // For the plugin it is the same path — the page's `current_language()`.
        global $DB;
        $DB->set_field('course', 'lang', 'ru', ['id' => $this->course->id]);
        rebuild_course_cache((int) $this->course->id, true);
        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant())));

        $html = \mod_agora_view_script::run((int) $this->agora->cmid);

        // The learner's page language goes into the player address, not the site language.
        $this->assertMatchesRegularExpression('#<iframe[^>]+src="[^"]*lang=ru#', $html);
    }

    /**
     * The page markup has no account logins and passwords.
     *
     * @return void
     */
    public function test_account_secrets_are_not_in_html(): void {
        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant())));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString('token=jwt-jti-1', $html);
        $this->assertStringNotContainsString('moodle-player', $html);
        $this->assertStringNotContainsString('moodle-admin', $html);
        $this->assertStringNotContainsString('p1', $html);
    }

    /**
     * The learner gets a phrase and a code; the editor — the code, error.code
     * and error.message; the full answer body — only in the event log.
     *
     * @return void
     */
    public function test_error_differs_for_viewer_and_editor(): void {
        $message = 'ERROR: relation "streams" does not exist';
        $fail = \mod_agora_mock_central::json(503, [
            'error' => ['code' => 'Internal', 'message' => $message],
            'trace' => 'SECRET-TRACE-FROM-CENTRAL',
        ]);

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($fail)));
        $sink = $this->redirectEvents();
        $viewerhtml = $this->page($this->agora, $this->cm, (int) $this->student->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertStringContainsString(get_string('errforviewer', 'mod_agora', 'AG-503'), $viewerhtml);
        $this->assertStringNotContainsString($message, $viewerhtml);
        $this->assertStringNotContainsString('SECRET-TRACE-FROM-CENTRAL', $viewerhtml);

        $logged = null;
        foreach ($events as $event) {
            if ($event instanceof \mod_agora\event\central_failed) {
                $logged = $event;
            }
        }
        $this->assertNotNull($logged, 'central refusal must reach the event log');
        $this->assertStringContainsString('SECRET-TRACE-FROM-CENTRAL', $logged->get_description());

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        \cache::make('mod_agora', 'streamstate')->purge();
        connection::set_client(\mod_agora_mock_central::client($this->routes($fail)));
        $editorhtml = $this->page($this->agora, $this->cm, (int) $teacher->id);

        $this->assertStringContainsString('503', $editorhtml);
        $this->assertStringContainsString('Internal', $editorhtml);
        $this->assertStringContainsString($message, $editorhtml);
        // No full answer bodies on the page: the other refusal fields are not shown.
        $this->assertStringNotContainsString('SECRET-TRACE-FROM-CENTRAL', $editorhtml);
        // The Agora console — only for the site administrator, not shown to the presenter.
        $this->assertStringNotContainsString(get_string('openinconsole', 'mod_agora'), $editorhtml);

        // The site administrator gets the link and the broadcast route.
        $this->setAdminUser();
        \cache::make('mod_agora', 'streamstate')->purge();
        connection::set_client(\mod_agora_mock_central::client($this->routes($fail)));
        $adminhtml = $this->page($this->agora, $this->cm, (int) $teacher->id);

        $this->assertStringContainsString(get_string('openinconsole', 'mod_agora'), $adminhtml);
        // The console is a single-page application: the broadcast route lives
        // after `#`, an address without the hash is unknown to the console
        // server (404 on the stand).
        $this->assertStringContainsString('/#/streams/' . rawurlencode($this->agora->stream_name), $adminhtml);
    }

    /**
     * The 401 refusal: the learner gets the AG-401 code, the editor — the
     * code, error.code and error.message.
     *
     * @return void
     */
    public function test_401_error_differs_for_viewer_and_editor(): void {
        $fail = \mod_agora_mock_central::error(401, 'unauthorized', 'bad credentials');

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($fail)));
        $viewerhtml = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString(get_string('errforviewer', 'mod_agora', 'AG-401'), $viewerhtml);
        $this->assertStringNotContainsString('unauthorized', $viewerhtml);

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        \cache::make('mod_agora', 'streamstate')->purge();
        connection::set_client(\mod_agora_mock_central::client($this->routes($fail)));
        $editorhtml = $this->page($this->agora, $this->cm, (int) $teacher->id);

        $this->assertStringContainsString('401', $editorhtml);
        $this->assertStringContainsString('unauthorized', $editorhtml);
        $this->assertStringContainsString('bad credentials', $editorhtml);
    }

    /**
     * The link to the Agora panel is seen only by the site administrator: not
     * by the learner, not by the editing presenter.
     *
     * @return void
     */
    public function test_console_link_is_for_site_admins_only(): void {
        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->live_stats(), $this->grant())));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringNotContainsString(get_string('openinconsole', 'mod_agora'), $html);
        $this->assertStringNotContainsString('/streams/optics-2026', $html);

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        $html = $this->page($this->agora, $this->cm, (int) $teacher->id);

        $this->assertStringNotContainsString(
            get_string('openinconsole', 'mod_agora'),
            $html,
            'The teacher is not shown the console link'
        );

        $this->setAdminUser();
        $html = $this->page($this->agora, $this->cm, (int) $teacher->id);
        $this->assertStringContainsString(
            get_string('openinconsole', 'mod_agora'),
            $html,
            'The admin sees the link'
        );
    }

    /**
     * No broadcast in Agora — the placeholder names the broadcast.
     *
     * @return void
     */
    public function test_missing_stream_is_named(): void {
        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client(
            $this->routes(\mod_agora_mock_central::error(404, 'not_found', 'no such stream'))
        ));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString(
            get_string('placeholderstreammissing', 'mod_agora', 'optics-2026'),
            $html
        );
    }

    /**
     * The broadcast name from Agora goes into the markup escaped.
     *
     * The expectation is a ready escaped string, not `s($name)`: otherwise the
     * test would repeat the same function as the code and pass with any of its
     * behaviour.
     *
     * @return void
     */
    public function test_stream_name_from_agora_is_escaped(): void {
        global $DB;

        $name = 'lec<script>alert(1)</script>';
        $DB->set_field('agora', 'stream_name', $name, ['id' => $this->agora->id]);
        $this->agora->stream_name = $name;

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/*' => \mod_agora_mock_central::error(404, 'not_found', 'no such stream'),
        ]));

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString('lec&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    /**
     * The editor sees the `error.message` from central as text, not as markup.
     *
     * @return void
     */
    public function test_error_message_from_agora_is_escaped(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        connection::set_client(\mod_agora_mock_central::client($this->routes(
            \mod_agora_mock_central::error(503, 'Internal', 'boom <script>alert("x")</script> "quoted"')
        )));

        $html = $this->page($this->agora, $this->cm, (int) $teacher->id);

        $this->assertStringContainsString('boom &lt;script&gt;alert("x")&lt;/script&gt; "quoted"', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    /**
     * The «recording only» mode during a broadcast shows the placeholder, not the player.
     *
     * @return void
     */
    public function test_record_only_mode_shows_placeholder_during_live(): void {
        global $DB;

        $DB->set_field('agora', 'display_mode', 'record_only', ['id' => $this->agora->id]);
        $this->agora->display_mode = 'record_only';

        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes($this->live_stats()));
        connection::set_client($client);

        $html = $this->page($this->agora, $this->cm, (int) $this->student->id);

        $this->assertStringContainsString(get_string('placeholdernorecord', 'mod_agora'), $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertSame(0, $DB->count_records('agora_grants'));
    }

    /**
     * The player address is assembled from the host, the broadcast name, the
     * ticket and the language.
     *
     * @return void
     */
    public function test_embed_url_assembly(): void {
        $url = playback::embed_url('https://agora.example.com/', 'optics-2026', 'jwt-1', [
            'dvr' => 'true',
            'from' => 1000,
            'to' => 2000,
        ])->out(false);

        $this->assertStringStartsWith('https://agora.example.com/playback/embed/optics-2026?', $url);
        $this->assertStringContainsString('token=jwt-1', $url);
        $this->assertStringContainsString('dvr=true', $url);
        $this->assertStringContainsString('from=1000', $url);
        $this->assertStringContainsString('to=2000', $url);
        $this->assertMatchesRegularExpression('/lang=(en|ru|es|pt)/', $url);
    }

    /**
     * The site label is not set — the ticket is not requested, the refusal names the field.
     *
     * @return void
     */
    public function test_missing_site_label_names_the_field(): void {
        set_config('sitelabel', '', 'mod_agora');

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        $client = \mod_agora_mock_central::client($this->routes($this->live_stats()));
        connection::set_client($client);

        $html = $this->page($this->agora, $this->cm, (int) $teacher->id);

        $this->assertNotContains('POST /central/api-v4/play-sessions/grants', $client->request_log());
        $this->assertStringContainsString(get_string('settingsitelabel', 'mod_agora'), $html);
    }

    /**
     * Deleting an activity revokes its live tickets with a DELETE call.
     *
     * @return void
     */
    public function test_delete_instance_revokes_live_grants(): void {
        global $DB;

        $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant(
            $this->agora,
            (int) $this->student->id,
            ['jti' => 'jti-live']
        );

        $client = \mod_agora_mock_central::client([
            'DELETE /play-sessions/grants/jti-live' => new \GuzzleHttp\Psr7\Response(204, [], ''),
        ]);
        connection::set_client($client);

        course_delete_module($this->agora->cmid);

        $this->assertContains('DELETE /central/api-v4/play-sessions/grants/jti-live', $client->request_log());
        $this->assertSame(0, $DB->count_records('agora_grants', ['agoraid' => $this->agora->id]));
    }

    /**
     * Deleting the whole course revokes the tickets of its activities.
     *
     * @return void
     */
    public function test_course_delete_revokes_grants(): void {
        global $DB;

        $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant(
            $this->agora,
            (int) $this->student->id,
            ['jti' => 'jti-course']
        );

        $client = \mod_agora_mock_central::client([
            'DELETE /play-sessions/grants/jti-course' => new \GuzzleHttp\Psr7\Response(204, [], ''),
        ]);
        connection::set_client($client);

        delete_course($this->course, false);

        $this->assertContains('DELETE /central/api-v4/play-sessions/grants/jti-course', $client->request_log());
        $this->assertSame(0, $DB->count_records('agora_grants'));
    }
}
