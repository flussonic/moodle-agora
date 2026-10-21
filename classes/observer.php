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

namespace mod_agora;

use mod_agora\local\grant_manager;

/**
 * Revoking tickets on core events.
 *
 * A ticket is bearer and lives until expiry, so a lost access right has to be
 * chased down with a revocation. The events differ: unenrolment always revokes,
 * an enrolment edit — only when the enrolment became inactive, and losing a
 * group or a role — only when the activity became unavailable.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A learner was unenrolled: tickets for the broadcasts of this course are revoked.
     *
     * @param \core\event\user_enrolment_deleted $event event
     * @return void
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event): void {
        grant_manager::revoke_for_course((int) $event->courseid, (int) $event->relateduserid);
    }

    /**
     * An enrolment was edited: revoke only when it became inactive.
     *
     * The event carries no status in `other`, and editing the enrolment dates
     * is not a revocation reason, so the activity is re-read from the core.
     *
     * @param \core\event\user_enrolment_updated $event event
     * @return void
     */
    public static function user_enrolment_updated(\core\event\user_enrolment_updated $event): void {
        $courseid = (int) $event->courseid;
        $userid = (int) $event->relateduserid;

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return;
        }
        if (is_enrolled($context, $userid, '', true)) {
            return;
        }
        grant_manager::revoke_for_course($courseid, $userid);
    }

    /**
     * A group or a role was removed: revoke the tickets of the activities that became unavailable.
     *
     * @param \core\event\base $event event
     * @return void
     */
    public static function access_may_have_changed(\core\event\base $event): void {
        $courseid = (int) $event->courseid;
        $userid = (int) $event->relateduserid;
        if (!$courseid || !$userid) {
            return;
        }
        grant_manager::revoke_lost_access($courseid, $userid);
    }

    /**
     * A user was deleted: all their live tickets are revoked.
     *
     * @param \core\event\user_deleted $event event
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;

        grant_manager::revoke_where(['userid' => (int) $event->objectid, 'purpose' => grant_manager::PURPOSE_VIEW]);
        // The pair rows of the deleted user: the accumulated seconds and their
        // `user_id` in Agora (with the login). A deleted user needs no
        // completion, and the login must not be kept after deletion.
        $DB->delete_records('agora_watch', ['userid' => (int) $event->objectid]);
    }
}
