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
 * The activity phase: a pure function of the activity fields and the legacy discriminator.
 *
 * Three phases of the broadcast lifecycle: "planned" (the broadcast is
 * configured, learners not admitted), "running" (learners admitted), "ended"
 * (available as a recording). The phases are stored in the activity fields
 * (`started_at`, `ended_at`) and are not computed from the stream state: the
 * state describes the signal, the phase — the teacher's decision. Activities
 * created before phases appeared (`has_phases = 0`) are MVP legacy: they have
 * no phase, and the page lives by the former rules.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class broadcast_phase {
    /** The broadcast is configured, learners not admitted. */
    const PLANNED = 'planned';

    /** Learners admitted, the recording is being written. */
    const ONGOING = 'ongoing';

    /** The broadcast is over, available as a recording. */
    const ENDED = 'ended';

    /**
     * The activity phase; null — legacy without phases.
     *
     * @param \stdClass $agora the activity record
     * @return string|null one of `PLANNED`, `ONGOING`, `ENDED` or null
     */
    public static function of(\stdClass $agora): ?string {
        if (empty($agora->has_phases)) {
            return null;
        }
        if (!empty($agora->ended_at)) {
            return self::ENDED;
        }
        if (!empty($agora->started_at)) {
            return self::ONGOING;
        }
        return self::PLANNED;
    }

    /**
     * The "could not end: <action>, <cause>" line for an activity with an
     * unconfirmed completion; null — there is no completion.
     *
     * @param \stdClass $agora the activity record
     * @return string|null
     */
    public static function pending_failure(\stdClass $agora): ?string {
        if (empty($agora->end_pending)) {
            return null;
        }
        return (string) ($agora->end_failure ?? '');
    }
}
