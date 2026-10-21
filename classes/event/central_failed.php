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

namespace mod_agora\event;

/**
 * Agora refused. The full response body lives here and nowhere else: the course
 * editor page shows only the code, `error.code` and `error.message`.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class central_failed extends \core\event\base {
    /**
     * The event description.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventcentralfailed', 'mod_agora');
    }

    /**
     * The log entry description: the code, the cause and the full central response body.
     *
     * @return string
     */
    public function get_description() {
        // Entries made before the `operation` field stay readable.
        $operation = (string) ($this->other['operation'] ?? '');
        // The event description is in English on any site language, as in the
        // core: the operation string is taken from the English pack, not the
        // current one.
        $what = $operation !== '' && get_string_manager()->string_exists($operation, 'mod_agora')
            ? get_string_manager()->get_string($operation, 'mod_agora', null, 'en') . " ({$operation})"
            : 'unknown operation';
        $account = (string) ($this->other['account'] ?? '');
        $as = $account !== '' && get_string_manager()->string_exists('account' . $account, 'mod_agora')
            ? ' as ' . get_string_manager()->get_string('account' . $account, 'mod_agora', null, 'en')
            : '';
        if (($this->other['reason'] ?? '') === 'busy') {
            // Not an Agora refusal: Moodle did not wait out its own lock — a
            // neighbouring request was issuing the same service ticket longer
            // than the wait.
            return "Moodle did not get to '{$what}'{$as}: another request was already doing it and did not finish "
                . "within the wait (not a refusal by Agora)";
        }
        return "Agora refused '{$what}'{$as}: HTTP {$this->other['status']}, reason '{$this->other['reason']}', " .
            "error.code '{$this->other['errorcode']}', response body: {$this->other['body']}";
    }

    /**
     * Checks the composition of the event.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['operation', 'status', 'reason', 'errorcode', 'body'] as $field) {
            if (!array_key_exists($field, $this->other ?? [])) {
                throw new \coding_exception('Event central_failed requires the other[' . $field . '] field.');
            }
        }
    }
}
