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
use mod_agora\local\preview;
use mod_agora\local\stream_state;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/../lib.php');

/**
 * A neighbour frame: from the cache only, a miss is a short 404 without requests to central.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\preview
 */
final class pluginfile_cached_test extends \advanced_testcase {
    /** @var \stdClass the neighbour course */
    private $course;

    /** @var \stdClass the learner */
    private $student;

    /** @var \stdClass the activity record */
    private $agora;

    /**
     * The neighbour course, the learner and an activity for a broadcast.
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
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'stream_name' => 'optics-2026',
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
     * Calls the frame handler by an address with the `cached` mark.
     *
     * @return \Throwable|null the refusal that aborted the handler
     */
    private function ask_cached_frame(): ?\Throwable {
        $cm = get_fast_modinfo($this->course)->get_cm($this->agora->cmid);
        $caught = null;
        ob_start();
        try {
            mod_agora_pluginfile(
                $this->course,
                $cm,
                $cm->context,
                'preview',
                ['0', preview::CACHED_MARK, 'preview.jpg'],
                false
            );
        } catch (\Throwable $error) {
            $caught = $error;
        }
        ob_end_clean();
        return $caught;
    }

    /**
     * How the handler aborted: with a gate refusal or by sending the frame.
     *
     * They cannot be told apart by the refusal class — under PHPUnit both paths
     * die on `header()` with a core warning. What tells them apart is the called
     * function: `send_file_not_found()` for a refusal versus `send_file()` for
     * a send.
     *
     * @param \Throwable|null $error how the handler aborted
     * @return string 'refused', 'sent' or 'none'
     */
    private function outcome(?\Throwable $error): string {
        if ($error === null) {
            return 'none';
        }
        foreach ($error->getTrace() as $frame) {
            if (($frame['function'] ?? '') === 'send_file_not_found') {
                return 'refused';
            }
            if (($frame['function'] ?? '') === 'send_file') {
                return 'sent';
            }
        }
        return 'none';
    }

    /**
     * Warms the broadcast state through the user door — a `for_stream()` call.
     *
     * @return void
     */
    private function warm_state(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));
        $this->assertSame(stream_state::LIVE, stream_state::for_stream('optics-2026')->state);
        connection::set_client(null);
    }

    /**
     * No state in the cache: a short 404 and not a single request to central.
     *
     * @return void
     */
    public function test_missing_state_answers_404_without_asking_central(): void {
        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);
        $this->setUser($this->student);

        $caught = $this->ask_cached_frame();

        $this->assertSame('none', $this->outcome($caught), 'A short 404, not the error page');
        $this->assertSame([], $client->request_log(), 'No central requests on a miss');
    }

    /**
     * The state is in the cache, the frame is not: the frame is fetched the
     * usual way, but the state is not asked from central a second time.
     *
     * @return void
     */
    public function test_warm_state_fetches_the_frame_without_asking_the_state_again(): void {
        $this->warm_state();

        $client = \mod_agora_mock_central::client([
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-preview',
                'token' => 'jwt-preview',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            'GET /playback/live-preview-jpeg/optics-2026' => \mod_agora_mock_central::image(200, 'LIVEJPEG'),
        ]);
        connection::set_client($client);
        $this->setUser($this->student);

        // The frame is sent by `send_file()`, which under PHPUnit never reaches
        // the body and kills the send with its own refusal: that is how the sent
        // frame is visible.
        $caught = $this->ask_cached_frame();

        $this->assertSame('sent', $this->outcome($caught), 'The frame goes via send_file()');
        $log = $client->request_log();
        $this->assertContains('GET /playback/live-preview-jpeg/optics-2026', $log);
        $this->assertContains('POST /central/api-v4/play-sessions/grants', $log);
        // Not a single state request: it was taken from the cache.
        $stats = array_filter($log, static function (string $line): bool {
            return strpos($line, '/streams/stats') !== false;
        });
        $this->assertSame([], array_values($stats));
    }

    /**
     * The frame is already in the frame cache: nothing goes to central at all.
     *
     * @return void
     */
    public function test_cached_frame_needs_no_requests_at_all(): void {
        $this->warm_state();
        \cache::make('mod_agora', 'previews')->set('optics-2026', [
            'body' => 'JPEGBYTES',
            'fetched_at' => time(),
        ]);

        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);
        $this->setUser($this->student);

        $caught = $this->ask_cached_frame();

        $this->assertSame('sent', $this->outcome($caught), 'The frame goes via send_file()');
        $this->assertSame([], $client->request_log());
    }

    /**
     * The neighbour frame address is given by the state and marked `cached`.
     *
     * @return void
     */
    public function test_neighbour_url_follows_the_state(): void {
        $context = \context_module::instance($this->agora->cmid);
        $now = time();

        // A broadcast that never ran has no frame — and no address either.
        $notstarted = new stream_state(stream_state::NOT_STARTED, null, null, $now);
        $this->assertNull(preview::cached_url($context, 'optics-2026', $notstarted));

        $live = new stream_state(stream_state::LIVE, null, null, $now);
        $url = preview::cached_url($context, 'optics-2026', $live);
        $this->assertNotNull($url);
        $this->assertStringContainsString('/' . preview::CACHED_MARK . '/', $url->out(false));
    }

    /**
     * The rights to a neighbour frame are checked as before: an unenrolled user gets nothing.
     *
     * @return void
     */
    public function test_outsider_gets_nothing_even_with_a_warm_cache(): void {
        $this->warm_state();
        \cache::make('mod_agora', 'previews')->set('optics-2026', [
            'body' => 'JPEGBYTES',
            'fetched_at' => time(),
        ]);
        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);
        $this->setUser($this->getDataGenerator()->create_user());

        $caught = $this->ask_cached_frame();

        // Exactly a gate refusal, not a frame send: the log is empty in both
        // cases, and only which core function fired tells them apart.
        $this->assertSame('refused', $this->outcome($caught), 'Without the watch capability the frame is not served');
        $this->assertSame([], $client->request_log());
    }
}
