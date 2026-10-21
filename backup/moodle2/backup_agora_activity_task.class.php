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
 * The backup task of the Agora broadcast activity.
 *
 * @package    mod_agora
 * @subpackage backup-moodle2
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/agora/backup/moodle2/backup_agora_stepslib.php');

/**
 * The activity backup: the task has no settings of its own.
 *
 * @package    mod_agora
 * @subpackage backup-moodle2
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_agora_activity_task extends backup_activity_task {
    /**
     * No settings of its own.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * The backup steps.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new backup_agora_activity_structure_step('agora_structure', 'agora.xml'));
    }

    /**
     * Hides the activity page addresses in text.
     *
     * @param string $content text with addresses
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot . '/mod/agora', '#');

        $content = preg_replace('#(' . $base . '/index\.php\?id=)([0-9]+)#', '$@AGORAINDEX*$2@$', $content);
        $content = preg_replace('#(' . $base . '/view\.php\?id=)([0-9]+)#', '$@AGORAVIEWBYID*$2@$', $content);

        return $content;
    }
}
