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

namespace mod_agora\output;

use mod_agora\local\stream_state;
use renderer_base;

/**
 * The frame card with the state badge — one on the course page and one on the
 * activity page.
 *
 * The card requests nothing: the frame address and the state are brought to it
 * ready-made. Its three kinds differ not in markup but in what is missing: the
 * frame (an empty card with the broadcast pictogram), the state (a frame
 * without a badge) and the link (the activity is closed by a restriction — a
 * lock instead of the frame).
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_card implements \renderable, \templatable {
    /** The card size in the course page list. */
    const SIZE_COURSE = 'course';

    /** The card size in the side column of the activity page. */
    const SIZE_SIDE = 'side';

    /** @var \moodle_url|null The frame address; null — there is no frame. */
    private ?\moodle_url $frame;

    /** @var stream_state|null The broadcast state; null — there is no state. */
    private ?stream_state $state;

    /** @var string The card size. */
    private string $size;

    /** @var \moodle_url|null Where the card leads; null — the card is not a link. */
    private ?\moodle_url $link;

    /**
     * @var bool The card is decorative.
     *
     * On the course page it duplicates the link of the activity name, so it is
     * removed from focus and from screen readers: there the state is announced
     * by the stock `activitybadge`. In the side column there is nothing to
     * duplicate.
     */
    private bool $decorative;

    /** @var bool The activity is closed by a restriction: a lock instead of the frame. */
    private bool $locked;

    /**
     * Builds the frame card.
     *
     * @param \moodle_url|null $frame the frame address; null — there is no frame
     * @param stream_state|null $state the broadcast state; null — no badge
     * @param string $size SIZE_COURSE or SIZE_SIDE
     * @param \moodle_url|null $link where the card leads; null — the card is not a link
     * @param bool $decorative remove the card from focus and from screen readers
     * @param bool $locked the activity is closed by an access restriction
     */
    public function __construct(
        ?\moodle_url $frame,
        ?stream_state $state,
        string $size,
        ?\moodle_url $link,
        bool $decorative,
        bool $locked = false
    ) {
        $this->frame = $frame;
        $this->state = $state;
        $this->size = $size;
        $this->link = $link;
        $this->decorative = $decorative;
        $this->locked = $locked;
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output renderer
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        // An activity closed by a restriction shows no frame, even if one is in
        // the cache: the learner is not allowed to watch it yet.
        $hasimage = $this->frame !== null && !$this->locked;
        $hasbadge = $this->state !== null && !$this->locked;

        $classes = ['mod-agora-preview-card', 'mod-agora-preview-' . $this->size];
        if (!$hasimage) {
            $classes[] = 'mod-agora-preview-empty';
        }

        return [
            'haslink' => $this->link !== null,
            'link' => $this->link !== null ? $this->link->out(false) : '',
            'cardclass' => implode(' ', $classes),
            'decorative' => $this->decorative,
            'hasimage' => $hasimage,
            'image' => $hasimage ? $this->frame->out(false) : '',
            'locked' => $this->locked,
            'hasbadge' => $hasbadge,
            'badge' => $hasbadge ? $this->state->badge_text() : '',
            'badgeclass' => $hasbadge
                ? 'mod-agora-state-badge-' . str_replace('_', '-', $this->state->state)
                : '',
            // The badge on the frame is a picture in words, and the reader gets
            // it as a separate line: otherwise the state would be announced twice.
            'srbadge' => $hasbadge && !$this->decorative,
        ];
    }
}
