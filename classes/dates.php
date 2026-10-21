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

declare(strict_types=1);

namespace mod_agora;

use core\activity_dates;

/**
 * The broadcast start date for the course card and the learner timeline.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dates extends activity_dates {
    /**
     * The important dates of the activity.
     *
     * @return array
     */
    protected function get_dates(): array {
        $timestart = (int) ($this->cm->customdata['timestart'] ?? 0);
        if (!$timestart) {
            return [];
        }
        // A past date is labelled in the past tense: "Broadcast started
        // on September 11" reads fine on a recording, "Broadcast will start" does not.
        $label = $timestart > time() ? 'timestartlabel' : 'timestartedlabel';
        return [[
            'dataid' => 'timestart',
            'label' => get_string($label, 'mod_agora'),
            'timestamp' => $timestart,
        ]];
    }
}
