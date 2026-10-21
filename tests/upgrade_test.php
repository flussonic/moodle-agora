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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/agora/db/upgrade.php');
require_once($CFG->libdir . '/ddllib.php');
// The `upgrade_mod_savepoint()` lives here and is not included in a normal request.
require_once($CFG->libdir . '/upgradelib.php');

/**
 * Upgrading the plugin schema on non-empty tables.
 *
 * The upgrade is the only path by which the plugin reaches a running site, and
 * an empty table does not check it: the core silently adds a NOT NULL column
 * without a default value while there are no rows, and refuses as soon as there
 * are some.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_agora_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * Rolls the schema back to the state before the changes and fills the tables.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Puts the schema back.
     *
     * The reset between tests restores the data but not the structure: it does
     * not bring back a dropped column, and the next test would run against a
     * foreign schema. The upgrade is idempotent, so a repeated run is the
     * restoration.
     *
     * @return void
     */
    protected function tearDown(): void {
        set_config('version', 2026092401, 'mod_agora');
        xmldb_agora_upgrade(2026092401);
        parent::tearDown();
    }

    /**
     * Rolls the schema back to the look of version 2026092401 and puts rows into the tables.
     *
     * @return void
     */
    private function roll_back_schema(): void {
        global $DB;

        $dbman = $DB->get_manager();
        // The upgrade savepoint is compared with the recorded plugin version:
        // without rolling the version back the core treats the call as a downgrade.
        set_config('version', 2026092401, 'mod_agora');

        $grants = new \xmldb_table('agora_grants');
        $index = new \xmldb_index('purposestream', XMLDB_INDEX_NOTUNIQUE, ['purpose', 'stream_name']);
        if ($dbman->index_exists($grants, $index)) {
            $dbman->drop_index($grants, $index);
        }
        foreach (['stream_name', 'bind_ip', 'scope'] as $name) {
            $field = new \xmldb_field($name);
            if ($dbman->field_exists($grants, $field)) {
                $dbman->drop_field($grants, $field);
            }
        }

        $agora = new \xmldb_table('agora');
        foreach (
            ['ondemand', 'last_live_at', 'started_at', 'ended_at', 'ended_by', 'recording_id', 'has_phases',
            'end_pending', 'end_failure', 'srt_port', 'srt_key'] as $name
        ) {
            $field = new \xmldb_field($name);
            if ($dbman->field_exists($agora, $field)) {
                $dbman->drop_field($agora, $field);
            }
        }

        $watch = new \xmldb_table('agora_watch');
        if ($dbman->table_exists($watch)) {
            $dbman->drop_table($watch);
        }

        // Non-empty tables: it is exactly on them that the upgrade used to fail.
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('agora', (object) [
            'course' => $course->id,
            'name' => 'Lecture',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'stream_name' => 'optics-2026',
            'created_by_plugin' => 0,
            'display_mode' => 'auto',
            'completion_minutes' => 0,
            'timestart' => 0,
            'timemodified' => time(),
        ]);
        $DB->insert_record('agora_grants', (object) [
            'agoraid' => 1,
            'userid' => 2,
            'purpose' => 'view',
            'jti' => 'jti-old',
            'token' => 'jwt-old',
            'user_id' => 'hr-lms:2:ivanov',
            'expires_at' => time() + HOURSECS,
            'status' => 'issued',
            'timecreated' => time(),
        ]);
    }

    /**
     * The upgrade on filled tables passes and yields the schema from install.xml.
     *
     * @return void
     */
    public function test_upgrade_on_filled_tables_matches_install_xml(): void {
        global $DB;

        $this->roll_back_schema();
        $dbman = $DB->get_manager();

        $this->assertTrue(xmldb_agora_upgrade(2026092401));

        // The fresh schema from install.xml and the upgrade result must match:
        // otherwise the upgraded site lives with a different schema than a new one.
        $errors = $dbman->check_database_schema($dbman->get_install_xml_schema());
        $ours = array_filter($errors, function (string $table): bool {
            return strpos($table, 'agora') === 0;
        }, ARRAY_FILTER_USE_KEY);
        $this->assertSame([], $ours, 'Schema drift: ' . json_encode($ours, JSON_UNESCAPED_UNICODE));
    }

    /**
     * The data that sat in the tables before the upgrade survives it.
     *
     * @return void
     */
    public function test_upgrade_keeps_the_rows(): void {
        global $DB;

        $this->roll_back_schema();

        $this->assertTrue(xmldb_agora_upgrade(2026092401));

        $grant = $DB->get_record('agora_grants', ['jti' => 'jti-old']);
        $this->assertNotFalse($grant, 'The ticket row must survive the upgrade');
        // The column is added with a default value, not lost.
        $this->assertSame('', (string) $grant->stream_name);
        // The former ticket knows no binding: its address is empty, and it will be
        // reused only with binding off.
        $this->assertNull($grant->bind_ip);
        // The former ticket knows no scope: its scope is the broadcast name of the pair itself.
        $this->assertNull($grant->scope);
        $this->assertSame(0, (int) $DB->get_field('agora', 'last_live_at', ['stream_name' => 'optics-2026']));

        $row = $DB->get_record('agora', ['stream_name' => 'optics-2026']);
        // The legacy discriminator: activities from before the change stay MVP
        // legacy forever — without it the NULL phase fields would turn every live
        // inherited broadcast into a «planned» one for the learners.
        $this->assertSame(0, (int) $row->has_phases);
        $this->assertSame(0, (int) $row->started_at);
        $this->assertSame(0, (int) $row->ended_at);
        $this->assertSame(0, (int) $row->end_pending);
        $this->assertNull($row->recording_id);
        $this->assertNull($row->ended_by);
        $this->assertNull($row->end_failure);
        // The legacy has no publication section: the port is free, the key is absent.
        $this->assertSame(0, (int) $row->srt_port);
        $this->assertNull($row->srt_key);
    }

    /**
     * An activity created after the change through the user door gets phases.
     *
     * The generator sets the legacy explicitly, so here the same
     * `agora_add_instance()` that the form saves with is called directly.
     *
     * @return void
     */
    public function test_new_elements_are_born_with_phases(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $id = agora_add_instance((object) [
            'course' => $course->id,
            'name' => 'Lecture',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'stream_name' => 'lecture-2',
            'display_mode' => 'auto',
            'completion_minutes' => 0,
            'timestart' => 0,
        ]);

        $row = $DB->get_record('agora', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $row->has_phases);
        $this->assertSame(\mod_agora\local\broadcast_phase::PLANNED, \mod_agora\local\broadcast_phase::of($row));
    }

    /**
     * The upgrade moves the pair's `user_id` and the last ticket's expiry into
     * the pair row: by them the task polls and marks the pair even after the
     * tickets are deleted.
     *
     * @return void
     */
    public function test_upgrade_moves_pair_age_into_the_pair_row(): void {
        global $DB;

        $this->roll_back_schema();
        $expires = (int) $DB->get_field('agora_grants', 'expires_at', ['jti' => 'jti-old']);

        $this->assertTrue(xmldb_agora_upgrade(2026092401));

        $row = $DB->get_record('agora_watch', ['agoraid' => 1, 'userid' => 2]);
        $this->assertNotFalse($row, 'The pair row appeared');
        $this->assertSame('hr-lms:2:ivanov', $row->user_ref);
        $this->assertSame($expires, (int) $row->lastexpiry);
        $this->assertSame(0, (int) $row->watched_secs);
    }

    /**
     * The schema before the step 2026092506: `agora_watch` has no `user_ref` and `lastexpiry`.
     *
     * @return void
     */
    private function drop_pair_age_fields(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $watch = new \xmldb_table('agora_watch');
        foreach (['user_ref', 'lastexpiry'] as $name) {
            $field = new \xmldb_field($name);
            if ($dbman->field_exists($watch, $field)) {
                $dbman->drop_field($watch, $field);
            }
        }
    }

    /**
     * The step 2026092506 writes the age and the `user_id` into an already
     * existing pair row without touching its seconds.
     *
     * @return void
     */
    public function test_pair_age_step_updates_an_existing_row(): void {
        global $DB;

        set_config('version', 2026092505, 'mod_agora');
        $this->drop_pair_age_fields();
        $DB->insert_record('agora_watch', (object) [
            'agoraid' => 7, 'userid' => 3, 'watched_secs' => 600, 'polled_until' => 100, 'timemodified' => 100,
        ]);
        $expires = time() + HOURSECS;
        $DB->insert_record('agora_grants', (object) [
            'agoraid' => 7, 'userid' => 3, 'purpose' => 'view', 'stream_name' => 'optics-2026', 'jti' => 'jti-7',
            'token' => 'jwt-7', 'user_id' => 'hr-lms:3:petrov', 'expires_at' => $expires, 'status' => 'issued',
            'timecreated' => time(),
        ]);

        $this->assertTrue(xmldb_agora_upgrade(2026092505));

        $row = $DB->get_record('agora_watch', ['agoraid' => 7, 'userid' => 3]);
        $this->assertSame(600, (int) $row->watched_secs, 'The seconds are untouched');
        $this->assertSame($expires, (int) $row->lastexpiry);
        $this->assertSame('hr-lms:3:petrov', $row->user_ref);
    }

    /**
     * The step 2026092507: a pair row without tickets gets the age by the last
     * thing known about it and the `user_id` by the `grant_manager::user_ref()`
     * formula; a deleted user's `user_id` is not guessed.
     *
     * @return void
     */
    public function test_pair_rows_without_tickets_get_an_age(): void {
        global $DB;

        set_config('sitelabel', 'hr-lms', 'mod_agora');
        $alive = $this->getDataGenerator()->create_user(['username' => 'ivanov']);
        $gone = $this->getDataGenerator()->create_user(['username' => 'sidorov']);
        delete_user($DB->get_record('user', ['id' => $gone->id]));
        $DB->delete_records('agora_watch');
        foreach ([$alive, $gone] as $user) {
            $DB->insert_record('agora_watch', (object) [
                'agoraid' => 9, 'userid' => $user->id, 'watched_secs' => 60, 'polled_until' => 2000,
                'timemodified' => 1000, 'lastexpiry' => 0, 'user_ref' => null,
            ]);
        }
        set_config('version', 2026092506, 'mod_agora');

        $this->expectOutputRegex('/given an age: 2, of them given user_id from the current username: 1, left without user_id: 1/');
        $this->assertTrue(xmldb_agora_upgrade(2026092506));

        $row = $DB->get_record('agora_watch', ['agoraid' => 9, 'userid' => $alive->id]);
        $this->assertSame(2000, (int) $row->lastexpiry, 'The age is the greater of the edit and the poll boundary');
        $this->assertSame('hr-lms:' . $alive->id . ':ivanov', $row->user_ref);
        // The same formula as the ticket issue: otherwise the history would be
        // looked up in the wrong place.
        $this->assertSame(\mod_agora\local\grant_manager::user_ref(999, (int) $alive->id), $row->user_ref);
        // No `user_id` is guessed for the deleted user (the step 2507 output:
        // «left without user_id: 1»), and the next step, 2026092508, removes
        // their row altogether.
        $this->assertFalse($DB->record_exists('agora_watch', ['agoraid' => 9, 'userid' => $gone->id]));
    }

    /**
     * The step 2026092507 without the site label: the age is set, and the
     * `user_id` is not guessed — the formula without the label would assemble
     * someone else's identifier.
     *
     * @return void
     */
    public function test_pair_rows_without_a_site_label_keep_no_user_ref(): void {
        global $DB;

        unset_config('sitelabel', 'mod_agora');
        $user = $this->getDataGenerator()->create_user(['username' => 'ivanov']);
        $DB->delete_records('agora_watch');
        $DB->insert_record('agora_watch', (object) [
            'agoraid' => 9, 'userid' => $user->id, 'watched_secs' => 60, 'polled_until' => 2000,
            'timemodified' => 1000, 'lastexpiry' => 0, 'user_ref' => null,
        ]);
        set_config('version', 2026092506, 'mod_agora');

        $this->expectOutputRegex('/given an age: 1, of them given user_id from the current username: 0, left without user_id: 1/');
        $this->assertTrue(xmldb_agora_upgrade(2026092506));

        $row = $DB->get_record('agora_watch', ['agoraid' => 9, 'userid' => $user->id]);
        $this->assertSame(2000, (int) $row->lastexpiry);
        $this->assertNull($row->user_ref);
    }

    /**
     * The step 2026092508 removes the pair rows of deleted users — like the
     * `user_deleted` observer does — and does not touch the rows of the living.
     *
     * @return void
     */
    public function test_pair_rows_of_deleted_users_are_removed(): void {
        global $DB;

        $alive = $this->getDataGenerator()->create_user();
        $gone = $this->getDataGenerator()->create_user();
        $DB->delete_records('agora_watch');
        foreach ([$alive, $gone] as $user) {
            $DB->insert_record('agora_watch', (object) [
                'agoraid' => 9, 'userid' => $user->id, 'watched_secs' => 60, 'polled_until' => 2000,
                'timemodified' => 1000, 'lastexpiry' => 2000, 'user_ref' => 'hr-lms:' . $user->id . ':x',
            ]);
        }
        // Deleted before the observer learned to clean the pair rows.
        $DB->set_field('user', 'deleted', 1, ['id' => $gone->id]);
        set_config('version', 2026092507, 'mod_agora');

        $this->expectOutputRegex('/pair rows of deleted users removed: 1/');
        $this->assertTrue(xmldb_agora_upgrade(2026092507));

        $this->assertTrue($DB->record_exists('agora_watch', ['userid' => $alive->id]));
        $this->assertFalse($DB->record_exists('agora_watch', ['userid' => $gone->id]));
    }

    /**
     * A repeated upgrade run breaks nothing.
     *
     * @return void
     */
    public function test_upgrade_is_idempotent(): void {
        global $DB;

        $this->roll_back_schema();

        $this->assertTrue(xmldb_agora_upgrade(2026092401));
        set_config('version', 2026092401, 'mod_agora');
        $this->assertTrue(xmldb_agora_upgrade(2026092401));

        $dbman = $DB->get_manager();
        $this->assertTrue($dbman->field_exists(new \xmldb_table('agora_grants'), new \xmldb_field('stream_name')));
        $this->assertTrue($dbman->field_exists(new \xmldb_table('agora_grants'), new \xmldb_field('bind_ip')));
        $this->assertTrue($dbman->table_exists(new \xmldb_table('agora_watch')));
    }
}
