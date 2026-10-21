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
 * The restore task of the Agora broadcast activity.
 *
 * @package    mod_agora
 * @subpackage backup-moodle2
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/agora/backup/moodle2/restore_agora_stepslib.php');

/**
 * Restoring the activity; at its end the broadcast tombstone is lifted.
 *
 * @package    mod_agora
 * @subpackage backup-moodle2
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_agora_activity_task extends restore_activity_task {
    /**
     * No settings of its own.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * The restore steps.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_agora_activity_structure_step('agora_structure', 'agora.xml'));
    }

    /**
     * Lifts the broadcast tombstone: the activity is back, there is no need
     * to delete the broadcast anymore.
     *
     * @return void
     */
    public function after_restore() {
        global $DB;

        $streamname = $DB->get_field('agora', 'stream_name', ['id' => $this->get_activityid()]);
        if (!empty($streamname)) {
            \mod_agora\local\tombstones::lift((string) $streamname);
        }
    }

    /**
     * The name to look up the new activity identifier by for comments.
     *
     * By default the core looks for a mapping by the area name (`stream`),
     * which is absent from the restore plan at all: the mapping is set by
     * `apply_activity_instance` under the name `agora`. Without this override
     * comments are silently not inserted — the restore passes, but the
     * activity has no feed.
     *
     * @param string $commentarea the comment area
     * @return string the mapping name
     */
    public function get_comment_mapping_itemname($commentarea) {
        return 'agora';
    }

    /**
     * The activity page addresses to decode back.
     *
     * @return array
     */
    public static function define_decode_contents() {
        return [new restore_decode_content('agora', ['intro'], 'agora')];
    }

    /**
     * The address decoding rules.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('AGORAVIEWBYID', '/mod/agora/view.php?id=$1', 'course_module'),
            new restore_decode_rule('AGORAINDEX', '/mod/agora/index.php?id=$1', 'course'),
        ];
    }

    /**
     * The log rules.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [];
    }
}
