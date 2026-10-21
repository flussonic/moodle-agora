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
 * The Agora broadcast activity page.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
// The "watch the recording" transition from the signal loss placeholder.
$wantrecord = (bool) optional_param('record', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('agora', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$agora = $DB->get_record('agora', ['id' => $cm->instance], '*', MUST_EXIST);

// The page address is set before login: with a hidden course `require_login()`
// prints the notice itself, and Moodle requires `set_url()` to have been
// called by then — otherwise a debugging message leaks into the output.
$PAGE->set_url('/mod/agora/view.php', ['id' => $cm->id]);

// The right to watch: course login, the activity capability and availability
// by restrictions.
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/agora:view', $context);

$cminfo = get_fast_modinfo($course)->get_cm($cm->id);
if (!$cminfo->uservisible) {
    throw new require_login_exception(get_string('errnotavailable', 'mod_agora'));
}

// Broadcast control actions: "Start broadcast" and "End broadcast", showing
// and rotating the key. They run before the view event and completion: a
// button press must not count as viewing the activity. Every action requires
// the broadcast capability; without it — 403 without a single call to Agora
// (D11). Actions change state — we accept only POST (sesskey in the form
// body): accidentally opening a GET link once is enough to irreversibly end
// the broadcast (review R2, Ф-1).
$action = optional_param('action', '', PARAM_ALPHAEXT);
if ($action !== '') {
    require_capability('mod/agora:broadcast', $context);
    require_sesskey();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new moodle_exception('errunknownaction', 'mod_agora');
    }
    $notice = null;
    if ($action === 'startbroadcast') {
        $notice = \mod_agora\local\broadcast_lifecycle::start($agora, $context, (int) $USER->id);
    } else if ($action === 'endbroadcast') {
        $notice = \mod_agora\local\broadcast_lifecycle::finish($agora, $context, 'button', (int) $USER->id);
    } else if ($action === 'revealkey') {
        // Revealing the key is an explicit action with a log event (without
        // the value).
        $SESSION->mod_agora_revealkey[(int) $cm->id] = true;
        \mod_agora\event\key_revealed::create([
            'objectid' => (int) $agora->id,
            'context' => $context,
            'relateduserid' => (int) $USER->id,
            'other' => ['stream' => (string) $agora->stream_name],
        ])->trigger();
    } else if ($action === 'hidekey') {
        // Hiding the key reveals nothing: no log event (D12).
        unset($SESSION->mod_agora_revealkey[(int) $cm->id]);
    } else if ($action === 'rotatekey') {
        if (empty($agora->created_by_plugin) || (int) ($agora->srt_port ?? 0) <= 0) {
            // A foreign or removed section: rotation does not send a PATCH
            // with port 0 into the broadcast config (review R2, М-3).
            throw new moodle_exception('errunknownaction', 'mod_agora');
        }
        try {
            \mod_agora\local\publish_manager::rotate_key($agora);
            \mod_agora\event\key_rotated::create([
                'objectid' => (int) $agora->id,
                'context' => $context,
                'relateduserid' => (int) $USER->id,
                'other' => ['stream' => (string) $agora->stream_name],
            ])->trigger();
        } catch (\mod_agora\local\agora_exception $error) {
            // A failed rotation changes nothing: the previous key and row
            // stay intact.
            \mod_agora\local\failure::log($error, $context, 'oppatchpublish', 'admin');
            $notice = \mod_agora\local\failure::message($error, $context);
        }
    } else {
        throw new moodle_exception('errunknownaction', 'mod_agora');
    }
    redirect(
        $PAGE->url,
        $notice ?? '',
        null,
        $notice !== null ? \core\output\notification::NOTIFY_ERROR : null
    );
}

$PAGE->set_title(format_string($agora->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_activity_record($agora);

\mod_agora\event\course_module_viewed::create([
    'objectid' => $agora->id,
    'context' => $context,
])->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// The description draws the page body, so it is removed from the module
// header. The activity name stays with the header: Boost prints it as the
// page heading together with the icon, and an own heading under the player
// would be the second one. The start date and completion — also in the header
// and only there.
$PAGE->activityheader->set_attrs(['description' => '']);

// Without this call the "no JS" comment branch is dead: the core prints the
// link to it anyway, and only `comment::init()` reads the request parameters.
require_once($CFG->dirroot . '/comment/lib.php');
comment::init();

// The page module: the new comment field on top, "Show all" for the broadcast
// list on a narrow screen and the "has the broadcast started" polling on a
// page without a live player. Without it the page stays functional — the list
// is full, the comment field is below the feed, and a started broadcast is
// visible after a reload.
$PAGE->requires->js_call_amd('mod_agora/watch', 'init');

echo $OUTPUT->header();

// The page reads the state of its own broadcast once for everything: the
// scene, the badge and the transitions are built from it. Older than 15
// seconds — the page asks central itself, under a per-broadcast lock
// (`stream_state::for_page()`).
$state = \mod_agora\local\stream_state::for_page((string) $agora->stream_name);
$scene = \mod_agora\local\player_view::scene($agora, $cminfo, (int) $USER->id, $wantrecord, $state);
echo $OUTPUT->render(new \mod_agora\output\watch_page($agora, $cminfo, $scene, $wantrecord, $state));

echo $OUTPUT->footer();
