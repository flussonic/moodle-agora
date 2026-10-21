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
 * Tombstones: plugin streams to be deleted after a grace period.
 *
 * Moodle counts deletion as final when the recycle bin is emptied, and the
 * emptying event does not carry the stream name. So the deferred deletion is
 * written by `delete_instance` itself, and the background task deletes the
 * stream once the period is up. Streams created by the operator never get a
 * deferred deletion.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tombstones {
    /**
     * The deferred deletion period in seconds or null when no deferred deletion should be written.
     *
     * The course recycle bin is on — the period is taken from it;
     * `coursebinexpiry = 0` means "never empty", and then the stream lives as
     * long as the activity lies in the bin. The recycle bin is off — there will
     * be no restore, and the period is taken from the plugin settings.
     *
     * @return int|null
     */
    public static function delay(): ?int {
        $binenabled = (int) get_config('tool_recyclebin', 'coursebinenable');
        if ($binenabled) {
            $expiry = (int) get_config('tool_recyclebin', 'coursebinexpiry');
            return $expiry > 0 ? $expiry : null;
        }
        $days = (int) get_config('mod_agora', 'tombstonedays');
        return ($days > 0 ? $days : 7) * DAYSECS;
    }

    /**
     * Writes a deferred deletion for the stream.
     *
     * @param string $streamname stream name
     * @param int $exceptagoraid do not count this activity as a binding — it is being deleted
     * @return bool whether the deferred deletion was written
     */
    public static function put(string $streamname, int $exceptagoraid = 0): bool {
        global $DB;

        if ($streamname === '') {
            return false;
        }
        // The same stream is bound by another activity — it must not be deleted.
        if ($DB->record_exists_select('agora', 'stream_name = ? AND id <> ?', [$streamname, $exceptagoraid])) {
            return false;
        }

        $delay = self::delay();
        if ($delay === null) {
            return false;
        }

        $now = time();
        $existing = $DB->get_record('agora_tombstones', ['streamname' => $streamname]);
        if ($existing) {
            $DB->set_field('agora_tombstones', 'deleteafter', $now + $delay, ['id' => $existing->id]);
            return true;
        }
        $DB->insert_record('agora_tombstones', (object) [
            'streamname' => $streamname,
            'deleteafter' => $now + $delay,
            'timecreated' => $now,
        ]);
        return true;
    }

    /**
     * Lifts the deferred deletion: the stream is bound again or the activity was restored.
     *
     * @param string $streamname stream name
     * @return void
     */
    public static function lift(string $streamname): void {
        global $DB;

        if ($streamname !== '') {
            $DB->delete_records('agora_tombstones', ['streamname' => $streamname]);
        }
    }

    /**
     * Tombstones whose period is up.
     *
     * @param int|null $now the reference moment
     * @return array rows of `agora_tombstones`
     */
    public static function due(?int $now = null): array {
        global $DB;

        return $DB->get_records_select('agora_tombstones', 'deleteafter <= ?', [$now ?? time()]);
    }
}
