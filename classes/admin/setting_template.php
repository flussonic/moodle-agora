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

use mod_agora\local\agora_exception;
use mod_agora\local\stream_creator;

/**
 * Default broadcast template: the name is checked against the template list of central.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_template extends \admin_setting_configtext {
    /**
     * Checks that Agora has a template with this name.
     *
     * @param string $data the entered value
     * @return true|string true if everything is fine, otherwise the error text
     */
    public function validate($data) {
        $data = trim((string) $data);
        if ($data === '') {
            return true;
        }
        try {
            // The template list — all its pages, walked by the same cursor loop as
            // the activity form: otherwise a template from the second page would be
            // rejected with "no such template".
            $templates = stream_creator::templates();
        } catch (agora_exception $e) {
            // Nothing to check against: name the central refusal instead of silently accepting the name.
            return $e->getMessage();
        }
        if (isset($templates[$data])) {
            return true;
        }
        return get_string('errtemplatemissing', 'mod_agora', $data);
    }
}
