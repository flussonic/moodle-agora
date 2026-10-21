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

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * Comments under the broadcast — through the user door, via the core web services.
 *
 * The plugin callbacks are not checked by themselves: a human reaches them only
 * through `comment_ajax.php` or the `core_comment_external` web service, and it
 * is there that `cm` arrives as a flat record without the `uservisible` field.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::mod_agora_comment_validate
 * @covers     ::mod_agora_comment_permissions
 */
final class comments_test extends \advanced_testcase {
    /** @var \stdClass The course with the broadcast. */
    private \stdClass $course;

    /** @var \stdClass The activity record. */
    private \stdClass $agora;

    /** @var \stdClass The course module. */
    private \stdClass $module;

    /** @var \stdClass The learner. */
    private \stdClass $student;

    /**
     * A course, a broadcast and a learner enrolled on it.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        set_config('centralurl', 'https://central.example.com', 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');

        $this->course = $this->getDataGenerator()->create_course();
        $this->module = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture',
            'stream_name' => 'optics-2026',
        ]);
        $this->agora = $DB->get_record('agora', ['id' => $this->module->id], '*', MUST_EXIST);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * An add-comment request — the same one the browser sends.
     *
     * @param array $override what to replace in the request
     * @return array the web service answer
     */
    private function add(array $override = []): array {
        return \core_comment_external::add_comments([$override + [
            'contextlevel' => 'module',
            'instanceid' => (int) $this->module->cmid,
            'component' => 'mod_agora',
            'content' => 'Thanks for the lecture',
            'itemid' => (int) $this->agora->id,
            'area' => 'stream',
        ]]);
    }

    /**
     * Asserts that the request is refused with the named code.
     *
     * @param string $errorcode the expected refusal code
     * @param array $override what to replace in the request
     * @return void
     */
    private function assert_add_refused(string $errorcode, array $override = []): void {
        try {
            $this->add($override);
            $this->fail('The add-comment request must be rejected');
        } catch (\moodle_exception $error) {
            $this->assertSame($errorcode, $error->errorcode);
        }
    }

    /**
     * A learner writes a comment and reads it; a second learner sees the same.
     *
     * @return void
     */
    public function test_learner_posts_and_everyone_reads(): void {
        $this->setUser($this->student);
        $added = $this->add();

        $this->assertCount(1, $added);
        $this->assertStringContainsString('Thanks for the lecture', $added[0]->content);

        $second = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($second);
        $read = \core_comment_external::get_comments(
            'module',
            (int) $this->module->cmid,
            'mod_agora',
            (int) $this->agora->id,
            'stream'
        );

        $this->assertCount(1, $read['comments']);
        $this->assertStringContainsString('Thanks for the lecture', $read['comments'][0]->content);
    }

    /**
     * A revoked plugin capability removes the ability to write.
     *
     * @return void
     */
    public function test_learner_without_the_plugin_capability_is_refused(): void {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability(
            'mod/agora:comment',
            CAP_PROHIBIT,
            $roleid,
            \context_module::instance((int) $this->module->cmid)->id,
            true
        );

        $this->setUser($this->student);

        $this->assert_add_refused('nopermissiontocomment');
    }

    /**
     * A revoked standard `moodle/comment:post` capability with the plugin
     * capability in place: the new comment field is absent from the page, and
     * the add request is refused — the same outcome as without the plugin capability.
     *
     * @return void
     */
    public function test_learner_without_the_core_capability_is_refused(): void {
        global $CFG, $DB, $OUTPUT, $PAGE;

        $CFG->usecomments = 1;
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $context = \context_module::instance((int) $this->module->cmid);
        assign_capability('moodle/comment:post', CAP_PROHIBIT, $roleid, $context->id, true);
        $this->assertTrue(has_capability('mod/agora:comment', $context, $this->student), 'Own capability is in place');

        $this->setUser($this->student);
        $PAGE->set_url('/mod/agora/view.php', ['id' => $this->module->cmid]);
        $cm = get_fast_modinfo($this->course, $this->student->id)->get_cm($this->module->cmid);
        \mod_agora\local\connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::json(
                200,
                \mod_agora_mock_central::stats_not_started()
            ),
        ]));
        try {
            $html = $OUTPUT->render(new \mod_agora\output\watch_page($this->agora, $cm, ''));
        } finally {
            \mod_agora\local\connection::set_client(null);
        }

        $this->assertStringNotContainsString('<textarea', $html, 'No new-comment field');
        $this->assertStringContainsString('mod-agora-comments', $html, 'The feed is in place');
        $this->assert_add_refused('nopermissiontocomment');
    }

    /**
     * A foreign comment area is refused.
     *
     * @return void
     */
    public function test_foreign_comment_area_is_refused(): void {
        $this->setUser($this->student);

        $this->assert_add_refused('invalidcommentarea', ['area' => 'glossary_entry']);
    }

    /**
     * A foreign item in the own area is refused.
     *
     * @return void
     */
    public function test_foreign_itemid_is_refused(): void {
        $this->setUser($this->student);

        $this->assert_add_refused('invalidcommentitemid', ['itemid' => (int) $this->agora->id + 1000]);
    }

    /**
     * The plugin setting switched off refuses even a direct request.
     *
     * @return void
     */
    public function test_switched_off_setting_refuses_the_request(): void {
        set_config('comments', 0, 'mod_agora');
        $this->setUser($this->student);

        $this->assert_add_refused('commentsoff');
    }

    /**
     * An activity closed by a restriction cannot be commented on.
     *
     * The refusal comes before the callback — at `require_login` with the
     * activity, and it has its own code. That the plugin computes availability
     * from the course data and not from the flat request record is shown by the
     * positive case above: with the flat record's `$cm->uservisible` even an
     * ordinary comment would not pass.
     *
     * @return void
     */
    public function test_restricted_element_is_refused(): void {
        global $DB;

        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&',
            'c' => [['type' => 'date', 'd' => '>=', 't' => time() + WEEKSECS]],
            'showc' => [true],
        ]), ['id' => $this->module->cmid]);
        rebuild_course_cache((int) $this->course->id, true);

        $this->setUser($this->student);

        $this->assert_add_refused('requireloginerror');
    }

    /**
     * An unenrolled user gets neither the feed nor the field: the activity is closed to them.
     *
     * @return void
     */
    public function test_unenrolled_user_reads_nothing(): void {
        $this->setUser($this->student);
        $this->add();

        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        $this->expectException(\require_login_exception::class);
        \core_comment_external::get_comments(
            'module',
            (int) $this->module->cmid,
            'mod_agora',
            (int) $this->agora->id,
            'stream'
        );
    }
}
