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
use mod_agora\local\grant_manager;
use mod_agora\local\preview;
use mod_agora\local\stream_state;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/../lib.php');

/**
 * The preview frame: the source by state, the service ticket, the delivery via pluginfile.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\preview
 */
final class preview_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the learner */
    private $student;

    /** @var \stdClass the activity record */
    private $agora;

    /** @var \cm_info the course module */
    private $cm;

    /**
     * A course, a learner and an activity for a broadcast.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        connection::set_client(null);

        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user(['username' => 'ivanov']);
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'stream_name' => 'optics-2026',
        ]);
        $this->cm = get_fast_modinfo($this->course)->get_cm($this->agora->cmid);
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
     * The routes of the stub Agora: the broadcast state, the service ticket and the frame.
     *
     * @param mixed $stats the `streams/stats` answer
     * @param array $extra additional routes
     * @return array
     */
    private function routes($stats, array $extra = []): array {
        return $extra + [
            'GET /streams/stats/optics-2026' => $stats,
            'POST /play-sessions/grants' => $this->preview_grant(),
        ];
    }

    /**
     * The answer to the service ticket issue.
     *
     * @return \GuzzleHttp\Psr7\Response
     */
    private function preview_grant(): \GuzzleHttp\Psr7\Response {
        return \mod_agora_mock_central::json(201, [
            'jti' => 'jti-preview',
            'token' => 'jwt-preview',
            'expires_at' => gmdate('c', time() + 3600),
            'status' => 'issued',
        ]);
    }

    /**
     * A running broadcast takes its frame from the live source.
     *
     * @return void
     */
    public function test_live_frame_comes_from_live_preview(): void {
        $client = \mod_agora_mock_central::client($this->routes(\mod_agora_mock_central::live_stats(), [
            'GET /playback/live-preview-jpeg/optics-2026' => \mod_agora_mock_central::image(200, 'LIVEJPEG'),
        ]));
        connection::set_client($client);

        $frame = preview::frame('optics-2026', $this->cm->context);

        $this->assertSame('LIVEJPEG', $frame);
        $this->assertContains('GET /playback/live-preview-jpeg/optics-2026', $client->request_log());
    }

    /**
     * The node refused the service ticket for the frame (403) — the ticket is
     * extinguished, the next show will issue a new one instead of presenting the
     * refused one for an hour.
     *
     * @return void
     */
    public function test_refused_service_ticket_on_the_frame_is_forgotten(): void {
        global $DB;

        connection::set_client(\mod_agora_mock_central::client($this->routes(\mod_agora_mock_central::live_stats(), [
            'GET /playback/live-preview-jpeg/optics-2026' => \mod_agora_mock_central::error(403, 'forbidden', 'revoked'),
        ])));

        $this->assertNull(preview::frame('optics-2026', $this->cm->context));

        $this->assertSame('expired', $DB->get_field('agora_grants', 'status', ['jti' => 'jti-preview']));
    }

    /**
     * Only the refused service ticket is extinguished, not a fresh one that a
     * neighbour process managed to issue.
     *
     * @return void
     */
    public function test_forget_service_keeps_a_fresh_ticket(): void {
        global $DB;

        foreach (['jti-refused', 'jti-fresh'] as $jti) {
            $DB->insert_record('agora_grants', (object) [
                'agoraid' => 0, 'userid' => 0, 'purpose' => 'preview', 'stream_name' => 'optics-2026',
                'jti' => $jti, 'token' => 'jwt-' . $jti, 'user_id' => 'hr-lms:service:preview',
                'expires_at' => time() + HOURSECS, 'status' => 'issued', 'timecreated' => time(),
            ]);
        }

        \mod_agora\local\grant_manager::forget_service('optics-2026', 'jti-refused');

        $this->assertSame('expired', $DB->get_field('agora_grants', 'status', ['jti' => 'jti-refused']));
        $this->assertSame('issued', $DB->get_field('agora_grants', 'status', ['jti' => 'jti-fresh']));
    }

    /**
     * An ended broadcast takes its frame from the archive by the `written_until` label.
     *
     * @return void
     */
    public function test_recorded_frame_comes_from_archive(): void {
        $client = \mod_agora_mock_central::client($this->routes(\mod_agora_mock_central::recorded_stats(3600), [
            'GET /playback/v/optics-2026/image/*' => \mod_agora_mock_central::image(200, 'ARCHIVEJPEG'),
        ]));
        connection::set_client($client);

        $frame = preview::frame('optics-2026', $this->cm->context);

        $this->assertSame('ARCHIVEJPEG', $frame);
        $archive = array_filter($client->request_log(), function (string $line): bool {
            return strpos($line, '/playback/v/optics-2026/image/i1/') === 4;
        });
        $this->assertCount(1, $archive, 'The frame of an ended broadcast comes from the archive: ' .
            implode(' | ', $client->request_log()));
    }

    /**
     * The states in which the frame is not requested.
     *
     * @return array
     */
    public static function silent_provider(): array {
        return [
            'not started' => [['status' => 'idle']],
            'without recording' => [['status' => 'idle', 'status_since' => gmdate('c', time() - 3600),
                'dvr' => ['recording' => false]]],
        ];
    }

    /**
     * In «not started» and «no recording» there is no frame request: a live
     * frame would raise the input.
     *
     * @param array $stats the `streams/stats` answer body
     * @return void
     * @dataProvider silent_provider
     */
    public function test_silent_states_do_not_ask_for_a_frame(array $stats): void {
        $client = \mod_agora_mock_central::client($this->routes(
            \mod_agora_mock_central::json(200, $stats + ['name' => 'optics-2026'])
        ));
        connection::set_client($client);

        $this->assertNull(preview::frame('optics-2026', $this->cm->context));

        foreach ($client->request_log() as $line) {
            $this->assertStringNotContainsString('/playback/', $line);
            $this->assertStringNotContainsString('/play-sessions/grants', $line);
        }
    }

    /**
     * On a state refusal the frame is not requested either.
     *
     * @return void
     */
    public function test_unknown_state_does_not_ask_for_a_frame(): void {
        $client = \mod_agora_mock_central::client($this->routes(
            \mod_agora_mock_central::error(429, 'too_many_sessions', 'limit')
        ));
        connection::set_client($client);

        $this->assertNull(preview::frame('optics-2026', $this->cm->context));
        foreach ($client->request_log() as $line) {
            $this->assertStringNotContainsString('/playback/', $line);
        }
    }

    /**
     * The service ticket is for one broadcast, one screen, under its own name.
     *
     * @return void
     */
    public function test_service_grant_covers_one_stream(): void {
        global $DB;

        $client = \mod_agora_mock_central::client([
            'POST /play-sessions/grants' => $this->preview_grant(),
        ]);
        connection::set_client($client);

        $grant = grant_manager::for_preview('optics-2026');

        $this->assertSame('hr-lms:service:preview', $grant->user_id);
        $this->assertSame(0, (int) $grant->userid);
        $this->assertSame('preview', $grant->purpose);
        $this->assertSame('optics-2026', $grant->stream_name);
        $this->assertSame(1, $DB->count_records('agora_grants', ['purpose' => 'preview']));

        // The issue body as the Agora operator will see it: an hour of life, the
        // service `user_id`, up to ten screens, no address binding.
        $body = \mod_agora_mock_central::last_body();
        $this->assertSame(['optics-2026'], $body['streams']);
        $this->assertSame(3600, $body['ttl_secs']);
        $this->assertSame('hr-lms:service:preview', $body['user_id']);
        $this->assertSame(10, $body['max_concurrent']);
        $this->assertArrayNotHasKey('bind_ip', $body);
    }

    /**
     * The service ticket is reused while alive.
     *
     * @return void
     */
    public function test_service_grant_is_reused(): void {
        $client = \mod_agora_mock_central::client([
            'POST /play-sessions/grants' => $this->preview_grant(),
        ]);
        connection::set_client($client);

        $first = grant_manager::for_preview('optics-2026');
        $second = grant_manager::for_preview('optics-2026');

        $this->assertEquals($first->id, $second->id);
        $posts = array_filter($client->request_log(), function (string $line): bool {
            return $line === 'POST /central/api-v4/play-sessions/grants';
        });
        $this->assertCount(1, $posts);
    }

    /**
     * A broadcast without the `thumbnails` section: a card without a frame and one log record.
     *
     * @return void
     */
    public function test_missing_thumbnails_logs_once(): void {
        $client = \mod_agora_mock_central::client($this->routes(\mod_agora_mock_central::live_stats(), [
            'GET /playback/live-preview-jpeg/optics-2026' => \mod_agora_mock_central::image(404, ''),
        ]));
        connection::set_client($client);

        $sink = $this->redirectEvents();
        $this->assertNull(preview::frame('optics-2026', $this->cm->context));
        // The second page show within a minute neither asks for the frame nor writes to the log.
        $this->assertNull(preview::frame('optics-2026', $this->cm->context));
        $events = $sink->get_events();
        $sink->close();

        $missing = array_filter($events, function ($event): bool {
            return $event instanceof \mod_agora\event\preview_missing;
        });
        $this->assertCount(1, $missing, 'A single log record per broadcast');

        // No frame — the card is drawn without the picture.
        $state = stream_state::for_stream('optics-2026');
        $this->assertNull(preview::url($this->cm->context, 'optics-2026', $state));
    }

    /**
     * The frame is fetched from central no more than once a minute.
     *
     * @return void
     */
    public function test_frame_is_cached(): void {
        $client = \mod_agora_mock_central::client($this->routes(\mod_agora_mock_central::live_stats(), [
            'GET /playback/live-preview-jpeg/optics-2026' => \mod_agora_mock_central::image(200, 'LIVEJPEG'),
        ]));
        connection::set_client($client);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('LIVEJPEG', preview::frame('optics-2026', $this->cm->context));
        }

        $frames = array_filter($client->request_log(), function (string $line): bool {
            return strpos($line, '/playback/live-preview-jpeg/') !== false;
        });
        $this->assertCount(1, $frames);
    }

    /**
     * An enrolled learner receives the frame.
     *
     * @return void
     */
    public function test_enrolled_learner_gets_the_frame(): void {
        $client = \mod_agora_mock_central::client($this->routes(\mod_agora_mock_central::live_stats(), [
            'GET /playback/live-preview-jpeg/optics-2026' => \mod_agora_mock_central::image(200, 'LIVEJPEG'),
        ]));
        connection::set_client($client);
        $this->setUser($this->student);
        // The `cm_info` remembers whom it was computed for: after switching the user
        // it must be taken anew, otherwise `uservisible` stays someone else's.
        $cm = get_fast_modinfo($this->course)->get_cm($this->agora->cmid);

        // The send_file() never reaches the body delivery under PHPUnit: the core
        // forbids sending headers from a test and kills the send with its own
        // exception. «The frame went out» and «file not found» are told apart by it.
        ob_start();
        $caught = null;
        try {
            mod_agora_pluginfile($this->course, $cm, $cm->context, 'preview', ['preview.jpg'], false);
        } catch (\Throwable $error) {
            $caught = $error;
        }
        ob_end_clean();

        // The frame is requested for the enrolled learner and goes into the
        // answer: a refusal would have aborted the handler before the request to
        // the player frontend.
        $this->assertContains('GET /playback/live-preview-jpeg/optics-2026', $client->request_log());

        // How exactly the frame goes to the browser: a 60-second lifetime, the
        // picture type, the body as a string and no file offload.
        $this->assertSame([
            'filename' => 'preview.jpg',
            'lifetime' => 60,
            'filter' => 0,
            'pathisstring' => true,
            'forcedownload' => false,
            'mimetype' => 'image/jpeg',
        ], preview::send_options());
    }

    /**
     * A hidden activity closes the frame even to one who has the right to watch.
     *
     * The third gate of the handler is `uservisible`: without it a saved frame
     * address would open a hidden or condition-restricted activity.
     *
     * @return void
     */
    public function test_hidden_activity_gate_refuses_without_central_calls(): void {
        global $DB;

        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);

        // The learner has the activity capability, but the activity is hidden:
        // this is the only way to tell the gates apart.
        $DB->set_field('course_modules', 'visible', 0, ['id' => $this->agora->cmid]);
        rebuild_course_cache($this->course->id, true);

        $this->setUser($this->student);
        $cm = get_fast_modinfo($this->course)->get_cm($this->agora->cmid);
        $this->assertTrue(has_capability('mod/agora:view', $cm->context));
        $this->assertFalse($cm->uservisible);

        $caught = null;
        try {
            mod_agora_pluginfile($this->course, $cm, $cm->context, 'preview', ['preview.jpg'], false);
        } catch (\Throwable $error) {
            $caught = $error;
        }

        $this->assertNotNull($caught, 'A hidden activity does not serve its frame');
        $this->assertSame([], $client->request_log(), 'No requests to central on refusal');
    }

    /**
     * An enrolled user stripped of the `mod/agora:view` capability gets no frame.
     *
     * The three gates of the handler are indistinguishable one by one:
     * `cm_info::uservisible` itself accounts for the activity capability, so a
     * revoked capability closes the frame through it as well. The outcome is
     * checked, not which gates exactly fired.
     *
     * @return void
     */
    public function test_capability_gate_refuses_without_central_calls(): void {
        global $DB;

        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        role_change_permission($roleid, $this->cm->context, 'mod/agora:view', CAP_PROHIBIT);

        $this->setUser($this->student);
        $cm = get_fast_modinfo($this->course)->get_cm($this->agora->cmid);

        $caught = null;
        try {
            mod_agora_pluginfile($this->course, $cm, $cm->context, 'preview', ['preview.jpg'], false);
        } catch (\Throwable $error) {
            $caught = $error;
        }

        $this->assertNotNull($caught, 'Without the mod/agora:view capability the frame is not served');
        $this->assertSame([], $client->request_log());
    }

    /**
     * An unenrolled user by the old frame address gets «file not found».
     *
     * @return void
     */
    public function test_unenrolled_user_is_refused_without_central_calls(): void {
        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);

        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        $cm = get_fast_modinfo($this->course)->get_cm($this->agora->cmid);

        $caught = null;
        try {
            mod_agora_pluginfile($this->course, $cm, $cm->context, 'preview', ['preview.jpg'], false);
        } catch (\Throwable $error) {
            $caught = $error;
        }

        // The refusal is visible by the frame delivery never happening: no
        // requests to central, and the handler aborted instead of returning the
        // picture.
        $this->assertNotNull($caught, 'Without the watch capability the frame is not served');
        $this->assertSame([], $client->request_log(), 'No requests to central on refusal');
    }
}
