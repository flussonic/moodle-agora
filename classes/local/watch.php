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

namespace mod_agora\local;

/**
 * The accumulated viewing duration of an "activity + learner" pair.
 *
 * It lives in its own table `agora_watch`, not in the columns of
 * `agora_grants`, for one reason: tickets are short-lived — they expire, get
 * revoked, get cleaned by the task — while the accumulated time must outlive
 * them all. The unit of counting is the pair, because there is no
 * "ticket ↔ viewing session" binding: the viewing history does not return
 * `jti`.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class watch {
    /**
     * The pair row: the existing one or an empty draft.
     *
     * @param int $agoraid activity
     * @param int $userid learner
     * @return \stdClass
     */
    public static function row(int $agoraid, int $userid): \stdClass {
        global $DB;

        $row = $DB->get_record('agora_watch', ['agoraid' => $agoraid, 'userid' => $userid]);
        if ($row) {
            return $row;
        }
        return (object) [
            'id' => 0,
            'agoraid' => $agoraid,
            'userid' => $userid,
            'watched_secs' => 0,
            'polled_until' => 0,
            'user_ref' => null,
            'lastexpiry' => 0,
            'timemodified' => 0,
        ];
    }

    /**
     * Remembers the issued ticket on the pair: its `user_id` in Agora and the ticket expiry.
     *
     * Called on every ticket issue to a learner (`grant_manager`). The `sync`
     * task decides by this row, not by the tickets, whether to poll the pair
     * and whether to mark it: redeemed tickets are deleted after a day, while
     * the pair must be remembered for a week after its last ticket.
     *
     * @param int $agoraid activity
     * @param int $userid learner
     * @param string $userref the pair `user_id` in Agora
     * @param int $expiresat the issued ticket expiry, unix seconds
     * @return void
     */
    public static function note_grant(int $agoraid, int $userid, string $userref, int $expiresat): void {
        global $DB;

        $now = time();
        if (!$DB->record_exists('agora_watch', ['agoraid' => $agoraid, 'userid' => $userid])) {
            try {
                $DB->insert_record('agora_watch', (object) [
                    'agoraid' => $agoraid,
                    'userid' => $userid,
                    'watched_secs' => 0,
                    'polled_until' => 0,
                    'user_ref' => $userref,
                    'lastexpiry' => $expiresat,
                    'timemodified' => $now,
                ]);
                return;
            } catch (\dml_write_exception $e) {
                // A neighbouring request managed to insert the pair row: we add
                // to it with the point update below.
                unset($e);
            }
        }
        // Pointwise, not by rewriting the row: the `sync` task may be adding
        // seconds to the same row at this very moment, and the copy read
        // earlier would erase what the neighbour wrote. The expiry is the
        // greatest of the two; the pair `user_id` is built once and does not
        // change with a rename.
        $DB->execute(
            "UPDATE {agora_watch}
                SET lastexpiry = CASE WHEN lastexpiry < :expires1 THEN :expires2 ELSE lastexpiry END,
                    user_ref = CASE WHEN user_ref IS NULL OR user_ref = '' THEN :userref ELSE user_ref END,
                    timemodified = :now
              WHERE agoraid = :agoraid AND userid = :userid",
            [
                'expires1' => $expiresat,
                'expires2' => $expiresat,
                'userref' => $userref,
                'now' => $now,
                'agoraid' => $agoraid,
                'userid' => $userid,
            ]
        );
    }

    /**
     * Adds the counted seconds to the pair and moves the polling bound.
     *
     * A point update of two fields: the row is not rewritten wholesale, and the
     * ticket expiry with the `user_id`, written by a ticket issue during the
     * history polling, is not lost.
     *
     * @param int $id the pair row
     * @param int $seconds how much to add
     * @param int $polleduntil the new polling bound, unix seconds
     * @return void
     */
    public static function add_seconds(int $id, int $seconds, int $polleduntil): void {
        global $DB;

        $DB->execute(
            "UPDATE {agora_watch}
                SET watched_secs = watched_secs + :seconds, polled_until = :polleduntil, timemodified = :now
              WHERE id = :id",
            ['seconds' => $seconds, 'polleduntil' => $polleduntil, 'now' => time(), 'id' => $id]
        );
    }

    /**
     * The accumulated viewing seconds of the pair.
     *
     * @param int $agoraid activity
     * @param int $userid learner
     * @return int
     */
    public static function seconds(int $agoraid, int $userid): int {
        return (int) self::row($agoraid, $userid)->watched_secs;
    }
}
