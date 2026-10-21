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
 * The address of the Agora installation: scheme, host, port — and nothing else.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_centralurl extends \admin_setting_configtext {
    /**
     * Checks the form of the address.
     *
     * @param string $data the entered value
     * @return true|string true if everything is fine, otherwise the error text
     */
    public function validate($data) {
        $data = trim((string) $data);
        if ($data === '') {
            // The address is not set yet: the plugin itself reports it in the operation that needs Agora.
            return true;
        }
        $parts = parse_url($data);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return get_string('errcentralurlform', 'mod_agora');
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return get_string('errcentralurlform', 'mod_agora');
        }
        if (!empty($parts['path']) && $parts['path'] !== '/') {
            return get_string('errcentralurlform', 'mod_agora');
        }
        if (!empty($parts['query']) || !empty($parts['fragment']) || !empty($parts['user'])) {
            return get_string('errcentralurlform', 'mod_agora');
        }
        return true;
    }
}
