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

use mod_agora\check\states_refresh;
use mod_agora\local\neighbors;
use mod_agora\local\player_view;
use mod_agora\local\preview;
use mod_agora\local\stream_state;
use renderer_base;

/**
 * The activity page: two grid rows around the scene.
 *
 * The first row — the scene (`player_view::scene`: the player or a
 * placeholder) with the metadata row and the description, and on the right
 * the stream list of this course exactly at its height. The second — the
 * comment area under the scene and cards of other courses to its right.
 *
 * No side column block goes to central: neighbour states and frames are taken
 * only from the cache warmed by the scheduled task `refresh_states`. The
 * lag of this task is visible to the teacher — as a muted line above the
 * scene.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class watch_page implements \renderable, \templatable {
    /** @var \stdClass The activity record. */
    private \stdClass $agora;

    /** @var \cm_info The open course module. */
    private \cm_info $cm;

    /** @var string The ready scene markup. */
    private string $player;

    /** @var bool The viewer can edit the course. */
    private bool $teacher;

    /** @var bool The viewer leads the broadcast (the `mod/agora:broadcast` right). */
    private bool $canbroadcast;

    /** @var bool The learner pressed "watch the recording". */
    private bool $wantrecord;

    /** @var stream_state|null The stream state the scene is built from. */
    private ?stream_state $state;

    /**
     * Assembles the page around a ready scene.
     *
     * @param \stdClass $agora the activity record
     * @param \cm_info $cm the open course module
     * @param string $player the scene markup from `player_view::scene`
     * @param bool $wantrecord the learner pressed "watch the recording"
     * @param stream_state|null $state the state the scene is built from;
     *     null — read it anew (`stream_state::for_page()`)
     */
    public function __construct(
        \stdClass $agora,
        \cm_info $cm,
        string $player,
        bool $wantrecord = false,
        ?stream_state $state = null
    ) {
        $this->agora = $agora;
        $this->cm = $cm;
        $this->player = $player;
        $this->wantrecord = $wantrecord;
        $this->state = $state;
        // The same context as `player_view` (the console link, the refusal
        // text): a right overridden at the activity level must not give one
        // page two different answers to "is this a teacher". Leading the
        // broadcast is its own right (D11): a non-editing teacher sees the
        // "How to broadcast" block and the buttons, but not the Agora console.
        $context = \context_module::instance($cm->id);
        $this->teacher = has_capability('moodle/course:manageactivities', $context);
        $this->canbroadcast = has_capability('mod/agora:broadcast', $context);
    }

    /**
     * The stream state of the open activity — one for the whole page.
     *
     * @return stream_state
     */
    private function state(): stream_state {
        if ($this->state === null) {
            $this->state = stream_state::for_page((string) $this->agora->stream_name);
        }
        return $this->state;
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output renderer
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $courseid = (int) $this->cm->course;

        $playlist = $this->playlist($output);
        $courses = $this->course_cards($output, $courseid);
        $comments = $this->comments();

        $intro = trim(strip_tags((string) $this->agora->intro)) !== ''
            ? format_module_intro('agora', $this->agora, $this->cm->id)
            : '';

        $actions = $this->actions();
        $state = $this->state();

        return [
            'player' => $this->player,
            // The state poll of the open page (the `mod_agora/watch` module):
            // what the scene shows now and whether to ask if the broadcast
            // has started.
            'scene' => [
                'cmid' => (int) $this->cm->id,
                'contextid' => (int) \context_module::instance((int) $this->cm->id)->id,
                'kind' => player_view::kind($this->agora, $state, $this->wantrecord, $this->canbroadcast),
                'poll' => player_view::polls($this->agora, $state, $this->wantrecord, $this->canbroadcast),
                'interval' => player_view::POLL_INTERVAL,
                'jitter' => player_view::POLL_JITTER,
            ],
            'broadcast' => $this->broadcast(),
            'publish' => player_view::publish_block($this->agora, $this->cm, $this->canbroadcast),
            'meta' => $this->meta(),
            'hasactions' => (bool) $actions,
            'actions' => $actions,
            // For the POST forms of the broadcast leading actions in the metadata row.
            'cmid' => (int) $this->cm->id,
            'hasintro' => $intro !== '',
            'intro' => $intro,
            'staleness' => $this->staleness(),
            'hasplaylist' => $playlist !== null,
            'playlist' => $playlist,
            'hascourses' => (bool) $courses,
            'courses' => $courses,
            'hascomments' => $comments !== null,
            'comments' => $comments,
        ];
    }

    /**
     * The page transitions as buttons: "watch the recording", "go live", the Agora console.
     *
     * @return array
     */
    private function actions(): array {
        $actions = [];
        foreach (player_view::actions($this->agora, $this->cm, $this->wantrecord, $this->state()) as $action) {
            $actions[] = [
                'key' => $action['key'],
                // An inactive button ("Start broadcast" without a signal) carries no link.
                'url' => $action['url'] !== null ? $action['url']->out(false) : '',
                'label' => $action['label'],
                // The Agora console is a foreign page: it is opened by a new tab.
                'blank' => $action['key'] === 'console',
                'primary' => !empty($action['primary']),
                'disabled' => !empty($action['disabled']),
                'hint' => (string) ($action['hint'] ?? ''),
                // The confirmation is already in the confirm() format: a JSON
                // string escaped for an attribute.
                'confirm' => (string) ($action['confirm'] ?? ''),
                // The broadcast leading actions are POST forms, not links (Ф-1).
                'form' => !empty($action['form']),
                'name' => (string) ($action['name'] ?? $action['key']),
                'sesskey' => (string) ($action['sesskey'] ?? ''),
            ];
        }
        return $actions;
    }

    /**
     * The metadata row under the scene: state, section, stream name.
     *
     * @return array
     */
    private function meta(): array {
        global $CFG;

        // Only the course format knows how to name a course section.
        require_once($CFG->dirroot . '/course/lib.php');

        $streamname = (string) $this->agora->stream_name;
        // The same state the scene is built from: the badge and the player do
        // not diverge, and there is no second request to central.
        $state = $this->state();

        $sections = $this->cm->get_modinfo()->get_section_info_all();
        $sectionnum = (int) $this->cm->sectionnum;
        $sectionname = isset($sections[$sectionnum])
            ? get_section_name($this->cm->get_modinfo()->get_course(), $sections[$sectionnum])
            : '';
        $badge = player_view::badge($this->agora, $state);

        return [
            'badge' => $badge['text'],
            'badgeclass' => $badge['class'],
            'hassectionname' => $sectionname !== '',
            'sectionname' => $sectionname,
            'hasstreamname' => $this->teacher && $streamname !== '',
            'streamname' => $streamname,
        ];
    }

    /**
     * The broadcast leading rows above the metadata: an unconfirmed completion
     * and the completion way on the page of an ended broadcast.
     *
     * Seen by the one who leads the broadcast: the badge and the scene are
     * enough for a learner, and refusal causes and completion ways are the
     * teacher's working details.
     *
     * @return array ['showpending' => bool, 'pending' => string,
     *     'showended' => bool, 'ended' => string, 'hint' => string]
     */
    private function broadcast(): array {
        $empty = ['showpending' => false, 'pending' => '', 'showended' => false, 'ended' => '', 'hint' => ''];
        if (!$this->canbroadcast) {
            return $empty;
        }
        $phase = \mod_agora\local\broadcast_phase::of($this->agora);
        $failure = \mod_agora\local\broadcast_phase::pending_failure($this->agora);
        if ($failure !== null) {
            // The completion is not confirmed: the cause names the action and
            // the refusal, the retry is available with the same button and the
            // scheduled task.
            return ['showpending' => true, 'pending' => get_string('endpendingline', 'mod_agora', $failure)]
                + $empty;
        }
        if ($phase !== \mod_agora\local\broadcast_phase::ENDED) {
            return $empty;
        }
        $by = (string) ($this->agora->ended_by ?? '');
        $how = $by !== '' && get_string_manager()->string_exists('endby' . $by, 'mod_agora')
            ? get_string('endby' . $by, 'mod_agora')
            : $by;
        $when = userdate((int) $this->agora->ended_at, get_string('strftimedatetime', 'langconfig'));
        return [
            'showended' => true,
            'ended' => get_string('broadcastendedline', 'mod_agora', (object) ['how' => $how, 'when' => $when]),
            'hint' => get_string('broadcastnewelement', 'mod_agora'),
        ] + $empty;
    }

    /**
     * The row about the warm-up lag — only for the one who will fix it.
     *
     * @return array
     */
    private function staleness(): array {
        if (!$this->teacher) {
            return ['show' => false, 'failed' => false, 'minutes' => 0];
        }
        // The condition is computed by the "Stream states refresh" check
        // itself: the row and the red report must light up together, and two
        // conditions would one day diverge.
        $lag = states_refresh::lag();
        if ($lag === null) {
            return ['show' => false, 'failed' => false, 'minutes' => 0];
        }
        return ['show' => true, 'failed' => $lag['failed'], 'minutes' => $lag['minutes']];
    }

    /**
     * The "In this course" block; null — nothing to draw.
     *
     * @param renderer_base $output renderer
     * @return array|null
     */
    private function playlist(renderer_base $output): ?array {
        $list = neighbors::for_course($this->cm, $this->teacher);
        // A list of the single open activity is not a list: it repeats what
        // the person already sees on the scene.
        if ($list['total'] <= 1) {
            return null;
        }

        $rows = [];
        foreach ($list['rows'] as $row) {
            $rows[] = $this->row($output, $row, false);
        }

        $progress = $list['progress'];
        return [
            'coursename' => format_string(
                $this->cm->get_modinfo()->get_course()->fullname,
                true,
                ['context' => \context_course::instance((int) $this->cm->course)]
            ),
            'total' => $list['total'],
            'hasprogress' => $progress !== null,
            'done' => $progress['done'] ?? 0,
            'tracked' => $progress['total'] ?? 0,
            'rows' => $rows,
        ];
    }

    /**
     * A course stream list row for the template.
     *
     * @param renderer_base $output renderer
     * @param array $row the row from `neighbors`
     * @param bool $withcourse show the course name instead of the subtitle
     * @return array
     */
    private function row(renderer_base $output, array $row, bool $withcourse): array {
        $card = new preview_card(
            $row['frame'],
            $row['state'],
            preview_card::SIZE_SIDE,
            null,
            false,
            $row['locked']
        );
        // The open row has no subtitle: the activity description already
        // stands under the scene, and its second copy in the list reads as a
        // duplicate.
        $subtitle = $withcourse ? (string) ($row['coursename'] ?? '') : (string) $row['subtitle'];
        if ($row['current']) {
            $subtitle = '';
        }

        return [
            'card' => $card->export_for_template($output),
            'name' => $row['name'],
            'haslink' => $row['url'] !== null,
            'link' => $row['url'] !== null ? $row['url']->out(false) : '',
            'current' => $row['current'],
            'hidden' => $row['hidden'],
            'locked' => $row['locked'],
            'hassubtitle' => $subtitle !== '',
            'subtitle' => $subtitle,
            'hasstreamname' => (string) $row['streamname'] !== '',
            'streamname' => (string) $row['streamname'],
            'hassectionname' => (string) ($row['sectionname'] ?? '') !== '',
            'sectionname' => (string) ($row['sectionname'] ?? ''),
        ];
    }

    /**
     * The comment area: the ready markup of the core widget.
     *
     * The widget itself looks neither at the site setting `usecomments` nor at
     * the plugin setting — that is the caller's care. The heading with the
     * counter is drawn by the plugin template: with `notoggle` the core prints
     * neither of the two.
     *
     * @return array|null null — no area
     */
    private function comments(): ?array {
        global $CFG;

        if (empty($CFG->usecomments) || !get_config('mod_agora', 'comments')) {
            return null;
        }

        require_once($CFG->dirroot . '/comment/lib.php');

        $args = new \stdClass();
        $args->context = \context_module::instance((int) $this->cm->id);
        $args->course = $this->cm->get_modinfo()->get_course();
        $args->cm = $this->cm;
        $args->component = 'mod_agora';
        $args->area = 'stream';
        $args->itemid = (int) $this->agora->id;
        // The feed is expanded at once and there is nothing to collapse it
        // with: it is its own page area, not a footnote to the recording.
        $args->autostart = true;
        $args->notoggle = true;

        $comment = new \comment($args);
        // The new comment field — at the full area width: otherwise the core
        // sets `cols = 20` on the text field.
        $comment->set_fullwidth(true);

        $html = $comment->output(true);
        // Viewing comments is not allowed — the core returns an empty string,
        // and there must be no frame with a heading.
        if (trim($html) === '') {
            return null;
        }

        return [
            'count' => (int) $comment->count(),
            'html' => $html,
        ];
    }

    /**
     * The "Other courses" block: a card per course, the link — to the card target.
     *
     * @param renderer_base $output renderer
     * @param int $courseid the current course
     * @return array
     */
    private function course_cards(renderer_base $output, int $courseid): array {
        $cards = [];
        foreach (neighbors::other_courses($courseid, $this->teacher) as $course) {
            $target = $course['target'];
            $state = $course['state'];
            $frame = $state !== null
                ? preview::cached_url(
                    $target->context,
                    (string) ($target->customdata['streamname'] ?? ''),
                    $state
                )
                : null;
            // The card is not decorative: it both shows the target state with
            // the badge and voices it to screen readers — there is nothing
            // else to duplicate it with in the card.
            $card = new preview_card($frame, $state, preview_card::SIZE_SIDE, null, false);

            $live = $state !== null && $state->state === stream_state::LIVE;
            $cards[] = [
                'card' => $card->export_for_template($output),
                'name' => $course['name'],
                'link' => $course['url']->out(false),
                'streams' => $course['streams'],
                'next' => get_string($live ? 'livenext' : 'nextup', 'mod_agora', $course['targetname']),
            ];
        }
        return $cards;
    }
}
