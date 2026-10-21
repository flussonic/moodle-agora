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

use mod_agora\local\connection;
use mod_agora\local\player_view;
use mod_agora\local\stream_state;
use mod_agora\output\watch_page;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * The activity page: one request to central and neighbours in the sidebar.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\output\watch_page
 * @covers     \mod_agora\output\preview_card
 */
final class watch_page_test extends \advanced_testcase {
    /**
     * A connection to the stub Agora.
     *
     * @return void
     */
    protected function setUp(): void {
        global $PAGE;

        parent::setUp();
        $this->resetAfterTest();
        // The learner address: it is always present in the browser request, and
        // the ticket is bound to it.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        // The page knows its own address in the browser too: `view.php` sets
        // it before the header, and the comment widget builds the «no JS» link
        // by it.
        $PAGE->set_url('/mod/agora/view.php');
        connection::set_client(null);
        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
    }

    /**
     * Removes the stub client afterwards.
     *
     * @return void
     */
    protected function tearDown(): void {
        connection::set_client(null);
        parent::tearDown();
    }

    /**
     * The routes of the stub Agora: the state of the open broadcast and the ticket issue.
     *
     * @return array
     */
    private function routes(): array {
        return [
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-1',
                'token' => 'jwt-1',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
            'GET /auth' => \mod_agora_mock_central::json(200, ['open_playback' => false]),
        ];
    }

    /**
     * Assembles a learner on four courses and the open activity of the first one.
     *
     * @return array [the activity record, cm_info, the learner]
     */
    private function learner_with_four_courses(): array {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Optics']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $open = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Optics lecture',
            'intro' => 'Diffraction and interference',
            'introformat' => FORMAT_HTML,
            'stream_name' => 'optics-2026',
        ]);
        $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Neighboring lecture',
            'stream_name' => 'optics-2027',
        ]);

        // Three foreign courses with seven broadcasts each: if the sidebar went
        // to central, there would be a score of requests.
        for ($c = 0; $c < 3; $c++) {
            $other = $this->getDataGenerator()->create_course(['fullname' => 'Course ' . $c]);
            $this->getDataGenerator()->enrol_user($student->id, $other->id, 'student');
            for ($i = 0; $i < 7; $i++) {
                $this->getDataGenerator()->create_module('agora', [
                    'course' => $other->id,
                    'name' => 'Broadcast ' . $c . '-' . $i,
                    'stream_name' => 'stream-' . $c . '-' . $i,
                ]);
            }
        }

        $this->setUser($student);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($open->cmid);
        return [$open, $cm, $student];
    }

    /**
     * An empty cache and twenty foreign broadcasts: the whole request log is known by name.
     *
     * The whole log is compared, not the state lines: otherwise an extra
     * request of any other kind — the admission policy, a second ticket, a
     * frame — would go unnoticed by the test.
     *
     * @return void
     */
    public function test_learner_page_asks_central_by_a_known_list(): void {
        global $DB, $OUTPUT;

        [$open, $cm] = $this->learner_with_four_courses();
        $client = \mod_agora_mock_central::client($this->routes());
        connection::set_client($client);

        $agora = $DB->get_record('agora', ['id' => $open->id], '*', MUST_EXIST);
        $player = player_view::scene($agora, $cm, (int) $cm->get_modinfo()->get_user_id());
        $html = $OUTPUT->render(new watch_page($agora, $cm, $player));

        // For the learner: the `admin` account login and the state of the open
        // broadcast, then the `player` account login and the pair ticket.
        // Anything beyond that is a regression.
        $this->assertSame([
            'POST /central/api-v4/auth/login',
            'GET /central/api-v4/streams/stats/optics-2026',
            'POST /central/api-v4/auth/login',
            'POST /central/api-v4/play-sessions/grants',
        ], $client->request_log());

        $this->assertStringContainsString('Neighboring lecture', $html);
        // Exactly one badge on a frame — on the open activity, whose state the
        // scene put into the cache. The neighbours have no state, and no frames either.
        $this->assertSame(1, substr_count($html, 'class="mod-agora-state-badge '));
        // And exactly one frame address — on it too: the frame is given by the
        // state from the cache, and the neighbours have no state.
        $this->assertSame(1, substr_count($html, 'mod_agora/preview/0/cached/'));
    }

    /**
     * For the presenter the admission policy is added to the same list — and nothing else.
     *
     * @return void
     */
    public function test_teacher_page_adds_the_admission_policy(): void {
        global $DB, $OUTPUT;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $open = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Lecture',
            'stream_name' => 'optics-2026',
        ]);
        $client = \mod_agora_mock_central::client($this->routes());
        connection::set_client($client);
        $this->setUser($teacher);
        $cm = get_fast_modinfo($course, $teacher->id)->get_cm($open->cmid);

        $agora = $DB->get_record('agora', ['id' => $open->id], '*', MUST_EXIST);
        $player = player_view::scene($agora, $cm, (int) $teacher->id);
        $OUTPUT->render(new watch_page($agora, $cm, $player));

        $this->assertSame([
            'POST /central/api-v4/auth/login',
            'GET /central/api-v4/auth',
            'GET /central/api-v4/streams/stats/optics-2026',
            'POST /central/api-v4/auth/login',
            'POST /central/api-v4/play-sessions/grants',
        ], $client->request_log());
    }

    /**
     * The name, the description and the sidebar are drawn exactly once.
     *
     * @return void
     */
    public function test_page_shows_the_name_and_the_intro_once(): void {
        global $DB, $OUTPUT;

        [$open, $cm] = $this->learner_with_four_courses();
        connection::set_client(\mod_agora_mock_central::client($this->routes()));

        $agora = $DB->get_record('agora', ['id' => $open->id], '*', MUST_EXIST);
        $player = player_view::scene($agora, $cm, (int) $cm->get_modinfo()->get_user_id());
        $html = $OUTPUT->render(new watch_page($agora, $cm, $player));

        // There is no own header with the activity name on the page: the Boost
        // header shows the name, and a second heading under the player would
        // be a duplicate.
        $this->assertStringNotContainsString('mod-agora-title', $html);
        $this->assertSame(1, substr_count($html, 'class="mod-agora-metaline"'));
        $this->assertSame(1, substr_count($html, 'class="mod-agora-intro"'));
        $this->assertStringContainsString(get_string('inthiscourse', 'mod_agora'), $html);
        $this->assertStringContainsString(get_string('othercourses', 'mod_agora'), $html);
    }

    /**
     * A single activity in a single course: neither the list, nor the cards.
     *
     * @return void
     */
    public function test_single_element_page_stays_one_column(): void {
        global $DB, $OUTPUT;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $open = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'The only lecture',
            'stream_name' => 'optics-2026',
        ]);
        connection::set_client(\mod_agora_mock_central::client($this->routes()));
        $this->setUser($student);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($open->cmid);

        $agora = $DB->get_record('agora', ['id' => $open->id], '*', MUST_EXIST);
        $html = $OUTPUT->render(new watch_page($agora, $cm, player_view::scene($agora, $cm, (int) $student->id)));

        // The grid is in place, but both right cells are empty: the scene and
        // the comments under it take the whole width.
        $this->assertStringNotContainsString('mod-agora-has-playlist', $html);
        $this->assertStringNotContainsString('mod-agora-has-courses', $html);
        $this->assertStringContainsString('mod-agora-has-comments', $html);
        $this->assertStringNotContainsString(get_string('inthiscourse', 'mod_agora'), $html);
        $this->assertStringNotContainsString(get_string('othercourses', 'mod_agora'), $html);
    }

    /**
     * The warming lag is seen by the presenter and not seen by the learner.
     * The link to the Agora console is seen only by the site administrator:
     * the presenter does not get it, the administrator does.
     *
     * @return void
     */
    public function test_staleness_line_is_for_the_teacher_only(): void {
        global $DB, $OUTPUT;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $open = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Lecture',
            'stream_name' => 'optics-2026',
        ]);
        set_config('statesrefreshedat', time() - 10 * MINSECS, 'mod_agora');
        connection::set_client(\mod_agora_mock_central::client($this->routes()));
        $agora = $DB->get_record('agora', ['id' => $open->id], '*', MUST_EXIST);
        $stale = get_string('statesstale', 'mod_agora', 10);

        $this->setUser($teacher);
        $cm = get_fast_modinfo($course, $teacher->id)->get_cm($open->cmid);
        $html = $OUTPUT->render(new watch_page($agora, $cm, ''));
        $this->assertStringContainsString($stale, $html);
        // The broadcast name in the metadata line is seen only by the presenter.
        $this->assertStringContainsString('optics-2026', $html);
        // The Agora console — only for the site administrator: the editing
        // presenter gets no link.
        $this->assertStringNotContainsString(get_string('openinconsole', 'mod_agora'), $html);

        // The site administrator gets the link.
        $this->setAdminUser();
        $cm = get_fast_modinfo($course)->get_cm($open->cmid);
        $html = $OUTPUT->render(new watch_page($agora, $cm, ''));
        // The transition to the Agora console stands as a button in the
        // metadata line, not as a bare link under the player.
        $this->assertStringContainsString('btn btn-outline-secondary btn-sm', $html);
        $this->assertStringContainsString(get_string('openinconsole', 'mod_agora'), $html);

        $this->setUser($student);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($open->cmid);
        $html = $OUTPUT->render(new watch_page($agora, $cm, ''));
        $this->assertStringNotContainsString($stale, $html);
        $this->assertStringNotContainsString('optics-2026', $html);
        $this->assertStringNotContainsString('btn btn-outline-secondary btn-sm', $html);
    }

    /**
     * A failed pass with a fresh time is also visible to the presenter.
     *
     * The condition of the line and of the red check is one, and they must not
     * diverge: before the fix the page was silent while the pass time was
     * fresh, although the report was already red.
     *
     * @return void
     */
    public function test_failed_refresh_shows_the_line_even_when_fresh(): void {
        global $DB, $OUTPUT;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $open = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Lecture',
            'stream_name' => 'optics-2026',
        ]);
        // The pass time is fresh: by age alone there would be no line.
        set_config('statesrefreshedat', time() - 10, 'mod_agora');
        set_config('statesfailure', 'Agora answered with code 503', 'mod_agora');
        set_config('statesfailed', 3, 'mod_agora');
        connection::set_client(\mod_agora_mock_central::client($this->routes()));
        $agora = $DB->get_record('agora', ['id' => $open->id], '*', MUST_EXIST);

        // The same pair of conditions gives the red check in the report.
        $this->assertSame(
            \core\check\result::ERROR,
            (new \mod_agora\check\states_refresh())->get_result()->get_status()
        );

        $this->setUser($teacher);
        $cm = get_fast_modinfo($course, $teacher->id)->get_cm($open->cmid);
        $html = $OUTPUT->render(new watch_page($agora, $cm, ''));
        $this->assertStringContainsString(get_string('statesrefreshfailedline', 'mod_agora'), $html);

        // The learner has nothing to fix with — no line for them either.
        $this->setUser($student);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($open->cmid);
        $html = $OUTPUT->render(new watch_page($agora, $cm, ''));
        $this->assertStringNotContainsString(get_string('statesrefreshfailedline', 'mod_agora'), $html);
    }

    /**
     * A live broadcast of a foreign course becomes the target of that course's card.
     *
     * @return void
     */
    public function test_live_stream_becomes_the_card_target(): void {
        global $DB, $OUTPUT;

        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course(['fullname' => 'Neighboring course']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $other->id, 'student');
        $open = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Lecture',
            'stream_name' => 'optics-2026',
        ]);
        $live = $this->getDataGenerator()->create_module('agora', [
            'course' => $other->id,
            'name' => 'Foreign live',
            'stream_name' => 'townhall',
        ]);

        // The cache is warmed through the user door: the state of the foreign
        // broadcast is put by the ordinary `for_stream()`, as the page of that
        // course does it.
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/townhall' => \mod_agora_mock_central::live_stats('townhall'),
        ]));
        $this->assertSame(stream_state::LIVE, stream_state::for_stream('townhall')->state);

        connection::set_client(\mod_agora_mock_central::client($this->routes()));
        $this->setUser($student);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($open->cmid);
        $agora = $DB->get_record('agora', ['id' => $open->id], '*', MUST_EXIST);

        $html = $OUTPUT->render(new watch_page($agora, $cm, ''));

        // There is no separate «Live now» block anymore: the live broadcast is
        // the target of its course's card, and the link leads exactly to it.
        $this->assertStringContainsString(get_string('othercourses', 'mod_agora'), $html);
        $this->assertStringContainsString(
            get_string('livenext', 'mod_agora', 'Foreign live'),
            $html
        );
        $this->assertStringContainsString('view.php?id=' . $live->cmid, $html);

        // The state is voiced to screen readers: the badge on the frame is
        // marked `aria-hidden`, and the state text lies in the card visually
        // hidden.
        $badge = get_string('badgelive', 'mod_agora');
        $this->assertStringContainsString('<span class="sr-only">' . $badge . '</span>', $html);
        $this->assertMatchesRegularExpression(
            '#<span class="mod-agora-state-badge [^"]*" aria-hidden="true">' . preg_quote($badge, '#') . '#',
            $html
        );
    }

    /**
     * The comment area: the heading with the counter and the new comment field.
     *
     * @return void
     */
    public function test_comment_area_is_shown_to_the_learner(): void {
        global $DB, $OUTPUT;

        [$open, $cm] = $this->learner_with_four_courses();
        connection::set_client(\mod_agora_mock_central::client($this->routes()));
        $agora = $DB->get_record('agora', ['id' => $open->id], '*', MUST_EXIST);

        $html = $OUTPUT->render(new watch_page($agora, $cm, ''));

        $this->assertStringContainsString('mod-agora-comments', $html);
        $this->assertStringContainsString(get_string('commentsheading', 'mod_agora', 0), $html);
        // The new comment field is there: the learner has the plugin capability by default.
        $this->assertStringContainsString('comment-area', $html);
    }

    /**
     * Comments are off on the site — no area and no frame.
     *
     * @return void
     */
    public function test_comment_area_is_absent_without_site_comments(): void {
        global $CFG, $DB, $OUTPUT;

        [$open, $cm] = $this->learner_with_four_courses();
        connection::set_client(\mod_agora_mock_central::client($this->routes()));
        $agora = $DB->get_record('agora', ['id' => $open->id], '*', MUST_EXIST);

        $CFG->usecomments = 0;
        $html = $OUTPUT->render(new watch_page($agora, $cm, ''));

        $this->assertStringNotContainsString('mod-agora-comments', $html);
        $this->assertStringNotContainsString(get_string('commentsheading', 'mod_agora', 0), $html);
    }

    /**
     * Comments are off by the plugin setting — no area.
     *
     * @return void
     */
    public function test_comment_area_is_absent_when_the_plugin_setting_is_off(): void {
        global $DB, $OUTPUT;

        [$open, $cm] = $this->learner_with_four_courses();
        connection::set_client(\mod_agora_mock_central::client($this->routes()));
        $agora = $DB->get_record('agora', ['id' => $open->id], '*', MUST_EXIST);

        set_config('comments', 0, 'mod_agora');
        $html = $OUTPUT->render(new watch_page($agora, $cm, ''));

        $this->assertStringNotContainsString('mod-agora-comments', $html);
    }

    /**
     * No other courses — the comment area takes the whole row width.
     *
     * @return void
     */
    public function test_comments_take_the_whole_row_without_course_cards(): void {
        global $DB, $OUTPUT;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $open = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'First',
            'stream_name' => 'optics-2026',
        ]);
        $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Second',
            'stream_name' => 'optics-2027',
        ]);
        connection::set_client(\mod_agora_mock_central::client($this->routes()));
        $this->setUser($student);
        $cm = get_fast_modinfo($course, $student->id)->get_cm($open->cmid);
        $agora = $DB->get_record('agora', ['id' => $open->id], '*', MUST_EXIST);

        $html = $OUTPUT->render(new watch_page($agora, $cm, ''));

        $this->assertStringContainsString('mod-agora-has-playlist', $html);
        $this->assertStringContainsString('mod-agora-has-comments', $html);
        // There is no «has cards» modifier — the CSS stretches the comments
        // over both columns of the second row.
        $this->assertStringNotContainsString('mod-agora-has-courses', $html);
    }

    /**
     * A course with two activities and the page of the first one, assembled for the user.
     *
     * @param \stdClass $user who opens
     * @param \stdClass $course the course
     * @param array $second the fields of the second activity
     * @return string the page HTML
     */
    private function page_of_first(\stdClass $user, \stdClass $course, array $second = []): string {
        global $DB, $OUTPUT;

        $open = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'First',
            'stream_name' => 'optics-2026',
        ]);
        $this->getDataGenerator()->create_module('agora', $second + [
            'course' => $course->id,
            'name' => 'Second',
            'stream_name' => 'optics-2027',
        ]);
        connection::set_client(\mod_agora_mock_central::client($this->routes()));
        $this->setUser($user);
        $cm = get_fast_modinfo($course, $user->id)->get_cm($open->cmid);
        $agora = $DB->get_record('agora', ['id' => $open->id], '*', MUST_EXIST);
        return $OUTPUT->render(new watch_page($agora, $cm, player_view::scene($agora, $cm, (int) $user->id)));
    }

    /**
     * The «edit activities» capability, revoked from the presenter at the
     * activity level, takes away everything presenter-ish from the page at
     * once: both the console link (it is decided by `player_view`), and the
     * broadcast name, and the lag line. Formerly the page asked for the
     * capability in the course, and the scene — in the activity, and one page
     * gave two answers to «is this a presenter».
     *
     * @return void
     */
    public function test_teacher_role_comes_from_the_activity_context(): void {
        global $DB, $OUTPUT;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        set_config('statesrefreshedat', time() - 10 * MINSECS, 'mod_agora');
        $module = $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Closed for editing',
            'stream_name' => 'optics-2026',
        ]);
        $this->getDataGenerator()->create_module('agora', [
            'course' => $course->id,
            'name' => 'Neighboring',
            'stream_name' => 'optics-2027',
        ]);
        // The LMS administrator revoked the capability from the presenter role in this activity.
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability(
            'moodle/course:manageactivities',
            CAP_PROHIBIT,
            $roleid,
            \context_module::instance($module->cmid)->id,
            true
        );
        connection::set_client(\mod_agora_mock_central::client($this->routes()));
        $this->setUser($teacher);
        $cm = get_fast_modinfo($course, $teacher->id)->get_cm($module->cmid);
        $agora = $DB->get_record('agora', ['id' => $module->id], '*', MUST_EXIST);
        $html = $OUTPUT->render(new watch_page($agora, $cm, player_view::scene($agora, $cm, (int) $teacher->id)));

        $this->assertStringNotContainsString(get_string('openinconsole', 'mod_agora'), $html, 'No console link');
        $this->assertStringNotContainsString('>optics-2026<', $html, 'No broadcast name in the metadata line');
        $this->assertStringNotContainsString(
            get_string('statesstale', 'mod_agora', 10),
            $html,
            'No lag line'
        );
    }

    /**
     * The open list row is marked with `aria-current="page"`, and the presenter
     * has no «Done k of M» row.
     *
     * @return void
     */
    public function test_current_row_is_marked_and_teacher_has_no_progress(): void {
        global $CFG;

        $CFG->enablecompletion = 1;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $html = $this->page_of_first($teacher, $course, [
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1,
        ]);

        $this->assertMatchesRegularExpression(
            '#<li class="mod-agora-row mod-agora-row-current[^"]*"\s+aria-current="page"#',
            $html
        );
        $this->assertStringContainsString(get_string('streamscount', 'mod_agora', 2), $html);
        // Under the block heading the presenter has one muted line —
        // «Broadcasts: N»; there is no «Done k of M» row.
        $this->assertSame(1, substr_count($html, 'class="mod-agora-block-sub text-muted"'));

        // The learner of the same course gets both lines: the check above is not empty.
        $this->setUser($student);
        $cm = get_fast_modinfo($course, $student->id)->get_cm(
            (int) array_values(get_fast_modinfo($course, $student->id)->get_instances_of('agora'))[0]->id
        );
        global $DB, $OUTPUT;
        $agora = $DB->get_record('agora', ['id' => $cm->instance], '*', MUST_EXIST);
        $learner = $OUTPUT->render(new watch_page($agora, $cm, ''));
        $this->assertSame(2, substr_count($learner, 'class="mod-agora-block-sub text-muted"'));
    }

    /**
     * The list row subtitle takes up to two lines, the access condition — one.
     *
     * @return void
     */
    public function test_row_subtitle_takes_two_lines_and_condition_one(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $html = $this->page_of_first($student, $course, [
            'intro' => str_repeat('Long description of the neighboring lecture. ', 10),
            'introformat' => FORMAT_HTML,
            'showdescription' => 1,
        ]);

        $this->assertMatchesRegularExpression('#class="mod-agora-row-subtitle text-muted mod-agora-clamp-2"#', $html);
        $this->assertStringNotContainsString('mod-agora-row-subtitle text-muted mod-agora-clamp-1', $html);
    }

    /**
     * The presenter of this course is a learner of the neighbour one: the
     * neighbour's card target is chosen by their role there, that is the first
     * unviewed broadcast, not just the first one.
     *
     * @return void
     */
    public function test_card_target_follows_the_role_in_that_course(): void {
        global $CFG;

        $CFG->enablecompletion = 1;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $other = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'fullname' => 'Neighboring']);
        $this->getDataGenerator()->enrol_user($teacher->id, $other->id, 'student');
        $modules = [];
        foreach (['One', 'Two', 'Three'] as $i => $name) {
            $modules[] = $this->getDataGenerator()->create_module('agora', [
                'course' => $other->id,
                'name' => $name,
                'stream_name' => 'other-' . $i,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionview' => 1,
            ]);
        }
        // As a learner of the neighbour course they have already opened the first broadcast.
        $this->setUser($teacher);
        (new \completion_info(get_course($other->id)))->set_module_viewed(
            get_coursemodule_from_id('agora', $modules[0]->cmid),
            (int) $teacher->id
        );

        $html = $this->page_of_first($teacher, $course);

        $this->assertStringContainsString(get_string('nextup', 'mod_agora', 'Two'), $html);
        $this->assertStringContainsString('view.php?id=' . $modules[1]->cmid, $html);
    }

    /**
     * No more than the first twelve candidate courses are looked at: courses
     * beyond this limit give no cards, even if the first twelve have no targets.
     *
     * @return void
     */
    public function test_only_twelve_candidate_courses_are_looked_at(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $others = [];
        for ($i = 0; $i < 14; $i++) {
            $other = $this->getDataGenerator()->create_course(['fullname' => 'Course ' . $i]);
            $this->getDataGenerator()->enrol_user($student->id, $other->id, 'student');
            $others[(int) $other->id] = $other;
        }
        // The candidate order is the core's order (`enrol_get_my_courses`): the
        // first twelve get only hidden broadcasts, the last two — available ones.
        $this->setUser($student);
        $order = array_values(array_filter(array_keys(enrol_get_my_courses('id')), static function ($id) use ($others) {
            return isset($others[(int) $id]);
        }));
        foreach ($order as $index => $id) {
            $this->getDataGenerator()->create_module('agora', [
                'course' => $id,
                'name' => 'Broadcast ' . $index,
                'stream_name' => 'stream-' . $index,
                'visible' => $index < 12 ? 0 : 1,
            ]);
        }

        $html = $this->page_of_first($student, $course);

        $this->assertStringNotContainsString(
            get_string('othercourses', 'mod_agora'),
            $html,
            'Courses beyond twelve are not considered'
        );
    }
}
