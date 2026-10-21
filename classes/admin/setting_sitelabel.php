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
 * The site label: mandatory and following the form of an Agora tag.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_sitelabel extends \admin_setting_configtext {
    /** The form of an Agora tag. */
    const FORM = '/^[a-zA-Z0-9_.\-]{1,40}$/';

    /**
     * Checks the form of the label.
     *
     * @param string $data the entered value
     * @return true|string true if everything is fine, otherwise the error text
     */
    public function validate($data) {
        $data = trim((string) $data);
        if ($data === '') {
            // An empty value means "not configured yet"; otherwise installing the plugin
            // would fail when applying the defaults. The gate keeps the label mandatory:
            // the operation that needs Agora refuses and names this field.
            return true;
        }
        if (!preg_match(self::FORM, $data)) {
            return get_string('errsitelabelform', 'mod_agora');
        }
        return true;
    }
}
