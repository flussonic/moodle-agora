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

namespace mod_agora\task;

use mod_agora\local\stream_state;

/**
 * Background refresh of the state of one stream.
 *
 * Scheduled by the page when the cached value went stale: the page itself
 * serves the previous value and does not call central. The task is single-use
 * — the stream name sits in `custom_data`, and a 60-second cache mark
 * deduplicates it per stream.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_state extends \core\task\adhoc_task {
    /**
     * The task name in the log.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskrefreshstate', 'mod_agora');
    }

    /**
     * The task pass.
     *
     * @return void
     */
    public function execute() {
        $data = (array) ($this->get_custom_data() ?? []);
        $name = (string) ($data['stream'] ?? '');
        if ($name === '') {
            return;
        }
        $state = stream_state::refresh($name);
        mtrace('mod_agora: stream state ' . $name . ' refreshed: ' . $state->state);
    }
}
