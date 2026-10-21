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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use mod_agora\local\connection;
use mod_agora\local\player_view;
use mod_agora\privacy\provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * The learner's data: metadata, export and deletion with ticket revocation.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The activity page markup as assembled by `view.php`.
     *
     * @param \stdClass $agora the activity record
     * @param \cm_info $cm the course module
     * @param int $userid the learner
     * @return string HTML
     */
    private function page(\stdClass $agora, \cm_info $cm, int $userid): string {
        global $OUTPUT;

        $scene = player_view::scene($agora, $cm, $userid);
        return $OUTPUT->render(new \mod_agora\output\watch_page($agora, $cm, $scene));
    }
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the learner */
    private $student;

    /** @var \stdClass the activity record */
    private $agora;

    /** @var \cm_info the course module */
    private $cm;

    /**
     * A course, a learner, an activity and a connection to the stub Agora.
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

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user(['username' => 'ivanov']);
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'stream_name' => 'optics-2026',
        ]);
        $this->cm = get_fast_modinfo($this->course)->get_cm($this->agora->cmid);
        $PAGE->set_url('/mod/agora/view.php', ['id' => $this->agora->cmid]);
    }

    /**
     * Removes the stub client afterwards.
     *
     * @return void
     */
    public function tearDown(): void {
        connection::set_client(null);
        parent::tearDown();
    }

    /**
     * Issues the learner a ticket the same way the activity page does.
     *
     * @return void
     */
    private function issue_grant(): void {
        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::json(200, ['status' => 'running']),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-privacy',
                'token' => 'jwt-privacy',
                'user_id' => 'hr-lms:' . $this->student->id . ':ivanov',
                'streams' => ['optics-2026'],
                'status' => 'issued',
                'created_at' => gmdate('c'),
                'expires_at' => gmdate('c', time() + 3600),
            ]),
        ]));
        $this->page($this->agora, $this->cm, (int) $this->student->id);
    }

    /**
     * The metadata names the tickets table and the external transfer of the identifier.
     *
     * @return void
     */
    public function test_metadata_names_table_and_external_location(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('mod_agora'));
        $types = [];
        foreach ($collection->get_collection() as $item) {
            $types[get_class($item)][] = $item->get_name();
        }

        $this->assertContains('agora_grants', $types[\core_privacy\local\metadata\types\database_table::class]);
        // The watched duration is a person's data too, and it has its own table.
        $this->assertContains('agora_watch', $types[\core_privacy\local\metadata\types\database_table::class]);
        $this->assertContains('agora', $types[\core_privacy\local\metadata\types\external_location::class]);
        // The core subsystem stores the comments, and the metadata links to it.
        $this->assertContains(
            'core_comment',
            $types[\core_privacy\local\metadata\types\subsystem_link::class]
        );
    }

    /**
     * Puts the accumulated watched duration on the learner.
     *
     * @param int $seconds the accumulated seconds
     * @return void
     */
    private function put_watched(int $seconds): void {
        // The same door as real watching: the ticket issue creates the pair row,
        // the task adds the seconds.
        \mod_agora\local\watch::note_grant(
            (int) $this->agora->id,
            (int) $this->student->id,
            'hr-lms:' . $this->student->id . ':user' . $this->student->id,
            time() - MINSECS
        );
        $row = \mod_agora\local\watch::row((int) $this->agora->id, (int) $this->student->id);
        \mod_agora\local\watch::add_seconds((int) $row->id, $seconds, time() - MINSECS);
    }

    /**
     * The watched duration without tickets also gives a context and a user.
     *
     * The task cleans the tickets, while the accumulated time outlives them:
     * without its own branch in the provider it would stay invisible to export
     * and deletion.
     *
     * @return void
     */
    public function test_watch_alone_gives_context_and_user(): void {
        $this->put_watched(600);

        $contextlist = provider::get_contexts_for_userid((int) $this->student->id);
        $this->assertEquals(
            [\context_module::instance($this->agora->cmid)->id],
            $contextlist->get_contextids()
        );

        $userlist = new \core_privacy\local\request\userlist(\context_module::instance($this->agora->cmid), 'mod_agora');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$this->student->id], $userlist->get_userids());
    }

    /**
     * The accumulated duration gets into the export.
     *
     * @return void
     */
    public function test_export_carries_watched_time(): void {
        $this->put_watched(600);
        $context = \context_module::instance($this->agora->cmid);

        provider::export_user_data(new approved_contextlist($this->student, 'mod_agora', [$context->id]));

        $exported = writer::with_context($context)->get_data([get_string('privacy:path:grants', 'mod_agora')]);
        $this->assertSame(600, $exported->watched->watched_secs);
    }

    /**
     * Deleting the data takes the accumulated duration away too — by all three paths.
     *
     * @return array
     */
    public static function deletion_provider(): array {
        return [
            'single user' => ['user'],
            'whole context' => ['context'],
            'user list' => ['userlist'],
        ];
    }

    /**
     * Any data deletion path takes the watched-duration row away too.
     *
     * @param string $way which path to delete by
     * @return void
     * @dataProvider deletion_provider
     */
    public function test_deletion_removes_watched_time(string $way): void {
        global $DB;

        $this->put_watched(600);
        $context = \context_module::instance($this->agora->cmid);
        connection::set_client(\mod_agora_mock_central::client([
            'DELETE /play-sessions/grants/*' => new \GuzzleHttp\Psr7\Response(204, [], ''),
        ]));

        if ($way === 'user') {
            provider::delete_data_for_user(new approved_contextlist($this->student, 'mod_agora', [$context->id]));
        } else if ($way === 'context') {
            provider::delete_data_for_all_users_in_context($context);
        } else {
            provider::delete_data_for_users(new \core_privacy\local\request\approved_userlist(
                $context,
                'mod_agora',
                [$this->student->id]
            ));
        }

        $this->assertSame(0, $DB->count_records('agora_watch', ['agoraid' => $this->agora->id]));
    }

    /**
     * The activity context is visible in the learner's context list.
     *
     * @return void
     */
    public function test_contexts_and_users(): void {
        $this->issue_grant();

        $contextlist = provider::get_contexts_for_userid((int) $this->student->id);
        $this->assertEquals(
            [\context_module::instance($this->agora->cmid)->id],
            $contextlist->get_contextids()
        );

        $userlist = new \core_privacy\local\request\userlist(\context_module::instance($this->agora->cmid), 'mod_agora');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$this->student->id], $userlist->get_userids());
    }

    /**
     * The export has the ticket with `jti`, but not the JWT itself.
     *
     * @return void
     */
    public function test_export_carries_jti_without_token(): void {
        $this->issue_grant();
        $context = \context_module::instance($this->agora->cmid);

        provider::export_user_data(new approved_contextlist(
            $this->student,
            'mod_agora',
            [$context->id]
        ));

        $exported = writer::with_context($context)->get_data([get_string('privacy:path:grants', 'mod_agora')]);
        $this->assertNotEmpty($exported->grants);
        $this->assertSame('jti-privacy', $exported->grants[0]->jti);
        // The bind address is a person's data: it is in the export.
        $this->assertSame('203.0.113.7', $exported->grants[0]->bind_ip);
        $this->assertStringNotContainsString('jwt-privacy', json_encode($exported));
    }

    /**
     * Leaves a comment under the broadcast through the user door.
     *
     * @param \stdClass $user who writes
     * @return void
     */
    private function post_comment(\stdClass $user): void {
        $this->setUser($user);
        \core_comment_external::add_comments([[
            'contextlevel' => 'module',
            'instanceid' => (int) $this->agora->cmid,
            'component' => 'mod_agora',
            'content' => 'Thanks for the lecture',
            'itemid' => (int) $this->agora->id,
            'area' => 'stream',
        ]]);
    }

    /**
     * One who only commented: the context, the export and the deletion do not lose them.
     *
     * Such a person has no ticket and no accumulated time at all — without the
     * comments branch in the provider they would not appear in any of the three
     * answers.
     *
     * @return void
     */
    public function test_commenter_without_a_ticket_is_seen_everywhere(): void {
        global $DB;

        $this->post_comment($this->student);
        $context = \context_module::instance($this->agora->cmid);

        $this->assertSame(0, $DB->count_records('agora_grants', ['userid' => $this->student->id]));
        $this->assertEquals(
            [$context->id],
            provider::get_contexts_for_userid((int) $this->student->id)->get_contextids()
        );

        $userlist = new \core_privacy\local\request\userlist($context, 'mod_agora');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$this->student->id], $userlist->get_userids());

        provider::export_user_data(new approved_contextlist($this->student, 'mod_agora', [$context->id]));
        $exported = writer::with_context($context)->get_data([get_string('commentsubcontext', 'core_comment')]);
        $this->assertNotEmpty($exported->comments);
        $this->assertStringContainsString('Thanks for the lecture', $exported->comments[0]->content);

        provider::delete_data_for_user(new approved_contextlist($this->student, 'mod_agora', [$context->id]));
        $this->assertSame(0, $DB->count_records('comments', ['component' => 'mod_agora']));
    }

    /**
     * Context deletion and userlist deletion also take the comments away.
     *
     * @return void
     */
    public function test_comments_go_with_the_context_and_with_the_userlist(): void {
        global $DB;

        $context = \context_module::instance($this->agora->cmid);

        $this->post_comment($this->student);
        provider::delete_data_for_users(new \core_privacy\local\request\approved_userlist(
            $context,
            'mod_agora',
            [$this->student->id]
        ));
        $this->assertSame(0, $DB->count_records('comments', ['component' => 'mod_agora']));

        $this->post_comment($this->student);
        provider::delete_data_for_all_users_in_context($context);
        $this->assertSame(0, $DB->count_records('comments', ['component' => 'mod_agora']));
    }

    /**
     * Deleting the learner's data revokes their tickets in Agora.
     *
     * @return void
     */
    public function test_delete_revokes_grants(): void {
        global $DB;

        $this->issue_grant();
        $context = \context_module::instance($this->agora->cmid);

        $client = \mod_agora_mock_central::client([
            'DELETE /play-sessions/grants/*' => new \GuzzleHttp\Psr7\Response(204, [], ''),
        ]);
        connection::set_client($client);

        provider::delete_data_for_user(new approved_contextlist($this->student, 'mod_agora', [$context->id]));

        $this->assertContains('DELETE /central/api-v4/play-sessions/grants/jti-privacy', $client->request_log());
        $this->assertSame(0, $DB->count_records('agora_grants', ['userid' => $this->student->id]));
    }
}
