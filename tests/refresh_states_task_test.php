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
use mod_agora\local\connection;
use mod_agora\local\stream_state;
use mod_agora\task\refresh_states;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * The scheduled warming of states: one pass warms the whole fleet of the
 * activities' broadcasts.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\task\refresh_states
 * @covers     \mod_agora\check\states_refresh
 */
final class refresh_states_task_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /**
     * A connection to the stub Agora and a course with activities.
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
     * Creates a course activity for the named broadcast.
     *
     * @param string $streamname the broadcast name
     * @return \stdClass the activity record
     */
    private function element(string $streamname): \stdClass {
        return $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture ' . $streamname,
            'stream_name' => $streamname,
        ]);
    }

    /**
     * Runs the task, silencing its output into the log.
     *
     * @return void
     */
    private function run_pass(): void {
        ob_start();
        (new refresh_states())->execute();
        ob_end_clean();
    }

    /**
     * The task is registered and runs once a minute.
     *
     * @return void
     */
    public function test_task_is_registered_every_minute(): void {
        $task = \core\task\manager::get_scheduled_task(refresh_states::class);

        $this->assertNotNull($task, 'The task must be visible in «Scheduled tasks»');
        $this->assertSame('*', $task->get_minute());
        $this->assertSame(get_string('taskrefreshstates', 'mod_agora'), $task->get_name());
    }

    /**
     * The pass puts the states of all the activities' broadcasts into the cache and writes the total.
     *
     * @return void
     */
    public function test_pass_warms_every_stream_of_the_plugin(): void {
        $this->element('optics-2026');
        $this->element('townhall');
        // A second activity on the same broadcast: it is not duplicated in the name list.
        $this->element('optics-2026');

        $client = \mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::json(200, [
                'streams' => [['name' => 'optics-2026'], ['name' => 'townhall'], ['name' => 'operator-only']],
                'next' => null,
            ]),
            'GET /streams/stats' => \mod_agora_mock_central::json(200, [
                'streams' => [
                    \mod_agora_mock_central::stats_live('optics-2026'),
                    \mod_agora_mock_central::stats_recorded(7200, 'townhall'),
                ],
                'next' => null,
            ]),
        ]);
        connection::set_client($client);

        $this->run_pass();

        $cached = stream_state::cached(['optics-2026', 'townhall']);
        $this->assertSame(stream_state::LIVE, $cached['optics-2026']->state);
        $this->assertSame(stream_state::ENDED_RECORDED, $cached['townhall']->state);
        $this->assertSame(2, (int) get_config('mod_agora', 'statesrefreshed'));
        $this->assertSame(0, (int) get_config('mod_agora', 'statesfailed'));
        $this->assertSame('', (string) get_config('mod_agora', 'statesfailure'));
        $this->assertGreaterThan(0, (int) get_config('mod_agora', 'statesrefreshedat'));
        $this->assertSame(result::OK, (new \mod_agora\check\states_refresh())->get_result()->get_status());
    }

    /**
     * The broadcast of a freshly created activity is not yet visible to central
     * (404 in stats): the badge gets «not started yet», not «unknown AG-404» —
     * the page and the card do not flash the refusal banner in the first
     * seconds of the activity's life.
     *
     * @return void
     */
    public function test_fresh_phase_element_missing_in_agora_is_not_started(): void {
        global $DB;

        $element = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Fresh lecture',
            'stream_name' => 'optics-2026',
            'has_phases' => 1,
        ]);
        // The broadcast is neither in the configs nor in the stats — central
        // has not shown it yet. (silent_client keeps the defaults to the left
        // of «+»: overriding the route would not work — assemble the client
        // directly.)
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::json(200, [
                'streams' => [],
                'next' => null,
            ]),
            'GET /streams/stats' => \mod_agora_mock_central::json(200, [
                'streams' => [],
                'next' => null,
            ]),
        ]));

        $this->run_pass();

        $cached = stream_state::cached(['optics-2026']);
        $this->assertSame(stream_state::NOT_STARTED, $cached['optics-2026']->state);
        $this->assertNull($cached['optics-2026']->reason);

        // Counter-fact: the same activity older than the freshness threshold —
        // an honest «unknown AG-404», a real «broadcast deleted» is not masked.
        $DB->set_field('agora', 'timecreated', time() - 10 * MINSECS, ['id' => $element->id]);
        \cache::make('mod_agora', 'streamstate')->delete('optics-2026');
        $this->run_pass();

        $cached = stream_state::cached(['optics-2026']);
        $this->assertSame(stream_state::UNKNOWN, $cached['optics-2026']->state);
        $this->assertSame('AG-404', $cached['optics-2026']->reason);
    }

    /**
     * The pass walks the list pages to the last one.
     *
     * @return void
     */
    public function test_pass_walks_every_page(): void {
        $this->element('optics-2026');
        $this->element('townhall');

        $client = \mod_agora_mock_central::client([
            'GET /streams/configs' => [
                \mod_agora_mock_central::json(200, ['streams' => [['name' => 'optics-2026']], 'next' => 'c2']),
                \mod_agora_mock_central::json(200, ['streams' => [['name' => 'townhall']], 'next' => null]),
            ],
            'GET /streams/stats' => [
                \mod_agora_mock_central::json(200, [
                    'streams' => [\mod_agora_mock_central::stats_live('optics-2026')],
                    'next' => 's2',
                ]),
                \mod_agora_mock_central::json(200, [
                    'streams' => [\mod_agora_mock_central::stats_recorded(7200, 'townhall')],
                    'next' => null,
                ]),
            ],
        ]);
        connection::set_client($client);

        $this->run_pass();

        $cached = stream_state::cached(['optics-2026', 'townhall']);
        $this->assertCount(2, $cached, 'The page-two broadcast updated too');
        $this->assertSame(2, (int) get_config('mod_agora', 'statesrefreshed'));
    }

    /**
     * A broadcast created in Agora, but without a stats row, has not started yet.
     *
     * @return void
     */
    public function test_configured_stream_without_stats_is_not_started(): void {
        $this->element('optics-2026');

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::json(200, [
                'streams' => [['name' => 'optics-2026']],
                'next' => null,
            ]),
            'GET /streams/stats' => \mod_agora_mock_central::json(200, ['streams' => [], 'next' => null]),
        ]));

        $this->run_pass();

        $this->assertSame(
            stream_state::NOT_STARTED,
            stream_state::cached(['optics-2026'])['optics-2026']->state
        );
    }

    /**
     * No broadcast in Agora at all: the state is unknown with the 404 reason.
     *
     * @return void
     */
    public function test_stream_missing_in_agora_becomes_unknown(): void {
        $this->element('gone-2026');

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::json(200, ['streams' => [], 'next' => null]),
            'GET /streams/stats' => \mod_agora_mock_central::json(200, ['streams' => [], 'next' => null]),
        ]));

        $this->run_pass();

        $state = stream_state::cached(['gone-2026'])['gone-2026'];
        $this->assertSame(stream_state::UNKNOWN, $state->state);
        $this->assertSame('notfound', $state->failure_reason());
    }

    /**
     * The lists arrived empty: this is not a failed pass, but «no broadcasts in Agora».
     *
     * This is what a substituted central address or a different API version
     * looks like. Without a separate «updated 2» counter it would be
     * indistinguishable from «two were not found».
     *
     * @return void
     */
    public function test_empty_lists_are_counted_as_missing_not_refreshed(): void {
        $this->element('optics-2026');
        $this->element('townhall');

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::json(200, ['streams' => [], 'next' => null]),
            'GET /streams/stats' => \mod_agora_mock_central::json(200, ['streams' => [], 'next' => null]),
        ]));

        $this->run_pass();

        $this->assertSame(0, (int) get_config('mod_agora', 'statesrefreshed'));
        $this->assertSame(2, (int) get_config('mod_agora', 'statesmissing'));

        $check = (new \mod_agora\check\states_refresh())->get_result();
        $this->assertSame(result::ERROR, $check->get_status());
        // The reason is named and the field too: it is fixed by the «Agora server address».
        $this->assertStringContainsString(get_string('settingcentralurl', 'mod_agora'), $check->get_details());
    }

    /**
     * One broadcast of two is gone — a warning, not an error.
     *
     * @return void
     */
    public function test_one_missing_stream_is_a_warning(): void {
        $this->element('optics-2026');
        $this->element('gone-2026');

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::json(200, [
                'streams' => [['name' => 'optics-2026']],
                'next' => null,
            ]),
            'GET /streams/stats' => \mod_agora_mock_central::json(200, [
                'streams' => [\mod_agora_mock_central::stats_live('optics-2026')],
                'next' => null,
            ]),
        ]));

        $this->run_pass();

        $this->assertSame(1, (int) get_config('mod_agora', 'statesrefreshed'));
        $this->assertSame(1, (int) get_config('mod_agora', 'statesmissing'));
        $this->assertSame(result::WARNING, (new \mod_agora\check\states_refresh())->get_result()->get_status());
    }

    /**
     * Garbage instead of a list page: the pass does not fail, the broadcasts
     * are recognized as missing.
     *
     * An answer without the `streams` key and a row without `name` — that is
     * exactly «the proxy returned its own»: silently counting it as a success
     * is not allowed.
     *
     * @return void
     */
    public function test_garbage_answer_does_not_pass_for_success(): void {
        $this->element('optics-2026');

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::json(200, ['data' => ['nonsense']]),
            'GET /streams/stats' => \mod_agora_mock_central::json(200, ['streams' => [['status' => 'running']]]),
        ]));

        $this->run_pass();

        $this->assertSame(0, (int) get_config('mod_agora', 'statesrefreshed'));
        $this->assertSame(1, (int) get_config('mod_agora', 'statesmissing'));
        $this->assertSame(result::ERROR, (new \mod_agora\check\states_refresh())->get_result()->get_status());
    }

    /**
     * Central is unavailable: the former values are intact, the reason is named, the check is red.
     *
     * @return void
     */
    public function test_failed_pass_names_the_reason_and_keeps_old_values(): void {
        $this->element('optics-2026');

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));
        stream_state::for_stream('optics-2026');

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::error(503, 'unavailable', 'central is down'),
        ]));

        $this->run_pass();

        $this->assertSame(
            stream_state::LIVE,
            stream_state::cached(['optics-2026'])['optics-2026']->state,
            'A failed pass does not spoil the previous value'
        );
        $this->assertSame(1, (int) get_config('mod_agora', 'statesfailed'));
        $this->assertNotSame('', (string) get_config('mod_agora', 'statesfailure'));

        $check = (new \mod_agora\check\states_refresh())->get_result();
        $this->assertSame(result::ERROR, $check->get_status());
        $this->assertStringContainsString('central is down', $check->get_details());
    }

    /**
     * No activities — the pass succeeded and the check does not go red.
     *
     * @return void
     */
    public function test_site_without_elements_is_not_a_failure(): void {
        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);

        $this->run_pass();

        $this->assertSame([], $client->request_log(), 'Nothing to update — no central request');
        $this->assertGreaterThan(0, (int) get_config('mod_agora', 'statesrefreshedat'));
        $this->assertSame(result::NA, (new \mod_agora\check\states_refresh())->get_result()->get_status());
    }

    /**
     * The pass has not run longer than the freshness window — the check is red and names the time.
     *
     * @return void
     */
    public function test_stale_pass_is_red(): void {
        $this->element('optics-2026');
        set_config('statesrefreshedat', time() - 10 * MINSECS, 'mod_agora');
        set_config('statesfailure', '', 'mod_agora');

        $check = (new \mod_agora\check\states_refresh())->get_result();

        $this->assertSame(result::ERROR, $check->get_status());
        $this->assertStringContainsString('10', $check->get_summary());
    }

    /**
     * Creates an activity in the «ongoing» phase for the named broadcast.
     *
     * @param string $streamname the broadcast name
     * @param array $fields additional activity fields
     * @return \stdClass the activity record
     */
    private function ongoing_element(string $streamname, array $fields = []): \stdClass {
        global $DB;

        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture ' . $streamname,
            'stream_name' => $streamname,
            'created_by_plugin' => 1,
            'has_phases' => 1,
        ]);
        foreach ($fields + ['started_at' => time() - 3600, 'last_live_at' => time() - 1200] as $field => $value) {
            $DB->set_field('agora', $field, $value, ['id' => $agora->id]);
        }
        return $agora;
    }

    /**
     * The stub central of the pass: the broadcast is created, there is no signal.
     *
     * @param array $routes additional routes
     * @return \mod_agora\local\agora_client the client
     */
    private function silent_client(array $routes = []): \mod_agora\local\agora_client {
        return \mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::json(200, [
                'streams' => [['name' => 'optics-2026']],
                'next' => null,
            ]),
            'GET /streams/stats' => \mod_agora_mock_central::json(200, [
                'streams' => [\mod_agora_mock_central::stats_ended_no_record('optics-2026')],
                'next' => null,
            ]),
        ] + $routes);
    }

    /**
     * A broadcast in the «ongoing» phase is silent longer than the threshold:
     * the task ends it by itself — the recording stop, the «ended» phase, the
     * event with the way «by silence» (userid=0).
     *
     * @return void
     */
    public function test_silence_longer_than_threshold_ends_the_broadcast(): void {
        global $DB;

        set_config('endofsilence', 600, 'mod_agora');
        $this->ongoing_element('optics-2026', ['recording_id' => 'rec-1']);

        $client = $this->silent_client([
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
        ]);
        connection::set_client($client);

        $sink = $this->redirectEvents();
        $this->run_pass();
        $ended = array_values(array_filter($sink->get_events(), static function (\core\event\base $event): bool {
            return $event instanceof \mod_agora\event\broadcast_ended;
        }));
        $sink->close();

        $row = $DB->get_record('agora', ['stream_name' => 'optics-2026'], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->ended_at, 'The activity is ended');
        $this->assertSame('silence', $row->ended_by);
        $this->assertSame(0, (int) $row->end_pending);
        $this->assertCount(1, $ended, 'The «broadcast ended by silence» event');
        $this->assertSame('silence', $ended[0]->other['by']);
        $this->assertSame(0, (int) $ended[0]->userid, 'The task event — on behalf of the system');
        $this->assertTrue(count(array_filter($client->request_log(), static function (string $line): bool {
            return strpos($line, 'recordings/stop') !== false;
        })) > 0, 'Recording stop called');
        // The body of exactly the stop request: after it the pass still looks
        // at the recordings for «without export», and the «last request» is no
        // longer the stop.
        $stopbody = null;
        foreach (\mod_agora_mock_central::requests() as $request) {
            if ($request->getMethod() === 'POST' && strpos($request->getUri()->getPath(), 'recordings/stop') !== false) {
                $stopbody = json_decode((string) $request->getBody(), true);
            }
        }
        $this->assertTrue($stopbody['confirm'], 'The recording stop goes with {confirm: true}');
    }

    /**
     * The signal returned before the threshold: no ending, the state is updated.
     *
     * @return void
     */
    public function test_silence_shorter_than_threshold_ends_nothing(): void {
        global $DB;

        set_config('endofsilence', 600, 'mod_agora');
        // Silent for 400 seconds — less than the threshold of 600.
        $this->ongoing_element('optics-2026', ['last_live_at' => time() - 400]);

        $client = $this->silent_client();
        connection::set_client($client);

        $this->run_pass();

        $row = $DB->get_record('agora', ['stream_name' => 'optics-2026'], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->ended_at, 'No end before the threshold');
        $this->assertSame(0, (int) $row->end_pending);
        $this->assertFalse(
            count(array_filter($client->request_log(), static function (string $line): bool {
                return strpos($line, 'recordings/stop') !== false;
            })) > 0,
            'Recording stop was not called'
        );
    }

    /**
     * Silence before the broadcast is announced ends nothing.
     *
     * @return void
     */
    public function test_planned_element_is_never_ended_by_silence(): void {
        global $DB;

        set_config('endofsilence', 60, 'mod_agora');
        $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture optics-2026',
            'stream_name' => 'optics-2026',
            'created_by_plugin' => 1,
            'has_phases' => 1,
        ]);

        connection::set_client($this->silent_client());
        $this->run_pass();

        $row = $DB->get_record('agora', ['stream_name' => 'optics-2026'], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->started_at);
        $this->assertSame(0, (int) $row->ended_at);
    }

    /**
     * A recording closed by the system (stop_reason) ends the activity with an
     * event naming the close reason.
     *
     * @return void
     */
    public function test_recording_closed_by_the_system_ends_the_broadcast(): void {
        global $DB;

        set_config('endofsilence', 600, 'mod_agora');
        // The signal «went missing» recently — the silence is shorter than the
        // threshold, it is not about it.
        $this->ongoing_element('optics-2026', [
            'recording_id' => 'rec-9',
            'last_live_at' => time() - 120,
        ]);

        $client = $this->silent_client([
            'GET /streams/optics-2026/recordings' => \mod_agora_mock_central::json(200, [
                'recordings' => [['id' => 'rec-9', 'phase' => 'ready', 'stop_reason' => 'max_duration']],
            ]),
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
        ]);
        connection::set_client($client);

        $sink = $this->redirectEvents();
        $this->run_pass();
        $ended = array_values(array_filter($sink->get_events(), static function (\core\event\base $event): bool {
            return $event instanceof \mod_agora\event\broadcast_ended;
        }));
        $sink->close();

        $row = $DB->get_record('agora', ['stream_name' => 'optics-2026'], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->ended_at);
        $this->assertSame('system', $row->ended_by);
        $this->assertCount(1, $ended);
        $this->assertSame('system', $ended[0]->other['by']);
        $this->assertSame('max_duration', $ended[0]->other['stop_reason'], 'The closing reason — in the log');
        $this->assertStringContainsString('max_duration', $ended[0]->get_description());
    }

    /**
     * A button-unconfirmed completion is repeated by the task pass with the
     * same way: the stop refusal left `end_pending` — the pass completes it.
     *
     * @return void
     */
    public function test_task_repeats_an_unfinished_button_end(): void {
        global $DB;

        set_config('endofsilence', 600, 'mod_agora');
        // The button started the ending and stumbled: the activity is «ongoing»,
        // the completion waits.
        $this->ongoing_element('optics-2026', [
            'end_pending' => 1,
            'ended_by' => 'button',
            'end_failure' => 'recording stop, HTTP 503',
        ]);

        $client = $this->silent_client([
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
        ]);
        connection::set_client($client);

        $sink = $this->redirectEvents();
        $this->run_pass();
        $ended = array_values(array_filter($sink->get_events(), static function (\core\event\base $event): bool {
            return $event instanceof \mod_agora\event\broadcast_ended;
        }));
        $sink->close();

        $row = $DB->get_record('agora', ['stream_name' => 'optics-2026'], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->ended_at, 'The pass finished the ending');
        $this->assertSame('button', $row->ended_by, 'The ending way kept the initiator');
        $this->assertSame(0, (int) $row->end_pending);
        $this->assertNull($row->end_failure);
        $this->assertCount(1, $ended);
        $this->assertSame('button', $ended[0]->other['by']);
    }
    /**
     * A ready recording without a finished export for over an hour: the counter
     * in the setting, the event once per recording (userid=0).
     *
     * @return void
     */
    public function test_ready_recording_without_export_is_counted_once(): void {
        // A fresh signal: the activity is not ended by silence; the recording
        // is already closed (ready) — the task completes the activity by the
        // system way, and the counter notices the recording without an export.
        $this->ongoing_element('optics-2026', [
            'recording_id' => 'rec-stale',
            'end_pending' => 0,
            'last_live_at' => time() - 100,
        ]);

        $readyat = gmdate('c', time() - 2 * HOURSECS);
        $client = $this->silent_client([
            'GET /streams/optics-2026/recordings' => \mod_agora_mock_central::json(200, [
                'recordings' => [[
                    'id' => 'rec-stale',
                    'stream' => 'optics-2026',
                    'phase' => 'ready',
                    'ready_at' => $readyat,
                    'ended_at' => $readyat,
                ]],
            ]),
            'GET /recordings/rec-stale/exports' => \mod_agora_mock_central::json(200, ['exports' => []]),
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-stale']),
            'PATCH /streams/configs/optics-2026' => \mod_agora_mock_central::json(200, ['ok' => true]),
        ]);
        connection::set_client($client);

        $sink = $this->redirectEvents();
        $this->run_pass();
        $events = array_values(array_filter($sink->get_events(), static function ($event): bool {
            return $event instanceof \mod_agora\event\recording_export_missing;
        }));
        $sink->close();

        $this->assertSame(1, (int) get_config('mod_agora', 'recordexportless'));
        $this->assertCount(1, $events, 'The event is single');
        $this->assertSame(0, (int) $events[0]->userid, 'The task event — on behalf of the system');
        $this->assertSame('rec-stale', $events[0]->other['recording_id']);

        // The second pass: the state is the same — the event is not spawned, the counter holds.
        $sink = $this->redirectEvents();
        $this->run_pass();
        $again = array_values(array_filter($sink->get_events(), static function ($event): bool {
            return $event instanceof \mod_agora\event\recording_export_missing;
        }));
        $sink->close();
        $this->assertCount(0, $again, 'No repeat event');
        $this->assertSame(1, (int) get_config('mod_agora', 'recordexportless'));
    }

    /**
     * The export has finished: the counter drops, the «noticed» mark is lifted.
     *
     * @return void
     */
    /**
     * A button-unconfirmed completion spams the log while central refuses: a
     * repeat with the same action and reason writes no new events (review R2,
     * К-1-obs). A change of the reason is a new event.
     *
     * @return void
     */
    public function test_stuck_completion_logs_the_refusal_once(): void {
        set_config('endofsilence', 600, 'mod_agora');
        $this->ongoing_element('optics-2026', [
            'end_pending' => 1,
            'end_pending_at' => time() - 60,
            'ended_by' => 'button',
            'end_failure' => 'recording stop, HTTP 503',
        ]);
        $refusal = \mod_agora_mock_central::error(503, 'NODE_DOWN', 'node is down');
        connection::set_client($this->silent_client([
            'POST /streams/optics-2026/recordings/stop' => [$refusal, $refusal, $refusal],
        ]));

        $events = function (): array {
            $sink = $this->redirectEvents();
            $this->run_pass();
            $found = array_values(array_filter($sink->get_events(), static function ($event): bool {
                return $event instanceof \mod_agora\event\broadcast_action_failed;
            }));
            $sink->close();
            return $found;
        };

        $first = $events();
        $this->assertCount(1, $first, 'The first refusal — one event');
        $this->assertCount(0, $events(), 'A repeat with the same reason is silent');
        $this->assertCount(0, $events(), 'And the third pass too');
    }

    /**
     * A completion unconfirmed for over a day: one escalation with the start
     * moment, afterwards — only repeats without new events (review R2, К-2).
     *
     * @return void
     */
    public function test_completion_stuck_for_a_day_escalates_once(): void {
        global $DB;

        set_config('endofsilence', 600, 'mod_agora');
        $this->ongoing_element('optics-2026', [
            'end_pending' => 1,
            'end_pending_at' => time() - 25 * HOURSECS,
            'ended_by' => 'button',
            'end_failure' => 'recording stop, HTTP 503',
        ]);
        $refusal = \mod_agora_mock_central::error(503, 'NODE_DOWN', 'node is down');
        connection::set_client($this->silent_client([
            'POST /streams/optics-2026/recordings/stop' => [$refusal, $refusal],
        ]));

        $stuckevents = function () use ($DB): array {
            $sink = $this->redirectEvents();
            $this->run_pass();
            $found = array_values(array_filter($sink->get_events(), static function ($event): bool {
                return $event instanceof \mod_agora\event\broadcast_action_failed
                    && strpos((string) $event->other['reason'], 'NODE_DOWN') === false;
            }));
            $sink->close();
            return $found;
        };

        $first = $stuckevents();
        $this->assertCount(1, $first, 'A single escalation');
        $this->assertNotEmpty($first[0]->other['reason'], 'The escalation names the reason and the start moment');
        $row = $DB->get_record('agora', ['stream_name' => 'optics-2026'], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->end_pending, 'The activity remains in completion');
        $this->assertCount(0, $stuckevents(), 'No repeat escalation');
    }

    /**
     * The export has finished: the counter drops, the «done» mark is set — the
     * recording is not polled anymore (review R2, S-10).
     *
     * @return void
     */
    public function test_finished_export_clears_the_counter(): void {
        $this->ongoing_element('optics-2026', [
            'recording_id' => 'rec-done',
            'end_pending' => 0,
            'last_live_at' => time() - 100,
        ]);

        $readyat = gmdate('c', time() - 2 * HOURSECS);
        $recordings = \mod_agora_mock_central::json(200, [
            'recordings' => [[
                'id' => 'rec-done',
                'stream' => 'optics-2026',
                'phase' => 'ready',
                'ready_at' => $readyat,
                'ended_at' => $readyat,
            ]],
        ]);
        $empty = \mod_agora_mock_central::json(200, ['exports' => []]);
        $done = \mod_agora_mock_central::recording_exports(['asset-1']);

        // The first pass — without an export: the counter is 1. The second —
        // the export is ready: 0.
        connection::set_client($this->silent_client([
            'GET /streams/optics-2026/recordings' => [$recordings, $recordings],
            'GET /recordings/rec-done/exports' => [$empty, $done],
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-done']),
            'PATCH /streams/configs/optics-2026' => \mod_agora_mock_central::json(200, ['ok' => true]),
        ]));
        $this->run_pass();
        $this->assertSame(1, (int) get_config('mod_agora', 'recordexportless'));

        $this->run_pass();
        $this->assertSame(0, (int) get_config('mod_agora', 'recordexportless'), 'Export caught up — counter empty');
    }
}
