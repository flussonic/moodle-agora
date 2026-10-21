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
use mod_agora\local\recording_routing;

/**
 * The recordings facet: whether the recordings endpoints
 * (`/streams/{name}/recordings`) are mounted in Agora. The probe is a LIST of
 * the recordings of a stream known to exist: yellow when the facet is absent
 * (recordings play through the archive window — a degradation, not a failure),
 * red with its own causes when the admin credentials are wrong or central is
 * unreachable. A live probe, with no display cache: the report answers for the
 * current moment.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_routing extends base {
    /**
     * The check name in the report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('checkrecordrouting', 'mod_agora');
    }

    /**
     * The "fix it" link: the plugin settings, the address and accounts fields.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/admin/settings.php', ['section' => 'modsettingagora']),
            get_string('settingspagelink', 'mod_agora')
        );
    }

    /**
     * The check result.
     *
     * @return result
     */
    public function get_result(): result {
        global $DB;

        $streamname = $DB->get_field_select('agora', 'stream_name', "stream_name <> ''", null, IGNORE_MULTIPLE);
        if ($streamname === false || $streamname === null || $streamname === '') {
            // No activities — there is nothing to probe, and "red" here would lie.
            return new result(result::NA, get_string('checkrecordroutingempty', 'mod_agora'), '');
        }

        $state = recording_routing::probe((string) $streamname, \context_system::instance());

        switch ($state) {
            case recording_routing::OK:
                return new result(result::OK, get_string('checkrecordroutingok', 'mod_agora'), '');
            case recording_routing::MISSING:
                return new result(
                    result::WARNING,
                    get_string('checkrecordroutingmissing', 'mod_agora'),
                    get_string('checkrecordroutingmissingdetails', 'mod_agora')
                );
            case recording_routing::AUTH:
                return new result(
                    result::ERROR,
                    get_string('checkrecordroutingauth', 'mod_agora'),
                    get_string('checkrecordroutingauthdetails', 'mod_agora')
                );
            default:
                return new result(
                    result::ERROR,
                    get_string('checkrecordroutingdown', 'mod_agora'),
                    get_string('checkrecordroutingdowndetails', 'mod_agora')
                );
        }
    }
}
