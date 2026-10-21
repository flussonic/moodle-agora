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
use mod_agora\local\agora_exception;
use mod_agora\local\port_registry;

/**
 * Publication is set up: publish address and a port range with free ports.
 *
 * Every refusal names its cause and the field it is fixed by: the address — in
 * Agora, the range — in the plugin settings. A separate line counts recordings
 * waiting for an export for over an hour: the counter is written by the
 * scheduled task `refresh_states`, here it is only read.
 *
 * TODO(teacher-broadcast): frozen 2026-10-16 — the sign-in of the publisher
 * account (in-browser broadcasting) is not checked while the feature and its
 * settings are hidden; restore it on unfreeze.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class publish_setup extends base {
    /**
     * The check name in the report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('checkpublishsetup', 'mod_agora');
    }

    /**
     * The "fix it" link: the plugin settings.
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
        $failures = [];

        $address = $this->publish_address();
        if ($address === null) {
            $failures[] = get_string('checkpublishnoaddress', 'mod_agora');
        }

        $free = $this->free_ports();
        if ($free === null) {
            $failures[] = get_string('checkpublishnorange', 'mod_agora');
        } else if ($free === 0) {
            $failures[] = get_string('checkpublishnoports', 'mod_agora');
        }

        $stale = (int) (get_config('mod_agora', 'recordexportless') ?: 0);

        if (!$failures) {
            $details = get_string('checkpublishsetupokdetails', 'mod_agora', (object) [
                'address' => s($address),
                'free' => $free,
            ]);
            if ($stale > 0) {
                $details .= '; ' . get_string('checkrecordexportless', 'mod_agora', $stale);
            }
            return new result(result::OK, get_string('checkpublishsetupok', 'mod_agora'), $details);
        }

        $details = implode('; ', $failures);
        if ($stale > 0) {
            $details .= ' ' . get_string('checkrecordexportless', 'mod_agora', $stale);
        }
        return new result(result::ERROR, get_string('checkpublishsetupfailed', 'mod_agora'), $details);
    }

    /**
     * The publish address: from the port summary, with the site setting as fallback.
     *
     * @return string|null null — no address either in Agora or in the settings
     */
    private function publish_address(): ?string {
        try {
            $address = \mod_agora\local\publish_manager::publish_address();
        } catch (agora_exception $error) {
            // An unreachable Agora is a refusal of the connectivity check, not
            // of this one: here we honestly look at the fallback address setting.
            unset($error);
            $address = trim((string) get_config('mod_agora', 'publishaddress'));
        }
        return $address !== '' ? $address : null;
    }

    /**
     * How many ports of the range are free; null — the range is not set.
     *
     * @return int|null
     */
    private function free_ports(): ?int {
        $range = port_registry::parse_range((string) get_config('mod_agora', 'srtportrange'));
        if ($range === null) {
            return null;
        }
        try {
            $occupied = port_registry::occupied();
        } catch (agora_exception $error) {
            // Without the summary freeness cannot be counted: it is fixed by the
            // central address, and that is no reason to go red here a second time.
            return max(0, $range['to'] - $range['from'] + 1);
        }
        $free = 0;
        for ($port = $range['from']; $port <= $range['to']; $port++) {
            if (!isset($occupied[$port])) {
                $free++;
            }
        }
        return $free;
    }
}
