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
use mod_agora\local\player_view;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/fixtures/view_script.php');

/**
 * The archive window in the player address: the recording of a stopped
 * broadcast opens with the `from`/`to` bounds, a live one — without them.
 *
 * Without the window the embedded player stops at the live edge, and for a
 * stopped broadcast there is nothing there: the feed does not play at all.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\player_view
 * @covers     \mod_agora\local\agora_client
 */
final class record_window_test extends \advanced_testcase {
    /** The archive left bound in the player frontend answer, ms. */
    const FROM = 1790022900000;

    /** The archive right bound in the player frontend answer, ms. */
    const TO = 1790023080000;

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

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $module = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture',
            'stream_name' => 'optics-2026',
        ]);
        $this->agora = $module;
        $this->cm = get_fast_modinfo($this->course)->get_cm($module->cmid);
        $PAGE->set_url('/mod/agora/view.php', ['id' => $module->cmid]);
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
     * The routes of the stub Agora.
     *
     * @param \GuzzleHttp\Psr7\Response $stats the `streams/stats` answer
     * @param mixed $range the answer to the archive bounds request; null — no route
     * @return array
     */
    private function routes($stats, $range = null): array {
        $routes = [
            'GET /streams/stats/optics-2026' => $stats,
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-1',
                'token' => 'jwt-1',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
        ];
        if ($range !== null) {
            $routes['GET /playback/dvr-range/optics-2026'] = $range;
        }
        return $routes;
    }

    /**
     * The answer «broadcast stopped, there is an archive».
     *
     * @return \GuzzleHttp\Psr7\Response
     */
    private function recorded_stats(): \GuzzleHttp\Psr7\Response {
        return \mod_agora_mock_central::json(200, [
            'name' => 'optics-2026',
            'status' => 'idle',
            'status_since' => '2026-09-20T20:38:00Z',
            'dvr' => [
                'recording' => false,
                'from' => '2026-09-20T20:00:00Z',
                'to' => '2026-09-20T21:00:00Z',
                'written_until' => '2026-09-20T20:38:00.000Z',
            ],
        ]);
    }

    /**
     * The answer «broadcast is running, and it already has an archive».
     *
     * @return \GuzzleHttp\Psr7\Response
     */
    private function live_with_record_stats(): \GuzzleHttp\Psr7\Response {
        return \mod_agora_mock_central::json(200, [
            'name' => 'optics-2026',
            'status' => 'running',
            'status_since' => gmdate('c', time() - 600),
            'inputs' => [['current' => true, 'status' => 'working']],
            'dvr' => [
                'recording' => true,
                'from' => gmdate('c', time() - 3600),
                'to' => gmdate('c', time() + 3600),
                'written_until' => gmdate('Y-m-d\TH:i:s', time() - 1) . '.000Z',
            ],
        ]);
    }

    /**
     * The player frontend answer with the archive bounds.
     *
     * @return \GuzzleHttp\Psr7\Response
     */
    private function range(): \GuzzleHttp\Psr7\Response {
        return \mod_agora_mock_central::json(200, ['from' => self::FROM, 'to' => self::TO]);
    }

    /**
     * The iframe address from the page.
     *
     * @param string $html the page markup
     * @return string
     */
    private function iframe_src(string $html): string {
        $this->assertSame(1, preg_match('#<iframe[^>]+src="([^"]+)"#', $html, $matches), 'The player is rendered');
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    /**
     * The recording of a stopped broadcast opens with the archive window.
     *
     * @return void
     */
    public function test_stopped_stream_record_carries_the_archive_window(): void {
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->recorded_stats(), $this->range())));

        $src = $this->iframe_src(player_view::scene($this->agora, $this->cm, (int) $this->student->id));

        $this->assertStringContainsString('dvr=true', $src);
        $this->assertStringContainsString('from=' . self::FROM, $src);
        $this->assertStringContainsString('to=' . self::TO, $src);
    }

    /**
     * The «recording only» mode of a stopped broadcast — also with the window.
     *
     * @return void
     */
    public function test_record_only_mode_carries_the_archive_window(): void {
        global $DB;

        $DB->set_field('agora', 'display_mode', 'record_only', ['id' => $this->agora->id]);
        $this->agora->display_mode = 'record_only';
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->recorded_stats(), $this->range())));

        $src = $this->iframe_src(player_view::scene($this->agora, $this->cm, (int) $this->student->id));

        $this->assertStringContainsString('from=' . self::FROM, $src);
        $this->assertStringContainsString('to=' . self::TO, $src);
    }

    /**
     * A live broadcast has no window: it would close the «to live» transition.
     *
     * @return void
     */
    public function test_live_stream_record_has_no_window(): void {
        $client = \mod_agora_mock_central::client($this->routes($this->live_with_record_stats(), $this->range()));
        connection::set_client($client);

        // The learner pressed «watch the recording» on a running broadcast.
        $src = $this->iframe_src(player_view::scene($this->agora, $this->cm, (int) $this->student->id, true));

        $this->assertStringContainsString('dvr=true', $src);
        $this->assertStringNotContainsString('from=', $src);
        $this->assertStringNotContainsString('to=', $src);
        $this->assertNotContains('GET /playback/dvr-range/optics-2026', $client->request_log());
    }

    /**
     * No bounds (404): the player is still drawn, and the reason goes to the log.
     *
     * @return void
     */
    public function test_missing_range_leaves_the_player_without_a_window(): void {
        connection::set_client(\mod_agora_mock_central::client($this->routes(
            $this->recorded_stats(),
            \mod_agora_mock_central::error(404, 'not_found', 'no archive')
        )));

        $sink = $this->redirectEvents();
        $src = $this->iframe_src(player_view::scene($this->agora, $this->cm, (int) $this->student->id));
        $events = $sink->get_events();
        $sink->close();

        $this->assertStringContainsString('dvr=true', $src);
        $this->assertStringNotContainsString('from=', $src);

        $failures = array_filter($events, static function (\core\event\base $event): bool {
            return $event instanceof \mod_agora\event\central_failed;
        });
        $this->assertCount(1, $failures, 'The reason is named in the event log');
    }

    /**
     * The second show within a minute makes no second bounds request.
     *
     * @return void
     */
    public function test_range_is_asked_once_a_minute(): void {
        $client = \mod_agora_mock_central::client($this->routes($this->recorded_stats(), $this->range()));
        connection::set_client($client);

        player_view::scene($this->agora, $this->cm, (int) $this->student->id);
        player_view::scene($this->agora, $this->cm, (int) $this->student->id);

        $asked = array_filter($client->request_log(), static function (string $line): bool {
            return strpos($line, '/playback/dvr-range/') !== false;
        });
        $this->assertCount(1, $asked);
    }

    /**
     * An empty window is the same as «no archive»: bounds with nothing to play
     * in them are not passed.
     *
     * @return void
     */
    public function test_empty_window_is_not_passed(): void {
        connection::set_client(\mod_agora_mock_central::client($this->routes(
            $this->recorded_stats(),
            \mod_agora_mock_central::json(200, ['from' => self::TO, 'to' => self::TO])
        )));

        $src = $this->iframe_src(player_view::scene($this->agora, $this->cm, (int) $this->student->id));

        $this->assertStringNotContainsString('from=', $src);
    }

    /**
     * Routes where the node behaves like the real one: the pair ticket is bound
     * to the learner's address, and the bounds are asked by the Moodle server
     * from another address — the node refuses such a ticket (403). The
     * broadcast's service ticket without binding passes.
     *
     * @return array
     */
    private function bound_routes(): array {
        return [
            'GET /streams/stats/optics-2026' => $this->recorded_stats(),
            'POST /play-sessions/grants' => function (\Psr\Http\Message\RequestInterface $request) {
                $body = json_decode((string) $request->getBody(), true);
                $service = strpos((string) ($body['user_id'] ?? ''), ':service:') !== false;
                return \mod_agora_mock_central::json(201, [
                    'jti' => $service ? 'jti-service' : 'jti-pair',
                    'token' => $service ? 'jwt-service' : 'jwt-pair',
                    'expires_at' => gmdate('c', time() + HOURSECS),
                ]);
            },
            'GET /playback/dvr-range/optics-2026' => function (\Psr\Http\Message\RequestInterface $request) {
                parse_str($request->getUri()->getQuery(), $query);
                if (($query['token'] ?? '') !== 'jwt-service') {
                    return \mod_agora_mock_central::error(403, 'forbidden', 'ticket is bound to another address');
                }
                return $this->range();
            },
        ];
    }

    /**
     * The ticket binding to the address is on (by default) — the recording
     * still opens on its window: the bounds are asked with the broadcast's
     * service ticket.
     *
     * The end-to-end run of 29.09.2026 saw the opposite: the Moodle server
     * presented the node the pair ticket bound to the learner's address, got
     * 403, and the recording opened as a boundless feed for every learner.
     *
     * @return void
     */
    public function test_bound_ticket_does_not_break_the_archive_window(): void {
        $this->assertNotEquals('0', (string) get_config('mod_agora', 'bindip'), 'Binding enabled, as by default');
        $client = \mod_agora_mock_central::client($this->bound_routes());
        connection::set_client($client);

        $src = $this->iframe_src(player_view::scene($this->agora, $this->cm, (int) $this->student->id));

        $this->assertStringContainsString('from=' . self::FROM, $src);
        $this->assertStringContainsString('to=' . self::TO, $src);
        // The player gets the pair ticket, the node gets the service one for the bounds.
        $this->assertStringContainsString('token=jwt-pair', $src);

        $service = null;
        foreach (\mod_agora_mock_central::requests() as $request) {
            $body = json_decode((string) $request->getBody(), true);
            if ($request->getMethod() === 'POST' && strpos((string) ($body['user_id'] ?? ''), ':service:') !== false) {
                $service = $body;
            }
        }
        $this->assertNotNull($service, 'The service ticket issued');
        $this->assertSame(['optics-2026'], $service['streams'], 'Coverage — one broadcast');
        $this->assertSame(10, $service['max_concurrent'], 'Screens with room for the Moodle farm, but not unlimited');
        $this->assertArrayNotHasKey('bind_ip', $service, 'Service ticket without address binding');
    }

    /**
     * A refused bounds request names the operation in the log and is remembered
     * for less than an answer: 15 seconds, not a minute.
     *
     * @return void
     */
    public function test_refused_range_names_the_operation_and_is_retried_soon(): void {
        $client = \mod_agora_mock_central::client($this->routes(
            $this->recorded_stats(),
            \mod_agora_mock_central::error(403, 'forbidden', 'nope')
        ));
        connection::set_client($client);

        $sink = $this->redirectEvents();
        player_view::scene($this->agora, $this->cm, (int) $this->student->id);
        player_view::scene($this->agora, $this->cm, (int) $this->student->id);
        $events = array_values(array_filter($sink->get_events(), static function (\core\event\base $event): bool {
            return $event instanceof \mod_agora\event\central_failed;
        }));
        $sink->close();

        $this->assertCount(1, $events, 'The second watch within the refusal lifetime goes nowhere');
        $this->assertSame('oparchiverange', $events[0]->other['operation']);
        $this->assertStringContainsString('oparchiverange', $events[0]->get_description());
        $this->assertCount(1, self::range_requests($client));

        // The refusal term (15 s) has passed — the next show asks again.
        $cache = \cache::make('mod_agora', 'dvrrange');
        $entry = $cache->get('optics-2026');
        // Without the timestamp mark a refusal would live the whole answer
        // term — a minute.
        $this->assertArrayHasKey('failed_at', $entry, 'The refusal remembered as a refusal, not an answer');
        $entry['failed_at'] = time() - 16;
        $cache->set('optics-2026', $entry);
        player_view::scene($this->agora, $this->cm, (int) $this->student->id);

        $this->assertCount(2, self::range_requests($client));
    }

    /**
     * «Recording only» during a broadcast with an already recording archive —
     * the placeholder, not a feed with a live edge and a «to live» button. And
     * `?record=1` does not work around it.
     *
     * @return void
     */
    public function test_record_only_during_live_with_archive_shows_placeholder(): void {
        global $DB;

        $DB->set_field('agora', 'display_mode', 'record_only', ['id' => $this->agora->id]);
        $this->agora->display_mode = 'record_only';
        $client = \mod_agora_mock_central::client($this->routes($this->live_with_record_stats(), $this->range()));
        connection::set_client($client);

        foreach ([false, true] as $wantrecord) {
            $html = player_view::scene($this->agora, $this->cm, (int) $this->student->id, $wantrecord);

            $this->assertStringContainsString(get_string('placeholdernorecord', 'mod_agora'), $html);
            $this->assertStringNotContainsString('<iframe', $html);
        }
        $this->assertSame(0, $DB->count_records('agora_grants'), 'No ticket issued');
        $this->assertSame([], self::range_requests($client));
    }

    /**
     * The node refused the service ticket (403) — the ticket is extinguished,
     * and the next show issues a new one instead of presenting the refused one
     * for another hour. The refusal is signed by the service ticket, not by
     * the `player` account.
     *
     * @return void
     */
    public function test_refused_service_ticket_is_replaced(): void {
        global $DB;

        $client = \mod_agora_mock_central::client($this->routes(
            $this->recorded_stats(),
            [\mod_agora_mock_central::error(403, 'forbidden', 'ticket revoked'), $this->range()]
        ));
        connection::set_client($client);

        $sink = $this->redirectEvents();
        player_view::scene($this->agora, $this->cm, (int) $this->student->id);
        $events = array_values(array_filter($sink->get_events(), static function (\core\event\base $event): bool {
            return $event instanceof \mod_agora\event\central_failed;
        }));
        $sink->close();

        $this->assertSame(
            0,
            $DB->count_records('agora_grants', ['purpose' => 'preview', 'status' => 'issued']),
            'The rejected service ticket redeemed'
        );
        $this->assertSame('oparchiverange', $events[0]->other['operation']);
        $this->assertSame('service', $events[0]->other['account'], 'The refusal signed by the service ticket');
        $this->assertStringContainsString('as service ticket', $events[0]->get_description());

        // The refusal term has passed — a new show, a new service ticket, there are bounds.
        $cache = \cache::make('mod_agora', 'dvrrange');
        $entry = $cache->get('optics-2026');
        $entry['failed_at'] = time() - 16;
        $cache->set('optics-2026', $entry);
        $src = $this->iframe_src(player_view::scene($this->agora, $this->cm, (int) $this->student->id));

        $this->assertStringContainsString('from=' . self::FROM, $src);
        $this->assertSame(2, count(array_filter(\mod_agora_mock_central::requests(), static function ($request): bool {
            $body = json_decode((string) $request->getBody(), true);
            return strpos((string) ($body['user_id'] ?? ''), ':service:') !== false;
        })), 'The second service ticket issued');
    }

    /**
     * A node refusal of the bounds request names the service ticket, not `player`.
     *
     * @return void
     */
    public function test_range_refusal_is_signed_by_the_service_ticket(): void {
        $client = \mod_agora_mock_central::client([
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-s', 'token' => 'jwt-s', 'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            'GET /playback/dvr-range/optics-2026' => \mod_agora_mock_central::error(403, 'forbidden', 'no'),
        ]);
        connection::set_client($client);

        try {
            $client->archive_range('optics-2026', 'jwt-s');
            $this->fail('403 must become a refusal');
        } catch (\mod_agora\local\agora_exception $error) {
            $this->assertSame('service', $error->role);
        }
    }

    /**
     * A network failure of the bounds request is signed by the client itself —
     * by the operation and the service ticket — and not only by the fallback
     * value of the log.
     *
     * @return void
     */
    public function test_network_refusal_of_the_range_is_signed_by_the_client(): void {
        $client = \mod_agora_mock_central::client([
            'GET /playback/dvr-range/optics-2026' => new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('GET', \mod_agora_mock_central::HOST . '/playback/dvr-range/optics-2026')
            ),
        ]);

        try {
            $client->archive_range('optics-2026', 'jwt-s');
            $this->fail('A network failure must become a refusal');
        } catch (\mod_agora\local\agora_exception $error) {
            $this->assertSame('network', $error->reason);
            $this->assertSame('oparchiverange', $error->operation);
            $this->assertSame('service', $error->role);
        }
    }

    /**
     * «Recording only» during a signal loss — also «broadcast is running»: the
     * «the recording will appear after the broadcast» placeholder, no «watch
     * the recording» transition.
     *
     * @return void
     */
    public function test_record_only_during_signal_loss_shows_placeholder(): void {
        global $DB;

        $DB->set_field('agora', 'display_mode', 'record_only', ['id' => $this->agora->id]);
        $this->agora->display_mode = 'record_only';
        $client = \mod_agora_mock_central::client($this->routes(
            \mod_agora_mock_central::recorded_stats(60),
            $this->range()
        ));
        connection::set_client($client);

        $this->assertSame(
            \mod_agora\local\stream_state::SIGNAL_LOST,
            \mod_agora\local\stream_state::for_page('optics-2026')->state
        );
        // Both without the recording request and by an address with `?record=1` — the same placeholder.
        foreach ([false, true] as $wantrecord) {
            $html = player_view::scene($this->agora, $this->cm, (int) $this->student->id, $wantrecord);
            $actions = array_column(player_view::actions($this->agora, $this->cm, $wantrecord), 'key');

            $this->assertStringContainsString(get_string('placeholdernorecord', 'mod_agora'), $html);
            $this->assertStringNotContainsString('<iframe', $html);
            $this->assertNotContains('record', $actions, 'No «watch recording» transition');
            $this->assertNotContains('live', $actions, 'No «go live» transition');
        }
    }

    /**
     * The `central_failed` records from the intercepted events.
     *
     * @param array $events the events
     * @return array
     */
    private static function central_failures(array $events): array {
        return array_values(array_filter($events, static function (\core\event\base $event): bool {
            return $event instanceof \mod_agora\event\central_failed;
        }));
    }

    /**
     * 404 on issuing the service ticket — a refusal, not «no archive»: it is
     * remembered for 15 seconds, and after 16 seconds the bounds are asked again.
     *
     * Formerly any 404 on the path to the bounds was cached for a minute as an
     * «no archive» answer, although the node was not even asked about the bounds.
     *
     * @return void
     */
    public function test_grant_404_is_a_refusal_not_a_missing_archive(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => $this->recorded_stats(),
            'POST /play-sessions/grants' => function (\Psr\Http\Message\RequestInterface $request) {
                $body = json_decode((string) $request->getBody(), true);
                if (strpos((string) ($body['user_id'] ?? ''), ':service:') !== false) {
                    return \mod_agora_mock_central::error(404, 'not_found', 'no such route');
                }
                return \mod_agora_mock_central::json(201, [
                    'jti' => 'jti-pair', 'token' => 'jwt-pair', 'expires_at' => gmdate('c', time() + HOURSECS),
                ]);
            },
            'GET /playback/dvr-range/optics-2026' => $this->range(),
        ]);
        connection::set_client($client);
        $this->setUser($this->student);

        $sink = $this->redirectEvents();
        $src = $this->iframe_src(\mod_agora_view_script::run((int) $this->cm->id));
        $failures = self::central_failures($sink->get_events());
        $sink->close();

        $this->assertStringNotContainsString('from=', $src);
        $this->assertSame('opcreategrant', $failures[0]->other['operation'] ?? null, 'The refusal named as ticket issue');
        $entry = \cache::make('mod_agora', 'dvrrange')->get('optics-2026');
        $this->assertArrayHasKey('failed_at', $entry, 'Ticket-issue 404 remembered as a refusal, not as «no archive»');

        $entry['failed_at'] = time() - 16;
        \cache::make('mod_agora', 'dvrrange')->set('optics-2026', $entry);
        \mod_agora_view_script::run((int) $this->cm->id);
        $this->assertCount(2, array_filter(\mod_agora_mock_central::requests(), static function ($request): bool {
            $body = json_decode((string) $request->getBody(), true);
            return strpos((string) ($body['user_id'] ?? ''), ':service:') !== false;
        }), '16 seconds later the service ticket is asked again');
    }

    /**
     * A network failure of the bounds request is signed by the operation and
     * the service ticket.
     *
     * @return void
     */
    public function test_network_refusal_of_the_range_names_operation_and_account(): void {
        connection::set_client(\mod_agora_mock_central::client($this->routes(
            $this->recorded_stats(),
            new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('GET', \mod_agora_mock_central::HOST . '/playback/dvr-range/optics-2026')
            )
        )));
        $this->setUser($this->student);

        $sink = $this->redirectEvents();
        \mod_agora_view_script::run((int) $this->cm->id);
        $failures = self::central_failures($sink->get_events());
        $sink->close();

        $this->assertCount(1, $failures);
        $this->assertSame('oparchiverange', $failures[0]->other['operation']);
        $this->assertSame('service', $failures[0]->other['account']);
        $this->assertSame(0, (int) $failures[0]->other['status'], 'No answer');
        $this->assertSame('network', $failures[0]->other['reason']);
        $this->assertStringNotContainsString('unknown operation', $failures[0]->get_description());
    }

    /**
     * A refusal not from Agora on the path to the bounds (a lock, the database)
     * is also written to the log: a recording without bounds must not be
     * unexplainable.
     *
     * @return void
     */
    public function test_moodle_failure_on_the_range_is_logged(): void {
        global $CFG;

        connection::set_client(\mod_agora_mock_central::client($this->routes($this->recorded_stats(), $this->range())));
        $this->setUser($this->student);
        $state = \mod_agora\local\stream_state::for_page('optics-2026');

        // The service ticket lock is not taken: the lock factory is broken.
        $saved = $CFG->lock_factory ?? null;
        $CFG->lock_factory = '\\mod_agora\\no_such_lock_factory';
        $sink = $this->redirectEvents();
        try {
            $src = $this->iframe_src(player_view::scene($this->agora, $this->cm, (int) $this->student->id, false, $state));
        } finally {
            $CFG->lock_factory = $saved;
        }
        $failures = self::central_failures($sink->get_events());
        $sink->close();

        $this->assertStringNotContainsString('from=', $src, 'A player without bounds, the page did not fail');
        $this->assertCount(1, $failures, 'The reason in the event log');
        // The lock is a step of issuing the service ticket, the bounds were never reached.
        $this->assertSame('opissueservice', $failures[0]->other['operation']);
        $this->assertSame('service', $failures[0]->other['account']);
        $this->assertSame('coding_exception', $failures[0]->other['reason']);
    }

    /**
     * The fallback operation and account of the log are filled only where the
     * refusal did not name them: the refusal's own is more important.
     *
     * @return void
     */
    public function test_failure_log_fills_only_missing_fields(): void {
        $context = \context_module::instance($this->cm->id);
        $bare = new \mod_agora\local\agora_exception('errnocentralurl', null, 'notconfigured');
        $signed = new \mod_agora\local\agora_exception('errhttp', (object) [
            'status' => 403, 'message' => '', 'role' => '', 'operation' => '', 'host' => '',
        ], 'role', 403);
        $signed->operation = 'opcreategrant';
        $signed->role = 'player';

        $sink = $this->redirectEvents();
        \mod_agora\local\failure::log($bare, $context, 'opstreamstats', 'admin');
        \mod_agora\local\failure::log($signed, $context, 'oparchiverange', 'service');
        $failures = self::central_failures($sink->get_events());
        $sink->close();

        $this->assertSame(['opstreamstats', 'admin'], [$failures[0]->other['operation'], $failures[0]->other['account']]);
        $this->assertSame(['opcreategrant', 'player'], [$failures[1]->other['operation'], $failures[1]->other['account']]);
    }

    /**
     * An empty site label: the log record names the operation and the account,
     * not «unknown operation».
     *
     * @return void
     */
    public function test_empty_site_label_refusal_names_operation_and_account(): void {
        set_config('sitelabel', '', 'mod_agora');
        connection::set_client(\mod_agora_mock_central::client($this->routes($this->recorded_stats(), $this->range())));
        $this->setUser($this->student);

        $sink = $this->redirectEvents();
        \mod_agora_view_script::run((int) $this->cm->id);
        $failures = self::central_failures($sink->get_events());
        $sink->close();

        $this->assertNotEmpty($failures, 'The refusal recorded in the log');
        $this->assertSame('opcreategrant', $failures[0]->other['operation']);
        $this->assertSame('player', $failures[0]->other['account']);
        $this->assertSame('notconfigured', $failures[0]->other['reason']);
        $this->assertStringNotContainsString('unknown operation', $failures[0]->get_description());
    }

    /**
     * «Watch the recording» during a signal loss in the «Auto» mode — the feed
     * of the running broadcast without bounds: a loss is «broadcast is
     * running», and when the signal returns, «to live» works from the feed.
     * The bounds are not asked from central.
     *
     * @return void
     */
    public function test_record_request_during_signal_loss_opens_a_feed_without_bounds(): void {
        $client = \mod_agora_mock_central::client($this->routes(
            \mod_agora_mock_central::recorded_stats(60),
            $this->range()
        ));
        connection::set_client($client);
        $this->setUser($this->student);

        $src = $this->iframe_src(\mod_agora_view_script::run((int) $this->cm->id, ['record' => 1]));

        $this->assertStringContainsString('dvr=true', $src);
        $this->assertStringNotContainsString('from=', $src);
        $this->assertStringNotContainsString('to=', $src);
        $this->assertSame([], self::range_requests($client), 'Bounds are not asked for an ongoing broadcast');
    }

    /**
     * The archive bounds requests in the client log.
     *
     * @param \mod_agora\local\agora_client $client the client
     * @return array
     */
    private static function range_requests(\mod_agora\local\agora_client $client): array {
        return array_values(array_filter($client->request_log(), static function (string $line): bool {
            return strpos($line, '/playback/dvr-range/') !== false;
        }));
    }
}
