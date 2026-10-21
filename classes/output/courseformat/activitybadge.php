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

namespace mod_agora\output\courseformat;

use mod_agora\local\broadcast_phase;
use mod_agora\local\stream_state;

/**
 * The broadcast state badge next to the activity name in the course format.
 *
 * The state is taken from the shared 60-second cache: the course page draws all
 * activities in one request, and no badge should call central on its own.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activitybadge extends \core_courseformat\output\activitybadge {
    /** @var array The badge style by broadcast state. */
    const BADGE_STYLES = [
        stream_state::LIVE => 'danger',
        stream_state::SIGNAL_LOST => 'warning',
        stream_state::ENDED_RECORDED => 'info',
        stream_state::NOT_STARTED => 'dark',
        stream_state::ENDED_NO_RECORD => 'dark',
        stream_state::UNKNOWN => 'dark',
    ];

    /**
     * Fills the badge before it is handed to the template.
     *
     * @return void
     */
    protected function update_content(): void {
        $streamname = (string) ($this->cminfo->customdata['streamname'] ?? '');
        if ($streamname === '') {
            return;
        }

        // For activities with phases the badge reads the phase, not the stream
        // state: "planned" does not become "live" from a signal alone, "ended"
        // does not go back to "live" while the encoder keeps sending. Legacy
        // (empty) — the state, as before.
        $phase = (string) ($this->cminfo->customdata['phase'] ?? '');
        if ($phase === broadcast_phase::PLANNED) {
            $this->content = get_string('badgeplanned', 'mod_agora');
            $this->style = self::STYLES['dark'];
            return;
        }
        if ($phase === broadcast_phase::ENDED) {
            $this->content = get_string('badgeended', 'mod_agora');
            $this->style = self::STYLES['info'];
            return;
        }

        $state = stream_state::for_stream($streamname);
        $this->content = $state->badge_text();
        $this->style = self::STYLES[self::BADGE_STYLES[$state->state] ?? 'none'];
    }
}
