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

use core\check\result;
use mod_agora\external\get_stream_state;
use mod_agora\local\connection;
use mod_agora\local\grant_manager;
use mod_agora\local\recording_scope;
use mod_agora\local\watch;
use mod_agora\task\sync;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/fixtures/view_script.php');

/**
 * The recording is a VOD clip: the iframe embed-vod by a ready export, until
 * then — the «the recording will appear soon» placeholder, for the presenter —
 * the export state. The ticket scope «broadcast + clip», reissue on readiness,
 * watched minutes by two identities.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\recording_scope
 * @covers     \mod_agora\local\player_view
 * @covers     \mod_agora\local\grant_manager
 * @covers     \mod_agora\task\sync
 */
final class record_playback_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the learner */
    private $student;

    /** @var \stdClass the presenter */
    private $teacher;

    /**
     * A course, people and a stub Agora.
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
     * An activity with a bound recording, in the «ended» phase.
     *
     * @param string $streamname the broadcast name (unique in the test: caches)
     * @param string $recordingid the recording identifier
     * @param int $hasphases the phases flag
     * @return \stdClass the activity record
     */
    private function recorded_element(
        string $streamname = 'route-1',
        string $recordingid = 'rec-r1',
        int $hasphases = 1
    ): \stdClass {
        global $DB;

        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture',
            'stream_name' => $streamname,
            'created_by_plugin' => 1,
            'has_phases' => $hasphases,
        ]);
        $DB->set_field('agora', 'recording_id', $recordingid, ['id' => $agora->id]);
        // The «Ended» state: in the «planned» phase the learner is owed a placeholder, and
        // the recording branch is not reached by the page at all.
        $DB->set_field('agora', 'started_at', time() - 1800, ['id' => $agora->id]);
        $DB->set_field('agora', 'ended_at', time() - 600, ['id' => $agora->id]);
        $DB->set_field('agora', 'ended_by', 'silence', ['id' => $agora->id]);
        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $row->cmid = $agora->cmid;
        return $row;
    }

    /**
     * The routes of the recording card and its exports.
     *
     * @param string $recordingid the recording identifier
     * @param array $assets the assets of finished exports; null — without an asset
     * @return array
     */
    private function recording_routes(string $recordingid, array $assets = ['asset-1']): array {
        return [
            'GET /recordings/' . $recordingid => \mod_agora_mock_central::json(
                200,
                \mod_agora_mock_central::recording_card($recordingid, 'route-1')
            ),
            'GET /recordings/' . $recordingid . '/exports' => \mod_agora_mock_central::recording_exports($assets),
        ];
    }

    /**
     * Opens the activity page as the current user.
     *
     * @param \stdClass $agora the activity record
     * @return string HTML
     */
    private function open(\stdClass $agora): string {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        return \mod_agora_view_script::run((int) $agora->cmid);
    }

    /**
     * How many times the client went to a path.
     *
     * @param \mod_agora\local\agora_client $client the client
     * @param string $needle the path substring
     * @return int
     */
    private static function went_to(\mod_agora\local\agora_client $client, string $needle): int {
        return count(array_filter($client->request_log(), static function (string $line) use ($needle): bool {
            return strpos($line, $needle) !== false;
        }));
    }

    /**
     * A ready clip plays by the embed-vod address with a ticket and a language;
     * there is no DVR archive and no `from`/`to` windows for activities with phases.
     *
     * @return void
     */
    public function test_ready_recording_plays_the_vod_embed(): void {
        $agora = $this->recorded_element('route-1', 'rec-r1');
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/route-1' => \mod_agora_mock_central::recorded_stats(600, 'route-1'),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-rec-1',
                'token' => 'jwt-rec-1',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
        ] + $this->recording_routes('rec-r1', ['asset-1']));
        connection::set_client($client);
        $this->setUser($this->student);

        $html = $this->open($agora);

        $this->assertMatchesRegularExpression(
            '#src="https://agora\.example\.com/playback/embed-vod/asset-1/lecture\.mp4\?token=jwt-rec-1[^"]*"#',
            $html,
            'The video plays via the embed-vod address with a ticket'
        );
        $this->assertSame(0, self::went_to($client, 'dvr-range'), 'No DVR archive and no from/to windows are built');
        // The ticket carried both identities: the broadcast and the clip's vod asset.
        $this->assertSame(
            ['route-1', 'vod/asset-1'],
            \mod_agora_mock_central::last_body()['streams']
        );
    }

    /**
     * No clip yet: the «the recording will appear soon» placeholder without an
     * iframe — neither the archive window, nor from/to. For the presenter — the
     * export state.
     *
     * @return void
     */
    public function test_pending_recording_shows_the_placeholder(): void {
        $agora = $this->recorded_element('route-2', 'rec-r2');
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/route-2' => \mod_agora_mock_central::recorded_stats(600, 'route-2'),
        ] + $this->recording_routes('rec-r2', []));
        connection::set_client($client);

        // The learner: the placeholder without an iframe.
        $this->setUser($this->student);
        $html = $this->open($agora);
        $this->assertStringContainsString(get_string('recordnotready', 'mod_agora'), $html);
        $this->assertStringNotContainsString('class="mod-agora-player"', $html, 'No iframe');
        $this->assertSame(0, self::went_to($client, 'dvr-range'), 'No archive windows are built');
        $this->assertStringNotContainsString(
            get_string('recordprocessing', 'mod_agora'),
            $html,
            'Export state — only to the presenter'
        );

        // The presenter: the same placeholder plus an honest «the recording is being prepared».
        $this->setUser($this->teacher);
        $html = $this->open($agora);
        $this->assertStringContainsString(get_string('recordnotready', 'mod_agora'), $html);
        $this->assertStringContainsString(get_string('recordprocessing', 'mod_agora'), $html);
    }

    /**
     * An export failure: the presenter sees the reason, the learner — the common placeholder.
     *
     * @return void
     */
    public function test_failed_export_shows_the_reason_to_the_teacher(): void {
        $agora = $this->recorded_element('route-3', 'rec-r3');
        $failedexport = \mod_agora_mock_central::json(200, [
            'exports' => [[
                'id' => 'exp-0',
                'status' => 'error',
                'file' => 'lecture.mp4',
                'asset_id' => null,
                'error' => ['message' => 'no space left on device'],
            ]],
        ]);
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/route-3' => \mod_agora_mock_central::recorded_stats(600, 'route-3'),
            'GET /recordings/rec-r3' => \mod_agora_mock_central::json(
                200,
                \mod_agora_mock_central::recording_card('rec-r3', 'route-3')
            ),
            'GET /recordings/rec-r3/exports' => $failedexport,
        ]);
        connection::set_client($client);

        $this->setUser($this->teacher);
        $html = $this->open($agora);
        $this->assertStringContainsString(
            get_string('recordexporterror', 'mod_agora', 'no space left on device'),
            $html,
            'The export refusal reason is named to the teacher'
        );

        $this->setUser($this->student);
        $html = $this->open($agora);
        $this->assertStringContainsString(get_string('recordnotready', 'mod_agora'), $html);
        $this->assertStringNotContainsString('no space left on device', $html, 'The reason — only to the presenter');
        $this->assertStringNotContainsString('class="mod-agora-player"', $html);
    }

    /**
     * A legacy activity keeps playing by the former archive window.
     *
     * @return void
     */
    public function test_legacy_element_keeps_the_archive_window(): void {
        $legacy = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Old lecture',
            'stream_name' => 'route-4',
            'has_phases' => 0,
        ]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/route-4' => \mod_agora_mock_central::recorded_stats(600, 'route-4'),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-arch',
                'token' => 'jwt-arch',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            'GET /playback/dvr-range/route-4' => \mod_agora_mock_central::json(200, [
                'from' => (time() - 3600) * 1000,
                'to' => (time() - 600) * 1000,
            ]),
        ]));
        $this->setUser($this->student);

        $html = $this->open($legacy);

        $this->assertStringContainsString('/playback/embed/route-4', $html, 'Legacy — archive window');
        $this->assertStringContainsString('from=', $html, 'Archive bounds are passed');
    }

    /**
     * An activity with phases without a bound recording (a start refusal, an old
     * Agora) plays by the archive window.
     *
     * @return void
     */
    public function test_unbound_element_keeps_the_archive_window(): void {
        $unbound = $this->recorded_element('route-5', 'rec-none');
        global $DB;
        $DB->set_field('agora', 'recording_id', null, ['id' => $unbound->id]);

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/route-5' => \mod_agora_mock_central::recorded_stats(600, 'route-5'),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-arch',
                'token' => 'jwt-arch',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            'GET /playback/dvr-range/route-5' => \mod_agora_mock_central::json(200, [
                'from' => (time() - 3600) * 1000,
                'to' => (time() - 600) * 1000,
            ]),
        ]));
        $this->setUser($this->student);

        $html = $this->open($unbound);

        $this->assertStringContainsString('/playback/embed/route-5', $html, 'An activity without recording — archive window');
        $this->assertStringContainsString('from=', $html, 'Archive bounds are passed');
    }

    /**
     * The «Recordings: the facet is available» check — the matrix by D12 v3.
     *
     * @return void
     */
    public function test_recordings_facet_check_matrix(): void {
        $element = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'stream_name' => 'route-check',
        ]);

        $cases = [
            'ok' => [200, result::OK, 'checkrecordroutingok'],
            'missing' => [404, result::WARNING, 'checkrecordroutingmissing'],
            'unauthorized' => [403, result::ERROR, 'checkrecordroutingauth'],
            'down' => [503, result::ERROR, 'checkrecordroutingdown'],
        ];
        foreach ($cases as $name => [$status, $expected, $stringkey]) {
            $response = $status === 200
                ? \mod_agora_mock_central::json(200, ['recordings' => []])
                : \mod_agora_mock_central::error($status, 'code', 'probe failed');
            connection::set_client(\mod_agora_mock_central::client([
                'GET /streams/route-check/recordings' => $response,
            ]));
            $check = (new \mod_agora\check\record_routing())->get_result();
            $this->assertSame($expected, $check->get_status(), $name);
            $this->assertStringContainsString(get_string($stringkey, 'mod_agora'), $check->get_summary(), $name);
        }

        // No activities — there is nothing to probe on.
        connection::set_client(\mod_agora_mock_central::client([]));
        course_delete_module((int) $element->cmid);
        $check = (new \mod_agora\check\record_routing())->get_result();
        $this->assertSame(result::NA, $check->get_status());
    }

    /**
     * The state poll: an ended one without a clip answers with the recording
     * placeholder, after the export is ready — with the player.
     *
     * @return void
     */
    public function test_poll_reports_recordpending_until_the_vod_is_ready(): void {
        $agora = $this->recorded_element('route-6', 'rec-poll');
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/route-6' => \mod_agora_mock_central::recorded_stats(600, 'route-6'),
        ] + $this->recording_routes('rec-poll', [])));
        $this->setUser($this->student);

        $answer = get_stream_state::execute((int) $agora->cmid);
        $this->assertSame('recordpending', $answer['kind'], 'Without a video the poll answers with the recording placeholder');

        // The export has finished: the next poll says «player» — without any
        // cache expiry: the page path reads the readiness fresh (user's
        // requirement: the placeholder does not hang for minutes when the clip is ready).
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/route-6' => \mod_agora_mock_central::recorded_stats(600, 'route-6'),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-poll',
                'token' => 'jwt-poll',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
        ] + $this->recording_routes('rec-poll', ['asset-7'])));
        $answer = get_stream_state::execute((int) $agora->cmid);
        $this->assertSame('record', $answer['kind'], 'A ready video makes the poll answer with the player the very next interval');
    }

    /**
     * The ticket scope: only the broadcast without a clip; the broadcast + vod
     * of the last finished export with a file; an export without an asset is skipped.
     *
     * @return void
     */
    public function test_ticket_scope_follows_the_vod_asset(): void {
        global $DB;

        $cases = [
            'no vod' => [[], ['scope-0']],
            'vod ready' => [['asset-1'], ['scope-1', 'vod/asset-1']],
            'assetless skipped' => [['asset-2', null], ['scope-2', 'vod/asset-2']],
        ];
        foreach ($cases as $name => [$assets, $expected]) {
            $agora = $this->recorded_element($expected[0], 'rec-' . $expected[0]);
            $client = \mod_agora_mock_central::client([
                'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                    'jti' => 'jti-' . $expected[0],
                    'token' => 'jwt',
                    'expires_at' => gmdate('c', time() + HOURSECS),
                ]),
            ] + $this->recording_routes('rec-' . $expected[0], $assets));
            connection::set_client($client);

            grant_manager::for_user($agora, (int) $this->student->id, (int) $this->course->id, (int) $agora->cmid, '');

            $this->assertSame($expected, \mod_agora_mock_central::last_body()['streams'], $name);
            $row = $DB->get_record('agora_grants', ['jti' => 'jti-' . $expected[0]], '*', MUST_EXIST);
            $this->assertSame(
                $expected,
                recording_scope::decode((string) $row->scope),
                'The scope column contents: ' . $name
            );
        }
    }

    /**
     * The export finished after the issue: the reissue is a new ticket first,
     * the revocation of the old one after it; the scope matches — reuse.
     *
     * @return void
     */
    public function test_finished_export_reissues_the_ticket_new_first(): void {
        global $DB;

        $agora = $this->recorded_element('scope-re', 'rec-re');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_agora');
        // A live ticket with the «only broadcast» scope: the export has finished.
        $old = $generator->create_grant($agora, (int) $this->student->id, [
            'scope' => recording_scope::encode(['scope-re']),
        ]);

        $client = \mod_agora_mock_central::client([
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-new',
                'token' => 'jwt-new',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            'DELETE /play-sessions/grants/*' => \mod_agora_mock_central::json(200, []),
        ] + $this->recording_routes('rec-re', ['asset-9']));
        connection::set_client($client);

        // The scope is read fresh: no manual cache reset between issues is needed.
        $grant = grant_manager::for_user(
            $agora,
            (int) $this->student->id,
            (int) $this->course->id,
            (int) $agora->cmid,
            ''
        );

        $this->assertSame('jti-new', $grant->jti, 'A new ticket issued');
        // The order: creating the new one before revoking the old one.
        $events = [];
        foreach ($client->request_log() as $line) {
            if (strpos($line, 'POST /central/api-v4/play-sessions/grants') === 0) {
                $events[] = 'create';
            } else if (strpos($line, 'DELETE /central/api-v4/play-sessions/grants/') === 0) {
                $events[] = 'revoke';
            }
        }
        $this->assertSame(['create', 'revoke'], array_slice($events, -2), 'First the new ticket, then the revoke');
        $this->assertSame(['scope-re', 'vod/asset-9'], recording_scope::decode((string) $grant->scope));
        $oldrow = $DB->get_record('agora_grants', ['id' => $old->id], '*', MUST_EXIST);
        $this->assertSame('revoked', $oldrow->status, 'The old ticket revoked');
        $this->assertNull($oldrow->token);

        // The scope matches — reuse, no new requests.
        $before = self::went_to($client, '/play-sessions/grants');
        $again = grant_manager::for_user(
            $agora,
            (int) $this->student->id,
            (int) $this->course->id,
            (int) $agora->cmid,
            ''
        );
        $this->assertSame('jti-new', $again->jti, 'The live ticket reused');
        $this->assertSame($before, self::went_to($client, '/play-sessions/grants'), 'No new requests');
    }

    /**
     * Watched minutes of the recording: the history is polled by the broadcast
     * and by the vod asset, the durations add up; a download session (an empty
     * proto) does not count.
     *
     * @return void
     */
    public function test_sync_counts_both_identities_and_skips_downloads(): void {
        global $DB;

        $agora = $this->recorded_element('sync-rec', 'rec-sync');
        $DB->set_field('agora', 'completion_minutes', 10, ['id' => $agora->id]);
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_AUTOMATIC, ['id' => $agora->cmid]);
        $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant($agora, (int) $this->student->id);
        $userref = 'hr-lms:' . $this->student->id . ':user' . $this->student->id;

        $history = static function ($request) use ($userref) {
            parse_str($request->getUri()->getQuery(), $query);
            $stream = (string) ($query['stream'] ?? '');
            $opened = time() - 1800;
            $sessions = [
                'sync-rec' => [
                    ['stream' => 'sync-rec', 'user_id' => $userref, 'opened_at' => $opened, 'closed_at' => $opened + 600,
                        'duration' => 600, 'close_reason' => 'client_teardown'],
                ],
                'vod/asset-1' => [
                    ['stream' => 'vod/asset-1', 'user_id' => $userref, 'opened_at' => $opened + 600,
                        'closed_at' => $opened + 900, 'duration' => 300, 'close_reason' => 'client_teardown'],
                    // A file download: the proto is empty — not watching.
                    ['stream' => 'vod/asset-1', 'user_id' => $userref, 'opened_at' => $opened + 900,
                        'closed_at' => $opened + 1400, 'duration' => 500, 'close_reason' => 'client_teardown', 'proto' => ''],
                ],
            ];
            return \mod_agora_mock_central::json(200, ['sessions' => $sessions[$stream] ?? []]);
        };

        $client = \mod_agora_mock_central::client([
            'GET /play-sessions/closed' => $history,
        ] + $this->recording_routes('rec-sync', ['asset-1']));
        connection::set_client($client);

        ob_start();
        (new sync())->execute();
        ob_end_clean();

        $this->assertSame(
            900,
            watch::seconds((int) $agora->id, (int) $this->student->id),
            '600 s from the broadcast + 300 s from the video'
        );
        $queries = \mod_agora_mock_central::queries_for('/play-sessions/closed');
        $asked = array_column($queries, 'stream');
        $this->assertContains('sync-rec', $asked);
        $this->assertContains('vod/asset-1', $asked, 'History asked by vod identity too');
    }

    /**
     * An old Agora without the recordings facet: «Start the broadcast» works
     * (the broadcast is announced), the recording start is not executed at all,
     * the refusal is recorded once per activity; an ended activity plays by the
     * archive window.
     *
     * @return void
     */
    public function test_old_agora_starts_broadcast_without_touching_recordings(): void {
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture on the old Agora',
            'stream_name' => 'old-agora',
            'created_by_plugin' => 1,
            'has_phases' => 1,
        ]);

        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/old-agora' => \mod_agora_mock_central::live_stats('old-agora'),
            'GET /streams/old-agora/recordings' => \mod_agora_mock_central::error(404, 'notfound', 'no facet'),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-old',
                'token' => 'jwt-old',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            'GET /playback/dvr-range/old-agora' => \mod_agora_mock_central::json(200, [
                'from' => (time() - 3600) * 1000,
                'to' => (time() - 600) * 1000,
            ]),
        ]);
        connection::set_client($client);

        $this->setUser($this->teacher);

        // The «Start the broadcast» button: the phase is set, there are no requests to the recording.
        $sink = $this->redirectEvents();
        try {
            \mod_agora_view_script::run((int) $agora->cmid, [
                'action' => 'startbroadcast',
                'sesskey' => sesskey(),
            ], 'view.php', true);
            $this->fail('The action must answer with a redirect');
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode);
        }
        $failed = array_values(array_filter($sink->get_events(), static function ($event): bool {
            return $event instanceof \mod_agora\event\broadcast_action_failed;
        }));
        $sink->close();

        global $DB;
        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->started_at, 'The broadcast is announced on the old Agora too');
        $this->assertNull($row->recording_id, 'Recording not created: no facet');
        $this->assertSame(0, self::went_to($client, 'recordings/start'), 'Recording start was not called');
        $this->assertCount(1, $failed, 'Facet refusal — one log record');
        $this->assertSame('RECORDING_FACET_MISSING', $failed[0]->other['reason']);

        // A repeated press does not spawn log records.
        $sink = $this->redirectEvents();
        try {
            \mod_agora_view_script::run((int) $agora->cmid, [
                'action' => 'startbroadcast',
                'sesskey' => sesskey(),
            ], 'view.php', true);
        } catch (\moodle_exception $e) {
            // A redirect with the «not planned» message — still a redirect.
            unset($e);
        }
        $again = array_values(array_filter($sink->get_events(), static function ($event): bool {
            return $event instanceof \mod_agora\event\broadcast_action_failed;
        }));
        $sink->close();
        $this->assertCount(0, $again, 'One log record per activity');

        // Ending: the activity is «ended», the learner watches the archive
        // window (the recorded broadcast has an archive, despite the missing
        // facet).
        $DB->set_field('agora', 'ended_at', time() - 100, ['id' => $agora->id]);
        $DB->set_field('agora', 'ended_by', 'button', ['id' => $agora->id]);
        // The broadcast state was cached as live since the button press: drop
        // it so the page sees the ended broadcast.
        \cache::make('mod_agora', 'streamstate')->delete('old-agora');
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/old-agora' => \mod_agora_mock_central::recorded_stats(600, 'old-agora'),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-old-2',
                'token' => 'jwt-old-2',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            'GET /playback/dvr-range/old-agora' => \mod_agora_mock_central::json(200, [
                'from' => (time() - 3600) * 1000,
                'to' => (time() - 600) * 1000,
            ]),
        ]));
        $this->setUser($this->student);
        $html = \mod_agora_view_script::run((int) $agora->cmid);
        $this->assertStringContainsString('/playback/embed/old-agora', $html, 'Ended without recording — archive window');
    }
}
