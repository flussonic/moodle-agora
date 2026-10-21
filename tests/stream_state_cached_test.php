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
use mod_agora\local\stream_state;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * Reading states from the cache: the central sidebar does not get asked.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\stream_state
 */
final class stream_state_cached_test extends \advanced_testcase {
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
     * A warmed value is read from the cache without asking the client.
     *
     * The cache is warmed through the same door as for the user: opening the
     * page calls `for_stream()`, it does not put the value into MUC by hand.
     *
     * @return void
     */
    public function test_warm_value_is_read_without_asking_central(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]);
        connection::set_client($client);

        $this->assertSame(stream_state::LIVE, stream_state::for_stream('optics-2026')->state);
        $asked = count($client->request_log());

        $found = stream_state::cached(['optics-2026']);

        $this->assertArrayHasKey('optics-2026', $found);
        $this->assertSame(stream_state::LIVE, $found['optics-2026']->state);
        $this->assertCount($asked, $client->request_log(), 'Cache reads make no requests');
    }

    /**
     * An empty cache — an empty answer and not a single request to central.
     *
     * @return void
     */
    public function test_empty_cache_asks_nothing(): void {
        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);

        $this->assertSame([], stream_state::cached(['optics-2026', 'townhall']));
        $this->assertSame([], $client->request_log());
    }

    /**
     * A value older than the freshness window counts as absent.
     *
     * A direct write into MUC is the only way to get the needed age: through
     * the user door the value is always fresh, and waiting three minutes in a
     * test is not allowed.
     *
     * @return void
     */
    public function test_value_older_than_the_window_is_absent(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]);
        connection::set_client($client);
        stream_state::for_stream('optics-2026');

        $cache = \cache::make('mod_agora', 'streamstate');
        $entry = $cache->get('optics-2026');
        $entry['fetched_at'] = time() - stream_state::SIDEBAR_MAXAGE - 1;
        $cache->set('optics-2026', $entry);

        $this->assertSame([], stream_state::cached(['optics-2026']));

        // Inside the window the value is still good. Exactly at the boundary cannot
        // be checked: time passes between the write and the read, and the test
        // would hang on a second.
        $entry['fetched_at'] = time() - stream_state::SIDEBAR_MAXAGE + 10;
        $cache->set('optics-2026', $entry);
        $this->assertArrayHasKey('optics-2026', stream_state::cached(['optics-2026']));
    }

    /**
     * Several names are read with one cache request; absent ones are not in the answer.
     *
     * @return void
     */
    public function test_many_names_come_back_by_name(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'GET /streams/stats/townhall' => \mod_agora_mock_central::recorded_stats(7200, 'townhall'),
        ]);
        connection::set_client($client);
        stream_state::for_stream('optics-2026');
        stream_state::for_stream('townhall');

        $found = stream_state::cached(['optics-2026', 'townhall', 'never-warmed']);

        $this->assertSame(
            [stream_state::LIVE, stream_state::ENDED_RECORDED],
            [$found['optics-2026']->state, $found['townhall']->state]
        );
        $this->assertArrayNotHasKey('never-warmed', $found);
    }

    /**
     * `store()` does not let a refusal overwrite a good value.
     *
     * @return void
     */
    public function test_store_keeps_a_good_value_over_unknown(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]);
        connection::set_client($client);
        stream_state::for_stream('optics-2026');

        $unknown = new stream_state(stream_state::UNKNOWN, null, 'AG-503', time());
        $kept = stream_state::store('optics-2026', $unknown);

        $this->assertSame(stream_state::LIVE, $kept->state);
        $this->assertSame(stream_state::LIVE, stream_state::cached(['optics-2026'])['optics-2026']->state);
    }
}
