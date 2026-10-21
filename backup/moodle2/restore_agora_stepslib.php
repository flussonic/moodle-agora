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
 * The restore steps of the Agora broadcast activity.
 *
 * @package    mod_agora
 * @subpackage backup-moodle2
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restoring one Agora broadcast activity.
 *
 * @package    mod_agora
 * @subpackage backup-moodle2
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_agora_activity_structure_step extends restore_activity_structure_step {
    /**
     * What to parse in the backup.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [new restore_path_element('agora', '/activity/agora')];
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Creates the activity from the backup.
     *
     * An activity on a broadcast created by the plugin is restored with a new
     * broadcast: the copy does not share the broadcast with the source
     * activity (D14). The publication section, the key, the port and the
     * recording policy are created by the same code as the creation from the
     * form; the template is the "Default template" site setting. A refusal
     * (no port range, the name is taken and the like) drops the restore with
     * the cause — no half-activity is left behind, restore rolls back the
     * step transaction.
     *
     * @param array $data activity fields
     * @return void
     */
    protected function process_agora($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        unset($data->id);

        if (!empty($data->created_by_plugin)) {
            // The copy carries the name of the source broadcast, but it
            // stays with the source activity: the new broadcast gets a name
            // by the creation rules.
            $template = (string) get_config('mod_agora', 'defaulttemplate');
            $publish = null;
            try {
                $data->stream_name = \mod_agora\local\stream_creator::create((string) $data->name, $template, $publish);
            } catch (\mod_agora\local\agora_exception $error) {
                if ($error->reason !== 'conflict') {
                    throw $error;
                }
                // The name from the same title is already taken (the source
                // broadcast is alive): try with a copy mark — the name is
                // for people, the slug is honest.
                $publish = null;
                $data->stream_name = \mod_agora\local\stream_creator::create(
                    (string) $data->name . ' ' . get_string('copyasnoun'),
                    $template,
                    $publish
                );
            }
            $data->srt_port = $publish['port'];
            $data->srt_key = \core\encryption::encrypt($publish['key']);
            $data->has_phases = 1;
            // The ondemand of the new broadcast is decided by its template,
            // not by the copy of the source.
            $data->ondemand = \mod_agora\local\stream_config::ondemand((string) $data->stream_name);
        }

        $newitemid = $DB->insert_record('agora', $data);
        \mod_agora\local\tombstones::lift((string) $data->stream_name);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * The activity description files.
     *
     * @return void
     */
    protected function after_execute() {
        $this->add_related_files('mod_agora', 'intro', null);
    }
}
