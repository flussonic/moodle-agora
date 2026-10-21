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
 * The publisher: broadcasting from the browser into the stream over WHIP.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('agora', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$agora = $DB->get_record('agora', ['id' => $cm->instance], '*', MUST_EXIST);

$PAGE->set_url('/mod/agora/publish.php', ['id' => $cm->id]);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/agora:view', $context);

$cminfo = get_fast_modinfo($course)->get_cm($cm->id);
if (!$cminfo->uservisible) {
    throw new require_login_exception(get_string('errnotavailable', 'mod_agora'));
}

// The broadcast capability: without it the page does not belong to the
// person, and there will be no call to Agora (D11).
require_capability('mod/agora:broadcast', $context);

// Broadcast control actions — the same as on the activity page (D4): "Start
// broadcast" and "End broadcast" call the shared lifecycle. The "Broadcast"
// toggle sends them by AJAX with `ajax=1`: the response is a 204 without a
// body (D10), the page does not reload and the live signal does not die; it
// catches up on the phase by polling the state. Without `ajax=1` — the
// former return to the publisher page with a notice. Actions change state —
// we accept only POST (sesskey in the body): accidentally opening a GET link
// once is enough to irreversibly end the broadcast (review R2, Ф-1).
$action = optional_param('action', '', PARAM_ALPHAEXT);
$ajax = (bool) optional_param('ajax', 0, PARAM_BOOL);
if ($action !== '') {
    require_sesskey();
    // In the CLI copy of the page REQUEST_METHOD may be absent: "not POST" is
    // an honest refusal, not a PHP warning.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new moodle_exception('errunknownaction', 'mod_agora');
    }
    $notice = null;
    if ($action === 'startbroadcast') {
        $notice = \mod_agora\local\broadcast_lifecycle::start($agora, $context, (int) $USER->id);
    } else if ($action === 'endbroadcast') {
        $notice = \mod_agora\local\broadcast_lifecycle::finish($agora, $context, 'button', (int) $USER->id);
    } else {
        throw new moodle_exception('errunknownaction', 'mod_agora');
    }
    if ($ajax) {
        // Success — 204 No Content; a lifecycle refusal — 409 with the reason
        // text, so the toggle names it instead of swallowing it silently. In
        // a CLI test run the headers are unavailable (the output has already
        // started) — the code is set when possible; the response contract is
        // the same: an empty body of success.
        if (!headers_sent()) {
            http_response_code($notice === null ? 204 : 409);
        }
        echo $notice ?? '';
        return;
    }
    redirect(
        $PAGE->url,
        $notice ?? '',
        null,
        $notice !== null ? \core\output\notification::NOTIFY_ERROR : null
    );
}

// The publisher lives only on phased activities created by the plugin, and
// only in the "planned" and "running" phases: an ended one has the
// publication section removed, and for a "take an existing stream" broadcast
// the plugin does not manage the section (D3).
$phase = \mod_agora\local\broadcast_phase::of($agora);
if (
    empty($agora->created_by_plugin) || ($phase !== \mod_agora\local\broadcast_phase::PLANNED
        && $phase !== \mod_agora\local\broadcast_phase::ONGOING)
) {
    redirect(new moodle_url('/mod/agora/view.php', ['id' => $cm->id]));
}

$whipbase = trim((string) get_config('mod_agora', 'whipaddress'));
$ticketttl = (int) get_config('mod_agora', 'publishticketttl') ?: 4 * HOURSECS;

// The state of the "Broadcast" toggle at server render — from the phase and
// the signal (D5); after that the state polling of the `mod_agora/publisher`
// module keeps it up to date.
$state = \mod_agora\local\stream_state::for_page((string) $agora->stream_name);
$planned = $phase === \mod_agora\local\broadcast_phase::PLANNED;

$PAGE->set_title(format_string($agora->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_activity_record($agora);
$PAGE->activityheader->set_attrs(['description' => '']);

echo $OUTPUT->header();

echo $OUTPUT->render_from_template('mod_agora/publisher', [
    'configured' => $whipbase !== '',
    'cmid' => (int) $cm->id,
    'stream' => (string) $agora->stream_name,
    'ticketttl' => $ticketttl,
    'sesskey' => sesskey(),
    'publishurl' => (new moodle_url('/mod/agora/publish.php'))->out(false),
    'planned' => $planned,
    'ongoing' => !$planned,
    'signallive' => $state->state === \mod_agora\local\stream_state::LIVE,
    'phasetext' => $planned
        ? get_string('badgeplanned', 'mod_agora')
        : get_string('publisherphaseongoing', 'mod_agora'),
    'recordurl' => (new moodle_url('/mod/agora/view.php', ['id' => $cm->id, 'record' => 1]))->out(false),
]);

if ($whipbase !== '') {
    $PAGE->requires->js_call_amd('mod_agora/publisher', 'init');
}

echo $OUTPUT->footer();
