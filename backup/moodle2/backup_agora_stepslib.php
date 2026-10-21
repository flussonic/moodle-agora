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

/**
 * The backup steps of the Agora broadcast activity.
 *
 * @package    mod_agora
 * @subpackage backup-moodle2
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The contents of the activity backup: the broadcast binding and the display
 * settings.
 *
 * The ticket table is not part of the backup: the ticket JWT is a secret,
 * and the ticket itself is short-lived and meaningless in another Agora
 * installation. The accumulated viewing duration — neither: it is
 * observation of the people of a specific course.
 *
 * @package    mod_agora
 * @subpackage backup-moodle2
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_agora_activity_structure_step extends backup_activity_structure_step {
    /**
     * The contents of the backup.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $agora = new backup_nested_element('agora', ['id'], [
            'name', 'intro', 'introformat', 'stream_name', 'created_by_plugin',
            'display_mode', 'completion_minutes', 'timestart', 'ondemand', 'timemodified',
        ]);

        $agora->set_source_table('agora', ['id' => backup::VAR_ACTIVITYID]);

        $agora->annotate_files('mod_agora', 'intro', null);

        return $this->prepare_activity_structure($agora);
    }
}
