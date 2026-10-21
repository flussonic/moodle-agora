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

use mod_agora\local\watch;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/cli_process.php');

/**
 * The `sync` task in a separate process — the way cron runs it.
 *
 * Under PHPUnit the completion library is preloaded, and a task that forgot to
 * require it passes here but fails in cron on every pair: that is how the stand
 * ended up with zero marks out of twenty. So the task is started as a separate
 * PHP process launched like `admin/cli/scheduled_task.php`, on top of PHPUnit
 * (`tests/fixtures/cli_runner.php`).
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\task\sync
 */
final class sync_cron_test extends \advanced_testcase {
    /**
     * A learner who watched past the 2-minute threshold gets a mark from the
     * task launched as in cron.
     *
     * @return void
     */
    public function test_cron_process_marks_completion(): void {
        global $DB;

        $this->resetAfterTest();
        // The process sees only what was truly written, not what lives in the test transaction.
        $this->preventResetByRollback();

        // Completion is enabled where the LMS administrator enables it — in the
        // site settings in the database: the process reads them from there, not
        // from the test's memory.
        set_config('enablecompletion', 1);
        set_config('centralurl', 'https://agora.example.com', 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'stream_name' => 'optics-2026',
            'completion_minutes' => 2,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ]);
        $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant($agora, (int) $student->id);

        // Cron in steady state starts with warm core caches. The first process
        // after the PHPUnit reset rebuilds them and on the way loads the `lib.php`
        // of every plugin, completionlib among them — the defect is invisible
        // there. So run a warmup process first.
        \mod_agora_cli_process::run('warmup', []);

        $opened = time() - HOURSECS;
        $answer = \mod_agora_cli_process::run('task', [
            'task' => '\\mod_agora\\task\\sync',
            'routes' => [
                'GET /play-sessions/closed' => [[
                    'status' => 200,
                    'body' => ['sessions' => [[
                        'stream' => 'optics-2026',
                        'user_id' => 'hr-lms:' . $student->id . ':user' . $student->id,
                        // The shape of the stand's live response: labels are whole unix seconds.
                        'opened_at' => $opened,
                        'closed_at' => $opened + 300,
                        'duration' => 300,
                        'close_reason' => 'client_teardown',
                    ]]],
                ]],
            ],
        ]);

        // Without this check the test would prove operation in the environment
        // where the completion library is already preloaded — exactly the one
        // where the defect is invisible.
        $this->assertFalse($answer['preloaded'], 'The process must start without completionlib, like cron: '
            . implode(', ', $answer['preloadedby']));
        $this->assertNull($answer['failed'], (string) $answer['failed']);
        $this->assertStringNotContainsString('not set', $answer['stdout']);
        $this->assertStringContainsString(
            'watching-time completion marks set: 1',
            $answer['stdout'],
            $answer['stdout'] . $answer['stderr']
        );

        $this->assertSame(300, watch::seconds((int) $agora->id, (int) $student->id));
        $state = $DB->get_field('course_modules_completion', 'completionstate', [
            'coursemoduleid' => $agora->cmid,
            'userid' => $student->id,
        ]);
        $this->assertSame(COMPLETION_COMPLETE, (int) $state, 'The completion stands in the core completion log');
    }
}
