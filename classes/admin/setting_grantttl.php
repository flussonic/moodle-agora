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

namespace mod_agora\admin;

/**
 * The ticket lifetime: from 10 minutes to 24 hours.
 *
 * The core error message names only one bound, while the LMS administrator
 * needs the whole range — hence a check of our own.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_grantttl extends \admin_setting_configduration {
    /** Lower bound, seconds. */
    const MIN = 600;

    /** Upper bound, seconds. */
    const MAX = 86400;

    /**
     * Checks that the lifetime lies within the allowed range.
     *
     * @param int $data value in seconds
     * @return string empty string if everything is fine, otherwise the error text
     */
    protected function validate_setting(int $data): string {
        if ($data < self::MIN || $data > self::MAX) {
            return get_string('errgrantttlrange', 'mod_agora');
        }
        return '';
    }
}
