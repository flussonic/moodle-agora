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
 * Schema upgrade of the Agora broadcast plugin.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Schema upgrade steps.
 *
 * @param int $oldversion the version we upgrade from
 * @return bool
 */
function xmldb_agora_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026092402) {
        // The broadcast ondemand flag: three-valued, NULL — "the template
        // decides".
        $table = new xmldb_table('agora');
        $field = new xmldb_field('ondemand', XMLDB_TYPE_INTEGER, '1', null, null, null, null, 'timestart');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The broadcast of a ticket: the preview service ticket is keyed by
        // it, not by the activity. The column has no default value: XMLDB
        // forbids DEFAULT "" on CHAR NOT NULL and strips it, while the SQL
        // generator substitutes an empty string itself — on a non-empty table
        // the column is added without losing rows.
        $grants = new xmldb_table('agora_grants');
        $stream = new xmldb_field('stream_name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'purpose');
        if (!$dbman->field_exists($grants, $stream)) {
            $dbman->add_field($grants, $stream);
        }
        $index = new xmldb_index('purposestream', XMLDB_INDEX_NOTUNIQUE, ['purpose', 'stream_name']);
        if (!$dbman->index_exists($grants, $index)) {
            $dbman->add_index($grants, $index);
        }

        // The accumulated viewing duration of a pair.
        $watch = new xmldb_table('agora_watch');
        $watch->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $watch->add_field('agoraid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $watch->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $watch->add_field('watched_secs', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $watch->add_field('polled_until', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $watch->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $watch->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $watch->add_key('agoraid', XMLDB_KEY_FOREIGN, ['agoraid'], 'agora', ['id']);
        $watch->add_index('pair', XMLDB_INDEX_UNIQUE, ['agoraid', 'userid']);
        if (!$dbman->table_exists($watch)) {
            $dbman->create_table($watch);
        }

        upgrade_mod_savepoint(true, 2026092402, 'agora');
    }

    if ($oldversion < 2026092403) {
        // The "the plugin itself saw the broadcast live" column: for a stopped
        // broadcast streams/stats returns no counters, and without it the
        // ended_no_record state is unreachable.
        $table = new xmldb_table('agora');
        $field = new xmldb_field('last_live_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'ondemand');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026092403, 'agora');
    }

    if ($oldversion < 2026092503) {
        // The address the ticket is bound to: without it a live ticket cannot
        // be checked against the learner address, and a change of address
        // would simply break viewing. Empty — the ticket was issued without a
        // binding.
        $grants = new xmldb_table('agora_grants');
        $field = new xmldb_field('bind_ip', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'user_id');
        if (!$dbman->field_exists($grants, $field)) {
            $dbman->add_field($grants, $field);
        }

        upgrade_mod_savepoint(true, 2026092503, 'agora');
    }

    if ($oldversion < 2026092506) {
        // The age of a pair and its `user_id` in Agora live in the pair
        // table, not in the tickets: the task deletes expired tickets after a
        // day, while the pair has to be polled and marked for a week after
        // its last ticket.
        $watch = new xmldb_table('agora_watch');
        $userref = new xmldb_field('user_ref', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'polled_until');
        if (!$dbman->field_exists($watch, $userref)) {
            $dbman->add_field($watch, $userref);
        }
        $lastexpiry = new xmldb_field('lastexpiry', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'user_ref');
        if (!$dbman->field_exists($watch, $lastexpiry)) {
            $dbman->add_field($watch, $lastexpiry);
        }

        // Migration from tickets that are still alive: every pair gets a row
        // with the `user_id` and the expiry of its last ticket.
        $pairs = $DB->get_recordset_sql(
            "SELECT agoraid, userid, MAX(expires_at) AS lastexpiry, MAX(user_id) AS user_ref
               FROM {agora_grants}
              WHERE purpose = 'view' AND userid > 0
           GROUP BY agoraid, userid"
        );
        foreach ($pairs as $pair) {
            $row = $DB->get_record('agora_watch', ['agoraid' => $pair->agoraid, 'userid' => $pair->userid]);
            if ($row) {
                $row->lastexpiry = max((int) $row->lastexpiry, (int) $pair->lastexpiry);
                $row->user_ref = $row->user_ref ?: $pair->user_ref;
                $DB->update_record('agora_watch', $row);
            } else {
                $DB->insert_record('agora_watch', (object) [
                    'agoraid' => (int) $pair->agoraid,
                    'userid' => (int) $pair->userid,
                    'watched_secs' => 0,
                    'polled_until' => 0,
                    'user_ref' => $pair->user_ref,
                    'lastexpiry' => (int) $pair->lastexpiry,
                    'timemodified' => time(),
                ]);
            }
        }
        $pairs->close();

        upgrade_mod_savepoint(true, 2026092506, 'agora');
    }

    if ($oldversion < 2026092507) {
        // Pair rows that had no live tickets left by step 2026092506 stayed
        // with `lastexpiry = 0` and without `user_ref` — the task no longer
        // saw them. Age: the last thing known about the pair — the row edit
        // time or the poll boundary. The pair `user_id` — by the same formula
        // as `grant_manager::user_ref()`: `<site label>:<userid>:<username>`.
        // If there is no label or the user is deleted — do not guess, leave
        // it empty.
        $label = trim((string) get_config('mod_agora', 'sitelabel'));
        $rows = $DB->get_recordset_sql(
            "SELECT w.id, w.userid, w.timemodified, w.polled_until, w.user_ref, u.username, u.deleted
               FROM {agora_watch} w
          LEFT JOIN {user} u ON u.id = w.userid
              WHERE w.lastexpiry = 0"
        );
        $aged = 0;
        $named = 0;
        foreach ($rows as $row) {
            $update = (object) [
                'id' => $row->id,
                'lastexpiry' => max((int) $row->timemodified, (int) $row->polled_until),
            ];
            $aged++;
            if ((string) $row->user_ref === '' && $label !== '' && $row->username !== null && empty($row->deleted)) {
                $update->user_ref = $label . ':' . (int) $row->userid . ':' . $row->username;
                $named++;
            }
            $DB->update_record('agora_watch', $update);
        }
        $rows->close();
        if ($aged > 0) {
            // For the administrator upgrading the site — how many rows got an
            // age and how many did not get a `user_id` back (the task will
            // not ask the history for those).
            mtrace('mod_agora: pair rows without tickets given an age: ' . $aged
                . ', of them given user_id from the current username: ' . $named
                . ', left without user_id: ' . ($aged - $named));
        }

        upgrade_mod_savepoint(true, 2026092507, 'agora');
    }

    if ($oldversion < 2026092508) {
        // Pair rows of deleted users: the `user_deleted` observer deletes
        // them now, while the ones deleted before it stayed — with the
        // username in `user_ref`. Delete them the same way the observer does.
        $gone = $DB->get_fieldset_sql(
            'SELECT w.id FROM {agora_watch} w JOIN {user} u ON u.id = w.userid WHERE u.deleted = 1'
        );
        if ($gone) {
            $DB->delete_records_list('agora_watch', 'id', $gone);
            mtrace('mod_agora: pair rows of deleted users removed: ' . count($gone));
        }

        upgrade_mod_savepoint(true, 2026092508, 'agora');
    }

    if ($oldversion < 2026101300) {
        // Activity phases: "planned" / "running" / "ended" — activity
        // fields, not a computation from the broadcast state. The legacy
        // discriminator: existing rows get has_phases = 0 and live by the MVP
        // rules without the broadcast control blocks; NULL phase fields
        // without the discriminator would turn every live legacy broadcast
        // into a "planned" one for learners.
        $table = new xmldb_table('agora');
        foreach (
            [
            'started_at' => new xmldb_field('started_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'last_live_at'),
            'ended_at' => new xmldb_field('ended_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'started_at'),
            // Who ended the broadcast. The column has no default value: XMLDB
            // forbids DEFAULT "" on CHAR NOT NULL.
            'ended_by' => new xmldb_field('ended_by', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'ended_at'),
            'recording_id' => new xmldb_field('recording_id', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'ended_by'),
            'has_phases' => new xmldb_field('has_phases', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'recording_id'),
            // Completion of the ending: until the actions are confirmed the
            // activity is not terminal, and a refusal names the action and the
            // reason.
            'end_pending' => new xmldb_field('end_pending', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'has_phases'),
            'end_failure' => new xmldb_field('end_failure', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'end_pending'),
            ] as $field
        ) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // The scope of a live recording ticket: the broadcast name and the
        // vod assets of the exports.
        $grants = new xmldb_table('agora_grants');
        $scope = new xmldb_field('scope', XMLDB_TYPE_TEXT, null, null, null, null, null, 'token');
        if (!$dbman->field_exists($grants, $scope)) {
            $dbman->add_field($grants, $scope);
        }

        upgrade_mod_savepoint(true, 2026101300, 'agora');
    }

    if ($oldversion < 2026101400) {
        // The publication section of a broadcast created by the plugin: the
        // port is an occupied cluster resource, the key (passphrase) is a
        // secret, stored encrypted in the DB. 0/NULL — no section: removed at
        // the end of the broadcast or never created.
        $table = new xmldb_table('agora');
        $port = new xmldb_field('srt_port', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'end_failure');
        if (!$dbman->field_exists($table, $port)) {
            $dbman->add_field($table, $port);
        }
        $key = new xmldb_field('srt_key', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'srt_port');
        if (!$dbman->field_exists($table, $key)) {
            $dbman->add_field($table, $key);
        }

        upgrade_mod_savepoint(true, 2026101400, 'agora');
    }

    if ($oldversion < 2026101501) {
        // The moment an unconfirmed end completion started: the "completion
        // stuck" escalation threshold (24 hours) is counted from it. 0 — no
        // completion.
        $table = new xmldb_table('agora');
        $field = new xmldb_field('end_pending_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'end_pending');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026101501, 'agora');
    }

    if ($oldversion < 2026101502) {
        // The activity creation time: the freshness threshold below which a
        // 404 stats of a new activity reads as "not started yet" rather than
        // "unknown". The creation time of existing rows is unknown — take the
        // nearest: the last edit time (older than the freshness threshold,
        // their behaviour does not change).
        $table = new xmldb_table('agora');
        $field = new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $DB->execute("UPDATE {agora} SET timecreated = timemodified WHERE timecreated = 0");

        upgrade_mod_savepoint(true, 2026101502, 'agora');
    }

    return true;
}
