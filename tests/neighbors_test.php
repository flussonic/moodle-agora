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
use mod_agora\local\neighbors;
use mod_agora\local\stream_state;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * The neighbours of the open activity: order, visibility, limits, completion and target.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\neighbors
 */
final class neighbors_test extends \advanced_testcase {
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
     * Creates an «Agora broadcast» activity in a course.
     *
     * @param \stdClass $course the course
     * @param string $name the activity name
     * @param string $streamname the broadcast name
     * @param array $extra additional fields
     * @return \stdClass the activity record
     */
    private function element(\stdClass $course, string $name, string $streamname, array $extra = []): \stdClass {
        return $this->getDataGenerator()->create_module('agora', $extra + [
            'course' => $course->id,
            'name' => $name,
            'stream_name' => $streamname,
        ]);
    }

    /**
     * Warms the broadcast state cache through the user door — a `for_stream()` call.
     *
     * @param string $streamname the broadcast name
     * @param string $state one of the states: live or ended_recorded
     * @return void
     */
    private function warm(string $streamname, string $state): void {
        $stats = $state === stream_state::LIVE
            ? \mod_agora_mock_central::live_stats($streamname)
            : \mod_agora_mock_central::recorded_stats(7200, $streamname);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/' . $streamname => $stats,
        ]));
        stream_state::for_stream($streamname);
        connection::set_client(null);
    }

    /**
     * The course list order is the section and the place in the section, not the modinfo order.
     *
     * @return void
     */
    public function test_playlist_follows_course_order(): void {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // The activities are created interleaved across sections: if the order
        // were taken from modinfo as is, the list would go by identifiers.
        $this->element($course, 'Second', 'stream-b', ['section' => 1]);
        $first = $this->element($course, 'First', 'stream-a', ['section' => 1]);
        $this->element($course, 'Third', 'stream-c', ['section' => 2]);
        $this->set_position($course, ['First', 'Second']);

        $this->setUser($student);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($first->cmid);
        $list = neighbors::for_course($cm, false);

        $this->assertSame(
            ['First', 'Second', 'Third'],
            array_column($list['rows'], 'name')
        );
        $this->assertSame(3, $list['total']);
        // More than one section — their names stand above their groups.
        $this->assertNotSame('', $list['rows'][0]['sectionname']);
        $this->assertSame('', $list['rows'][1]['sectionname']);
        $this->assertNotSame('', $list['rows'][2]['sectionname']);
    }

    /**
     * Puts the section activities in the named order.
     *
     * @param \stdClass $course the course
     * @param string[] $names the activity names in the needed order
     * @return void
     */
    private function set_position(\stdClass $course, array $names): void {
        global $DB;

        $ids = [];
        foreach ($names as $name) {
            $instance = $DB->get_field('agora', 'id', ['name' => $name, 'course' => $course->id]);
            $cm = get_coursemodule_from_instance('agora', $instance, $course->id);
            $ids[] = (int) $cm->id;
        }
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1]);
        $DB->set_field('course_sections', 'sequence', implode(',', $ids), ['id' => $section->id]);
        rebuild_course_cache($course->id, true);
    }

    /**
     * The open activity is marked and is not a link.
     *
     * @return void
     */
    public function test_open_element_is_marked_and_is_not_a_link(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $open = $this->element($course, 'Open one', 'stream-a');
        $this->element($course, 'Neighboring', 'stream-b');

        $this->setUser($student);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($open->cmid);
        $rows = neighbors::for_course($cm, false)['rows'];

        $current = array_values(array_filter($rows, static function (array $row): bool {
            return $row['current'];
        }));
        $this->assertCount(1, $current);
        $this->assertSame('Open one', $current[0]['name']);
        $this->assertNull($current[0]['url']);
    }

    /**
     * An activity hidden from learners: not given to the learner, to the presenter — with a flag.
     *
     * @return void
     */
    public function test_hidden_element_is_for_the_teacher_only(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $open = $this->element($course, 'Open one', 'stream-a');
        $secret = $this->element($course, 'Hidden one', 'stream-b');
        $DB->set_field('course_modules', 'visible', 0, ['id' => $secret->cmid]);
        rebuild_course_cache($course->id, true);

        $this->setUser($student);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($open->cmid);
        $this->assertSame(['Open one'], array_column(neighbors::for_course($cm, false)['rows'], 'name'));

        $this->setUser($teacher);
        $cm = get_fast_modinfo($course, $teacher->id)->get_cm($open->cmid);
        $rows = neighbors::for_course($cm, true)['rows'];
        $this->assertSame(['Open one', 'Hidden one'], array_column($rows, 'name'));
        $this->assertTrue($rows[1]['hidden']);
    }

    /**
     * An activity closed by a restriction — no link, with the condition text.
     *
     * @return void
     */
    public function test_restricted_element_shows_the_condition(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $open = $this->element($course, 'Open one', 'stream-a');
        $closed = $this->element($course, 'Closed one', 'stream-b');
        // A date condition: the core shows it as text and removes the link.
        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&',
            'c' => [['type' => 'date', 'd' => '>=', 't' => time() + WEEKSECS]],
            'showc' => [true],
        ]), ['id' => $closed->cmid]);
        rebuild_course_cache($course->id, true);

        $this->setUser($student);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($open->cmid);
        $rows = neighbors::for_course($cm, false)['rows'];

        $this->assertSame(['Open one', 'Closed one'], array_column($rows, 'name'));
        $this->assertTrue($rows[1]['locked']);
        $this->assertNull($rows[1]['url']);
        $this->assertNotSame('', $rows[1]['subtitle'], 'The access condition is named in words');
        $this->assertNull($rows[1]['frame'], 'The closed activity has no frame');

        // The case is not spoiled: the core puts the condition date in <strong>,
        // and `html_to_text()` would have raised it to uppercase — «2 OCTOBER 2026».
        $closedcm = get_fast_modinfo($course, $student->id)->get_cm($closed->cmid);
        $this->assertSame(1, preg_match('#<strong>(.+?)</strong>#s', $closedcm->availableinfo, $matches));
        $this->assertStringContainsString($matches[1], $rows[1]['subtitle']);
        $this->assertStringNotContainsString('<', $rows[1]['subtitle'], 'The line has no markup');
    }

    /**
     * Cards: no card of the current course, none of a course without a recording; the limit is six.
     *
     * @return void
     */
    public function test_other_courses_holds_only_the_courses_of_the_viewer(): void {
        $current = $this->getDataGenerator()->create_course(['fullname' => 'Current']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $current->id, 'student');
        $this->element($current, 'Own', 'stream-here');

        $names = [];
        for ($i = 0; $i < neighbors::COURSE_LIMIT + 1; $i++) {
            $other = $this->getDataGenerator()->create_course(['fullname' => 'Course ' . $i]);
            $this->getDataGenerator()->enrol_user($student->id, $other->id, 'student');
            $this->element($other, 'Broadcast ' . $i, 'stream-' . $i);
            $names[] = 'Course ' . $i;
        }
        // A course with broadcasts the learner is not enrolled on.
        $stranger = $this->getDataGenerator()->create_course(['fullname' => 'Stranger']);
        $this->element($stranger, 'Foreign one', 'stream-stranger');

        $this->setUser($student);
        $cards = neighbors::other_courses((int) $current->id, false);

        $this->assertCount(neighbors::COURSE_LIMIT, $cards);
        $shown = array_column($cards, 'name');
        $this->assertNotContains('Current', $shown);
        $this->assertNotContains('Stranger', $shown);
        foreach ($shown as $name) {
            $this->assertContains($name, $names);
        }
    }

    /**
     * A learner without other courses gets no cards.
     *
     * @return void
     */
    public function test_single_course_learner_gets_no_cards(): void {
        $current = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($current, 'student');
        $this->element($current, 'Own', 'stream-here');

        $this->setUser($student);

        $this->assertSame([], neighbors::other_courses((int) $current->id, false));
    }

    /**
     * The card order: live ones, then by the nearest date, then by name.
     *
     * @return void
     */
    public function test_cards_go_live_then_date_then_name(): void {
        $current = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $current->id, 'student');

        $withlive = $this->getDataGenerator()->create_course(['fullname' => 'Apples']);
        $withdate = $this->getDataGenerator()->create_course(['fullname' => 'Bananas']);
        $plain = $this->getDataGenerator()->create_course(['fullname' => 'Oranges']);
        foreach ([$withlive, $withdate, $plain] as $course) {
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        }
        $this->element($withlive, 'Live one', 'stream-live');
        $this->element($withdate, 'With date', 'stream-date', ['timestart' => time() + DAYSECS]);
        $this->element($plain, 'No date', 'stream-plain');
        $this->warm('stream-live', stream_state::LIVE);

        $this->setUser($student);
        $cards = neighbors::other_courses((int) $current->id, false);

        $this->assertSame(['Apples', 'Bananas', 'Oranges'], array_column($cards, 'name'));

        // For the presenter — by name.
        $cards = neighbors::other_courses((int) $current->id, true);
        $this->assertSame(['Apples', 'Bananas', 'Oranges'], array_column($cards, 'name'));
    }

    /**
     * «Done k of M»: M counts only the activities with tracking on.
     *
     * @return void
     */
    public function test_progress_counts_only_tracked_elements(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $tracked = [];
        for ($i = 0; $i < 3; $i++) {
            $tracked[] = $this->element($course, 'Tracked ' . $i, 'stream-t' . $i, [
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionview' => 1,
            ]);
        }
        $this->element($course, 'Untracked', 'stream-plain', ['completion' => COMPLETION_TRACKING_NONE]);

        // Completion is marked through the user door: the learner opened the activity.
        $completion = new \completion_info(get_course($course->id));
        $this->setUser($student);
        foreach ([$tracked[0], $tracked[1]] as $module) {
            $cm = get_coursemodule_from_id('agora', $module->cmid);
            $completion->set_module_viewed($cm, (int) $student->id);
        }

        $modinfo = get_fast_modinfo($course, $student->id);
        $this->assertSame(['done' => 2, 'total' => 3], neighbors::progress($modinfo, (int) $student->id));
    }

    /**
     * Completion is off in the course — no row.
     *
     * @return void
     */
    public function test_progress_is_absent_without_completion(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->element($course, 'Own', 'stream-a');

        $this->setUser($student);

        $this->assertNull(neighbors::progress(get_fast_modinfo($course, $student->id), (int) $student->id));
    }

    /**
     * The card target: a live broadcast goes first, even if it is not first in the course.
     *
     * @return void
     */
    public function test_target_prefers_the_live_stream(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->element($course, 'First', 'stream-a', ['completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1]);
        $live = $this->element($course, 'Live one', 'stream-live');
        $this->warm('stream-live', stream_state::LIVE);

        $this->setUser($student);
        $modinfo = get_fast_modinfo($course, $student->id);
        $states = stream_state::cached(['stream-a', 'stream-live']);

        $target = neighbors::target_for($modinfo, (int) $student->id, false, $states);

        $this->assertSame((int) $live->cmid, (int) $target->id);
    }

    /**
     * With completion on the target is the first broadcast the learner has not opened.
     *
     * @return void
     */
    public function test_target_is_the_first_unviewed_element(): void {
        global $CFG;

        $CFG->enablecompletion = 1;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $modules = [];
        foreach (['First', 'Second', 'Third'] as $i => $name) {
            $modules[] = $this->element($course, $name, 'stream-' . $i, [
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionview' => 1,
            ]);
        }

        // Completion is marked through the user door: the learner opened the first one.
        $this->setUser($student);
        $completion = new \completion_info(get_course($course->id));
        $completion->set_module_viewed(get_coursemodule_from_id('agora', $modules[0]->cmid), (int) $student->id);

        $modinfo = get_fast_modinfo($course, $student->id);
        $target = neighbors::target_for($modinfo, (int) $student->id, false, []);

        $this->assertSame((int) $modules[1]->cmid, (int) $target->id);
    }

    /**
     * Completion is off — the target is the first broadcast of the course.
     *
     * @return void
     */
    public function test_target_is_the_first_element_without_completion(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $first = $this->element($course, 'First', 'stream-a');
        $this->element($course, 'Second', 'stream-b');

        $this->setUser($student);
        $target = neighbors::target_for(get_fast_modinfo($course, $student->id), (int) $student->id, false, []);

        $this->assertSame((int) $first->cmid, (int) $target->id);
    }

    /**
     * Everything is viewed — the target is again the first broadcast of the course.
     *
     * @return void
     */
    public function test_target_returns_to_the_first_when_everything_is_viewed(): void {
        global $CFG;

        $CFG->enablecompletion = 1;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $first = $this->element($course, 'First', 'stream-a', ['completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1]);
        $second = $this->element($course, 'Second', 'stream-b', ['completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1]);

        $this->setUser($student);
        $completion = new \completion_info(get_course($course->id));
        foreach ([$first, $second] as $module) {
            $completion->set_module_viewed(get_coursemodule_from_id('agora', $module->cmid), (int) $student->id);
        }

        $target = neighbors::target_for(get_fast_modinfo($course, $student->id), (int) $student->id, false, []);

        $this->assertSame((int) $first->cmid, (int) $target->id);
    }

    /**
     * Activities closed by a restriction and hidden ones never become the target.
     *
     * @return void
     */
    public function test_target_skips_restricted_and_hidden_elements(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $hidden = $this->element($course, 'Hidden one', 'stream-hidden', ['visible' => 0]);
        $closed = $this->element($course, 'Closed one', 'stream-closed');
        $open = $this->element($course, 'Open one', 'stream-open');
        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&',
            'c' => [['type' => 'date', 'd' => '>=', 't' => time() + WEEKSECS]],
            'showc' => [true],
        ]), ['id' => $closed->cmid]);
        rebuild_course_cache($course->id, true);
        // The live broadcast lies exactly in the closed activity: it does not fit either.
        $this->warm('stream-closed', stream_state::LIVE);

        $this->setUser($student);
        $states = stream_state::cached(['stream-hidden', 'stream-closed', 'stream-open']);
        $target = neighbors::target_for(get_fast_modinfo($course, $student->id), (int) $student->id, false, $states);

        $this->assertSame((int) $open->cmid, (int) $target->id);
        $this->assertNotSame((int) $hidden->cmid, (int) $target->id);
    }

    /**
     * For the presenter — the live one, else the first: completion is not counted for them.
     *
     * @return void
     */
    public function test_target_for_the_teacher_ignores_completion(): void {
        global $CFG;

        $CFG->enablecompletion = 1;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $first = $this->element($course, 'First', 'stream-a', ['completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1]);
        $second = $this->element($course, 'Second', 'stream-b', ['completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1]);

        $this->setUser($teacher);
        $modinfo = get_fast_modinfo($course, $teacher->id);

        $this->assertSame(
            (int) $first->cmid,
            (int) neighbors::target_for($modinfo, (int) $teacher->id, true, [])->id
        );

        // The live one — ahead of the first one, for the presenter too.
        $this->warm('stream-b', stream_state::LIVE);
        $states = stream_state::cached(['stream-a', 'stream-b']);
        $this->assertSame(
            (int) $second->cmid,
            (int) neighbors::target_for($modinfo, (int) $teacher->id, true, $states)->id
        );
    }

    /**
     * The course card describes one and the same target: the link, the state and the name.
     *
     * @return void
     */
    public function test_card_takes_the_link_and_the_state_from_the_target(): void {
        $current = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course(['fullname' => 'Neighboring']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $current->id, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $other->id, 'student');
        $this->element($current, 'Own', 'stream-here');
        $this->element($other, 'Recording', 'stream-old');
        $live = $this->element($other, 'Live one', 'stream-live');
        $this->warm('stream-live', stream_state::LIVE);

        $this->setUser($student);
        $cards = neighbors::other_courses((int) $current->id, false);

        $this->assertCount(1, $cards);
        $card = reset($cards);
        $this->assertSame((int) $live->cmid, (int) $card['target']->id);
        $this->assertSame('Live one', $card['targetname']);
        $this->assertSame(stream_state::LIVE, $card['state']->state);
        $this->assertStringContainsString('/mod/agora/view.php', $card['url']->out(false));
        $this->assertStringContainsString('id=' . $live->cmid, $card['url']->out(false));
    }

    /**
     * A course where all broadcasts are closed to the learner gets no card.
     *
     * @return void
     */
    public function test_course_without_an_available_element_gets_no_card(): void {
        global $DB;

        $current = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course(['fullname' => 'Closed']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $current->id, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $other->id, 'student');
        $this->element($current, 'Own', 'stream-here');
        $closed = $this->element($other, 'Closed one', 'stream-closed');
        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&',
            'c' => [['type' => 'date', 'd' => '>=', 't' => time() + WEEKSECS]],
            'showc' => [true],
        ]), ['id' => $closed->cmid]);
        rebuild_course_cache($other->id, true);

        $this->setUser($student);

        $this->assertSame([], neighbors::other_courses((int) $current->id, false));
    }

    /**
     * Neighbours never go to central: neither the list rows, nor the cards.
     *
     * @return void
     */
    public function test_neighbours_never_ask_central(): void {
        $current = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $current->id, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $other->id, 'student');
        $open = $this->element($current, 'Open one', 'stream-a');
        $this->element($current, 'Neighboring', 'stream-b');
        $this->element($other, 'Foreign one', 'stream-c');

        $client = \mod_agora_mock_central::client([]);
        connection::set_client($client);
        $this->setUser($student);
        $cm = get_fast_modinfo($current, $student->id)->get_cm($open->cmid);

        neighbors::for_course($cm, false);
        neighbors::other_courses((int) $current->id, false);

        $this->assertSame([], $client->request_log());
    }
}
