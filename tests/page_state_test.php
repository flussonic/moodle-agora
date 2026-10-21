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

use mod_agora\external\get_stream_state;
use mod_agora\local\connection;
use mod_agora\local\stream_state;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/fixtures/cli_process.php');
require_once(__DIR__ . '/fixtures/view_script.php');

/**
 * The activity page takes the state of its broadcast fresh — and does not make a herd.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\stream_state
 * @covers     \mod_agora\external\get_stream_state
 * @covers     \mod_agora\local\player_view
 */
final class page_state_test extends \advanced_testcase {
    /**
     * A connection to the stub Agora.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        connection::set_client(null);
        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
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
     * Ages the value in the state cache.
     *
     * A direct write into MUC is the only way to get the needed age: through
     * the user door the value is always fresh, and waiting fifteen seconds in
     * a test makes no sense.
     *
     * @param string $name the broadcast name
     * @param int $seconds by how many seconds to age it
     * @return void
     */
    private static function age_entry(string $name, int $seconds): void {
        $cache = \cache::make('mod_agora', 'streamstate');
        $entry = $cache->get($name);
        $entry['fetched_at'] = time() - $seconds;
        $cache->set($name, $entry);
        $attempt = \cache::make('mod_agora', 'stateattempt');
        if ($attempt->get($name) !== false) {
            $attempt->set($name, time() - $seconds);
        }
    }

    /**
     * The state requests in the client log.
     *
     * @param \mod_agora\local\agora_client $client the client
     * @return int
     */
    private static function stats_requests(\mod_agora\local\agora_client $client): int {
        return count(array_filter($client->request_log(), function (string $line): bool {
            return strpos($line, '/streams/stats/') !== false;
        }));
    }

    /**
     * The broadcast started — a page opened 16 seconds after the previous
     * answer sees it right away, not in a minute.
     *
     * This is how a learner came to the lecture start on the stand: the
     * broadcast already had `running`, and the page still showed «the broadcast
     * has not started yet» for another minute.
     *
     * @return void
     */
    public function test_stale_value_is_read_synchronously(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => [
                \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
                \mod_agora_mock_central::live_stats(),
            ],
        ]);
        connection::set_client($client);
        $this->assertSame(stream_state::NOT_STARTED, stream_state::for_page('optics-2026')->state);

        self::age_entry('optics-2026', stream_state::PAGE_FRESH + 1);

        $this->assertSame(stream_state::LIVE, stream_state::for_page('optics-2026')->state);
        $this->assertSame(2, self::stats_requests($client));
        // The fresh value landed in the shared cache: the course page sees it too.
        $this->assertSame(stream_state::LIVE, stream_state::for_stream('optics-2026')->state);
        $this->assertSame(2, self::stats_requests($client));
    }

    /**
     * A learner coming to the lecture start gets the live player, though the
     * cache last saw the broadcast «not started» 20 seconds ago.
     *
     * The page is opened by the real `view.php`: this is how the learner opens it.
     *
     * @return void
     */
    public function test_listener_at_the_start_gets_the_live_player(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
            'display_mode' => 'auto',
        ]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => [
                \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
                \mod_agora_mock_central::live_stats(),
            ],
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-1',
                'token' => 'jwt-1',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
        ]));
        // The course page cached «not started» a minute ago.
        $this->assertSame(stream_state::NOT_STARTED, stream_state::for_stream('optics-2026')->state);
        self::age_entry('optics-2026', 20);

        $this->setUser($student);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $html = \mod_agora_view_script::run((int) $agora->cmid);

        $this->assertStringContainsString('class="mod-agora-player"', $html);
        $this->assertStringNotContainsString(get_string('statenotstarted', 'mod_agora'), $html);
        $this->assertMatchesRegularExpression('#data-region="agora-scene"[^>]*data-kind="live"#', $html);
    }

    /**
     * A value younger than the threshold is taken by the page from the cache.
     *
     * @return void
     */
    public function test_fresh_value_is_not_fetched_again(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]);
        connection::set_client($client);
        stream_state::for_page('optics-2026');

        self::age_entry('optics-2026', stream_state::PAGE_FRESH - 5);
        stream_state::for_page('optics-2026');
        stream_state::for_page('optics-2026');

        $this->assertSame(1, self::stats_requests($client));
    }

    /**
     * A central refusal also counts as an attempt: the next show within the
     * threshold does not go to central and serves the former value.
     *
     * @return void
     */
    public function test_failed_attempt_is_not_repeated_within_the_threshold(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => [
                \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
                \mod_agora_mock_central::error(503, 'Internal', 'down'),
                \mod_agora_mock_central::live_stats(),
            ],
        ]);
        connection::set_client($client);
        stream_state::for_page('optics-2026');
        self::age_entry('optics-2026', stream_state::PAGE_FRESH + 1);

        // The refusal does not spoil the former value.
        $this->assertSame(stream_state::NOT_STARTED, stream_state::for_page('optics-2026')->state);
        $this->assertSame(2, self::stats_requests($client));

        // A hundred learners with central down — not a hundred requests.
        for ($i = 0; $i < 10; $i++) {
            stream_state::for_page('optics-2026');
        }
        $this->assertSame(2, self::stats_requests($client));

        // The threshold has passed — the page asks again.
        self::age_entry('optics-2026', stream_state::PAGE_FRESH + 1);
        $this->assertSame(stream_state::LIVE, stream_state::for_page('optics-2026')->state);
        $this->assertSame(3, self::stats_requests($client));
    }

    /**
     * The page's synchronous request is time-limited: 3 seconds for the
     * connection, 5 for the answer.
     *
     * @return void
     */
    public function test_page_request_has_short_limits(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));

        stream_state::for_page('optics-2026');

        $options = \mod_agora_mock_central::options_for('/streams/stats/');
        $this->assertSame(stream_state::CONNECT_TIMEOUT, $options['connect_timeout']);
        $this->assertSame(stream_state::PAGE_FETCH_TIMEOUT, $options['timeout']);
    }

    /**
     * A herd: several processes open the page with an expired state at once —
     * one request goes to central, and all see its answer.
     *
     * The processes are real (`tests/fixtures/cli_runner.php`): the lock and
     * the cache are checked between processes, as between the learners'
     * requests, and central answers with a delay — the window in which the
     * others manage to come.
     *
     * @return void
     */
    public function test_herd_of_pages_makes_one_request(): void {
        $this->preventResetByRollback();

        \mod_agora_cli_process::run('warmup', []);

        $job = [
            'stream' => 'optics-2026',
            'delay' => 0.5,
            'routes' => [
                'GET /streams/stats/optics-2026' => [
                    ['status' => 200, 'body' => \mod_agora_mock_central::stats_live()],
                ],
            ],
        ];
        $herd = [];
        for ($i = 0; $i < 4; $i++) {
            $herd[] = new \mod_agora_cli_process('pagestate', $job);
        }
        foreach ($herd as $process) {
            $process->wait_for('ready');
        }
        foreach ($herd as $process) {
            $process->send('go');
        }

        $requests = 0;
        foreach ($herd as $process) {
            $answer = $process->finish();
            $this->assertSame(stream_state::LIVE, $answer['state'] ?? null, $answer['stderr']);
            $requests += count(array_filter($answer['requests'], function (string $line): bool {
                return strpos($line, '/streams/stats/') !== false;
            }));
        }
        $this->assertSame(1, $requests, 'One state request per broadcast no matter how many pages open');
    }

    /**
     * A herd for the broadcast's service ticket: four processes open the
     * recordings of one broadcast with a cold table — one service ticket is issued.
     *
     * @return void
     */
    public function test_herd_issues_one_service_ticket(): void {
        global $DB;

        $this->preventResetByRollback();
        \mod_agora_cli_process::run('warmup', []);

        $job = [
            'stream' => 'optics-2026',
            'delay' => 0.5,
            'routes' => [
                'POST /play-sessions/grants' => [
                    ['status' => 201, 'body' => [
                        'jti' => 'jti-service', 'token' => 'jwt-service', 'expires_at' => gmdate('c', time() + HOURSECS),
                    ]],
                ],
            ],
        ];
        $herd = [];
        for ($i = 0; $i < 4; $i++) {
            $herd[] = new \mod_agora_cli_process('servicegrant', $job);
        }
        foreach ($herd as $process) {
            $process->wait_for('ready');
        }
        foreach ($herd as $process) {
            $process->send('go');
        }

        $issued = 0;
        $answers = [];
        foreach ($herd as $process) {
            $answers[] = $process->finish();
        }
        $rows = $DB->count_records('agora_grants', ['purpose' => 'preview']);
        // The rows were inserted by other processes: the PHPUnit reset does not
        // see them — we remove them ourselves and before the checks, so that a
        // failed test does not leak into the neighbours.
        $DB->delete_records('agora_grants');
        foreach ($answers as $answer) {
            $this->assertSame('jwt-service', $answer['token'] ?? null, ($answer['error'] ?? '') . $answer['stderr']);
            $issued += count(array_filter($answer['requests'], function (string $line): bool {
                return $line === 'POST /central/api-v4/play-sessions/grants';
            }));
        }
        $this->assertSame(1, $issued, 'One service ticket per broadcast no matter how many processes come');
        $this->assertSame(1, $rows);
    }

    /**
     * The service ticket issue lock is held longer than the wait — the `AG-BUSY`
     * refusal with its own operation, and the log does not blame Agora for it.
     *
     * The lock is held by a real neighbour process: in a single process the
     * core lock is re-taken without waiting.
     *
     * @return void
     */
    public function test_busy_service_lock_is_named_and_not_blamed_on_agora(): void {
        $this->preventResetByRollback();
        \mod_agora_cli_process::run('warmup', []);
        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);

        $holder = new \mod_agora_cli_process('holdservicelock', ['stream' => 'optics-2026']);
        $holder->wait_for('locked');
        try {
            \mod_agora\local\grant_manager::for_preview('optics-2026');
            $this->fail('Lock held — no ticket must be issued');
        } catch (\mod_agora\local\agora_exception $error) {
            $this->assertSame('busy', $error->reason);
            $this->assertSame('opissueservice', $error->operation);
            $this->assertSame('AG-BUSY', $error->short_code());
        } finally {
            $holder->send('go');
            $holder->finish();
        }
        $this->assertSame([], $client->request_log(), 'No central request for a ticket');

        $sink = $this->redirectEvents();
        \mod_agora\local\failure::log($error, \context_system::instance(), 'oparchiverange', 'service');
        $event = $sink->get_events()[0];
        $sink->close();
        $this->assertStringContainsString(
            "Moodle did not get to 'issue the service ticket of a stream (opissueservice)'",
            $event->get_description()
        );
        $this->assertStringContainsString('not a refusal by Agora', $event->get_description());
        $this->assertStringNotContainsString('Agora refused', $event->get_description());
    }

    /**
     * An enrolled learner without the `mod/agora:view` capability on the
     * activity: neither the poll, nor the fragment, nor a single request to central.
     *
     * @return void
     */
    public function test_poll_and_fragment_are_closed_without_the_view_capability(): void {
        global $DB;

        [$agora, $client] = $this->fragment_setup(false);
        $student = $this->getDataGenerator()->create_and_enrol(get_course($agora->course), 'student');
        $context = \context_module::instance((int) $agora->cmid);
        $role = $DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability('mod/agora:view', CAP_PROHIBIT, $role, $context->id, true);
        $this->setUser($student);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        try {
            get_stream_state::execute((int) $agora->cmid);
            $this->fail('The poll answered without a capability');
        } catch (\moodle_exception $e) {
            // It is the core's `require_login()` that refuses: without
            // `mod/agora:view` the activity is inaccessible to the user
            // (`uservisible`), the own capability check is never reached.
            $this->assertSame([], $client->request_log(), 'Poll: no central request');
        }
        try {
            $this->fragment($agora);
            $this->fail('Fragment served without a capability');
        } catch (\moodle_exception $e) {
            $this->assertSame([], $client->request_log(), 'Fragment: no central request');
        }
        $this->assertSame(0, $DB->count_records('agora_grants'));
    }

    /**
     * A central refusal received by the poll lands in the event log the same
     * way as from the page — with the named operation.
     *
     * @return void
     */
    public function test_poll_logs_the_central_failure(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
        ]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::error(503, 'Internal', 'db is down'),
        ]));
        $this->setUser($student);

        $sink = $this->redirectEvents();
        $answer = get_stream_state::execute((int) $agora->cmid);
        $events = array_values(array_filter($sink->get_events(), static function (\core\event\base $event): bool {
            return $event instanceof \mod_agora\event\central_failed;
        }));
        $sink->close();

        $this->assertSame('unknown', $answer['state']);
        $this->assertCount(1, $events, 'The poll refusal recorded');
        $this->assertSame('opstreamstats', $events[0]->other['operation']);
    }

    /**
     * The poll of the open page gives the state and what the scene would show.
     *
     * @return void
     */
    public function test_poll_answers_state_and_kind(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
            'display_mode' => 'auto',
        ]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));
        $this->setUser($student);

        $answer = get_stream_state::execute((int) $agora->cmid);
        $answer = \core_external\external_api::clean_returnvalue(get_stream_state::execute_returns(), $answer);

        $this->assertSame('live', $answer['state']);
        $this->assertSame('live', $answer['kind']);
        $this->assertSame(get_string('badgelive', 'mod_agora'), $answer['badge']);
        $this->assertArrayNotHasKey('token', $answer, 'No ticket in the poll answer');
    }

    /**
     * The poll is closed to one the activity is closed to.
     *
     * @return void
     */
    public function test_poll_is_closed_to_an_outsider(): void {
        $course = $this->getDataGenerator()->create_course();
        $outsider = $this->getDataGenerator()->create_user();
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
        ]);
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]);
        connection::set_client($client);
        $this->setUser($outsider);

        try {
            get_stream_state::execute((int) $agora->cmid);
            $this->fail('The poll must not answer for a non-enrolled user');
        } catch (\require_login_exception $e) {
            $this->assertSame([], $client->request_log(), 'No central request for a foreign user');
        }
    }

    /**
     * The poll is closed to the enrolled too, if the activity is inaccessible to them.
     *
     * @return void
     */
    public function test_poll_is_closed_when_the_element_is_hidden(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
            'visible' => 0,
        ]);
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]);
        connection::set_client($client);
        $this->setUser($student);

        try {
            get_stream_state::execute((int) $agora->cmid);
            $this->fail('The poll does not serve a hidden activity');
        } catch (\require_login_exception $e) {
            $this->assertSame([], $client->request_log(), 'No central request for a hidden activity');
        }
    }

    /**
     * The poll is closed to a guest and to one who is not logged in.
     *
     * @return void
     */
    public function test_poll_is_closed_to_a_guest_and_to_nobody(): void {
        $course = $this->getDataGenerator()->create_course();
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
        ]);
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]);
        connection::set_client($client);

        foreach (['guest', 'nobody'] as $who) {
            if ($who === 'guest') {
                $this->setGuestUser();
            } else {
                $this->setUser(null);
            }
            try {
                get_stream_state::execute((int) $agora->cmid);
                $this->fail('The poll answered: ' . $who);
            } catch (\moodle_exception $e) {
                $this->assertSame([], $client->request_log(), 'No central request: ' . $who);
            }
        }
    }

    /**
     * The scene fragment gives the live player to the enrolled learner — by the
     * same door as the open page (`core_get_fragment`).
     *
     * @return void
     */
    public function test_fragment_gives_the_live_player_to_a_learner(): void {
        [$agora, $client] = $this->fragment_setup(false);
        $student = $this->getDataGenerator()->create_and_enrol(get_course($agora->course), 'student');
        $this->setUser($student);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $answer = $this->fragment($agora);

        $this->assertStringContainsString('class="mod-agora-player"', $answer['html']);
        $this->assertContains('POST /central/api-v4/play-sessions/grants', $client->request_log());
    }

    /**
     * The cases when the scene fragment must refuse.
     *
     * @return array
     */
    public static function fragment_refusals(): array {
        return [
            'hidden activity' => ['hidden'],
            'not enrolled' => ['outsider'],
            'guest' => ['guest'],
            'anonymous' => ['nobody'],
        ];
    }

    /**
     * The scene fragment is closed to one the activity is closed to: neither a
     * state request, nor a ticket.
     *
     * @dataProvider fragment_refusals
     * @param string $who who asks
     * @return void
     */
    public function test_fragment_is_closed(string $who): void {
        [$agora, $client] = $this->fragment_setup($who === 'hidden');
        $course = get_course($agora->course);
        if ($who === 'hidden') {
            $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        } else if ($who === 'outsider') {
            $this->setUser($this->getDataGenerator()->create_user());
        } else if ($who === 'guest') {
            $this->setGuestUser();
        } else {
            $this->setUser(null);
        }
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        try {
            $this->fragment($agora);
            $this->fail('Fragment served: ' . $who);
        } catch (\moodle_exception $e) {
            $this->assertSame([], $client->request_log(), 'Neither state nor ticket: ' . $who);
        }
        $this->assertSame(0, $GLOBALS['DB']->count_records('agora_grants'));
    }

    /**
     * An activity on a running broadcast and the stub central for the fragment.
     *
     * @param bool $hidden the activity is hidden from learners
     * @return array [the activity record, the client]
     */
    private function fragment_setup(bool $hidden): array {
        $course = $this->getDataGenerator()->create_course();
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
            'display_mode' => 'auto',
            'visible' => $hidden ? 0 : 1,
        ]);
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-1',
                'token' => 'jwt-1',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
        ]);
        connection::set_client($client);
        return [$agora, $client];
    }

    /**
     * The scene fragment through the core external function — as the page takes it.
     *
     * @param \stdClass $agora the activity record
     * @return array ['html' => …, 'javascript' => …]
     */
    private function fragment(\stdClass $agora): array {
        global $CFG;

        require_once($CFG->libdir . '/external/externallib.php');
        $context = \context_module::instance((int) $agora->cmid);
        return \core_external::get_fragment('mod_agora', 'scene', $context->id, []);
    }

    /**
     * The page cases: the broadcast state, the address parameters, what the
     * scene shows and whether the page asks if the broadcast has started.
     *
     * @return array
     */
    public static function page_cases(): array {
        return [
            'not started — placeholder polls' => ['not_started', [], 'placeholder', true],
            'ended with recording — recording polls too' => ['recorded', [], 'record', true],
            'live — live player does not poll' => ['live', [], 'live', false],
            'recording by button — does not poll' => ['recorded', ['record' => 1], 'record', false],
        ];
    }

    /**
     * A page without a live player asks for the poll, with one — does not.
     *
     * The scene and the poll data are built from one state. The page is opened
     * by the real `view.php`.
     *
     * @dataProvider page_cases
     * @param string $stats which state central gives
     * @param array $params the page address parameters
     * @param string $kind what the scene must show
     * @param bool $poll whether the page must ask
     * @return void
     */
    public function test_page_asks_to_poll_only_without_a_live_player(
        string $stats,
        array $params,
        string $kind,
        bool $poll
    ): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
            'display_mode' => 'auto',
        ]);
        $answers = [
            'not_started' => \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
            'recorded' => \mod_agora_mock_central::recorded_stats(7200),
            'live' => \mod_agora_mock_central::live_stats(),
        ];
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => $answers[$stats],
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-1',
                'token' => 'jwt-1',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            'GET /playback/dvr-range/*' => \mod_agora_mock_central::json(200, ['from' => 1, 'to' => 2]),
        ]));
        // The learner address — as in a real request: without it the ticket
        // binding to the address writes a warning.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $this->setUser($student);

        $html = \mod_agora_view_script::run((int) $agora->cmid, $params);

        $this->assertMatchesRegularExpression('#data-region="agora-scene"[^>]*data-kind="' . $kind . '"#', $html);
        if ($poll) {
            // Fifteen seconds is the poll interval from the design, not from the constant.
            $this->assertMatchesRegularExpression('#data-region="agora-scene"[^>]*data-poll="15"#', $html);
        } else {
            $this->assertDoesNotMatchRegularExpression('#data-region="agora-scene"[^>]*data-poll=#', $html);
        }
    }

    /**
     * In the «recording only» mode the page does not ask about the broadcast
     * start: the live player will not come there.
     *
     * @return void
     */
    public function test_record_only_page_does_not_poll(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
            'display_mode' => 'record_only',
        ]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::json(
                200,
                \mod_agora_mock_central::stats_not_started()
            ),
        ]));
        $this->setUser($student);

        $html = \mod_agora_view_script::run((int) $agora->cmid);

        $this->assertMatchesRegularExpression('#data-region="agora-scene"[^>]*data-kind="placeholder"#', $html);
        $this->assertDoesNotMatchRegularExpression('#data-region="agora-scene"[^>]*data-poll=#', $html);
    }

    /**
     * The state request lock is held by a neighbour page longer than the wait:
     * this page serves the former value and does not go to central; without a
     * value — «unknown» with the `AG-BUSY` code.
     *
     * The lock is held by a real neighbour process: in a single process the
     * core lock is re-taken without waiting.
     *
     * @return void
     */
    public function test_busy_state_lock_gives_the_previous_value(): void {
        $this->preventResetByRollback();
        \mod_agora_cli_process::run('warmup', []);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::json(
                200,
                \mod_agora_mock_central::stats_not_started()
            ),
        ]));
        stream_state::for_stream('optics-2026');
        self::age_entry('optics-2026', 30);
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]);
        connection::set_client($client);

        $holder = new \mod_agora_cli_process('holdstatelock', ['stream' => 'optics-2026']);
        $holder->wait_for('locked');
        try {
            $previous = stream_state::for_page('optics-2026');
            \cache::make('mod_agora', 'streamstate')->delete('optics-2026');
            $nothing = stream_state::for_page('optics-2026');
        } finally {
            $holder->send('go');
            $holder->finish();
        }

        $this->assertSame(stream_state::NOT_STARTED, $previous->state, 'The previous value, not «unknown»');
        $this->assertSame(stream_state::UNKNOWN, $nothing->state);
        $this->assertSame('AG-BUSY', $nothing->reason);
        $this->assertSame(0, self::stats_requests($client), 'No central request while the lock is held');
    }
}
