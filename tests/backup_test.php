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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * Course copy and restore: the copy creates a new broadcast, tickets are not carried over.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_agora_activity_structure_step
 * @covers     \restore_agora_activity_task
 */
final class backup_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the activity record */
    private $agora;

    /**
     * A course with an activity for a broadcast.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        connection::set_client(null);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary(),
            'PUT /streams/configs/*' => \mod_agora_mock_central::json(201, ['name' => 'hr-lms-restored']),
            'PATCH /streams/*/recording-policy' => \mod_agora_mock_central::json(200, ['auto_export' => true]),
            'DELETE /streams/configs/*' => new \GuzzleHttp\Psr7\Response(204, [], ''),
            'DELETE /play-sessions/grants/*' => new \GuzzleHttp\Psr7\Response(204, [], ''),
        ]));

        $CFG->enablecompletion = 1;
        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
        set_config('srtportrange', '30000:39999', 'mod_agora');

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'stream_name' => 'optics-2026',
            'display_mode' => 'record_only',
            'completion_minutes' => 45,
            'created_by_plugin' => 1,
            'timestart' => 1790000000,
            'ondemand' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
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
     * Backs up the course and restores it into a new course.
     *
     * @return \stdClass the new course
     */
    private function backup_and_restore(): \stdClass {
        global $CFG, $USER;

        $backupid = 'agoratest';
        $controller = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $controller->execute_plan();
        $result = $controller->get_results();
        $file = $result['backup_destination'];
        $path = make_backup_temp_directory($backupid);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);
        $controller->destroy();

        $newcourseid = \restore_dbops::create_new_course('Copy', 'copy-' . time(), $this->course->category);
        $restore = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        try {
            $restore->execute_precheck();
            $restore->execute_plan();
        } finally {
            // A restore failure (for example, the port range not set) must not
            // leave the backup temp tables behind: without destroy() the
            // PHPUnit reset would drop them, and the next test would fail on
            // a repeated deletion.
            try {
                $restore->destroy();
            } catch (\Throwable $ignored) {
                // Clean up the already broken controller the best way available.
                unset($ignored);
            }
            // A half-built controller's destroy() finishes quietly without
            // reaching the drop. On postgres the PHPUnit rollback hides the
            // leftover; on mysql-family there is no transaction, so the temp
            // tables survive and the next test trips over "table already
            // exists". Drop them unconditionally (no-op after a clean run).
            global $DB;
            $dbman = $DB->get_manager();
            foreach (['backup_ids_temp', 'backup_files_temp'] as $temptable) {
                if ($dbman->table_exists($temptable)) {
                    $dbman->drop_table(new \xmldb_table($temptable));
                }
            }
        }

        return get_course($newcourseid);
    }

    /**
     * The activity copy creates a new broadcast: the original broadcast and its
     * recording stay with the original activity (D14), the copy gets its own
     * publication section with a new key and a port issued by the creation code
     * from the form.
     *
     * @return void
     */
    public function test_restore_creates_a_new_stream_for_the_copy(): void {
        global $DB;

        $newcourse = $this->backup_and_restore();

        $copies = $DB->get_records('agora', ['course' => $newcourse->id]);
        $this->assertCount(1, $copies);

        $copy = reset($copies);
        // The original binding is not carried over: the copy lives on a new broadcast.
        $this->assertNotSame('optics-2026', $copy->stream_name);
        $this->assertStringStartsWith('hr-lms-', $copy->stream_name);
        // A new publication section: a port from the range, a 24-character key.
        $this->assertGreaterThanOrEqual(30000, (int) $copy->srt_port);
        $this->assertLessThanOrEqual(39999, (int) $copy->srt_port);
        $key = \mod_agora\local\publish_manager::key_of($copy);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', (string) $key);
        // The copy is an activity with phases; the phase fields and records are not carried over.
        $this->assertSame(1, (int) $copy->has_phases);
        $this->assertSame(0, (int) $copy->started_at);
        $this->assertSame(0, (int) $copy->ended_at);
        $this->assertNull($copy->recording_id);

        // The display settings are carried over, the original broadcast of the original activity is intact.
        $this->assertSame('record_only', $copy->display_mode);
        $this->assertSame(45, (int) $copy->completion_minutes);
        $this->assertSame(1, (int) $copy->created_by_plugin);
        $this->assertSame(1790000000, (int) $copy->timestart);
        $original = $DB->get_record('agora', ['id' => $this->agora->id], '*', MUST_EXIST);
        $this->assertSame('optics-2026', $original->stream_name);

        // PUT carried the publication section, the recording policy followed.
        $put = null;
        foreach (\mod_agora_mock_central::requests() as $request) {
            if ($request->getMethod() === 'PUT') {
                $put = json_decode((string) $request->getBody(), true);
            }
        }
        $this->assertNotNull($put);
        $this->assertSame($key, $put['srt_publish']['passphrase']);
        $this->assertTrue(
            count(array_filter(
                \mod_agora_mock_central::requests(),
                static function ($request): bool {
                    return $request->getMethod() === 'PATCH'
                        && strpos($request->getUri()->getPath(), '/recording-policy') !== false;
                }
            )) === 1,
            'The new broadcast recording policy is set'
        );
    }

    /**
     * Restoring without a port range refuses with a reason, and no half-activity
     * remains in the new course.
     *
     * @return void
     */
    public function test_restore_without_a_port_range_refuses_cleanly(): void {
        global $DB;

        unset_config('srtportrange', 'mod_agora');

        $thrown = null;
        try {
            $this->backup_and_restore();
        } catch (\Throwable $error) {
            $thrown = $error;
        } finally {
            // A failure inside the plan leaves the backup temp tables registered,
            // while the PHPUnit reset drops them physically: the next backup
            // would fail on a repeated deletion. The temp table store is
            // private, so clean it via reflection — the physical tables will be
            // dropped by the PHPUnit reset together with the test transaction.
            $store = (new \ReflectionProperty($DB, 'temptables'))->getValue($DB);
            $store->delete_temptable('backup_ids_temp');
            $store->delete_temptable('backup_files_temp');
        }

        $this->assertNotNull($thrown, 'The restore refused honestly');
        $this->assertStringContainsStringIgnoringCase(
            'port range',
            $thrown->getMessage(),
            'The reason names the range setting'
        );
        foreach (get_courses() as $course) {
            if ($course->id == $this->course->id) {
                continue;
            }
            $this->assertSame(0, $DB->count_records('agora', ['course' => $course->id]), 'No half-activities');
        }
    }

    /**
     * Tickets and the accumulated time are not included into the copy.
     *
     * @return void
     */
    public function test_grants_are_not_copied(): void {
        global $DB;

        // A direct write: the ticket is issued by central, and the test checks
        // only that the copy does not carry it over.
        $DB->insert_record('agora_grants', (object) [
            'agoraid' => $this->agora->id,
            'userid' => 5,
            'purpose' => 'view',
            'stream_name' => 'optics-2026',
            'jti' => 'jti-secret',
            'token' => 'jwt-secret',
            'user_id' => 'hr-lms:5:ivanov',
            'expires_at' => time() + 3600,
            'status' => 'issued',
            'timecreated' => time(),
        ]);

        $newcourse = $this->backup_and_restore();
        $copies = $DB->get_records('agora', ['course' => $newcourse->id]);
        $copy = reset($copies);

        $this->assertSame(0, $DB->count_records('agora_grants', ['agoraid' => $copy->id]));
        $this->assertSame(1, $DB->count_records('agora_grants'));
    }

    /**
     * A comment moves into the new course together with the activity.
     *
     * The course is restored specifically into a new one: only there does the
     * activity get a new identifier, and it is visible that the comment mapping
     * is looked up by the `agora` name, not by the area name.
     *
     * @return void
     */
    public function test_comments_survive_the_restore_into_a_new_course(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $cmid = (int) get_coursemodule_from_instance('agora', $this->agora->id)->id;

        // The comment is written through the user door.
        $this->setUser($student);
        \core_comment_external::add_comments([[
            'contextlevel' => 'module',
            'instanceid' => $cmid,
            'component' => 'mod_agora',
            'content' => 'Comment before the copy',
            'itemid' => (int) $this->agora->id,
            'area' => 'stream',
        ]]);
        $this->setAdminUser();

        $newcourse = $this->backup_and_restore();

        $copies = $DB->get_records('agora', ['course' => $newcourse->id]);
        $copy = reset($copies);
        $this->assertNotEquals((int) $this->agora->id, (int) $copy->id);

        $newcmid = (int) get_coursemodule_from_instance('agora', $copy->id)->id;
        $comments = $DB->get_records('comments', [
            'contextid' => \context_module::instance($newcmid)->id,
            'component' => 'mod_agora',
            'commentarea' => 'stream',
        ]);

        $this->assertCount(1, $comments);
        $comment = reset($comments);
        $this->assertSame('Comment before the copy', $comment->content);
        // The activity identifier is rebuilt: the comment hangs on the copy.
        $this->assertSame((int) $copy->id, (int) $comment->itemid);
    }

    /**
     * Restoring lifts the tombstone of the copy's new broadcast: the broadcast
     * is back in use.
     *
     * @return void
     */
    public function test_restore_lifts_the_tombstone(): void {
        global $DB;

        // The name of the new broadcast is the stream_creator rules applied to
        // the activity name: it is predictable, so the tombstone can be laid in advance.
        $streamname = \mod_agora\local\stream_creator::stream_name($this->agora->name);
        $DB->insert_record('agora_tombstones', (object) [
            'streamname' => $streamname,
            'deleteafter' => time() + 7 * DAYSECS,
            'timecreated' => time(),
        ]);

        $this->backup_and_restore();

        $this->assertSame(0, $DB->count_records('agora_tombstones', ['streamname' => $streamname]));
    }
}
