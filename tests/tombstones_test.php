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
use mod_agora\task\sync;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/../lib.php');

/**
 * Tombstones: who gets them, when they are lifted and how they fire.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\tombstones
 */
final class tombstones_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /**
     * A course and a connection to the stub Agora; the course bin is enabled for a week.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        connection::set_client(null);

        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
        set_config('coursebinenable', 1, 'tool_recyclebin');
        set_config('coursebinexpiry', 7 * DAYSECS, 'tool_recyclebin');

        $this->course = $this->getDataGenerator()->create_course();
        connection::set_client(\mod_agora_mock_central::client([
            'DELETE /streams/configs/*' => new \GuzzleHttp\Psr7\Response(204, [], ''),
            'DELETE /play-sessions/grants/*' => new \GuzzleHttp\Psr7\Response(204, [], ''),
        ]));
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
     * Creates an activity for a broadcast.
     *
     * @param int $createdbyplugin the broadcast is created by the plugin
     * @param string $streamname the broadcast name
     * @return \stdClass
     */
    private function make_activity(int $createdbyplugin, string $streamname = 'hr-lms-optika'): \stdClass {
        return $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'stream_name' => $streamname,
            'created_by_plugin' => $createdbyplugin,
        ]);
    }

    /**
     * An operator broadcast gets no tombstone.
     *
     * @return void
     */
    public function test_operator_stream_gets_no_tombstone(): void {
        global $DB;

        $agora = $this->make_activity(0, 'operator-stream');
        course_delete_module($agora->cmid);

        $this->assertSame(0, $DB->count_records('agora_tombstones'));
    }

    /**
     * A plugin broadcast gets a tombstone with the course bin expiry.
     *
     * @return void
     */
    public function test_plugin_stream_gets_a_tombstone(): void {
        global $DB;

        $agora = $this->make_activity(1);
        $before = time();
        course_delete_module($agora->cmid);

        $row = $DB->get_record('agora_tombstones', ['streamname' => 'hr-lms-optika']);
        $this->assertNotFalse($row);
        // The deadline is asserted from both sides: a one-sided boundary would
        // let both «in a year» and «in a minute» pass.
        $this->assertGreaterThanOrEqual($before + 7 * DAYSECS, (int) $row->deleteafter);
        $this->assertLessThanOrEqual($before + 7 * DAYSECS + 5, (int) $row->deleteafter);
    }

    /**
     * A bin without an expiry means «never clean» — no tombstone.
     *
     * @return void
     */
    public function test_zero_expiry_writes_no_tombstone(): void {
        global $DB;

        set_config('coursebinexpiry', 0, 'tool_recyclebin');

        $agora = $this->make_activity(1);
        course_delete_module($agora->cmid);

        $this->assertSame(0, $DB->count_records('agora_tombstones'));
    }

    /**
     * The bin is off — the expiry comes from the plugin settings.
     *
     * @return void
     */
    public function test_disabled_bin_uses_plugin_setting(): void {
        global $DB;

        set_config('coursebinenable', 0, 'tool_recyclebin');
        set_config('tombstonedays', 3, 'mod_agora');

        $agora = $this->make_activity(1);
        $before = time();
        course_delete_module($agora->cmid);

        $row = $DB->get_record('agora_tombstones', ['streamname' => 'hr-lms-optika']);
        $this->assertNotFalse($row);
        $this->assertGreaterThanOrEqual($before + 3 * DAYSECS, (int) $row->deleteafter);
        $this->assertLessThanOrEqual($before + 3 * DAYSECS + 5, (int) $row->deleteafter);
    }

    /**
     * Deleting the whole course also writes a tombstone: the module event does not fire there.
     *
     * @return void
     */
    public function test_deleting_the_whole_course_writes_a_tombstone(): void {
        global $DB;

        $this->make_activity(1);
        delete_course($this->course, false);

        $this->assertSame(1, $DB->count_records('agora_tombstones', ['streamname' => 'hr-lms-optika']));
    }

    /**
     * The same broadcast bound by another activity gets no tombstone.
     *
     * @return void
     */
    public function test_stream_bound_elsewhere_survives(): void {
        global $DB;

        $first = $this->make_activity(1);
        $this->make_activity(1);

        course_delete_module($first->cmid);

        $this->assertSame(0, $DB->count_records('agora_tombstones'));
    }

    /**
     * A new binding of the same broadcast lifts the tombstone.
     *
     * @return void
     */
    public function test_new_binding_lifts_the_tombstone(): void {
        global $DB;

        $agora = $this->make_activity(1);
        course_delete_module($agora->cmid);
        $this->assertSame(1, $DB->count_records('agora_tombstones'));

        $this->make_activity(0);

        $this->assertSame(0, $DB->count_records('agora_tombstones'));
    }

    /**
     * When the deadline comes, the background task deletes the broadcast and lifts the tombstone.
     *
     * @return void
     */
    public function test_due_tombstone_deletes_the_stream(): void {
        global $DB;

        // A row with the deadline in the past: waiting a week in a test is not allowed.
        $DB->insert_record('agora_tombstones', (object) [
            'streamname' => 'hr-lms-optika',
            'deleteafter' => time() - 60,
            'timecreated' => time() - DAYSECS,
        ]);

        $client = \mod_agora_mock_central::client([
            'DELETE /streams/configs/hr-lms-optika' => new \GuzzleHttp\Psr7\Response(204, [], ''),
        ]);
        connection::set_client($client);

        ob_start();
        (new sync())->execute();
        ob_end_clean();

        $this->assertContains('DELETE /central/api-v4/streams/configs/hr-lms-optika', $client->request_log());
        $this->assertSame(0, $DB->count_records('agora_tombstones'));
    }

    /**
     * The task leaves alone a tombstone whose deadline has not come.
     *
     * @return void
     */
    public function test_pending_tombstone_is_left_alone(): void {
        global $DB;

        $DB->insert_record('agora_tombstones', (object) [
            'streamname' => 'hr-lms-optika',
            'deleteafter' => time() + DAYSECS,
            'timecreated' => time(),
        ]);

        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);

        ob_start();
        (new sync())->execute();
        ob_end_clean();

        $this->assertSame([], $client->request_log());
        $this->assertSame(1, $DB->count_records('agora_tombstones'));
    }
}
