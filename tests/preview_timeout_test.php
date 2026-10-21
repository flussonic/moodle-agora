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

use GuzzleHttp\Exception\ConnectException;
use mod_agora\local\connection;
use mod_agora\local\preview;
use mod_agora\local\stream_state;
use Psr\Http\Message\RequestInterface;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * The preview frame waiting limit: the request does not hang, the refusal is named and cached.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\preview
 */
final class preview_timeout_test extends \advanced_testcase {
    /** @var \cm_info the course module */
    private $cm;

    /**
     * A course with an activity for a broadcast and a connection to the stub Agora.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        connection::set_client(null);

        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');

        $course = $this->getDataGenerator()->create_course();
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
        ]);
        $this->cm = get_fast_modinfo($course)->get_cm($agora->cmid);
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
     * @param array $extra the frame routes and the rest
     * @return array
     */
    private function routes(array $extra = []): array {
        return $extra + [
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-preview',
                'token' => 'jwt-preview',
                'expires_at' => gmdate('c', time() + 3600),
                'status' => 'issued',
            ]),
        ];
    }

    /**
     * How many frame requests went to the player frontend.
     *
     * @param \mod_agora\local\agora_client $client the stub client
     * @return int
     */
    private function frame_requests($client): int {
        return count(array_filter($client->request_log(), function (string $line): bool {
            return strpos($line, '/playback/live-preview-jpeg/') !== false;
        }));
    }

    /**
     * The frame request goes with its own timeouts, not with the shared ones.
     *
     * Without them a hung node would hold a PHP worker for the client's shared
     * timeout — one worker per every course topic card.
     *
     * @return void
     */
    public function test_frame_request_carries_its_own_timeouts(): void {
        $client = \mod_agora_mock_central::client($this->routes([
            'GET /playback/live-preview-jpeg/optics-2026' => \mod_agora_mock_central::image(200, 'LIVEJPEG'),
        ]));
        connection::set_client($client);

        $this->assertSame('LIVEJPEG', preview::frame('optics-2026', $this->cm->context));

        $options = \mod_agora_mock_central::options_for('/playback/live-preview-jpeg/');
        $this->assertNotNull($options, 'The frame request reached the substituted central');
        $this->assertEquals(preview::CONNECT_TIMEOUT, $options['connect_timeout'] ?? null);
        $this->assertEquals(preview::FETCH_TIMEOUT, $options['timeout'] ?? null);
        $this->assertSame(3, preview::CONNECT_TIMEOUT);
        $this->assertSame(5, preview::FETCH_TIMEOUT);
    }

    /**
     * The other requests do not get their own timeouts: they keep the client shared timeouts.
     *
     * @return void
     */
    public function test_own_timeouts_belong_to_the_frame_request_only(): void {
        $client = \mod_agora_mock_central::client($this->routes([
            'GET /playback/live-preview-jpeg/optics-2026' => \mod_agora_mock_central::image(200, 'LIVEJPEG'),
        ]));
        connection::set_client($client);

        preview::frame('optics-2026', $this->cm->context);

        $options = \mod_agora_mock_central::options_for('/play-sessions/grants');
        $this->assertNotNull($options, 'The preview ticket was issued');
        $this->assertSame(20, $options['timeout'] ?? null);
    }

    /**
     * The node did not answer: no frame, the reason is named, we do not wait a second time.
     *
     * @return void
     */
    public function test_timeout_is_logged_and_cached(): void {
        $client = \mod_agora_mock_central::client($this->routes([
            'GET /playback/live-preview-jpeg/optics-2026' => function (RequestInterface $request) {
                return new ConnectException('cURL error 28: Operation timed out after 5001 milliseconds', $request);
            },
        ]));
        connection::set_client($client);

        $sink = $this->redirectEvents();
        $this->assertNull(preview::frame('optics-2026', $this->cm->context));
        // The second page show within the same minute does not go to the node at all.
        $this->assertNull(preview::frame('optics-2026', $this->cm->context));
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame(1, $this->frame_requests($client), 'The frame is not re-requested after a refusal');

        $missing = array_values(array_filter($events, function ($event): bool {
            return $event instanceof \mod_agora\event\preview_missing;
        }));
        $this->assertCount(1, $missing, 'One log record per broadcast per hour');
        $this->assertSame('network', $missing[0]->other['reason']);
        $this->assertSame(0, (int) $missing[0]->other['status'], 'No answer at all');
        $this->assertStringContainsString('network', $missing[0]->get_description());

        // No frame — the card is drawn without the picture.
        $state = stream_state::for_stream('optics-2026');
        $this->assertNull(preview::url($this->cm->context, 'optics-2026', $state));
    }

    /**
     * A refusal of the service ticket issue is also named, not swallowed.
     *
     * @return void
     */
    public function test_refused_service_grant_is_logged(): void {
        $client = \mod_agora_mock_central::client($this->routes([
            'POST /play-sessions/grants' => \mod_agora_mock_central::error(403, 'forbidden', 'role is not allowed'),
        ]));
        connection::set_client($client);

        $sink = $this->redirectEvents();
        $this->assertNull(preview::frame('optics-2026', $this->cm->context));
        $events = $sink->get_events();
        $sink->close();

        $missing = array_values(array_filter($events, function ($event): bool {
            return $event instanceof \mod_agora\event\preview_missing;
        }));
        $this->assertCount(1, $missing, 'The ticket refusal is visible in the log');
        $this->assertSame('role', $missing[0]->other['reason']);
        $this->assertSame(403, (int) $missing[0]->other['status']);
        $this->assertSame(0, $this->frame_requests($client), 'No frame request without a ticket');
    }

    /**
     * The «no frame» records from the intercepted events.
     *
     * @param array $events the events
     * @return \mod_agora\event\preview_missing[]
     */
    private static function missing(array $events): array {
        return array_values(array_filter($events, function ($event): bool {
            return $event instanceof \mod_agora\event\preview_missing;
        }));
    }

    /**
     * The frame deadline is one for the whole path including the hops to the node.
     *
     * The Guzzle response limit applies to each hop separately: five hops of
     * five seconds would give twenty five. Here the first hop takes three
     * seconds, the second — a bit over two, and there is no third one: the
     * deadline is over, there is no frame, the reason is `network`.
     *
     * @return void
     */
    public function test_redirects_share_one_deadline(): void {
        $client = \mod_agora_mock_central::client($this->routes([
            'GET /playback/live-preview-jpeg/optics-2026' => function () {
                sleep(3);
                return new \GuzzleHttp\Psr7\Response(302, [
                    'Location' => 'https://node1.example.com/hop2/live-preview-jpeg/optics-2026?token=jwt-preview',
                ]);
            },
            'GET /hop2/live-preview-jpeg/optics-2026' => function () {
                usleep(2100000);
                return new \GuzzleHttp\Psr7\Response(302, ['Location' => '/hop3/live-preview-jpeg/optics-2026']);
            },
            'GET /hop3/live-preview-jpeg/optics-2026' => \mod_agora_mock_central::image(200, 'LATEJPEG'),
        ]));
        connection::set_client($client);

        $sink = $this->redirectEvents();
        $frame = preview::frame('optics-2026', $this->cm->context);
        $missing = self::missing($sink->get_events());
        $sink->close();

        $this->assertNull($frame, 'A frame arriving after the deadline is not served');
        $hop2 = \mod_agora_mock_central::options_for('/hop2/');
        $this->assertNotNull($hop2, 'The hop to the node happened');
        $this->assertLessThanOrEqual(2.0, (float) $hop2['timeout'], 'The second hop gets only the remaining deadline');
        $this->assertNull(\mod_agora_mock_central::options_for('/hop3/'), 'No hops after the deadline');
        $this->assertCount(1, $missing);
        $this->assertSame('network', $missing[0]->other['reason']);
    }

    /**
     * Every «no frame» reason is described in its own words, not «no thumbnails
     * section»: from the log record the administrator must understand what to fix.
     *
     * @return array
     */
    public static function reasons_provider(): array {
        $grant = function (\Psr\Http\Message\ResponseInterface $answer): array {
            return ['POST /play-sessions/grants' => $answer];
        };
        return [
            'no thumbnails section (404)' => [
                ['GET /playback/live-preview-jpeg/optics-2026' => \mod_agora_mock_central::image(404, '')],
                'http', 404, 'thumbnails',
            ],
            'node rejected the service ticket (403)' => [
                ['GET /playback/live-preview-jpeg/optics-2026' => \mod_agora_mock_central::image(403, '')],
                'http', 403, 'refused the service ticket',
            ],
            'node did not answer' => [
                ['GET /playback/live-preview-jpeg/optics-2026' => new \GuzzleHttp\Exception\ConnectException(
                    'Connection refused',
                    new \GuzzleHttp\Psr7\Request('GET', 'https://node1.example.com/')
                )],
                'network', 0, 'The node did not answer',
            ],
            'player role' => [
                $grant(\mod_agora_mock_central::error(403, 'forbidden', 'role')),
                'role', 403, 'role of the player account',
            ],
            'player password' => [
                ['POST /auth/login' => \mod_agora_mock_central::error(401, 'unauthorized', 'bad password')],
                'password', 401, 'Password of the player account',
            ],
            'license without accounts' => [
                ['POST /auth/login' => \mod_agora_mock_central::error(404, 'not_found', 'no accounts')],
                'license', 404, 'licence',
            ],
            'login rate' => [
                ['POST /auth/login' => \mod_agora_mock_central::error(429, 'rate', 'slow down')],
                'ratelimited', 429, 'too many sign-ins',
            ],
            'garbage instead of a ticket' => [
                $grant(new \GuzzleHttp\Psr7\Response(200, [], '[]')),
                'badresponse', 200, 'not a JSON object',
            ],
            '404 on ticket issue' => [
                $grant(\mod_agora_mock_central::error(404, 'not_found', 'no route')),
                'notfound', 404, 'answered 404 to the ticket request',
            ],
            'central silent on ticket issue' => [
                ['POST /auth/login' => new \GuzzleHttp\Exception\ConnectException(
                    'Connection refused',
                    new \GuzzleHttp\Psr7\Request('POST', 'https://agora.example.com/')
                )],
                'network', 0, 'The Agora server did not answer the service ticket request',
            ],
            'central error on ticket issue' => [
                $grant(\mod_agora_mock_central::error(500, 'internal', 'db is down')),
                'http', 500, 'answered the service ticket request with an error',
            ],
        ];
    }

    /**
     * The refusal reason is in the log record description in its own words.
     *
     * @dataProvider reasons_provider
     * @param array $routes the routes that break the frame path
     * @param string $reason the expected reason word
     * @param int $status the expected code
     * @param string $words what the description must say
     * @return void
     */
    public function test_each_reason_is_described_in_its_own_words(
        array $routes,
        string $reason,
        int $status,
        string $words
    ): void {
        connection::set_client(\mod_agora_mock_central::client($this->routes($routes)));
        // The broadcast state is ready, like for a neighbour frame: a login
        // refusal would break the state request too, while the frame path is
        // what is checked here.
        $live = new stream_state(
            stream_state::LIVE,
            new \mod_agora\local\stream_facts(\mod_agora_mock_central::stats_live()),
            null,
            time()
        );

        $sink = $this->redirectEvents();
        $this->assertNull(preview::frame('optics-2026', $this->cm->context, $live));
        $missing = self::missing($sink->get_events());
        $sink->close();

        $this->assertCount(1, $missing);
        $this->assertSame($reason, $missing[0]->other['reason']);
        $this->assertSame($status, (int) $missing[0]->other['status']);
        $description = $missing[0]->get_description();
        $this->assertStringContainsString($words, $description);
        // The refusing side is in the record: the node (the frame itself) or central (the ticket).
        $operation = (string) ($missing[0]->other['operation'] ?? '');
        $this->assertNotSame('', $operation, 'The record names the refusal step');
        $this->assertStringContainsString(
            $operation === 'oppreviewframe' ? 'step: the frame request' : 'step: the service ticket',
            $description
        );
        if ($status !== 404 || $reason !== 'http') {
            $this->assertStringNotContainsString('thumbnails', $description, 'A foreign reason is not named');
        }
    }

    /**
     * An empty site label — «the connection is not configured», not «no thumbnails».
     *
     * @return void
     */
    public function test_empty_site_label_is_described_as_not_configured(): void {
        set_config('sitelabel', '', 'mod_agora');
        connection::set_client(\mod_agora_mock_central::client($this->routes()));

        $sink = $this->redirectEvents();
        preview::frame('optics-2026', $this->cm->context);
        $missing = self::missing($sink->get_events());
        $sink->close();

        $this->assertSame('notconfigured', $missing[0]->other['reason']);
        $this->assertStringContainsString('not configured', $missing[0]->get_description());
        $this->assertStringNotContainsString('thumbnails', $missing[0]->get_description());
    }

    /**
     * Reasons unreachable through the page door (the neighbour lock, a Moodle
     * failure itself) are described too — by the record, as `preview::frame()`
     * would have made it.
     *
     * @return void
     */
    public function test_rare_reasons_are_described(): void {
        $describe = function (string $reason, int $status): string {
            return \mod_agora\event\preview_missing::create([
                'context' => $this->cm->context,
                'other' => ['stream' => 'optics-2026', 'status' => $status, 'reason' => $reason],
            ])->get_description();
        };

        $this->assertStringContainsString('not a refusal by Agora', $describe('busy', 0));
        $this->assertStringContainsString('conflict', $describe('conflict', 409));
        $this->assertStringContainsString('Moodle failed on its side (dml_read_exception)', $describe('dml_read_exception', 0));
        // A record made before the reason appeared reads as the node's answer.
        $old = \mod_agora\event\preview_missing::create([
            'context' => $this->cm->context,
            'other' => ['stream' => 'optics-2026', 'status' => 404, 'reason' => 'http'],
        ]);
        $this->assertStringContainsString('thumbnails', $old->get_description());
    }

    /**
     * A 404 record does not hide the next, different reason for an hour: the log
     * record key is the broadcast and the reason. The same reason is not written
     * twice within an hour.
     *
     * @return void
     */
    public function test_a_new_reason_is_logged_within_the_hour(): void {
        $client = \mod_agora_mock_central::client($this->routes([
            'GET /playback/live-preview-jpeg/optics-2026' => [
                \mod_agora_mock_central::image(404, ''),
                new \GuzzleHttp\Exception\ConnectException(
                    'Connection refused',
                    new \GuzzleHttp\Psr7\Request('GET', 'https://node1.example.com/')
                ),
            ],
        ]));
        connection::set_client($client);
        $previews = \cache::make('mod_agora', 'previews');

        $sink = $this->redirectEvents();
        preview::frame('optics-2026', $this->cm->context);
        // The «no frame» minute has passed — the next show goes to the node again.
        $previews->delete('optics-2026');
        preview::frame('optics-2026', $this->cm->context);
        $previews->delete('optics-2026');
        preview::frame('optics-2026', $this->cm->context);
        $missing = self::missing($sink->get_events());
        $sink->close();

        $this->assertSame(3, $this->frame_requests($client), 'The node is asked three times');
        $this->assertSame(['http', 'network'], array_map(function ($event): string {
            return $event->other['reason'];
        }, $missing), 'A new reason recorded, a repeat of the same — not');
    }
}
