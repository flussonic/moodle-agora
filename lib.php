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
 * Mandatory functions of the Agora broadcast course activity.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * What the activity can do out of the common Moodle features.
 *
 * @param string $feature FEATURE_* constant
 * @return mixed true/false/constant or null if the feature is unknown
 */
function agora_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_CONTENT;
        default:
            return null;
    }
}

/**
 * Creates the activity record.
 *
 * @param stdClass $data form data
 * @param mod_agora_mod_form|null $mform the form
 * @return int id of the new record
 */
function agora_add_instance($data, $mform = null) {
    global $DB;

    // A broadcast from a template is created before the activity record: a
    // central refusal propagates upwards, add_moduleinfo rolls back the
    // transaction, and no half-activity is left behind.
    if ((string) ($data->source_mode ?? '') === 'template') {
        $publish = null;
        $data->stream_name = \mod_agora\local\stream_creator::create(
            (string) ($data->newstreamtitle ?? ''),
            (string) ($data->template ?? ''),
            $publish
        );
        $data->created_by_plugin = 1;
        if ($publish !== null) {
            // The port and the key of the publication section: the key is a
            // secret, stored encrypted in the DB.
            $data->srt_port = $publish['port'];
            $data->srt_key = \core\encryption::encrypt($publish['key']);
        }
    } else if (!property_exists($data, 'created_by_plugin')) {
        // The form does not send this field; the value comes ready-made only
        // from a course copy and from the test generator.
        $data->created_by_plugin = 0;
    }

    $data->timecreated = time();
    $data->timemodified = time();
    $data->completion_minutes = agora_completion_minutes_from_form($data);
    if (!property_exists($data, 'has_phases')) {
        // Activities created after phases appeared live with phases; a
        // ready-made value comes only from the test generator (the MVP legacy
        // is set explicitly there). A backup does not carry the field — it is
        // its compatibility slice.
        $data->has_phases = 1;
    }
    // A value that arrived ready-made (a restore, the test generator) is not
    // re-asked from Agora.
    if (!property_exists($data, 'ondemand')) {
        $data->ondemand = \mod_agora\local\stream_config::ondemand((string) $data->stream_name);
    }

    $id = $DB->insert_record('agora', $data);
    \mod_agora\local\tombstones::lift((string) $data->stream_name);
    return $id;
}

/**
 * Updates the activity record.
 *
 * @param stdClass $data form data
 * @param mod_agora_mod_form|null $mform the form
 * @return bool
 */
function agora_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    $data->completion_minutes = agora_completion_minutes_from_form($data);
    if (!property_exists($data, 'ondemand')) {
        $data->ondemand = \mod_agora\local\stream_config::ondemand((string) $data->stream_name);
    }
    // The "create from a template" mode is unavailable on a saved activity,
    // and the form does not change the "broadcast created by the plugin"
    // flag.
    unset($data->created_by_plugin, $data->source_mode, $data->newstreamtitle, $data->template);

    $done = $DB->update_record('agora', $data);
    \mod_agora\local\tombstones::lift((string) $data->stream_name);
    return $done;
}

/**
 * Minutes of the completion condition from the form data: checkbox off — the
 * condition is disabled.
 *
 * @param stdClass $data form data
 * @return int minutes, 0 — the condition is disabled
 */
function agora_completion_minutes_from_form($data) {
    $enabled = !empty($data->completionminutesenabled);
    return $enabled ? (int) ($data->completion_minutes ?? 0) : 0;
}

/**
 * Deletes the activity record and revokes its live tickets.
 *
 * @param int $id record id
 * @return bool
 */
function agora_delete_instance($id) {
    global $DB;

    $agora = $DB->get_record('agora', ['id' => $id]);
    if (!$agora) {
        return false;
    }

    // Completing the broadcast towards deferred deletion: stop a running
    // recording, revoke the live tickets (viewing and publishing) below. A
    // failed completion is logged and does not block the deletion — the
    // scheduled task on deferred deletion finishes the mismatch. The context
    // is computed once for both completion steps (review R2, S-12).
    $needsfinish = !empty($agora->has_phases) && !empty($agora->started_at) && empty($agora->ended_at);
    if ($needsfinish || !empty($agora->srt_port)) {
        $cm = get_coursemodule_from_instance('agora', (int) $agora->id, (int) $agora->course, false, IGNORE_MISSING);
        $context = $cm ? context_module::instance((int) $cm->id) : context_system::instance();
    }
    if ($needsfinish) {
        \mod_agora\local\broadcast_lifecycle::finish($agora, $context, 'system', 0);
    }

    // Removing the publication section of a broadcast that was not ended by
    // the button or the task (deletion in the "planned" phase): the port in
    // the registry is freed only after a confirmed removal. A refusal — to
    // the log, the deletion goes on.
    if (!empty($agora->srt_port)) {
        try {
            \mod_agora\local\publish_manager::remove_section($agora);
        } catch (\mod_agora\local\agora_exception $error) {
            \mod_agora\local\failure::log($error, $context, 'opremovepublish', 'admin');
        }
    }

    \mod_agora\local\grant_manager::revoke_where(['agoraid' => $agora->id]);

    // The deferred deletion is written here, not in the event observer:
    // deleting a whole course does not publish the course_module_deleted
    // event, while this function is reached.
    if (!empty($agora->created_by_plugin)) {
        \mod_agora\local\tombstones::put((string) $agora->stream_name, (int) $agora->id);
    }

    $DB->delete_records('agora_grants', ['agoraid' => $agora->id]);
    $DB->delete_records('agora_watch', ['agoraid' => $agora->id]);
    $DB->delete_records('agora', ['id' => $agora->id]);
    return true;
}

/**
 * Activity information for the course page: dates and completion rules.
 *
 * @param stdClass $coursemodule the course module record
 * @return cached_cm_info|null
 */
function agora_get_coursemodule_info($coursemodule) {
    global $CFG, $DB;

    $fields = 'id, name, intro, introformat, stream_name, display_mode, completion_minutes, timestart, ondemand, '
        . 'has_phases, started_at, ended_at';
    $agora = $DB->get_record('agora', ['id' => $coursemodule->instance], $fields);
    if (!$agora) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $agora->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('agora', $agora, $coursemodule->id, false);
    }
    $info->customdata['timestart'] = (int) $agora->timestart;
    // The broadcast name is needed by the state badge and the preview:
    // otherwise every activity of the course page would go to the database
    // for it with a separate query.
    $info->customdata['streamname'] = (string) $agora->stream_name;
    // The activity phase for the course theme badge: empty for the legacy —
    // the badge reads the broadcast state, as before.
    $info->customdata['phase'] = \mod_agora\local\broadcast_phase::of($agora) ?? '';
    // The subtitle of the row on the course page — the activity description
    // in one line. The context is set explicitly: the core collects activity
    // information for foreign courses too (the side column of the player
    // page) before `$PAGE->set_context()`, and `format_text()` without a
    // context takes it from `$PAGE` and in developer mode throws the page
    // down with a "$PAGE->context was not set" exception.
    $intro = trim(html_to_text(format_text((string) $agora->intro, (int) $agora->introformat, [
        'filter' => false,
        'context' => context_module::instance($coursemodule->id),
    ]), 0, false));
    $info->customdata['subtitle'] = $intro === '' ? '' : shorten_text($intro, 140);
    // The `COMPLETION_*` constants live in the completion library, and the
    // core collects activity information in cron too (rebuilding the course
    // cache from a task), where nobody included it.
    require_once($CFG->libdir . '/completionlib.php');
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionminutes'] = (int) $agora->completion_minutes;
    }
    return $info;
}

/**
 * Descriptions of the enabled completion rules — for the form and reports.
 *
 * @param cm_info|stdClass $cm the course module
 * @return array description strings
 */
function agora_get_completion_active_rule_descriptions($cm) {
    if (empty($cm->customdata['customcompletionrules'])) {
        return [];
    }
    $descriptions = [];
    foreach ($cm->customdata['customcompletionrules'] as $key => $value) {
        if ($key === 'completionminutes' && !empty($value)) {
            $descriptions[] = get_string('completionminutesdesc', 'mod_agora', $value);
        }
    }
    return $descriptions;
}

/**
 * Complements the activity card on the course page with a preview frame.
 *
 * The frame is served through `pluginfile`, where the plugin itself checks
 * the rights; here there is only the decision whether to draw the picture.
 *
 * @param cm_info $cm the course module
 * @return void
 */
function agora_cm_info_view(cm_info $cm) {
    global $OUTPUT, $USER, $PAGE;

    // The handler builds a list row of the course page: it calls `$OUTPUT`
    // (and hence the theme and the `$PAGE` context), `has_capability()` with
    // the implicit `$USER` and a synchronous state request to central. All of
    // this is good only for its own page. The side column of the player page
    // builds the modinfo of foreign courses — sometimes for another user —
    // and nobody draws a list row there: without this exit one call to
    // `get_formatted_content()` of a neighbour would go to Agora for every
    // broadcast.
    if ((int) $cm->get_modinfo()->get_user_id() !== (int) $USER->id) {
        return;
    }
    if (empty($PAGE->course->id) || (int) $cm->course !== (int) $PAGE->course->id) {
        return;
    }

    $streamname = (string) ($cm->customdata['streamname'] ?? '');
    // The `uservisibleoncoursepage` is not a field but a method: an activity
    // closed by a restriction with a shown condition still gets into the
    // course list, and a row without a card would look foreign in it.
    if ($streamname === '' || !$cm->is_visible_on_course_page()) {
        return;
    }

    // A row like in a video service list: the frame on the left (it takes the
    // place of the module icon, see styles.css), the name, the date and the
    // subtitle on the right.
    $subtitle = (string) ($cm->customdata['subtitle'] ?? '');
    $afterlink = '';
    if ($subtitle !== '') {
        $afterlink .= html_writer::div(s($subtitle), 'mod-agora-subtitle text-muted');
    }
    if (has_capability('moodle/course:manageactivities', $cm->context)) {
        $afterlink .= html_writer::div(s($streamname), 'mod-agora-meta text-muted small');
    }
    if ($afterlink !== '') {
        $cm->set_after_link($afterlink);
    }

    // The activity is closed by a restriction: it cannot be watched, so
    // neither a frame nor the state — an empty card of the same size with a
    // lock and without a link. The state is not asked from central at all
    // here.
    if (!$cm->uservisible) {
        $cm->set_content($OUTPUT->render(new \mod_agora\output\preview_card(
            null,
            null,
            \mod_agora\output\preview_card::SIZE_COURSE,
            null,
            true,
            true
        )), true);
        return;
    }

    // The frame on the course page is a compact card-link to the activity,
    // not a full-width picture: the learner recognises the broadcast by the
    // frame and clicks it. The card is decorative: it duplicates the link
    // from the name, and the stock `activitybadge` announces the state to the
    // screen reader.
    $state = \mod_agora\local\stream_state::for_stream($streamname);
    $cm->set_content($OUTPUT->render(new \mod_agora\output\preview_card(
        \mod_agora\local\preview::url($cm->context, $streamname, $state),
        $state,
        \mod_agora\output\preview_card::SIZE_COURSE,
        $cm->url,
        true
    )), true);
}

/**
 * Serves the preview frame of a broadcast. For `mod_*` areas the core checks
 * neither the login nor the rights — the plugin does both checks.
 *
 * @param stdClass $course the course
 * @param stdClass|cm_info $cm the course module
 * @param context $context the context
 * @param string $filearea the file area
 * @param array $args the rest of the address
 * @param bool $forcedownload whether to serve as an attachment
 * @param array $options serving options
 * @return void
 */
function mod_agora_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB;

    if ($context->contextlevel != CONTEXT_MODULE || $filearea !== 'preview') {
        send_file_not_found();
    }

    // There is nowhere to redirect a picture to: a guest and a not-logged-in
    // user need a refusal, not an enrolment page of the course. So the login
    // is checked with redirecting forbidden, and any refusal of it becomes a
    // "file not found".
    try {
        require_login($course, false, $cm, false, true);
    } catch (\Throwable $error) {
        send_file_not_found();
    }
    if (!has_capability('mod/agora:view', $context)) {
        send_file_not_found();
    }

    $cminfo = get_fast_modinfo($course)->get_cm($cm->id);
    if (!$cminfo->uservisible) {
        send_file_not_found();
    }

    $agora = $DB->get_record('agora', ['id' => $cm->instance], 'id, stream_name', MUST_EXIST);

    // The address of a side column neighbour is marked with the `cached`
    // segment: the state for such a frame is taken only from the cache — no
    // state, a short 404 and not a single request. After that the path is the
    // usual one: the frame cache, the preview service ticket, the frame
    // request to the node. There is no synchronous state request here —
    // exactly what the activity page promised not to do.
    if (in_array(\mod_agora\local\preview::CACHED_MARK, $args, true)) {
        $streamname = (string) $agora->stream_name;
        $state = \mod_agora\local\stream_state::cached([$streamname])[$streamname] ?? null;
        if ($state === null) {
            \mod_agora\local\preview::send_missing();
            return;
        }
        $frame = \mod_agora\local\preview::frame($streamname, $context, $state);
        if ($frame === null) {
            \mod_agora\local\preview::send_missing();
            return;
        }
    } else {
        $frame = \mod_agora\local\preview::frame((string) $agora->stream_name, $context);
        if ($frame === null) {
            send_file_not_found();
        }
    }

    $send = \mod_agora\local\preview::send_options();
    send_file(
        $frame,
        $send['filename'],
        $send['lifetime'],
        $send['filter'],
        $send['pathisstring'],
        $send['forcedownload'],
        $send['mimetype']
    );
}

/**
 * Connection checks for the "System status" report.
 *
 * @return array \core\check\check objects
 */
function mod_agora_status_checks() {
    return [
        new \mod_agora\check\network(),
        new \mod_agora\check\playerrole(),
        new \mod_agora\check\adminrole(),
        new \mod_agora\check\openplayback(),
        new \mod_agora\check\states_refresh(),
        new \mod_agora\check\record_routing(),
        new \mod_agora\check\publish_setup(),
    ];
}

/**
 * The course module the comment subsystem request belongs to.
 *
 * The request does not always carry the activity record itself:
 * `comment_ajax.php` puts a flat record without the `uservisible` field into
 * `cm`, and the comment reading web service puts nothing. The only thing
 * that is always there is the activity context, and the course information
 * returns a real `cm_info` for it. This does not hit the database:
 * `get_fast_modinfo()` answers from the request cache.
 *
 * @param stdClass $commentparam request parameters
 * @return cm_info|null null — the context is not of an Agora broadcast activity
 */
function mod_agora_comment_cm($commentparam) {
    global $USER;

    $context = $commentparam->context ?? null;
    if (!$context instanceof context_module) {
        return null;
    }
    try {
        $cm = get_fast_modinfo((int) $commentparam->courseid, (int) $USER->id)->get_cm((int) $context->instanceid);
    } catch (moodle_exception $error) {
        return null;
    }
    return $cm->modname === 'agora' ? $cm : null;
}

/**
 * Validation of a comment subsystem request: whether the area is ours and
 * the activity is ours.
 *
 * The core calls this check from `can_view()` and `can_post()` several times
 * per page view, so it does not hit the database: the activity id is taken
 * from the course information (`cm->instance`), and that answers from the
 * request cache.
 *
 * @param stdClass $commentparam request parameters: context, courseid, cm,
 *                               commentarea, itemid
 * @return bool
 */
function mod_agora_comment_validate($commentparam) {
    if ($commentparam->commentarea !== 'stream') {
        throw new comment_exception('invalidcommentarea');
    }
    $cm = mod_agora_comment_cm($commentparam);
    if ($cm === null || (int) $commentparam->itemid !== (int) $cm->instance) {
        throw new comment_exception('invalidcommentitemid');
    }
    // The plugin switch must reject a direct request too: the core looks
    // only at the `usecomments` site setting and does not know about the
    // plugin.
    if (!get_config('mod_agora', 'comments')) {
        throw new comment_exception('commentsoff', 'mod_agora');
    }
    return true;
}

/**
 * Plugin permissions for comments: viewing and posting.
 *
 * The core multiplies the answer by its own `moodle/comment:view` and
 * `moodle/comment:post`, so here there is only the own capability and the
 * activity availability. The availability is taken from the course
 * information for this user: in an AJAX request `cm` is a flat
 * `get_coursemodule_from_id()` record that has no `uservisible` field at
 * all, and in the reading web service `cm` does not arrive at all.
 *
 * @param stdClass $commentparam request parameters
 * @return array ['post' => bool, 'view' => bool]
 */
function mod_agora_comment_permissions($commentparam) {
    $cm = mod_agora_comment_cm($commentparam);
    if ($cm === null || !$cm->uservisible) {
        return ['post' => false, 'view' => false];
    }

    $canview = has_capability('mod/agora:view', $commentparam->context);
    return [
        'post' => $canview && has_capability('mod/agora:comment', $commentparam->context),
        'view' => $canview,
    ];
}

/**
 * The "activity scene" fragment for an open page where a broadcast started.
 *
 * It is called by the core (`core_get_fragment`) when the `mod_agora/watch`
 * module learned from the polling that the broadcast went live: the page
 * swaps the placeholder for the live player without a reload. The core has
 * already checked the course login and the activity availability
 * (`validate_context()` → `require_login()` with the activity); here — the
 * own capability and `uservisible` once more, from the course information
 * for this user. The ticket is issued the same way as on the page
 * (`grant_manager::for_user()`): the live ticket of the pair is reused.
 *
 * @param array $args fragment arguments: `context` — the activity context
 * @return string the scene HTML
 */
function mod_agora_output_fragment_scene(array $args): string {
    global $DB, $USER;

    $context = $args['context'] ?? null;
    if (!$context instanceof context_module) {
        throw new coding_exception('The scene fragment expects the activity context');
    }
    require_capability('mod/agora:view', $context);

    [$course, $cm] = get_course_and_cm_from_cmid($context->instanceid, 'agora');
    if (!$cm->uservisible) {
        throw new require_login_exception(get_string('errnotavailable', 'mod_agora'));
    }
    $agora = $DB->get_record('agora', ['id' => $cm->instance], '*', MUST_EXIST);

    return \mod_agora\local\player_view::scene($agora, $cm, (int) $USER->id);
}
