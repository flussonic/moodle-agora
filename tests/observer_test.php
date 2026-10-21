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

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * Revoking tickets on core events.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\observer
 */
final class observer_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the learner */
    private $student;

    /** @var \stdClass the activity record */
    private $agora;

    /** @var \mod_agora\local\agora_client the stub client */
    private $client;

    /**
     * A course, a learner, an activity and a live pair ticket.
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

        $this->client = \mod_agora_mock_central::client([
            'DELETE /play-sessions/grants/*' => new \GuzzleHttp\Psr7\Response(204, [], ''),
        ]);
        connection::set_client($this->client);
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
     * Puts a live pair ticket through the shared generator helper.
     *
     * The ticket here is a premise of the scenario, not its result: the issuing
     * itself is covered by `playback_test`.
     *
     * @param int|null $userid the learner
     * @param string $jti the ticket identifier
     * @return int the row id
     */
    private function put_grant(?int $userid = null, string $jti = 'jti-live'): int {
        $grant = $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant(
            $this->agora,
            $userid ?? (int) $this->student->id,
            ['jti' => $jti, 'token' => 'jwt-' . $jti]
        );
        return (int) $grant->id;
    }

    /**
     * The revocation requests that went to central.
     *
     * @return array
     */
    private function revocations(): array {
        return array_values(array_filter($this->client->request_log(), function (string $line): bool {
            return strpos($line, 'DELETE /central/api-v4/play-sessions/grants/') === 0;
        }));
    }

    /**
     * Unenrolment revokes the learner's tickets for the course broadcasts.
     *
     * @return void
     */
    public function test_unenrolment_revokes(): void {
        global $DB;

        $this->put_grant();
        $enrol = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $enrol->unenrol_user($instance, $this->student->id);

        $this->assertContains('DELETE /central/api-v4/play-sessions/grants/jti-live', $this->revocations());
        $this->assertSame('revoked', $DB->get_field('agora_grants', 'status', ['jti' => 'jti-live']));
        $this->assertNull($DB->get_field('agora_grants', 'token', ['jti' => 'jti-live']));
    }

    /**
     * Suspending the enrolment revokes the tickets.
     *
     * @return void
     */
    public function test_suspending_revokes(): void {
        global $DB;

        $this->put_grant();
        $enrol = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $enrol->update_user_enrol($instance, $this->student->id, ENROL_USER_SUSPENDED);

        $this->assertContains('DELETE /central/api-v4/play-sessions/grants/jti-live', $this->revocations());
    }

    /**
     * Editing the enrolment dates does not touch the ticket.
     *
     * @return void
     */
    public function test_changing_enrolment_dates_keeps_the_grant(): void {
        global $DB;

        $this->put_grant();
        $enrol = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $enrol->update_user_enrol($instance, $this->student->id, null, time() - 100, time() + DAYSECS);

        $this->assertSame([], $this->revocations());
        $this->assertSame('issued', $DB->get_field('agora_grants', 'status', ['jti' => 'jti-live']));
    }

    /**
     * A removed role revokes the ticket if the activity became inaccessible.
     *
     * @return void
     */
    public function test_role_unassign_revokes_when_access_is_gone(): void {
        global $DB;

        $this->put_grant();
        $context = \context_course::instance($this->course->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        role_unassign($roleid, $this->student->id, $context->id);

        $this->assertContains('DELETE /central/api-v4/play-sessions/grants/jti-live', $this->revocations());
    }

    /**
     * Leaving a group revokes the ticket if the activity was restricted to that group.
     *
     * @return void
     */
    public function test_group_removal_revokes_when_element_is_group_restricted(): void {
        global $CFG, $DB;

        $CFG->enableavailability = 1;

        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group->id,
            'userid' => $this->student->id,
        ]);

        // The «only this group» access restriction — the same one the presenter
        // sets in the activity form.
        $cm = get_coursemodule_from_instance('agora', $this->agora->id, $this->course->id, false, MUST_EXIST);
        $DB->set_field('course_modules', 'availability', json_encode((object) [
            'op' => '&',
            'c' => [(object) ['type' => 'group', 'id' => (int) $group->id]],
            'showc' => [false],
        ]), ['id' => $cm->id]);
        rebuild_course_cache($this->course->id, true);

        $this->put_grant();
        groups_remove_member($group->id, $this->student->id);

        $this->assertContains('DELETE /central/api-v4/play-sessions/grants/jti-live', $this->revocations());
    }

    /**
     * Deleting a user revokes all their live tickets.
     *
     * @return void
     */
    public function test_user_deleted_revokes(): void {
        global $DB;

        $this->put_grant();
        delete_user($DB->get_record('user', ['id' => $this->student->id]));

        $this->assertContains('DELETE /central/api-v4/play-sessions/grants/jti-live', $this->revocations());
    }

    /**
     * Deleting a user wipes their pair rows: the seconds and the `user_id` in
     * Agora with the login.
     *
     * @return void
     */
    public function test_user_deleted_removes_pair_rows(): void {
        global $DB;

        $this->put_grant();
        $this->assertTrue($DB->record_exists('agora_watch', ['userid' => $this->student->id]), 'The pair row exists');

        delete_user($DB->get_record('user', ['id' => $this->student->id]));

        $this->assertFalse($DB->record_exists('agora_watch', ['userid' => $this->student->id]));
    }

    /**
     * An event without live tickets makes not a single call.
     *
     * @return void
     */
    public function test_event_without_grants_makes_no_calls(): void {
        global $DB;

        $enrol = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $enrol->unenrol_user($instance, $this->student->id);

        $this->assertSame([], $this->client->request_log());
    }
}
