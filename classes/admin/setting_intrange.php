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
 * An integer within a named range: the error names both bounds.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_intrange extends \admin_setting_configtext {
    /** @var int lower bound */
    private int $min;

    /** @var int upper bound */
    private int $max;

    /** @var string key of the string with the error text */
    private string $errorkey;

    /**
     * Creates a setting with a range of allowed values.
     *
     * @param string $name setting name
     * @param string $visiblename title
     * @param string $description description
     * @param int $defaultsetting default value
     * @param int $min lower bound
     * @param int $max upper bound
     * @param string $errorkey key of the string with the error text naming the range
     */
    public function __construct(
        string $name,
        string $visiblename,
        string $description,
        int $defaultsetting,
        int $min,
        int $max,
        string $errorkey
    ) {
        $this->min = $min;
        $this->max = $max;
        $this->errorkey = $errorkey;
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_RAW, 8);
    }

    /**
     * Checks that the value is an integer within the range.
     *
     * @param string $data the entered value
     * @return true|string true if everything is fine, otherwise the error text
     */
    public function validate($data) {
        $data = trim((string) $data);
        if (!preg_match('/^-?\d+$/', $data)) {
            return get_string($this->errorkey, 'mod_agora');
        }
        $value = (int) $data;
        if ($value < $this->min || $value > $this->max) {
            return get_string($this->errorkey, 'mod_agora');
        }
        return true;
    }
}
