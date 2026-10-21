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

use core\activity_dates;

/**
 * The broadcast start date through the standard activity dates mechanism.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\dates
 */
final class dates_test extends \advanced_testcase {
    /**
     * A date is set — the activity dates list has one row with that timestamp.
     *
     * @return void
     */
    public function test_timestart_is_published(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // A future date: the "starts" label depends on the date being ahead.
        // A hard-coded date here once became past and broke the test without
        // a single code change.
        $timestart = time() + WEEKSECS;
        $module = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
            'timestart' => $timestart,
        ]);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($module->cmid);

        $dates = activity_dates::get_dates_for_module($cm, (int) $student->id);

        $this->assertCount(1, $dates);
        $this->assertSame('timestart', $dates[0]['dataid']);
        $this->assertSame($timestart, $dates[0]['timestamp']);
        $this->assertSame(get_string('timestartlabel', 'mod_agora'), $dates[0]['label']);
    }

    /**
     * A past date is labelled with the past tense.
     *
     * @return void
     */
    public function test_past_timestart_gets_the_past_label(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $timestart = time() - WEEKSECS;
        $module = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
            'timestart' => $timestart,
        ]);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($module->cmid);

        $dates = activity_dates::get_dates_for_module($cm, (int) $student->id);

        $this->assertCount(1, $dates);
        $this->assertSame(get_string('timestartedlabel', 'mod_agora'), $dates[0]['label']);
    }

    /**
     * No date — no row.
     *
     * @return void
     */
    public function test_no_timestart_gives_no_row(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
            'timestart' => 0,
        ]);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($module->cmid);

        $this->assertSame([], activity_dates::get_dates_for_module($cm, (int) $student->id));
    }
}
