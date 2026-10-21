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
use mod_agora\local\stream_facts;
use mod_agora\local\stream_state;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * The broadcast state: six values, the interruption threshold, the cache with soft expiry, the badge.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\stream_state
 * @covers     \mod_agora\local\stream_facts
 * @covers     \mod_agora\output\courseformat\activitybadge
 */
final class stream_state_test extends \advanced_testcase {
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
     * The fact table: every combination gives its own state.
     *
     * @return array
     */
    public static function facts_provider(): array {
        $now = 1790000000;
        $ms = static function (int $seconds): string {
            return gmdate('Y-m-d\TH:i:s', $seconds) . '.000Z';
        };
        $seen = gmdate('c', $now - 3600);

        return [
            'source alive via status' => [
                ['status' => 'running'],
                stream_state::LIVE,
            ],
            'source alive via input' => [
                ['status' => 'waiting', 'inputs' => [['current' => true, 'status' => 'working']]],
                stream_state::LIVE,
            ],
            'drop two minutes ago' => [
                ['status' => 'idle', 'status_since' => $seen, 'dvr' => [
                    'recording' => true,
                    'from' => $ms($now - 7200),
                    'to' => $ms($now),
                    'written_until' => $ms($now - 120),
                ]],
                stream_state::SIGNAL_LOST,
            ],
            'drop exactly at the 180 s threshold' => [
                ['status' => 'idle', 'status_since' => $seen, 'dvr' => [
                    'recording' => true,
                    'from' => $ms($now - 7200),
                    'to' => $ms($now),
                    'written_until' => $ms($now - 180),
                ]],
                stream_state::SIGNAL_LOST,
            ],
            'a second past the threshold — broadcast over' => [
                ['status' => 'idle', 'status_since' => $seen, 'dvr' => [
                    'recording' => true,
                    'from' => $ms($now - 7200),
                    'to' => $ms($now),
                    'written_until' => $ms($now - 181),
                ]],
                stream_state::ENDED_RECORDED,
            ],
            'drop an hour ago — broadcast over' => [
                ['status' => 'idle', 'status_since' => $seen, 'dvr' => [
                    'recording' => false,
                    'from' => $ms($now - 7200),
                    'to' => $ms($now - 3600),
                    'written_until' => $ms($now - 3600),
                ]],
                stream_state::ENDED_RECORDED,
            ],
            'seen, recording off' => [
                ['status' => 'idle', 'status_since' => $seen, 'dvr' => ['recording' => false]],
                stream_state::ENDED_NO_RECORD,
            ],
            'seen, no dvr section at all' => [
                ['status' => 'idle', 'status_since' => $seen],
                stream_state::ENDED_NO_RECORD,
            ],
            'source alive, recording off' => [
                ['status' => 'idle', 'inputs' => [['current' => true, 'status' => 'working']], 'dvr' => ['recording' => false]],
                stream_state::LIVE,
            ],
            'not seen: status_since empty' => [
                ['status' => 'waiting', 'inputs' => [['current' => true, 'status' => 'unchecked']]],
                stream_state::NOT_STARTED,
            ],
            'not seen: status_since epoch' => [
                ['status' => 'idle', 'status_since' => '1970-01-01T00:00:00Z'],
                stream_state::NOT_STARTED,
            ],
            'created, no fields' => [
                ['status' => 'idle'],
                stream_state::NOT_STARTED,
            ],
            'planned window present, no appended archive' => [
                ['status' => 'idle', 'dvr' => [
                    'recording' => false,
                    'from' => $ms($now - 3600),
                    'to' => $ms($now + 3600),
                ]],
                stream_state::NOT_STARTED,
            ],
            'recording on, nothing recorded, not seen' => [
                ['status' => 'waiting', 'dvr' => ['recording' => true]],
                stream_state::NOT_STARTED,
            ],
            // The `recording` flag says «recording is on», not «there is an archive».
            // A broadcast that ran with recording on, but whose `written_until`
            // central did not give, ended without a record — «not started yet»
            // here would mix it up with a broadcast that never happened at all.
            'seen, recording on, no appended archive' => [
                ['status' => 'idle', 'status_since' => $seen, 'dvr' => ['recording' => true]],
                stream_state::ENDED_NO_RECORD,
            ],
            'seen, recording on, only planned window' => [
                ['status' => 'idle', 'status_since' => $seen, 'dvr' => [
                    'recording' => true,
                    'from' => $ms($now - 3600),
                    'to' => $ms($now + 3600),
                ]],
                stream_state::ENDED_NO_RECORD,
            ],
        ];
    }

    /**
     * The state is computed from the facts of one answer.
     *
     * @param array $stats the `streams/stats` answer body
     * @param string $expected the expected state
     * @return void
     * @dataProvider facts_provider
     */
    public function test_state_from_facts(array $stats, string $expected): void {
        $this->assertSame($expected, stream_state::from_facts(new stream_facts($stats), 1790000000));
    }

    /**
     * The planned archive window without `written_until` does not count as a record.
     *
     * Acceptance defect A2: `dvr.to` is the end of the current hour, that is the
     * future, and the player would get a window that is not in the archive.
     *
     * @return void
     */
    public function test_planned_window_without_written_until_is_not_a_record(): void {
        $facts = new stream_facts(['dvr' => [
            'recording' => false,
            'from' => gmdate('c', time() - 3600),
            'to' => gmdate('c', time() + 3600),
        ]]);

        $this->assertFalse($facts->has_record());
        $this->assertNull($facts->record_to_ms());
    }

    /**
     * A broadcast observed only by the plugin is distinguishable without `status_since`.
     *
     * For a stopped broadcast central gives neither counters nor `inputs`; the
     * plugin's own observation is the second source of the same fact.
     *
     * @return void
     */
    public function test_seen_live_by_plugin_makes_ended_no_record_reachable(): void {
        $stats = ['status' => 'idle', 'dvr' => ['recording' => false]];

        $this->assertSame(stream_state::NOT_STARTED, stream_state::from_facts(new stream_facts($stats), null, false));
        $this->assertSame(stream_state::ENDED_NO_RECORD, stream_state::from_facts(new stream_facts($stats), null, true));
    }

    /**
     * A live broadcast is marked in `agora.last_live_at`, and from then on it is «observed».
     *
     * @return void
     */
    public function test_live_observation_is_remembered(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
        ]);

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));
        $this->assertSame(stream_state::LIVE, stream_state::for_stream('optics-2026')->state);
        $this->assertGreaterThan(0, (int) $DB->get_field('agora', 'last_live_at', ['stream_name' => 'optics-2026']));

        // The broadcast stopped: neither counters nor `status_since` in the
        // answer, and only the plugin's memory can tell «ended without a record».
        \cache::make('mod_agora', 'streamstate')->purge();
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::json(200, [
                'name' => 'optics-2026',
                'status' => 'idle',
                'dvr' => ['recording' => false],
            ]),
        ]));
        $this->assertSame(stream_state::ENDED_NO_RECORD, stream_state::for_stream('optics-2026')->state);
    }

    /**
     * The `written_until` label as an RFC 3339 string with milliseconds is parsed.
     *
     * @return void
     */
    public function test_written_until_string_with_millis(): void {
        $facts = new stream_facts(['dvr' => ['written_until' => '2026-09-20T11:45:30.250Z']]);
        $this->assertSame(strtotime('2026-09-20T11:45:30Z') * 1000 + 250, $facts->written_until_ms());
    }

    /**
     * Every refusal code gives `unknown`, not «broadcast not started».
     *
     * @return array
     */
    public static function refusal_provider(): array {
        return [
            '401' => [401, 'unauthorized'],
            '403' => [403, 'forbidden'],
            '404' => [404, 'not_found'],
            '429' => [429, 'too_many_sessions'],
        ];
    }

    /**
     * A central refusal turns into `unknown` with the reason code.
     *
     * @param int $status the answer code
     * @param string $code the error.code field
     * @return void
     * @dataProvider refusal_provider
     */
    public function test_refusal_gives_unknown(int $status, string $code): void {
        connection::set_client(\mod_agora_mock_central::client([
            // The client retries 401 once — answer the same both times.
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::error($status, $code, 'nope'),
        ]));

        $state = stream_state::for_stream('optics-2026');

        $this->assertSame(stream_state::UNKNOWN, $state->state);
        $this->assertSame('AG-' . $status, $state->reason);
        $this->assertNotNull($state->failure);
    }

    /**
     * Central does not answer — also `unknown`.
     *
     * @return void
     */
    public function test_network_failure_gives_unknown(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/*' => new \GuzzleHttp\Exception\ConnectException(
                'timeout',
                new \GuzzleHttp\Psr7\Request('GET', '/')
            ),
        ]));

        $state = stream_state::for_stream('optics-2026');

        $this->assertSame(stream_state::UNKNOWN, $state->state);
        $this->assertSame('AG-NET', $state->reason);
    }

    /**
     * 404 on a freshly created activity with phases — central has not shown the
     * broadcast in stats yet: «not started yet» without a refusal code, not
     * «unknown AG-404».
     *
     * @return void
     */
    public function test_fresh_phase_element_404_reads_as_not_started(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Fresh lecture',
            'stream_name' => 'optics-2026',
            'has_phases' => 1,
        ]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::error(404, 'not_found', 'missing'),
        ]));

        $state = stream_state::for_page('optics-2026');

        $this->assertSame(stream_state::NOT_STARTED, $state->state);
        $this->assertNull($state->reason, 'The «not started yet» has no refusal code');
        $this->assertNull($state->error, 'No log record: this is not a refusal');
        $this->assertFalse($state->has_record());
    }

    /**
     * An old activity on 404 stays «unknown AG-404»: a real «broadcast deleted»
     * must not be masked.
     *
     * @return void
     */
    public function test_stale_element_404_stays_unknown(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $element = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Old lecture',
            'stream_name' => 'optics-2026',
            'has_phases' => 1,
        ]);
        // Older than the freshness threshold.
        $DB->set_field('agora', 'timecreated', time() - 10 * MINSECS, ['id' => $element->id]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::error(404, 'not_found', 'missing'),
        ]));

        $state = stream_state::for_page('optics-2026');

        $this->assertSame(stream_state::UNKNOWN, $state->state);
        $this->assertSame('AG-404', $state->reason);
        $this->assertNotNull($state->error);
    }

    /**
     * Legacy (`has_phases = 0`) on 404 lives by the former rules — nothing
     * softens its «unknown».
     *
     * @return void
     */
    public function test_legacy_404_stays_unknown(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Old lecture',
            'stream_name' => 'optics-2026',
            'has_phases' => 0,
        ]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::error(404, 'not_found', 'missing'),
        ]));

        $this->assertSame(stream_state::UNKNOWN, stream_state::for_page('optics-2026')->state);
    }

    /**
     * A fresh activity: while central 404s, the page lives on «not started yet»,
     * and once the stats appear — the same state, but with facts: the poll
     * brought the state without a manual refresh.
     *
     * @return void
     */
    public function test_fresh_element_404_resolves_when_stats_appear(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Fresh lecture',
            'stream_name' => 'optics-2026',
            'has_phases' => 1,
        ]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::error(404, 'not_found', 'missing'),
        ]));
        $this->assertSame(stream_state::NOT_STARTED, stream_state::for_page('optics-2026')->state);

        // The stats materialized: the next request (after the cache expires)
        // brings the real «not started yet» with facts.
        \cache::make('mod_agora', 'streamstate')->delete('optics-2026');
        \cache::make('mod_agora', 'stateattempt')->delete('optics-2026');
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026'
                => \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
        ]));
        $state = stream_state::for_page('optics-2026');

        $this->assertSame(stream_state::NOT_STARTED, $state->state);
        $this->assertNotNull($state->facts, 'Facts arrived: a real state, not a 404 placeholder');
        $this->assertNull($state->reason);
    }


    /**
     * The state is fetched with one request, and afterwards taken from the shared cache.
     *
     * @return void
     */
    public function test_cache_holds_one_request_per_stream(): void {
        $routes = [];
        for ($i = 0; $i < 5; $i++) {
            $routes['GET /streams/stats/stream-' . $i] = \mod_agora_mock_central::live_stats('stream-' . $i);
        }
        $client = \mod_agora_mock_central::client($routes);
        connection::set_client($client);

        // Fifty accesses within a minute to five broadcasts.
        for ($round = 0; $round < 10; $round++) {
            for ($i = 0; $i < 5; $i++) {
                $this->assertSame(stream_state::LIVE, stream_state::for_stream('stream-' . $i)->state);
            }
        }

        $this->assertCount(5, self::stats_requests($client), 'Five broadcasts must get no more than five state requests');
    }

    /**
     * An expired value is served right away, and the update goes to an adhoc task.
     *
     * @return void
     */
    public function test_stale_value_is_served(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => [
                \mod_agora_mock_central::live_stats(),
                \mod_agora_mock_central::recorded_stats(),
            ],
        ]);
        connection::set_client($client);

        $this->assertSame(stream_state::LIVE, stream_state::for_stream('optics-2026')->state);
        self::age_entry('optics-2026', 61);

        // The page serves the former value and does not go to central.
        $this->assertSame(stream_state::LIVE, stream_state::for_stream('optics-2026')->state);
        $this->assertCount(1, self::stats_requests($client), 'A stale value does not pull a synchronous request');

        $tasks = \core\task\manager::get_adhoc_tasks(\mod_agora\task\refresh_state::class);
        $this->assertCount(1, $tasks, 'The update is ordered by exactly one adhoc task');

        // The task has run — the cache holds the fresh value.
        $this->expectOutputRegex('/optics-2026/');
        reset($tasks)->execute();
        $this->assertSame(stream_state::ENDED_RECORDED, stream_state::for_stream('optics-2026')->state);
        $this->assertCount(2, self::stats_requests($client));
    }

    /**
     * The second show of an expired value does not spawn a second task.
     *
     * @return void
     */
    public function test_stale_refresh_is_queued_once(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));

        stream_state::for_stream('optics-2026');
        self::age_entry('optics-2026', 61);
        stream_state::for_stream('optics-2026');
        stream_state::for_stream('optics-2026');

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\mod_agora\task\refresh_state::class));
    }

    /**
     * A refusal during the refresh does not spoil the previous value.
     *
     * @return void
     */
    public function test_failed_refresh_keeps_previous_value(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));
        $this->assertSame(stream_state::LIVE, stream_state::for_stream('optics-2026')->state);

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::error(503, 'Internal', 'down'),
        ]));
        $this->assertSame(stream_state::LIVE, stream_state::refresh('optics-2026')->state);
        $this->assertSame(stream_state::LIVE, stream_state::for_stream('optics-2026')->state);
    }

    /**
     * The `unknown` state lives in the cache no longer than 15 seconds.
     *
     * @return void
     */
    public function test_unknown_is_not_cached_for_long(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::error(429, 'too_many_sessions', 'limit'),
        ]));
        $this->assertSame(stream_state::UNKNOWN, stream_state::for_stream('optics-2026')->state);

        self::age_entry('optics-2026', 16);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));

        // The expired `unknown` is also served right away, and the update goes by a task.
        $this->assertSame(stream_state::UNKNOWN, stream_state::for_stream('optics-2026')->state);
        $this->expectOutputRegex('/optics-2026/');
        foreach (\core\task\manager::get_adhoc_tasks(\mod_agora\task\refresh_state::class) as $task) {
            $task->execute();
        }
        $this->assertSame(stream_state::LIVE, stream_state::for_stream('optics-2026')->state);
    }

    /**
     * The cache keys accept broadcast names with a dash and a dot.
     *
     * Acceptance trap A4: `simplekeys` on such a key drops the course page with
     * a `coding_exception`, and this can only be seen by accessing the cache.
     *
     * @return void
     */
    public function test_cache_keys_accept_dash_and_dot(): void {
        $name = 'hr-lms.optics-2026';
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/*' => \mod_agora_mock_central::live_stats($name),
        ]));

        $this->assertSame(stream_state::LIVE, stream_state::for_stream($name)->state);
        foreach (['streamstate', 'staterefresh', 'previews', 'previewlog'] as $definition) {
            $cache = \cache::make('mod_agora', $definition);
            $cache->set($name, ['x' => 1]);
            $this->assertSame(['x' => 1], $cache->get($name), 'Cache ' . $definition . ' must take the broadcast name as key');
        }
    }

    /**
     * The activity badge names the state in words.
     *
     * @return void
     */
    public function test_badge_says_live(): void {
        global $PAGE;

        $course = $this->getDataGenerator()->create_course();
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
        ]);
        $cm = get_fast_modinfo($course)->get_cm($agora->cmid);

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));

        $badge = \core_courseformat\output\activitybadge::create_instance($cm);
        $this->assertNotNull($badge, 'The activity must declare its state badge');

        $data = $badge->export_for_template($PAGE->get_renderer('core'));
        $this->assertSame(get_string('badgelive', 'mod_agora'), $data->badgecontent);
    }

    /**
     * The badge of every state names it in its own words.
     *
     * @return void
     */
    public function test_badge_of_every_state(): void {
        $expected = [
            stream_state::LIVE => 'badgelive',
            stream_state::SIGNAL_LOST => 'badgesignallost',
            stream_state::ENDED_RECORDED => 'badgeendedrecorded',
            stream_state::NOT_STARTED => 'badgenotstarted',
            stream_state::ENDED_NO_RECORD => 'badgeendednorecord',
        ];
        foreach ($expected as $state => $key) {
            $value = new stream_state($state, null, null, time());
            $this->assertSame(get_string($key, 'mod_agora'), $value->badge_text());
        }
        $unknown = new stream_state(stream_state::UNKNOWN, null, 'AG-503', time());
        $this->assertSame(get_string('badgeunknown', 'mod_agora', 'AG-503'), $unknown->badge_text());
    }

    /**
     * Ages the value in the state cache.
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
    }

    /**
     * The broadcast state requests in the client log.
     *
     * @param \mod_agora\local\agora_client $client the client
     * @return array
     */
    private static function stats_requests(\mod_agora\local\agora_client $client): array {
        return array_filter($client->request_log(), function (string $line): bool {
            return strpos($line, '/streams/stats/') !== false;
        });
    }
}
