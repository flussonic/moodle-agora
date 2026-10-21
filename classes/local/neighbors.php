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
 * Neighbours of the open activity: the course stream list and cards of other courses.
 *
 * Only selection, order and counting live here — not a single markup string.
 * Stream states are read exclusively from the cache (`stream_state::cached()`),
 * frames — only by checking presence in the cache: the activity page promises
 * no more than one synchronous request to central, and it goes for the open
 * activity.
 *
 * Visibility is measured by `cm_info` fields: the lists take activities that
 * the core shows to the person on the course page (`uservisibleoncoursepage`),
 * and inside them "grey" ones (`!uservisible` — closed by a restriction with
 * a shown condition) and "hidden from learners" (`!visible`) are told apart.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class neighbors {
    /** How many course cards to show. */
    const COURSE_LIMIT = 6;

    /**
     * How many candidate courses to consider at all.
     *
     * A modinfo is built for each, and that is the most expensive part of the
     * pass. Twelve candidates are enough for six cards with a margin: only
     * courses where all activities are hidden from this person are dropped.
     */
    const COURSE_CANDIDATES = 12;

    /**
     * The current course stream list in course order.
     *
     * @param \cm_info $current the open activity
     * @param bool $teacher the viewer can edit the course
     * @return array ['rows' => [], 'total' => int, 'progress' => ['done','total']|null]
     */
    public static function for_course(\cm_info $current, bool $teacher): array {
        global $CFG;

        // Only the course format knows how to name a course section.
        require_once($CFG->dirroot . '/course/lib.php');

        $modinfo = $current->get_modinfo();
        // The open activity is always in the list, even when the course page
        // does not show it (`visibleoncoursepage = 0`): the spec requires its
        // mark, and without it there would be nothing to mark.
        $cms = self::ordered_agora_cms($modinfo, (int) $current->id);
        $states = stream_state::cached(self::stream_names($cms));

        $sections = $modinfo->get_section_info_all();
        $seensections = [];
        foreach ($cms as $cm) {
            $seensections[(int) $cm->sectionnum] = true;
        }
        // The section name is needed only when there is more than one section:
        // otherwise it is a heading above the whole list repeating itself.
        $withsections = count($seensections) > 1;

        $rows = [];
        $lastsection = null;
        foreach ($cms as $cm) {
            $row = self::row($cm, $teacher, (int) $cm->id === (int) $current->id, $states);
            $sectionnum = (int) $cm->sectionnum;
            $row['sectionname'] = '';
            if ($withsections && $sectionnum !== $lastsection && isset($sections[$sectionnum])) {
                $row['sectionname'] = get_section_name($modinfo->get_course(), $sections[$sectionnum]);
            }
            $lastsection = $sectionnum;
            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'total' => count($rows),
            'progress' => $teacher ? null : self::progress($modinfo, (int) $modinfo->get_user_id()),
        ];
    }

    /**
     * Cards of the learner's other courses: up to six.
     *
     * @param int $courseid the current course — it has no card
     * @param bool $teacher the open page is viewed by someone who can
     *     edit the activity: sets only the order of the cards; each card's
     *     target is computed by the viewer's role in its own course
     * @return array cards
     */
    public static function other_courses(int $courseid, bool $teacher): array {
        global $USER;

        $userid = (int) $USER->id;
        $courses = array_slice(self::candidate_courses($courseid), 0, self::COURSE_CANDIDATES, true);
        if (!$courses) {
            return [];
        }

        // First the modinfo and stream names of all candidates, then one state
        // cache read for the whole page: a read per course would be up to a
        // dozen `get_many()` where one is enough.
        $seen = [];
        $names = [];
        foreach ($courses as $id => $course) {
            $modinfo = get_fast_modinfo($course, $userid);
            $cms = self::ordered_agora_cms($modinfo);
            if (!$cms) {
                continue;
            }
            $seen[$id] = ['course' => $course, 'modinfo' => $modinfo, 'cms' => $cms];
            $names = array_merge($names, self::stream_names($cms));
        }
        if (!$seen) {
            return [];
        }
        $states = stream_state::cached($names);

        $cards = [];
        foreach ($seen as $id => $found) {
            // The live target decides the card order, and it is known without
            // a single request: only the state cache and the `cm_info` fields.
            // The full target is computed below and only for the six shown —
            // it costs a completion pass over the course.
            $live = self::live_target($found['cms'], $states) !== null;
            $next = null;
            foreach ($found['cms'] as $cm) {
                $timestart = (int) ($cm->customdata['timestart'] ?? 0);
                if ($timestart > time() && ($next === null || $timestart < $next)) {
                    $next = $timestart;
                }
            }

            $cards[] = [
                'id' => (int) $id,
                'name' => self::course_name($found['course']),
                'streams' => count($found['cms']),
                'live' => $live,
                'next' => $next,
                'modinfo' => $found['modinfo'],
            ];
        }

        self::sort_cards($cards, $teacher);

        $ready = [];
        foreach ($cards as $card) {
            // Who the viewer is in the card's course — that course itself
            // decides: the teacher of the current course may be a learner of
            // the neighbouring one, and the target they need there is the
            // learner one — the first unwatched, not just the first.
            // The card order above is set by the role on the open page.
            $teaches = has_capability('moodle/course:manageactivities', \context_course::instance((int) $card['id']));
            $target = self::target_for($card['modinfo'], $userid, $teaches, $states);
            // A course without a single available stream has no card: there
            // would be nowhere for the card to lead.
            if ($target === null) {
                continue;
            }
            unset($card['modinfo']);
            $card['target'] = $target;
            $card['targetname'] = $target->get_formatted_name();
            $card['url'] = $target->url;
            $card['state'] = $states[self::stream_name($target)] ?? null;
            $ready[] = $card;
            if (count($ready) >= self::COURSE_LIMIT) {
                break;
            }
        }

        return $ready;
    }

    /**
     * The course card target: the activity it leads to and whose frame it shows.
     *
     * The order is one and the same for the cover, the badge and the link —
     * otherwise the person clicks on "running now" and lands on last week's
     * lecture.
     *
     * Completion is asked only of a learner and only where the course tracks
     * it: a teacher never gets "viewed the activity" records at all, and
     * `get_data()` would return zeros for them on every activity.
     *
     * @param \course_modinfo $modinfo course information for this user
     * @param int $userid viewer
     * @param bool $teacher the viewer can edit the course
     * @param array $states states from the cache: stream name => stream_state
     * @return \cm_info|null null — no available streams in the course
     */
    public static function target_for(
        \course_modinfo $modinfo,
        int $userid,
        bool $teacher,
        array $states
    ): ?\cm_info {
        global $CFG;

        $cms = self::ordered_agora_cms($modinfo);
        if (!$cms) {
            return null;
        }

        $live = self::live_target($cms, $states);
        if ($live !== null) {
            return $live;
        }

        $first = null;
        foreach ($cms as $cm) {
            if ($cm->uservisible) {
                $first = $cm;
                break;
            }
        }
        if ($first === null || $teacher) {
            return $first;
        }

        // The course `enablecompletion` comes from `enrol_get_my_courses()` and
        // lies among the fields modinfo caches — the check does not cost a
        // separate course query. We include the completion library ourselves:
        // the method is public, and the caller is not obliged to have it
        // already.
        require_once($CFG->libdir . '/completionlib.php');
        $completion = new \completion_info($modinfo->get_course());
        if (!$completion->is_enabled()) {
            return $first;
        }

        // The "viewed the activity" flag is meaningful only where this
        // condition is also enabled: for the rest `viewed` is always zero, and
        // the first activity without the condition would become the eternal
        // target.
        // One query per course: `wholecourse` puts the completion of all
        // activities into the core cache, and the following `get_data()` read
        // from there.
        $completion->get_data($first, true, $userid);
        foreach ($cms as $cm) {
            if (!$cm->uservisible || empty($cm->completionview)) {
                continue;
            }
            if ((int) $completion->get_data($cm, true, $userid)->viewed === 0) {
                return $cm;
            }
        }

        // Everything is watched — the first again: the target of such a course
        // is the same as of a course without tracking, and their substring is
        // the same.
        return $first;
    }

    /**
     * The learner-available live stream of the course — first in course order.
     *
     * @param \cm_info[] $cms course activities in course order
     * @param array $states states from the cache: stream name => stream_state
     * @return \cm_info|null
     */
    private static function live_target(array $cms, array $states): ?\cm_info {
        foreach ($cms as $cm) {
            $name = self::stream_name($cm);
            if ($name === '' || !$cm->uservisible) {
                continue;
            }
            if (isset($states[$name]) && $states[$name]->state === stream_state::LIVE) {
                return $cm;
            }
        }
        return null;
    }

    /**
     * Completion across the course streams: how many of the tracked are done.
     *
     * Only activities with tracking enabled are counted: "Streams: N" and
     * "Completed k of M" count different things and do not have to match.
     *
     * @param \course_modinfo $modinfo course information
     * @param int $userid viewer
     * @return array|null ['done' => k, 'total' => M]; null — no tracking
     */
    public static function progress(\course_modinfo $modinfo, int $userid): ?array {
        global $CFG;

        // The completion library — its own line: see `target_for()`.
        require_once($CFG->libdir . '/completionlib.php');
        $completion = new \completion_info($modinfo->get_course());
        if (!$completion->is_enabled()) {
            return null;
        }

        $cms = self::ordered_agora_cms($modinfo);
        if (!$cms) {
            return null;
        }
        // One query per course: `get_data` with `wholecourse` puts the
        // completion of all activities into the core cache, and
        // `cm_completion_details` then reads from there without going to the
        // database for every activity.
        $completion->get_data(reset($cms), true, $userid);

        $done = 0;
        $total = 0;
        foreach ($cms as $cm) {
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_NONE) {
                continue;
            }
            $total++;
            if (\core_completion\cm_completion_details::get_instance($cm, $userid, false)->is_overall_complete()) {
                $done++;
            }
        }
        return $total > 0 ? ['done' => $done, 'total' => $total] : null;
    }

    /**
     * The "Agora broadcast" activities of the course in course order.
     *
     * The key order of modinfo is not promised, so the section and the place
     * in the section are computed explicitly: otherwise the list would go in
     * identifier order.
     *
     * @param \course_modinfo $modinfo course information
     * @param int $alwaysid the activity that stays in the list at any visibility
     * @return \cm_info[] in (section, place in section) order
     */
    private static function ordered_agora_cms(\course_modinfo $modinfo, int $alwaysid = 0): array {
        $position = [];
        $order = 0;
        $sections = $modinfo->get_sections();
        ksort($sections, SORT_NUMERIC);
        foreach ($sections as $cmids) {
            foreach ($cmids as $cmid) {
                $position[(int) $cmid] = $order++;
            }
        }

        $cms = [];
        foreach ($modinfo->get_instances_of('agora') as $cm) {
            if ((int) $cm->id !== $alwaysid && !$cm->is_visible_on_course_page()) {
                continue;
            }
            $cms[] = $cm;
        }
        usort($cms, static function (\cm_info $a, \cm_info $b) use ($position): int {
            return ($position[(int) $a->id] ?? PHP_INT_MAX) <=> ($position[(int) $b->id] ?? PHP_INT_MAX);
        });
        return $cms;
    }

    /**
     * A list row: what the page knows about the neighbour.
     *
     * @param \cm_info $cm course activity
     * @param bool $teacher the viewer can edit the course
     * @param bool $current this is the open activity
     * @param array $states states from the cache: stream name => stream_state
     * @return array
     */
    private static function row(\cm_info $cm, bool $teacher, bool $current, array $states): array {
        $name = self::stream_name($cm);
        $state = $name !== '' ? ($states[$name] ?? null) : null;
        // An activity closed by a restriction shows no frame and has no link:
        // a lock instead of the frame, the access condition instead of the
        // subtitle.
        $locked = !$cm->uservisible;

        return [
            'cmid' => (int) $cm->id,
            'name' => $cm->get_formatted_name(),
            'url' => $locked || $current ? null : $cm->url,
            'streamname' => $teacher ? $name : '',
            'subtitle' => $locked
                ? self::plain((string) $cm->availableinfo)
                : (string) ($cm->customdata['subtitle'] ?? ''),
            'current' => $current,
            'hidden' => !$cm->visible,
            'locked' => $locked,
            'state' => $state,
            'frame' => $state !== null && !$locked ? preview::cached_url($cm->context, $name, $state) : null,
        ];
    }

    /**
     * The core text in one line, without markup and without case damage.
     *
     * `html_to_text()` is no good here: it raises the case inside `<strong>`,
     * and the core puts exactly the access condition date into `<strong>` — so
     * the line arrived in caps ("Available from 2 OCTOBER 2026").
     *
     * @param string $html text with markup
     * @return string
     */
    private static function plain(string $html): string {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // A non-breaking space is not caught by `\s`, and it occurs in core dates.
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /**
     * The stream name of the activity.
     *
     * @param \cm_info $cm course activity
     * @return string
     */
    private static function stream_name(\cm_info $cm): string {
        return (string) ($cm->customdata['streamname'] ?? '');
    }

    /**
     * The stream names of a set of activities.
     *
     * @param \cm_info[] $cms course activities
     * @return string[]
     */
    private static function stream_names(array $cms): array {
        $names = [];
        foreach ($cms as $cm) {
            $name = self::stream_name($cm);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * The viewer's courses that have "Agora broadcast" activities, except the current one.
     *
     * Enrolments are counted by the core (`enrol_get_my_courses` — only those
     * visible to the viewer and in their order), and whether there are streams
     * there is decided by one query on the plugin table: no modinfo is built
     * at this step at all.
     *
     * @param int $courseid the current course
     * @return array course id => course record
     */
    private static function candidate_courses(int $courseid): array {
        global $DB, $USER;

        // Both side column blocks ask for the candidates, and
        // `enrol_get_my_courses()` drags along context preloading: there is no
        // reason to pay a second such pass per page view. The cache is for one
        // request: the viewer's course list changes between page views, and it
        // must not be kept longer.
        $cache = \cache::make('mod_agora', 'candidates');
        $key = (int) $USER->id . ':' . $courseid;
        $found = $cache->get($key);
        if (is_array($found)) {
            return $found;
        }

        // The `enablecompletion` flag here is not a luxury: without it `completion_info`
        // fetches the course record with a separate query for every card.
        $mine = enrol_get_my_courses(
            ['id', 'fullname', 'visible', 'cacherev', 'shortname', 'format', 'enablecompletion']
        );
        unset($mine[$courseid]);
        if (!$mine) {
            $cache->set($key, []);
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($mine));
        $withstreams = $DB->get_fieldset_sql("SELECT DISTINCT course FROM {agora} WHERE course $insql", $params);
        $withstreams = array_fill_keys(array_map('intval', $withstreams), true);

        $candidates = [];
        foreach ($mine as $id => $course) {
            if (isset($withstreams[(int) $id])) {
                $candidates[(int) $id] = $course;
            }
        }
        $cache->set($key, $candidates);
        return $candidates;
    }

    /**
     * The course name filtered in the context of the course itself.
     *
     * Without an explicit context `format_string()` takes it from `$PAGE`, that
     * is from the current module: another course's filters would be computed
     * by foreign settings, and without `$PAGE` (cron, web service) the call
     * fails entirely.
     *
     * @param \stdClass $course the course record
     * @return string
     */
    private static function course_name(\stdClass $course): string {
        return format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]);
    }

    /**
     * The card order: with a live target, then by the nearest date, then by name.
     *
     * For a teacher — by name: they need a predictable list, not a selection.
     *
     * @param array $cards course cards
     * @param bool $teacher the viewer can edit the course
     * @return void
     */
    private static function sort_cards(array &$cards, bool $teacher): void {
        // First by name — with a real string comparison, with Cyrillic and
        // case. Further on, `usort` in PHP 8 is stable, so the name stays the
        // last sort key by itself.
        \core_collator::asort_array_of_arrays_by_key($cards, 'name');
        $cards = array_values($cards);
        if ($teacher) {
            return;
        }
        usort($cards, static function (array $a, array $b): int {
            $live = ($b['live'] ? 1 : 0) <=> ($a['live'] ? 1 : 0);
            if ($live !== 0) {
                return $live;
            }
            return ($a['next'] ?? PHP_INT_MAX) <=> ($b['next'] ?? PHP_INT_MAX);
        });
    }
}
