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
use mod_agora\local\broadcast_phase;
use mod_agora\local\connection;
use mod_agora\local\stream_state;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/fixtures/view_script.php');

/**
 * Leading the broadcast by the presenter: the activity phases, the
 * «Start»/«End» buttons, adopting an already running recording and the
 * completion of refusals.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\broadcast_phase
 * @covers     \mod_agora\local\broadcast_lifecycle
 * @covers     \mod_agora\local\player_view
 * @covers     \mod_agora\local\agora_client
 */
final class broadcast_lead_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the presenter with the edit right */
    private $teacher;

    /** @var \stdClass the learner */
    private $student;

    /**
     * A course, a presenter, a learner and a stub Agora.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Sink the enrolment welcome message: it renders through $PAGE and
        // claims the theme before the page fixtures run. On mysql-family
        // databases phpunit holds no global transaction, so the message goes
        // out immediately instead of being buffered (advanced_testcase only
        // wraps postgres/mssql).
        $this->redirectMessages();
        connection::set_client(null);
        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
        set_config('adminlogin', 'moodle-admin', 'mod_agora');
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
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
     * Creates an activity with phases, made by the plugin.
     *
     * @param string $streamname the broadcast name
     * @return \stdClass the activity record
     */
    private function element(string $streamname = 'optics-2026'): \stdClass {
        return $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture',
            'stream_name' => $streamname,
            'created_by_plugin' => 1,
            'has_phases' => 1,
        ]);
    }

    /**
     * Creates a legacy activity without phases.
     *
     * @return \stdClass the activity record
     */
    private function legacy_element(): \stdClass {
        return $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Old lecture',
            'stream_name' => 'optics-2026',
            'has_phases' => 0,
        ]);
    }

    /**
     * Opens the activity page as the current user.
     *
     * @param \stdClass $agora the activity record
     * @param array $params the request parameters
     * @param bool $post open with a POST request
     * @return string HTML
     */
    private function open(\stdClass $agora, array $params = [], bool $post = false): string {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        return \mod_agora_view_script::run((int) $agora->cmid, $params, 'view.php', $post);
    }

    /**
     * A live signal at the broadcast, the page reads the state from the cache.
     *
     * @return void
     */
    private function warm_live_cache(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));
        stream_state::for_page('optics-2026');
    }

    /**
     * A button action opens the real view.php; the page answers with a
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
     * A legacy activity with a live broadcast shows the learner the player, as in MVP:
     * no phases, no lead buttons, the badge is the broadcast state.
     *
     * @return void
     */
    public function test_legacy_element_lives_as_mvp(): void {
        $agora = $this->legacy_element();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-1',
                'token' => 'jwt-1',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
        ]));
        $this->setUser($this->student);

        $html = $this->open($agora);

        $this->assertStringContainsString(
            'class="mod-agora-player"',
            $html,
            'Legacy: live player, as before the change'
        );
        $this->assertStringNotContainsString(
            get_string('startbroadcast', 'mod_agora'),
            $html,
            'No buttons drawn for the legacy one'
        );
        $this->assertStringContainsString(get_string('badgelive', 'mod_agora'), $html);
    }

    /**
     * «Planned» with a live signal: the learner sees the placeholder, not the player.
     *
     * @return void
     */
    public function test_planned_listener_sees_placeholder_while_signal_is_up(): void {
        $agora = $this->element();
        $this->warm_live_cache();
        $this->setUser($this->student);

        $html = $this->open($agora);

        $this->assertStringNotContainsString('class="mod-agora-player"', $html, 'No live player for the viewer');
        $this->assertStringContainsString(get_string('statenotstarted', 'mod_agora'), $html);
        $this->assertStringContainsString(get_string('badgeplanned', 'mod_agora'), $html);
        $this->assertMatchesRegularExpression('#data-region="agora-scene"[^>]*data-kind="placeholder"#', $html);
        $this->assertStringNotContainsString(get_string('startbroadcast', 'mod_agora'), $html, 'No buttons for the viewer');
    }

    /**
     * «Planned» with a live signal: the presenter sees the check player, the
     * banner about the learners and the enabled «Start the broadcast» button.
     *
     * @return void
     */
    public function test_planned_teacher_sees_check_player_and_start_button(): void {
        $agora = $this->element();
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-1',
                'token' => 'jwt-1',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $html = $this->open($agora);

        $this->assertStringContainsString('class="mod-agora-player"', $html, 'The teacher gets the check player');
        $this->assertStringContainsString(
            get_string('signalhidden', 'mod_agora'),
            $html,
            'The «signal on, viewers do not see» banner'
        );
        $this->assertMatchesRegularExpression('#data-action="agora-start"#', $html);
        // The action is a POST form, not a GET link (Ф-1): there is no
        // parameter in the URL, the action name rides a hidden form field.
        $this->assertMatchesRegularExpression(
            '#<form method="post" action="/mod/agora/view.php"[^>]*>#',
            $html,
            '«Start broadcast» is a POST form'
        );
        $this->assertStringContainsString(
            'name="action" value="startbroadcast"',
            $html,
            'A hidden field carries the action name'
        );
        $this->assertStringNotContainsString('action=startbroadcast', $html, 'No action GET-parameter in the URL');
    }

    /**
     * Without a signal the «Start the broadcast» button is disabled and explains what it waits for.
     *
     * @return void
     */
    public function test_start_button_is_disabled_without_signal(): void {
        $agora = $this->element();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
        ]));
        $this->setUser($this->teacher);

        $html = $this->open($agora);

        $this->assertMatchesRegularExpression(
            '#<span class="btn btn-outline-secondary btn-sm disabled" title="[^"]*">'
                . get_string('startbroadcast', 'mod_agora') . '</span>#',
            $html,
            'The no-signal button is inactive, with a hint'
        );
        $this->assertStringContainsString(get_string('startbroadcastnosignal', 'mod_agora'), $html);
        $this->assertStringNotContainsString('action=startbroadcast', $html, 'The inactive button does not lead to the action');
    }

    /**
     * The state poll respects the phase: the learner gets the placeholder, the leader — the player.
     *
     * @return void
     */
    public function test_poll_respects_the_phase(): void {
        $agora = $this->element();
        $this->warm_live_cache();

        $this->setUser($this->student);
        $answer = get_stream_state::execute((int) $agora->cmid);
        $this->assertSame('placeholder', $answer['kind'], 'The poll answers the viewer with a placeholder, not a player');
        $this->assertSame(get_string('badgeplanned', 'mod_agora'), $answer['badge']);

        $this->setUser($this->teacher);
        $answer = get_stream_state::execute((int) $agora->cmid);
        $this->assertSame('live', $answer['kind'], 'The presenter with a live signal gets the check player');
        $keys = array_column($answer['actions'], 'key');
        $this->assertContains('start', $keys, 'The poll carries the «Start broadcast» button for the presenter');
    }

    /**
     * «Start the broadcast» with a live signal: the «ongoing» phase, the
     * recording starts and gets the activity name, the start event is in the log.
     *
     * @return void
     */
    public function test_start_broadcast_starts_recording_named_after_the_element(): void {
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
        $this->assertSame(0, (int) $row->ended_at);
        $this->assertCount(1, $events, 'Broadcast-start event in the log');

        $this->assertTrue(self::client_went_to($client, '/streams/optics-2026/recordings/start'));
        $this->assertTrue(self::client_went_to($client, '/recordings/rec-1'));
        $renamebody = \mod_agora_mock_central::last_body();
        $this->assertSame('Lecture', $renamebody['name'], 'Recording name — activity name');
    }

    /**
     * The client log contains a request to a path (the API mounting not counted).
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
     * «Start the broadcast» without a signal does not set the phase and does not go to Agora.
     *
     * @return void
     */
    public function test_start_broadcast_requires_signal(): void {
        global $DB;

        $agora = $this->element();
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $this->click($agora, 'startbroadcast');

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->started_at, 'No phase without a signal');
        $this->assertFalse(self::client_went_to($client, 'recordings'), 'No central request for recordings without a signal');
    }

    /**
     * 409 RECORDING_ACTIVE: an open recording of the broadcast is adopted as the
     * bound one, an own one is renamed, the log has «the recording was already
     * running» with the owner.
     *
     * @return void
     */
    public function test_start_broadcast_adopts_an_already_running_recording(): void {
        global $DB;

        $agora = $this->element();
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /streams/optics-2026/recordings/start' => \mod_agora_mock_central::error(409, 'RECORDING_ACTIVE', 'already'),
            'GET /streams/optics-2026/recordings' => \mod_agora_mock_central::json(200, [
                'recordings' => [['id' => 'rec-9', 'phase' => 'recording', 'owner' => 'moodle-admin']],
            ]),
            'PATCH /recordings/rec-9' => \mod_agora_mock_central::json(200, ['id' => 'rec-9']),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'startbroadcast');
        $adopted = $this->events_of($sink, \mod_agora\event\broadcast_recording_adopted::class);
        $failed = $this->events_of($sink, \mod_agora\event\broadcast_action_failed::class);
        $sink->close();

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertSame('rec-9', $row->recording_id, 'An open recording adopted as linked');
        $this->assertGreaterThan(0, (int) $row->started_at);
        $this->assertTrue(self::client_went_to($client, '/recordings/rec-9'), 'Own recording renamed');
        $this->assertCount(1, $adopted, 'The log says «recording already running»');
        $this->assertSame('moodle-admin', $adopted[0]->other['owner']);
        $this->assertCount(0, $failed, 'Adopting a recording is not a refusal');
    }

    /**
     * 409 RECORDING_ACTIVE on someone else's recording: adopt it, but do not touch the name.
     *
     * @return void
     */
    public function test_start_broadcast_adopts_a_foreign_recording_without_renaming(): void {
        global $DB;

        $agora = $this->element();
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /streams/optics-2026/recordings/start' => \mod_agora_mock_central::error(409, 'RECORDING_ACTIVE', 'already'),
            'GET /streams/optics-2026/recordings' => \mod_agora_mock_central::json(200, [
                'recordings' => [['id' => 'rec-7', 'phase' => 'recording', 'owner' => 'operator-ivan']],
            ]),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'startbroadcast');
        $adopted = $this->events_of($sink, \mod_agora\event\broadcast_recording_adopted::class);
        $sink->close();

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertSame('rec-7', $row->recording_id);
        $this->assertFalse(self::client_went_to($client, '/recordings/rec-7'), 'No renaming of a foreign recording');
        $this->assertSame('operator-ivan', $adopted[0]->other['owner']);
        $this->assertFalse((bool) $adopted[0]->other['renamed']);
    }

    /**
     * 409 of readiness: the «ongoing» phase stands, the recording is not bound,
     * the reason is in the log as a separate refusal class.
     *
     * @return void
     */
    public function test_start_broadcast_survives_a_readiness_refusal(): void {
        global $DB;

        $agora = $this->element();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /streams/optics-2026/recordings/start'
                => \mod_agora_mock_central::error(409, 'RECORDING_NO_DVR', 'dvr is not configured'),
        ]));
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'startbroadcast');
        $started = $this->events_of($sink, \mod_agora\event\broadcast_started::class);
        $failed = $this->events_of($sink, \mod_agora\event\broadcast_action_failed::class);
        $sink->close();

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->started_at, 'Viewers admitted: the phase stands');
        $this->assertNull($row->recording_id, 'Recording not linked: playback falls back to the archive window');
        $this->assertCount(1, $started);
        $this->assertCount(1, $failed, 'Recording refusal in the log');
        $this->assertSame('actionstartrecording', $failed[0]->other['action']);
        $this->assertSame('RECORDING_NO_DVR', $failed[0]->other['reason']);
    }

    /**
     * «End the broadcast»: the recording stop with the `{confirm: true}`
     * confirmation, the «ended» phase, the way is the button, the event is in the log.
     *
     * @return void
     */
    public function test_end_broadcast_stops_recording_and_ends_the_phase(): void {
        global $DB;

        $agora = $this->element();
        $startedat = time() - 600;
        $DB->set_field('agora', 'started_at', $startedat, ['id' => $agora->id]);
        $DB->set_field('agora', 'recording_id', 'rec-1', ['id' => $agora->id]);

        $client = \mod_agora_mock_central::client([
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'endbroadcast');
        $ended = $this->events_of($sink, \mod_agora\event\broadcast_ended::class);
        $sink->close();

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->ended_at, 'The activity is terminal');
        $this->assertSame('button', $row->ended_by, 'The way it ended — button');
        $this->assertSame(0, (int) $row->end_pending);
        $this->assertNull($row->end_failure);
        $this->assertCount(1, $ended);
        $this->assertSame('button', $ended[0]->other['by']);

        $this->assertTrue(self::client_went_to($client, '/streams/optics-2026/recordings/stop'));
        $this->assertTrue(\mod_agora_mock_central::last_body()['confirm'], 'The recording stop goes with {confirm: true}');
    }

    /**
     * A direct «Start the broadcast» request to a «take existing» activity is
     * refused before any call to Agora: no buttons are drawn for it, and the
     * broadcast cannot be started by a URL workaround (review R2, К-1, D3).
     *
     * @return void
     */
    public function test_start_broadcast_is_refused_for_an_existing_stream(): void {
        global $DB;

        // The «take existing» activity: it has phases, but the broadcast does not belong to the plugin.
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Operator broadcast',
            'stream_name' => 'optics-2026',
            'created_by_plugin' => 0,
            'has_phases' => 1,
        ]);
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $refusal = \mod_agora_view_script::refusal((int) $agora->cmid, [
            'action' => 'startbroadcast',
            'sesskey' => sesskey(),
        ], 'view.php', true);
        $this->assertNotNull($refusal, 'Refusal');
        $this->assertSame('errunknownaction', $refusal->errorcode, 'Unknown action for a foreign broadcast');

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->started_at, 'The phase untouched');
        $this->assertFalse(
            count(array_filter($client->request_log(), static function (string $line): bool {
                return strpos($line, 'recordings/start') !== false;
            })) > 0,
            'No recording-start call to Agora'
        );
    }

    /**
     * The «button + scheduled task» race does not duplicate the «broadcast
     * ended» event: a repeated finish on an already ended activity is silence
     * (review R2, М-8).
     *
     * @return void
     */
    public function test_broadcast_ended_event_fires_once(): void {
        global $DB;

        $agora = $this->element();
        $DB->set_field('agora', 'started_at', time() - 600, ['id' => $agora->id]);
        $DB->set_field('agora', 'recording_id', 'rec-1', ['id' => $agora->id]);

        $client = \mod_agora_mock_central::client([
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $context = \context_module::instance((int) $agora->cmid);
        $sink = $this->redirectEvents();
        // The first pass is as the button, the second — as the task that came in the race.
        \mod_agora\local\broadcast_lifecycle::finish($agora, $context, 'button', (int) $this->teacher->id);
        $fresh = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        \mod_agora\local\broadcast_lifecycle::finish($fresh, $context, 'silence', 0);
        $ended = $this->events_of($sink, \mod_agora\event\broadcast_ended::class);
        $sink->close();

        $this->assertCount(1, $ended, 'A single «broadcast ended» event per phase transition');
        $this->assertSame('button', $ended[0]->other['by'], 'The way — whoever completed it first');
    }

    /**
     * 409 RECORDING_NOT_RUNNING on ending — «already stopped», not a refusal.
     *
     * @return void
     */
    public function test_end_broadcast_treats_not_running_as_already_stopped(): void {
        global $DB;

        $agora = $this->element();
        $DB->set_field('agora', 'started_at', time() - 600, ['id' => $agora->id]);

        connection::set_client(\mod_agora_mock_central::client([
            'POST /streams/optics-2026/recordings/stop'
                => \mod_agora_mock_central::error(409, 'RECORDING_NOT_RUNNING', 'nothing to stop'),
        ]));
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'endbroadcast');
        $ended = $this->events_of($sink, \mod_agora\event\broadcast_ended::class);
        $failed = $this->events_of($sink, \mod_agora\event\broadcast_action_failed::class);
        $sink->close();

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->ended_at, 'The activity is ended');
        $this->assertCount(1, $ended);
        $this->assertCount(0, $failed, '«No recording» is not a refusal');
    }

    /**
     * A refused recording stop does not make the activity terminal: the
     * «ongoing» phase stands, the page shows «ending failed», a button repeat
     * completes it.
     *
     * @return void
     */
    public function test_failed_end_is_not_terminal_and_can_be_repeated(): void {
        global $DB;

        $agora = $this->element();
        $DB->set_field('agora', 'started_at', time() - 600, ['id' => $agora->id]);

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-1',
                'token' => 'jwt-1',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            'POST /streams/optics-2026/recordings/stop'
                => \mod_agora_mock_central::error(503, 'Internal', 'central is down'),
        ]));
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'endbroadcast');
        $failed = $this->events_of($sink, \mod_agora\event\broadcast_action_failed::class);
        $sink->close();

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->ended_at, 'The refusal is not terminal: the «ongoing» phase stands');
        $this->assertSame(1, (int) $row->end_pending);
        $this->assertStringContainsString(get_string('actionstoprecording', 'mod_agora'), $row->end_failure);
        $this->assertCount(1, $failed);
        $this->assertSame('actionstoprecording', $failed[0]->other['action']);

        // The page names the refusal and leaves the button for a repeat. The
        // string in the markup is escaped, like everything from the template.
        $html = $this->open($agora);
        // The string in the markup is escaped, like everything from the
        // template; we compose it from the lang strings and hold on to the
        // beginning — the refusal details (error.code, error.message) are in
        // the core's words.
        $line = explode('@@', get_string('endpendingline', 'mod_agora', '@@'), 2);
        $needle = s($line[0] . get_string('actionstoprecording', 'mod_agora') . ', Agora: HTTP 503');
        $this->assertStringContainsString($needle, $html);
        $this->assertMatchesRegularExpression('#data-action="agora-end"#', $html, 'The retry button is in place');

        // A repeat by the same button: central is alive — the activity is completed.
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-2',
                'token' => 'jwt-2',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
        ]));
        $this->click($agora, 'endbroadcast');

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->ended_at, 'The retry brought the activity to «ended»');
        $this->assertSame('button', $row->ended_by, 'The ending way kept the initiator');
        $this->assertSame(0, (int) $row->end_pending);
    }

    /**
     * The page of an ended activity: the ending way with the moment and the
     * «a new session is a new activity» hint, the «Ended» badge.
     *
     * @return void
     */
    public function test_ended_page_shows_how_it_ended(): void {
        global $DB;

        $agora = $this->element();
        $DB->set_field('agora', 'started_at', time() - 3600, ['id' => $agora->id]);
        $DB->set_field('agora', 'ended_at', time() - 600, ['id' => $agora->id]);
        $DB->set_field('agora', 'ended_by', 'silence', ['id' => $agora->id]);

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::recorded_stats(600),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-1',
                'token' => 'jwt-1',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            // A «recorded» broadcast has no archive in the test: the window is not built.
            'GET /playback/dvr-range/optics-2026' => \mod_agora_mock_central::error(404, 'notfound', 'no archive'),
        ]));
        $this->setUser($this->teacher);

        $html = $this->open($agora);

        $this->assertStringContainsString(get_string('badgeended', 'mod_agora'), $html);
        $this->assertStringContainsString(get_string('endbysilence', 'mod_agora'), $html, 'The ending way is named');
        $this->assertStringContainsString(get_string('broadcastnewelement', 'mod_agora'), $html, 'A hint about a new activity');
    }

    /**
     * An action without the right to lead the broadcast refuses before any call to Agora.
     *
     * @return void
     */
    public function test_action_requires_the_lead_capability(): void {
        $agora = $this->element();
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]);
        connection::set_client($client);
        $this->setUser($this->student);

        $refusal = \mod_agora_view_script::refusal((int) $agora->cmid, [
            'action' => 'startbroadcast',
            'sesskey' => sesskey(),
        ]);

        $this->assertNotNull($refusal, 'The action is refused for the viewer');
        $this->assertFalse(self::client_went_to($client, 'recordings'), 'The call never reached Agora');
    }

    /**
     * Deleting an activity in the «ongoing» phase stops the recording and
     * revokes the tickets before the tombstone; removing the publication
     * section is the «How to broadcast» cut (TODO).
     *
     * @return void
     */
    public function test_deletion_stops_the_recording_and_revokes_tickets(): void {
        global $DB;

        $agora = $this->element();
        $DB->set_field('agora', 'started_at', time() - 600, ['id' => $agora->id]);
        $DB->set_field('agora', 'recording_id', 'rec-1', ['id' => $agora->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant($agora, (int) $this->student->id);

        $client = \mod_agora_mock_central::client([
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
            'DELETE /play-sessions/grants/*' => \mod_agora_mock_central::json(200, []),
        ]);
        connection::set_client($client);

        course_delete_module((int) $agora->cmid);

        $this->assertFalse($DB->record_exists('agora', ['id' => $agora->id]), 'The activity is deleted');
        $this->assertTrue(
            self::client_went_to($client, '/streams/optics-2026/recordings/stop'),
            'The running recording stopped before the tombstone'
        );
        // The ticket rows are wiped after the activity deletion: the trace of
        // the revocation is the request to central itself. The publish tickets
        // go away with it (the same `revoke_where` revokes them without a
        // purpose filter).
        $this->assertTrue(
            self::client_went_to($client, '/play-sessions/grants/'),
            'Tickets revoked in central before the tombstone'
        );
    }
}
