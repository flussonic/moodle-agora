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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/fixtures/view_script.php');

/**
 * The publisher page: three device toggles, the «Broadcast» toggle, the phase
 * banner and the broadcast actions «Start the broadcast»/«Stop the broadcast»
 * (POST with sesskey and the right to lead the broadcast, the same lifecycle
 * actions as on the activity page; with `ajax=1` — a 204 answer without a reload).
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\broadcast_lifecycle
 */
final class publisher_controls_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the presenter */
    private $teacher;

    /**
     * A course, a presenter and a stub Agora.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        connection::set_client(null);
        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
        set_config('whipaddress', 'https://node.agora.example.com', 'mod_agora');
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
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
     * An activity with phases, created by the plugin, in the «planned» phase.
     *
     * @return \stdClass the activity record
     */
    private function element(): \stdClass {
        global $DB;

        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture',
            'stream_name' => 'optics-2026',
            'created_by_plugin' => 1,
            'has_phases' => 1,
        ]);
        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $row->cmid = $agora->cmid;
        return $row;
    }

    /**
     * Opens the publisher page as the current user.
     *
     * @param \stdClass $agora the activity record
     * @param array $params the request parameters
     * @param bool $post send the parameters as POST (button actions)
     * @return string HTML
     */
    private function open(\stdClass $agora, array $params = [], bool $post = false): string {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        return \mod_agora_view_script::run((int) $agora->cmid, $params, 'publish.php', $post);
    }

    /**
     * A button action opens the real publish.php; the page answers with a
     * redirect (an exception in CLI), the side effects are already applied.
     *
     * @param \stdClass $agora the activity record
     * @param string $action startbroadcast | endbroadcast
     * @return void
     */
    private function click(\stdClass $agora, string $action): void {
        try {
            $this->open($agora, ['action' => $action, 'sesskey' => sesskey()], true);
            $this->fail('The action must answer with a redirect');
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode, 'The answer is a redirect, not: ' . $e->getMessage());
        }
    }

    /**
     * The «Broadcast» toggle action with `ajax=1`: the page answers with an
     * empty body (204 No Content; headers are unavailable in the CLI run, so
     * the contract is checked by the body and the absence of a redirect), the
     * side effects are applied.
     *
     * @param \stdClass $agora the activity record
     * @param string $action startbroadcast | endbroadcast
     * @return string the answer body
     */
    private function click_ajax(\stdClass $agora, string $action): string {
        return $this->open($agora, ['action' => $action, 'sesskey' => sesskey(), 'ajax' => 1], true);
    }

    /**
     * The client log contains a request to a path.
     *
     * @param \mod_agora\local\agora_client $client the client
     * @param string $needle the path substring
     * @return bool
     */
    private static function client_went_to(\mod_agora\local\agora_client $client, string $needle): bool {
        return count(array_filter($client->request_log(), static function (string $line) use ($needle): bool {
            return strpos($line, $needle) !== false;
        })) > 0;
    }

    /**
     * Filters events of the class out of the sink log.
     *
     * @param \phpunit_event_sink $sink the event sink
     * @param string $class the event class
     * @return \core\event\base[]
     */
    private function events_of($sink, string $class): array {
        return array_values(array_filter($sink->get_events(), static function (\core\event\base $event) use ($class): bool {
            return $event instanceof $class;
        }));
    }

    /**
     * The «planned» phase without a signal: three device toggles, the phase
     * banner; the «Broadcast» toggle shows «Start the broadcast» but is
     * disabled and explains what it waits for. The testing block and the
     * «Start»/«Stop» (signal) pair are absent from the markup (D8–D11).
     *
     * @return void
     */
    public function test_planned_page_renders_device_toggles_and_a_disabled_broadcast(): void {
        $agora = $this->element();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026'
                => \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
        ]));
        $this->setUser($this->teacher);

        $html = $this->open($agora);

        $this->assertStringContainsString('data-action="agora-toggle-camera"', $html, 'Camera toggle');
        $this->assertStringContainsString('data-action="agora-toggle-mic"', $html, 'Mic toggle');
        $this->assertStringContainsString('data-action="agora-toggle-screen"', $html, 'Screen toggle');
        $this->assertStringContainsString(get_string('publishercamera', 'mod_agora'), $html);
        $this->assertStringContainsString(get_string('publishermicrophone', 'mod_agora'), $html);
        $this->assertStringContainsString(get_string('publisherscreen', 'mod_agora'), $html);
        $this->assertStringContainsString('data-region="agora-broadcast-phase"', $html, 'Phase banner');
        $this->assertStringContainsString(get_string('badgeplanned', 'mod_agora'), $html, 'The «planned» banner');

        $this->assertStringNotContainsString('agora-publisher-check', $html, 'No check block');
        $this->assertStringNotContainsString('agora-publish-start', $html, 'No «Start» (signal) button');
        $this->assertStringNotContainsString('agora-publish-stop', $html, 'No «Stop» (signal) button');

        $this->assertStringContainsString(get_string('publisherbroadcaston', 'mod_agora'), $html, '«Broadcast on»');
        $this->assertMatchesRegularExpression(
            '#data-action="agora-toggle-broadcast"\s+aria-pressed="false"\s+disabled title="[^"]*"#',
            $html,
            'Without a signal the «Broadcast» toggle is inactive, with a hint'
        );
        $this->assertStringContainsString(get_string('startbroadcastnosignal', 'mod_agora'), $html);
    }

    /**
     * The «planned» phase with a live signal: the «Broadcast» toggle is enabled.
     *
     * @return void
     */
    public function test_planned_page_enables_broadcast_with_a_live_signal(): void {
        $agora = $this->element();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));
        $this->setUser($this->teacher);

        $html = $this->open($agora);

        $this->assertDoesNotMatchRegularExpression(
            '#data-action="agora-toggle-broadcast"[^>]*disabled#',
            $html,
            'With a live signal the «Broadcast» toggle is active'
        );
    }

    /**
     * The «ongoing» phase: the «ongoing» banner, the «Broadcast» toggle in the
     * on state shows «Stop the broadcast».
     *
     * @return void
     */
    public function test_ongoing_page_renders_the_broadcast_toggle_on(): void {
        global $DB;

        $agora = $this->element();
        $DB->set_field('agora', 'started_at', time() - 600, ['id' => $agora->id]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));
        $this->setUser($this->teacher);

        $html = $this->open($agora);

        $this->assertStringContainsString(get_string('publisherphaseongoing', 'mod_agora'), $html, 'The «ongoing» banner');
        $this->assertStringContainsString(get_string('publisherbroadcastoff', 'mod_agora'), $html, '«Broadcast off»');
        $this->assertMatchesRegularExpression(
            '#class="btn btn-primary" data-action="agora-toggle-broadcast"\s+aria-pressed="true"#',
            $html,
            'The «Broadcast» toggle is on'
        );
        $this->assertStringNotContainsString(get_string('publisherbroadcaston', 'mod_agora'), $html, '«Broadcast on» hidden');
    }

    /**
     * An ended activity is not opened by the publisher: the gate sends to the
     * activity page.
     *
     * @return void
     */
    public function test_ended_element_is_redirected_to_the_watch_page(): void {
        global $DB;

        $agora = $this->element();
        $DB->set_field('agora', 'started_at', time() - 3600, ['id' => $agora->id]);
        $DB->set_field('agora', 'ended_at', time() - 600, ['id' => $agora->id]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026'
                => \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
        ]));
        $this->setUser($this->teacher);

        $refusal = \mod_agora_view_script::refusal((int) $agora->cmid, [], 'publish.php');
        $this->assertNotNull($refusal, 'The ended activity publisher is not rendered');
        $this->assertSame('redirecterrordetected', $refusal->errorcode, 'The answer is a redirect to the activity page');
    }

    /**
     * The «Broadcast» toggle (ajax=1): the answer without a body and without a
     * redirect (204), the «ongoing» phase is set.
     *
     * @return void
     */
    public function test_ajax_start_answers_204_without_a_redirect(): void {
        global $DB;

        $agora = $this->element();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /streams/optics-2026/recordings/start' => \mod_agora_mock_central::json(201, ['id' => 'rec-1']),
            'PATCH /recordings/rec-1' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
        ]));
        $this->setUser($this->teacher);

        $body = $this->click_ajax($agora, 'startbroadcast');

        $this->assertSame('', $body, 'The AJAX action answers with an empty body (204), not a redirect');
        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->started_at, 'The «ongoing» phase is set');
    }

    /**
     * A lifecycle refusal with ajax=1 is returned as the reason text, not
     * swallowed silently.
     *
     * @return void
     */
    public function test_ajax_failure_names_the_reason(): void {
        global $DB;

        $agora = $this->element();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026'
                => \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
        ]));
        $this->setUser($this->teacher);

        $body = $this->click_ajax($agora, 'startbroadcast');

        $this->assertStringContainsString(
            get_string('startbroadcastnosignal', 'mod_agora'),
            $body,
            'The refusal is named in text'
        );
        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->started_at, 'The phase unchanged');
    }

    /**
     * «Start the broadcast» from the publisher page: the «ongoing» phase, the
     * recording starts, the start event is in the log.
     *
     * @return void
     */
    public function test_start_broadcast_from_the_publisher_page(): void {
        global $DB;

        $agora = $this->element();
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /streams/optics-2026/recordings/start' => \mod_agora_mock_central::json(201, ['id' => 'rec-1']),
            'PATCH /recordings/rec-1' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'startbroadcast');
        $events = $this->events_of($sink, \mod_agora\event\broadcast_started::class);
        $sink->close();

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->started_at, 'The «ongoing» phase is set');
        $this->assertSame('rec-1', $row->recording_id, 'Recording linked to the activity');
        $this->assertCount(1, $events, 'Broadcast-start event in the log');
        $this->assertTrue(self::client_went_to($client, '/streams/optics-2026/recordings/start'));
    }

    /**
     * «Stop the broadcast» from the publisher page: the broadcast is ended, the
     * end event is in the log.
     *
     * @return void
     */
    public function test_end_broadcast_from_the_publisher_page(): void {
        global $DB;

        $agora = $this->element();
        $DB->set_field('agora', 'started_at', time() - 600, ['id' => $agora->id]);
        $client = \mod_agora_mock_central::client([
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'endbroadcast');
        $events = $this->events_of($sink, \mod_agora\event\broadcast_ended::class);
        $sink->close();

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->ended_at, 'The broadcast is ended');
        $this->assertCount(1, $events, 'The end event in the log');
    }

    /**
     * An action with a foreign sesskey is refused, the phase does not change.
     *
     * @return void
     */
    public function test_action_requires_sesskey(): void {
        global $DB;

        $agora = $this->element();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));
        $this->setUser($this->teacher);

        $refusal = \mod_agora_view_script::refusal(
            (int) $agora->cmid,
            ['action' => 'startbroadcast', 'sesskey' => 'not-a-sesskey'],
            'publish.php',
            true
        );
        $this->assertNotNull($refusal, 'No sesskey — refusal');
        $this->assertSame('invalidsesskey', $refusal->errorcode);
        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->started_at, 'The phase unchanged');
    }

    /**
     * An action without the right to lead the broadcast is refused before any call to Agora.
     *
     * @return void
     */
    public function test_action_requires_the_broadcast_capability(): void {
        global $DB;

        $agora = $this->element();
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]);
        connection::set_client($client);
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $refusal = \mod_agora_view_script::refusal(
            (int) $agora->cmid,
            ['action' => 'startbroadcast', 'sesskey' => sesskey()],
            'publish.php',
            true
        );
        $this->assertNotNull($refusal, 'The viewer is refused');
        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->started_at, 'The phase unchanged');
        $this->assertFalse(self::client_went_to($client, 'recordings'), 'The call never reached Agora');
    }

    /**
     * An action by a GET request is refused: a state change is POST only.
     *
     * @return void
     */
    public function test_action_over_get_is_refused(): void {
        global $DB;

        $agora = $this->element();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));
        $this->setUser($this->teacher);

        $refusal = \mod_agora_view_script::refusal(
            (int) $agora->cmid,
            ['action' => 'startbroadcast', 'sesskey' => sesskey()],
            'publish.php'
        );
        $this->assertNotNull($refusal, 'GET with an action — refusal');
        $this->assertSame('errunknownaction', $refusal->errorcode);
        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->started_at, 'The phase unchanged');
    }

    /**
     * A repeated «Start the broadcast» is idempotent: one start event, the
     * recording starts once.
     *
     * @return void
     */
    public function test_repeated_start_is_idempotent(): void {
        $agora = $this->element();
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /streams/optics-2026/recordings/start' => \mod_agora_mock_central::json(201, ['id' => 'rec-1']),
            'PATCH /recordings/rec-1' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'startbroadcast');
        $this->click($agora, 'startbroadcast');
        $events = $this->events_of($sink, \mod_agora\event\broadcast_started::class);
        $sink->close();

        $this->assertCount(1, $events, 'A single start event');
        $starts = count(array_filter($client->request_log(), static function (string $line): bool {
            return strpos($line, 'recordings/start') !== false;
        }));
        $this->assertSame(1, $starts, 'Recording start — a single call');
    }

    /**
     * A repeated «Stop the broadcast» is idempotent: one end event.
     *
     * @return void
     */
    public function test_repeated_end_is_idempotent(): void {
        global $DB;

        $agora = $this->element();
        $DB->set_field('agora', 'started_at', time() - 600, ['id' => $agora->id]);
        $client = \mod_agora_mock_central::client([
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'endbroadcast');
        $this->click($agora, 'endbroadcast');
        $events = $this->events_of($sink, \mod_agora\event\broadcast_ended::class);
        $sink->close();

        $this->assertCount(1, $events, 'A single end event');
    }
}
