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

use mod_agora\task\refresh_state;
use mod_agora\task\sync;

defined('MOODLE_INTERNAL') || die();

/**
 * The plugin's tasks: the schedule lives in «Scheduled tasks», not in settings.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\task\sync
 * @covers     \mod_agora\task\refresh_state
 */
final class tasks_test extends \advanced_testcase {
    /**
     * Clean state.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The background pass is registered and runs every 10 minutes by default.
     *
     * @return void
     */
    public function test_scheduled_task_is_registered(): void {
        $task = \core\task\manager::get_scheduled_task(sync::class);

        $this->assertNotNull($task, 'The task must be visible in «Scheduled tasks»');
        $this->assertSame('*/10', $task->get_minute());
        $this->assertSame('*', $task->get_hour());
        $this->assertSame(get_string('tasksync', 'mod_agora'), $task->get_name());
    }

    /**
     * The task interval is changed in «Scheduled tasks», not in the settings.
     *
     * @return void
     */
    public function test_schedule_changes_in_scheduled_tasks_not_in_settings(): void {
        $task = \core\task\manager::get_scheduled_task(sync::class);
        $task->set_minute('*/5');
        \core\task\manager::configure_scheduled_task($task);

        $reloaded = \core\task\manager::get_scheduled_task(sync::class);
        $this->assertSame('*/5', $reloaded->get_minute());
        // The form help reads the schedule from the same place, not from its own setting.
        $this->assertStringStartsWith('*/5 ', \mod_agora_mod_form::task_interval());

        // The plugin has no «interval» setting and must not gain one.
        foreach (array_keys((array) get_config('mod_agora')) as $name) {
            $this->assertStringNotContainsString('interval', strtolower($name));
        }
    }

    /**
     * The state refresh is a one-off task carrying the broadcast name in its data.
     *
     * @return void
     */
    public function test_refresh_task_carries_the_stream_name(): void {
        $task = new refresh_state();
        $task->set_custom_data(['stream' => 'optics-2026']);
        \core\task\manager::queue_adhoc_task($task);

        $queued = \core\task\manager::get_adhoc_tasks(refresh_state::class);
        $this->assertCount(1, $queued);
        $this->assertSame('optics-2026', reset($queued)->get_custom_data()->stream);
        $this->assertSame(get_string('taskrefreshstate', 'mod_agora'), reset($queued)->get_name());
    }

    /**
     * A task without a broadcast name does nothing and does not fail.
     *
     * @return void
     */
    public function test_refresh_task_without_a_stream_is_a_no_op(): void {
        $task = new refresh_state();
        $task->set_custom_data([]);

        $this->expectNotToPerformAssertions();
        $task->execute();
    }
}
