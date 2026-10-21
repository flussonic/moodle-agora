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

use mod_agora\local\port_registry;

/**
 * Publication port range: two fields, stored as "from:to".
 *
 * A port is a cluster-wide resource: creating an activity takes the first free
 * port of this range. If the range is not set (empty), creation honestly refuses
 * and names this setting; therefore there is no default value at all.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_portrange extends \admin_setting {
    /** Lower bound of valid ports. */
    const MIN = 1;

    /** Upper bound of valid ports. */
    const MAX = 65535;

    /**
     * Creates the port range setting.
     *
     * @param string $name setting name
     * @param string $visiblename title
     * @param string $description description
     */
    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, null);
    }

    /**
     * Current value: "from:to" or null when not set.
     *
     * @return string|null
     */
    public function get_setting() {
        $value = $this->config_read($this->name);
        if ($value === false || $value === '') {
            return null;
        }
        return (string) $value;
    }

    /**
     * Default value — not set: without a range creation honestly refuses.
     *
     * @return null
     */
    public function get_defaultsetting() {
        return null;
    }

    /**
     * Splits "from:to" into bounds; null when not set or the form is wrong.
     *
     * Delegates to `port_registry::parse_range()`: one range parser per
     * plugin, the setting keeps no copy of it (review R2, S-6).
     *
     * @param string|null $value stored value
     * @return array|null ['from' => int, 'to' => int]
     */
    public static function parse(?string $value): ?array {
        return port_registry::parse_range($value);
    }

    /**
     * Checks the port pair: both within bounds, start strictly less than end.
     *
     * @param mixed $data ['from' => …, 'to' => …] from the form
     * @return true|string true if everything is fine, otherwise the error text
     */
    public function validate($data) {
        if (!is_array($data)) {
            return get_string('errsrtportrangeform', 'mod_agora');
        }
        $from = trim((string) ($data['from'] ?? ''));
        $to = trim((string) ($data['to'] ?? ''));
        if ($from === '' && $to === '') {
            // An empty pair means "not configured yet": otherwise applying the
            // defaults would fail on a fresh installation.
            return true;
        }
        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            if (!preg_match('/^\d+$/', $value) || (int) $value < self::MIN || (int) $value > self::MAX) {
                return get_string('errsrtportrangeform', 'mod_agora');
            }
        }
        if ((int) $from >= (int) $to) {
            return get_string('errsrtportrangeorder', 'mod_agora');
        }
        return true;
    }

    /**
     * Saves the pair as "from:to"; an empty pair erases the setting.
     *
     * @param mixed $data ['from' => …, 'to' => …] from the form
     * @return string empty string on success, otherwise the error text
     */
    public function write_setting($data) {
        $validated = $this->validate($data);
        if ($validated !== true) {
            return $validated;
        }
        $from = trim((string) ($data['from'] ?? ''));
        $to = trim((string) ($data['to'] ?? ''));
        if ($from === '' && $to === '') {
            $this->config_write($this->name, '');
            return '';
        }
        $this->config_write($this->name, (int) $from . ':' . (int) $to);
        return '';
    }

    /**
     * Two numeric fields on one settings row.
     *
     * @param mixed $data current value
     * @param string $query settings search string
     * @return string HTML
     */
    public function output_html($data, $query = '') {
        $defaults = $this->get_defaultsetting();
        $current = $this->parse(is_string($data) ? $data : null) ?? ['from' => '', 'to' => ''];
        if ($defaults !== null && $this->get_setting() === null) {
            $current = $this->parse($defaults) ?? $current;
        }

        $input = static function (string $field, $value): string {
            return \html_writer::empty_tag('input', [
                'type' => 'number',
                'min' => self::MIN,
                'max' => self::MAX,
                'size' => 6,
                'id' => 'id_' . strtr($field, '[]:', '---'),
                'name' => $field,
                'value' => $value,
                'class' => 'form-control d-inline-block w-auto',
            ]);
        };

        $element = $input($this->get_full_name() . '[from]', $current['from'])
            . ' — '
            . $input($this->get_full_name() . '[to]', $current['to']);
        return format_admin_setting($this, $this->visiblename, $element, $this->description, false, '', null, $query);
    }
}
