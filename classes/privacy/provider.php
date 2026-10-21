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

namespace mod_agora\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_agora\local\grant_manager;

/**
 * The learner data the plugin stores and passes outside.
 *
 * The plugin stores viewing tickets and the accumulated viewing duration of a
 * pair, and passes the person identifier `<site label>:<id>:<login>` to Agora
 * — without the provider the stock Moodle export and deletion would not see
 * this.
 *
 * Comments under a broadcast live in the core comments subsystem, and the
 * provider calls its six points itself: a context is given by even a single
 * comment, without a single ticket.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes what the plugin writes and where.
     *
     * @param collection $collection metadata collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('agora_grants', [
            'userid' => 'privacy:metadata:agora_grants:userid',
            'user_id' => 'privacy:metadata:agora_grants:user_id',
            'purpose' => 'privacy:metadata:agora_grants:purpose',
            'jti' => 'privacy:metadata:agora_grants:jti',
            'token' => 'privacy:metadata:agora_grants:token',
            'bind_ip' => 'privacy:metadata:agora_grants:bind_ip',
            'scope' => 'privacy:metadata:agora_grants:scope',
            'expires_at' => 'privacy:metadata:agora_grants:expires_at',
            'status' => 'privacy:metadata:agora_grants:status',
            'timecreated' => 'privacy:metadata:agora_grants:timecreated',
        ], 'privacy:metadata:agora_grants');

        $collection->add_database_table('agora_watch', [
            'userid' => 'privacy:metadata:agora_watch:userid',
            'watched_secs' => 'privacy:metadata:agora_watch:watched_secs',
            'polled_until' => 'privacy:metadata:agora_watch:polled_until',
            'user_ref' => 'privacy:metadata:agora_watch:user_ref',
            'lastexpiry' => 'privacy:metadata:agora_watch:lastexpiry',
            'timemodified' => 'privacy:metadata:agora_watch:timemodified',
        ], 'privacy:metadata:agora_watch');

        $collection->add_external_location_link('agora', [
            'user_id' => 'privacy:metadata:agora:user_id',
        ], 'privacy:metadata:agora');

        $collection->add_subsystem_link('core_comment', [], 'privacy:metadata:core_comment');

        return $collection;
    }

    /**
     * Contexts in which the user has data.
     *
     * @param int $userid user
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        // The pair data lives in two tables: the tickets and the accumulated
        // viewing duration. Either of them yields the context.
        foreach (['agora_grants', 'agora_watch'] as $table) {
            $sql = "SELECT ctx.id
                      FROM {" . $table . "} t
                      JOIN {agora} a ON a.id = t.agoraid
                      JOIN {course_modules} cm ON cm.instance = a.id
                      JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                      JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel
                     WHERE t.userid = :userid";
            $contextlist->add_from_sql($sql, [
                'modname' => 'agora',
                'modlevel' => CONTEXT_MODULE,
                'userid' => $userid,
            ]);
        }

        // A commenter may have never opened the player: they have no ticket,
        // yet they have data in the context.
        $sql = "SELECT c.contextid
                  FROM {comments} c
                  JOIN {context} ctx ON ctx.id = c.contextid AND ctx.contextlevel = :modlevel
                 WHERE c.component = :component AND c.commentarea = :commentarea AND c.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'modlevel' => CONTEXT_MODULE,
            'component' => 'mod_agora',
            'commentarea' => 'stream',
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Users who have data in the context.
     *
     * @param userlist $userlist the context user list
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        foreach (['agora_grants', 'agora_watch'] as $table) {
            $sql = "SELECT t.userid
                      FROM {" . $table . "} t
                      JOIN {agora} a ON a.id = t.agoraid
                      JOIN {course_modules} cm ON cm.instance = a.id
                      JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                     WHERE cm.id = :cmid AND t.userid > 0";
            $userlist->add_from_sql('userid', $sql, ['modname' => 'agora', 'cmid' => $context->instanceid]);
        }

        \core_comment\privacy\provider::get_users_in_context_from_sql(
            $userlist,
            'com',
            'mod_agora',
            'stream',
            $context->id
        );
    }

    /**
     * Exports the user's tickets.
     *
     * @param approved_contextlist $contextlist approved contexts
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('agora', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            // Comments are always exported: a person may have only them.
            \core_comment\privacy\provider::export_comments(
                $context,
                'mod_agora',
                'stream',
                (int) $cm->instance,
                [],
                true
            );

            $rows = $DB->get_records('agora_grants', ['agoraid' => $cm->instance, 'userid' => $userid], 'timecreated ASC');
            $watch = $DB->get_record('agora_watch', ['agoraid' => $cm->instance, 'userid' => $userid]);
            if (!$rows && !$watch) {
                continue;
            }
            $grants = [];
            foreach ($rows as $row) {
                // The ticket JWT is a secret: it does not go into the export.
                $grants[] = (object) [
                    'jti' => $row->jti,
                    'user_id' => $row->user_id,
                    // The learner address is their own data: it goes into the
                    // person's export, not into the teacher's reports.
                    'bind_ip' => (string) ($row->bind_ip ?? ''),
                    'status' => $row->status,
                    'expires_at' => transform::datetime($row->expires_at),
                    'timecreated' => transform::datetime($row->timecreated),
                ];
            }
            $data = (object) ['grants' => $grants];
            if ($watch) {
                $data->watched = (object) [
                    'watched_secs' => (int) $watch->watched_secs,
                    'polled_until' => transform::datetime($watch->polled_until),
                    'user_ref' => (string) ($watch->user_ref ?? ''),
                    'lastexpiry' => transform::datetime($watch->lastexpiry),
                    'timemodified' => transform::datetime($watch->timemodified),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('privacy:path:grants', 'mod_agora')],
                $data
            );
        }
    }

    /**
     * Deletes the data of all users of the context, revoking their tickets.
     *
     * @param \context $context context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }
        \core_comment\privacy\provider::delete_comments_for_all_users($context, 'mod_agora', 'stream');

        $cm = get_coursemodule_from_id('agora', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        grant_manager::revoke_where(['agoraid' => $cm->instance]);
        $DB->delete_records('agora_grants', ['agoraid' => $cm->instance]);
        $DB->delete_records('agora_watch', ['agoraid' => $cm->instance]);
    }

    /**
     * Deletes the data of one user, revoking their tickets.
     *
     * @param approved_contextlist $contextlist approved contexts
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        \core_comment\privacy\provider::delete_comments_for_user($contextlist, 'mod_agora', 'stream');

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('agora', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            grant_manager::revoke_where(['agoraid' => $cm->instance, 'userid' => $userid]);
            $DB->delete_records('agora_grants', ['agoraid' => $cm->instance, 'userid' => $userid]);
            $DB->delete_records('agora_watch', ['agoraid' => $cm->instance, 'userid' => $userid]);
        }
    }

    /**
     * Deletes the data of the listed users of the context.
     *
     * @param approved_userlist $userlist approved users
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        \core_comment\privacy\provider::delete_comments_for_users($userlist, 'mod_agora', 'stream');

        $cm = get_coursemodule_from_id('agora', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            grant_manager::revoke_where(['agoraid' => $cm->instance, 'userid' => $userid]);
            $DB->delete_records('agora_grants', ['agoraid' => $cm->instance, 'userid' => $userid]);
            $DB->delete_records('agora_watch', ['agoraid' => $cm->instance, 'userid' => $userid]);
        }
    }
}
