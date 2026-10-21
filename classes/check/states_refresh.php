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

namespace mod_agora\check;

use core\check\result;
use mod_agora\local\stream_state;

/**
 * Refresh of broadcast states: whether the scheduled task runs and does not fail.
 *
 * Without this pass the side column of the activity page is left without badges
 * and frames, and that tells the learner nothing: they simply see a list
 * without pictures. So the lag is reported to the one who fixes it.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class states_refresh extends base {
    /**
     * The check name in the report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('checkstatesrefresh', 'mod_agora');
    }

    /**
     * The "fix it" link: the scheduled tasks page.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/admin/tool/task/scheduledtasks.php'),
            get_string('checkstatesrefreshlink', 'mod_agora')
        );
    }

    /**
     * Whether the warm-up lags behind: the last pass failed or it is older than the freshness window.
     *
     * The condition lives here once for two displays — the red row of the
     * report and the line to the teacher above the stage
     * (`\mod_agora\output\watch_page`). They must not diverge: a failed pass
     * with a fresh timestamp would paint the report red while the page said
     * nothing to the one who fixes it.
     *
     * @return array|null null — the warm-up is fine; otherwise ['failed' => bool, 'minutes' => int]
     */
    public static function lag(): ?array {
        $failure = (string) get_config('mod_agora', 'statesfailure');
        $refreshedat = (int) get_config('mod_agora', 'statesrefreshedat');
        $minutes = $refreshedat > 0 ? (int) floor(max(time() - $refreshedat, 0) / MINSECS) : 0;

        if ($failure !== '') {
            return ['failed' => true, 'minutes' => $minutes];
        }
        // Not a single pass yet — that is "unknown", not a lag: the task may
        // have been scheduled a minute ago.
        if ($refreshedat === 0) {
            return null;
        }
        if (time() - $refreshedat > stream_state::SIDEBAR_MAXAGE) {
            return ['failed' => false, 'minutes' => $minutes];
        }
        return null;
    }

    /**
     * The check result.
     *
     * @return result
     */
    public function get_result(): result {
        global $DB;

        if (!$DB->record_exists_select('agora', "stream_name <> ''")) {
            // No activities — there is nothing to warm up, and "red" here would lie.
            return new result(result::NA, get_string('checkstatesrefreshempty', 'mod_agora'), '');
        }

        $refreshedat = (int) get_config('mod_agora', 'statesrefreshedat');
        $refreshed = (int) get_config('mod_agora', 'statesrefreshed');
        $missing = (int) get_config('mod_agora', 'statesmissing');
        $failed = (int) get_config('mod_agora', 'statesfailed');

        $lag = self::lag();

        if ($lag !== null && $lag['failed']) {
            return new result(
                result::ERROR,
                get_string('checkstatesrefreshfailed', 'mod_agora'),
                get_string('checkstatesrefreshdetails', 'mod_agora', (object) [
                    'failure' => s((string) get_config('mod_agora', 'statesfailure')),
                    'failed' => $failed,
                ])
            );
        }

        if ($refreshedat === 0) {
            return new result(result::UNKNOWN, get_string('checkstatesrefreshnever', 'mod_agora'), '');
        }

        if ($lag !== null) {
            return new result(
                result::ERROR,
                get_string('checkstatesrefreshstale', 'mod_agora', $lag['minutes']),
                userdate($refreshedat)
            );
        }

        // The pass succeeded, but the broadcasts were not found in Agora. One or
        // two — the operator deleted a broadcast, and it is fixed by the "Stream"
        // field in the activity settings. All of them — the lists came from the
        // wrong installation: the central address was replaced or the API version
        // changed, and it is fixed by the "Agora server address" field.
        if ($missing > 0) {
            $total = $refreshed + $missing + $failed;
            $a = (object) ['missing' => $missing, 'total' => $total];
            if ($missing === $total) {
                return new result(
                    result::ERROR,
                    get_string('checkstatesrefreshmissing', 'mod_agora', $missing),
                    get_string('checkstatesrefreshmissingall', 'mod_agora', $a)
                );
            }
            return new result(
                result::WARNING,
                get_string('checkstatesrefreshmissing', 'mod_agora', $missing),
                get_string('checkstatesrefreshmissingsome', 'mod_agora', $a)
            );
        }

        return new result(
            result::OK,
            get_string('checkstatesrefreshok', 'mod_agora', $refreshed),
            userdate($refreshedat)
        );
    }
}
