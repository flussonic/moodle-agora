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
 * The activity row on the course page: the frame card, the subtitle, the broadcast name.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\output\preview_card
 */
final class cm_info_view_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /**
     * A connection to the stub Agora and a course.
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
        // The list row is only assembled on the page of its own course: that is
        // where the handler is invoked, so the test sets the page the same way.
        $this->setup_page($this->course);
    }

    /**
     * Sets the page to a course — as `course/view.php` does.
     *
     * @param \stdClass $course the course
     * @return void
     */
    private function setup_page(\stdClass $course): void {
        global $PAGE;

        $PAGE = new \moodle_page();
        $PAGE->set_url('/course/view.php', ['id' => $course->id]);
        $PAGE->set_course($course);
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
     * An ordinary activity: a card link with a badge, decorative for screen readers.
     *
     * @return void
     */
    public function test_ordinary_element_gets_a_card_with_a_badge(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $module = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture',
            'intro' => 'About diffraction',
            'introformat' => FORMAT_HTML,
            'stream_name' => 'optics-2026',
        ]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));

        $this->setUser($student);
        $cm = get_fast_modinfo($this->course, $student->id)->get_cm($module->cmid);
        $content = $cm->get_formatted_content();

        $this->assertStringContainsString('mod-agora-preview-course', $content);
        $this->assertStringContainsString('aria-hidden="true"', $content);
        $this->assertStringContainsString(get_string('badgelive', 'mod_agora'), $content);
        $this->assertStringContainsString('view.php?id=' . $module->cmid, $content);
        // The subtitle is a separate block: otherwise it would be glued to the broadcast name.
        $this->assertStringContainsString('mod-agora-subtitle', $cm->afterlink);
    }

    /**
     * The presenter gets the broadcast name on its own line, not at the tail of the subtitle.
     *
     * @return void
     */
    public function test_teacher_sees_the_stream_name_on_its_own_line(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $module = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture',
            'intro' => 'About diffraction',
            'introformat' => FORMAT_HTML,
            'stream_name' => 'optics-2026',
        ]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
        ]));

        $this->setUser($teacher);
        $cm = get_fast_modinfo($this->course, $teacher->id)->get_cm($module->cmid);
        $after = $cm->afterlink;

        // Two adjacent blocks, not one: the subtitle closes before the broadcast
        // name begins. styles.css separates them by a line break — a test cannot
        // check that, but the markup itself can be pinned down.
        $this->assertMatchesRegularExpression(
            '#mod-agora-subtitle[^>]*>.*?</div>\s*<div class="mod-agora-meta#s',
            $after
        );
        $this->assertStringContainsString('optics-2026', $after);
    }

    /**
     * An activity closed by a restriction: a lock instead of the frame, no badge
     * and no link, and not a single request to central.
     *
     * @return void
     */
    public function test_restricted_element_gets_a_lock_card_without_central(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $module = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Closed one',
            'stream_name' => 'optics-2026',
        ]);
        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&',
            'c' => [['type' => 'date', 'd' => '>=', 't' => time() + WEEKSECS]],
            'showc' => [true],
        ]), ['id' => $module->cmid]);
        rebuild_course_cache($this->course->id, true);

        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);
        $this->setUser($student);
        $cm = get_fast_modinfo($this->course, $student->id)->get_cm($module->cmid);
        $this->assertTrue($cm->is_visible_on_course_page());
        $this->assertFalse($cm->uservisible);

        $content = $cm->get_formatted_content();

        $this->assertStringContainsString('mod-agora-preview-empty', $content);
        $this->assertStringNotContainsString('mod-agora-state-badge', $content);
        $this->assertStringNotContainsString('view.php?id=' . $module->cmid, $content);
        $this->assertSame([], $client->request_log(), 'The closed activity never asks for state');
    }

    /**
     * A row of a neighbour from a foreign course is not assembled and does not hit central.
     *
     * The sidebar builds modinfo of other courses. If the handler worked there
     * too, a single access to a row's content would take the page into Agora
     * for every foreign broadcast.
     *
     * @return void
     */
    public function test_foreign_course_row_is_not_built(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($student->id, $other->id, 'student');
        $module = $this->getDataGenerator()->create_module('agora', [
            'course' => $other->id,
            'name' => 'Foreign one',
            'stream_name' => 'optics-2026',
        ]);

        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);
        $this->setUser($student);
        // The page stands on its own course while the row is taken from the foreign one.
        $this->setup_page($this->course);
        $cm = get_fast_modinfo($other, $student->id)->get_cm($module->cmid);

        $this->assertSame('', $cm->get_formatted_content());
        $this->assertSame('', (string) $cm->afterlink);
        $this->assertSame([], $client->request_log(), 'No central request for a foreign stream');
    }
}
