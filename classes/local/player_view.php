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
 * The activity page body: a player with a ticket or a word placeholder.
 *
 * The viewing right is checked by the caller before the call — `view.php` or
 * the fragment `mod_agora_output_fragment_scene()`: an already admitted user
 * arrives here. Account secrets never reach the markup — only the ticket JWT
 * in the iframe address goes out.
 *
 * What to show is decided by two facts: the stream state (six values; for the
 * activity page — no older than `stream_state::PAGE_FRESH` seconds) and the
 * activity display mode, which narrows the state.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class player_view {
    /**
     * How often the open page asks Moodle about the stream state, seconds.
     *
     * A page without a live player, once in this many seconds (plus random up
     * to `POLL_JITTER`, so that a hundred pages opened for the start of a
     * lecture do not ask in chorus), learns whether the broadcast has started.
     * The answer comes from the state cache; to central — no more than once
     * per `stream_state::PAGE_FRESH` per stream.
     */
    const POLL_INTERVAL = 15;

    /** The random addition to the polling interval, seconds. */
    const POLL_JITTER = 5;

    /**
     * The scene: warnings, a player or a word placeholder.
     *
     * There are no transitions here — `actions()` serves them: the page puts
     * them into the metadata row as buttons, not bare links under the player.
     *
     * @param \stdClass $agora the activity record
     * @param \cm_info $cm course module
     * @param int $userid learner
     * @param bool $wantrecord the learner pressed "watch the recording"
     * @param stream_state|null $state the stream state already read by the page;
     *     null — read it here (`stream_state::for_page()`)
     * @return string HTML
     */
    public static function scene(
        \stdClass $agora,
        \cm_info $cm,
        int $userid,
        bool $wantrecord = false,
        ?stream_state $state = null
    ): string {
        global $OUTPUT;

        $context = \context_module::instance($cm->id);
        $html = self::open_playback_warning($context);
        $canmanage = has_capability('mod/agora:broadcast', $context);

        $state = $state ?? stream_state::for_page($agora->stream_name);
        if ($state->state === stream_state::UNKNOWN) {
            return $html . self::unknown($state, $agora, $context);
        }

        try {
            $html .= self::body($agora, $cm, $userid, $state, $wantrecord, $canmanage);
        } catch (agora_exception $error) {
            failure::log($error, $context, 'opcreategrant', 'player');
            $html .= self::framed(
                $OUTPUT->notification(failure::message($error, $context), \core\output\notification::NOTIFY_ERROR)
            );
        }
        return $html;
    }

    /**
     * A placeholder in a 16:9 field — the same height as the player.
     *
     * The first row of the page grid is set by the scene, so the scene height
     * must not depend on the stream state: without this wrapper the
     * placeholder would be an ordinary two-line notice, and the stream list
     * next to it would collapse.
     *
     * @param string $inner markup inside the field
     * @param \moodle_url|null $frame the preview frame address; null — no frame
     * @return string HTML
     */
    private static function framed(string $inner, ?\moodle_url $frame = null): string {
        $html = '';
        if ($frame !== null) {
            $html .= \html_writer::empty_tag('img', [
                'src' => $frame->out(false),
                // The frame is decorative: the same words stand next to it as without it.
                'alt' => '',
                'class' => 'mod-agora-preview mod-agora-stage-frame',
            ]);
        }
        $html .= \html_writer::div($inner, 'mod-agora-stage-note');
        return \html_writer::div($html, 'mod-agora-stage-placeholder');
    }

    /**
     * The page transitions: "watch the recording", "back to the broadcast", the Agora console.
     *
     * Returns data, not markup: the page draws them as buttons in the metadata
     * row, and `render()` — as the old links under the player.
     *
     * @param \stdClass $agora the activity record
     * @param \cm_info $cm course module
     * @param bool $wantrecord the learner pressed "watch the recording"
     * @param stream_state|null $state the stream state already read by the page
     * @return array a list of ['key' => …, 'url' => \moodle_url, 'label' => …]
     */
    public static function actions(
        \stdClass $agora,
        \cm_info $cm,
        bool $wantrecord = false,
        ?stream_state $state = null
    ): array {
        $context = \context_module::instance($cm->id);
        $state = $state ?? stream_state::for_page($agora->stream_name);
        $actions = [];

        $broadcast = self::broadcast_actions($agora, $cm, $state);
        if ($broadcast !== null) {
            $actions[] = $broadcast + ['form' => true, 'sesskey' => sesskey()];
        }

        // The `unknown` state has no transitions: where to lead is unknown.
        if ($state->state !== stream_state::UNKNOWN) {
            $plan = self::plan($agora, $state, $wantrecord);
            if ($plan['offerrecord']) {
                $actions[] = [
                    'key' => 'record',
                    'url' => new \moodle_url('/mod/agora/view.php', ['id' => $cm->id, 'record' => 1]),
                    'label' => get_string('watchrecord', 'mod_agora'),
                ];
            }
            if ($plan['backtolive']) {
                $actions[] = [
                    'key' => 'live',
                    'url' => new \moodle_url('/mod/agora/view.php', ['id' => $cm->id]),
                    'label' => get_string('backtolive', 'mod_agora'),
                ];
            }
        }

        // The Agora console — for the site administrator: they need the stream
        // name and the placement to fix the connectivity. We do not show the
        // link to the teacher — no console operations are required from the
        // course (user requirement).
        $host = (string) get_config('mod_agora', 'centralurl');
        if ($host !== '' && is_siteadmin()) {
            $actions[] = [
                'key' => 'console',
                'url' => playback::console_url($host, $agora->stream_name),
                'label' => get_string('openinconsole', 'mod_agora'),
            ];
        }
        return $actions;
    }

    /**
     * The broadcast leading button: "Start broadcast" in the "planned" phase,
     * "End broadcast" in the "running" phase; null — no button.
     *
     * The buttons live only on phased activities created by the plugin: the
     * plugin does not lead a "take an existing" stream and the legacy ones
     * (D3). The right is to lead the broadcast (`mod/agora:broadcast`):
     * without it there are no buttons, and a direct action request answers 403
     * without a call to Agora (D11).
     *
     * @param \stdClass $agora the activity record
     * @param \cm_info $cm course module
     * @param stream_state $state stream state
     * @return array|null ['key' => …, 'url' => …, 'label' => …] plus optionally
     *     'primary', 'disabled', 'hint', 'confirm'
     */
    private static function broadcast_actions(\stdClass $agora, \cm_info $cm, stream_state $state): ?array {
        $context = \context_module::instance($cm->id);
        if (!has_capability('mod/agora:broadcast', $context)) {
            return null;
        }
        if (empty($agora->created_by_plugin)) {
            return null;
        }
        $phase = broadcast_phase::of($agora);
        if ($phase === broadcast_phase::PLANNED) {
            if ($state->state === stream_state::LIVE) {
                return [
                    'key' => 'start',
                    // The POST action name: the form sends it as a hidden field (Ф-1).
                    'name' => 'startbroadcast',
                    'url' => new \moodle_url('/mod/agora/view.php', [
                        'id' => $cm->id,
                        'action' => 'startbroadcast',
                        'sesskey' => sesskey(),
                    ]),
                    'label' => get_string('startbroadcast', 'mod_agora'),
                    'primary' => true,
                ];
            }
            // Without a signal the button is inactive and explains what it waits for (D4).
            return [
                'key' => 'startdisabled',
                'url' => null,
                'label' => get_string('startbroadcast', 'mod_agora'),
                'disabled' => true,
                'hint' => get_string('startbroadcastnosignal', 'mod_agora'),
            ];
        }
        if ($phase === broadcast_phase::ONGOING) {
            // The irreversibility confirmation — on the button: the recording
            // is renamed to the activity name, the port is freed, the
            // broadcast ends. The text is already in the confirm() format: a
            // JSON string escaped for an attribute.
            return [
                'key' => 'end',
                'name' => 'endbroadcast',
                'url' => new \moodle_url('/mod/agora/view.php', [
                    'id' => $cm->id,
                    'action' => 'endbroadcast',
                    'sesskey' => sesskey(),
                ]),
                'label' => get_string('endbroadcast', 'mod_agora'),
                'confirm' => s(json_encode(get_string('endbroadcastconfirm', 'mod_agora'))),
            ];
        }
        return null;
    }

    /**
     * The "How to broadcast" block: connection string, port state, show/rotate.
     *
     * Visible only to the bearer of the broadcast leading right (temporarily —
     * the course editing right) and only in the "planned" and "running"
     * phases: an ended broadcast has the section removed, there is nowhere to
     * broadcast. Legacy activities do not know the block at all.
     *
     * A "take an existing" stream has a read-only block: the string is read on
     * the fly from the stream config (the API serves the key), there is no
     * rotation; no section — no block.
     *
     * @param \stdClass $agora the activity record
     * @param \cm_info $cm course module
     * @param bool $canmanage the viewer leads the broadcast
     * @return array data for the template; 'show' => false — no block
     */
    public static function publish_block(\stdClass $agora, \cm_info $cm, bool $canmanage): array {
        $empty = ['show' => false];
        if (!$canmanage) {
            return $empty;
        }
        $phase = broadcast_phase::of($agora);
        if ($phase !== broadcast_phase::PLANNED && $phase !== broadcast_phase::ONGOING) {
            return $empty;
        }

        $owned = !empty($agora->created_by_plugin);
        $port = (int) ($agora->srt_port ?? 0);
        $key = null;
        if ($owned) {
            if ($port <= 0) {
                // The section is removed (the completion is done) or was never created.
                return $empty;
            }
            $key = publish_manager::key_of($agora);
        } else {
            // A "take an existing" stream: read the section from the config on
            // the fly, without storing it. A read refusal — the block is not
            // drawn, the cause is in the log.
            try {
                $config = connection::client()->stream_config((string) $agora->stream_name);
            } catch (agora_exception $error) {
                failure::log(
                    $error,
                    \context_module::instance($cm->id),
                    'opstreamconfig',
                    'admin'
                );
                return $empty;
            }
            $section = $config['srt_publish'] ?? null;
            if (!is_array($section)) {
                return $empty;
            }
            $port = (int) ($section['port'] ?? 0);
            $key = isset($section['passphrase']) ? (string) $section['passphrase'] : null;
            if ($port <= 0) {
                return $empty;
            }
        }

        // The key in the markup — only by the explicit "show" action (with an event).
        $revealed = self::publish_revealed((int) $cm->id);
        $address = '';
        $portstate = 'unknown';
        $portmessage = '';
        try {
            $summary = connection::client()->get_srt_ports();
            $address = publish_manager::publish_address($summary);
            [$portstate, $portmessage] = self::port_state($summary, (string) $agora->stream_name, $port);
        } catch (agora_exception $error) {
            failure::log($error, \context_module::instance($cm->id), 'opsrtports', 'admin');
        }

        return [
            'show' => true,
            'title' => get_string('publishblocktitle', 'mod_agora'),
            'cmid' => (int) $cm->id,
            'sesskey' => sesskey(),
            'hasstring' => $address !== '',
            // The whole connection string — the address, the port and the key —
            // is hidden by default: it is not in the HTML until it is shown by
            // an explicit action (user requirement: the masked string was
            // copy-pasted by mistake). After showing, the string goes into the
            // input whole, next to it — "Hide the address" (D12).
            'connection' => $revealed ? publish_manager::connection_string($address, $port, $key) : null,
            'hidden' => !$revealed,
            'copyhint' => get_string('publishcopyhint', 'mod_agora'),
            'revealed' => $revealed,
            'reveallabel' => get_string('publishrevealkey', 'mod_agora'),
            'hidelabel' => get_string('publishhidekey', 'mod_agora'),
            // Rotation — only for the plugin's own section and only with the
            // string shown: changing the key without seeing the address is
            // senseless; the plugin does not change a foreign section (D3),
            // and the read-only block has no button at all.
            'canrotate' => $owned && $revealed,
            // The publisher — only for the plugin's phased activities (М-4):
            // for a "take an existing" stream the publish.php page honestly
            // redirects back, and there must be no dead transition in the
            // block. The page itself decides by the phase and explains what is
            // missing.
            //
            // FROZEN 2026-10-16 (#65205): browser broadcasting is removed from
            // the interface by the owner's decision — the button is hidden,
            // the code and the publish.php page are kept and reachable by the
            // direct address. To unfreeze: bring back the commented version
            // below and the `publish_block_test` for the button.
            'browserurl' => '',
            // The frozen-out version of the empty value above, pasted back on
            // unfreeze:
            // 'browserurl' => $owned ? (new \moodle_url('/mod/agora/publish.php', ['id' => $cm->id]))->out(false) : '',
            // the rest of the publish block stays as it is.
            'browserlabel' => get_string('publishinbrowser', 'mod_agora'),
            'rotatelabel' => get_string('publishrotatekey', 'mod_agora'),
            'rotateconfirm' => s(json_encode(get_string('publishrotateconfirm', 'mod_agora'))),
            'obshelp' => get_string('publishobshelp', 'mod_agora'),
            'noaddress' => get_string('publishnoaddress', 'mod_agora'),
            'showportstate' => $portmessage !== '',
            'portmessage' => $portmessage,
            'portclass' => 'mod-agora-port-' . $portstate,
        ];
    }

    /**
     * The "show the key" flag on this page: set by the "show the key" action,
     * lives in the session and dies with it.
     *
     * @param int $cmid course module
     * @return bool
     */
    public static function publish_revealed(int $cmid): bool {
        global $SESSION;

        return !empty($SESSION->mod_agora_revealkey[$cmid]);
    }

    /**
     * The publish port state from the summary: listening / not up with a cause /
     * could not be obtained.
     *
     * @param array $summary the `GET /srt-ports` body
     * @param string $streamname stream name
     * @param int $port the activity publish port
     * @return array [state, string for the block]
     */
    private static function port_state(array $summary, string $streamname, int $port): array {
        $info = null;
        foreach (($summary['ports'] ?? []) as $candidate) {
            if (
                (int) ($candidate['port'] ?? 0) === $port
                && ($candidate['kind'] ?? '') === 'publish'
                && (string) ($candidate['stream'] ?? '') === $streamname
            ) {
                $info = $candidate;
                break;
            }
        }
        if ($info === null) {
            // The summary does not see the port yet (it is being applied) — an
            // honest "no data", not "listening" and not "not up".
            return ['unknown', get_string('publishportunknown', 'mod_agora')];
        }
        switch ((string) ($info['state'] ?? '')) {
            case 'listening':
                return ['listening', get_string('publishportlistening', 'mod_agora')];
            case 'disabled':
            case 'apply_error':
                $why = (string) ($info['apply_error']['message'] ?? $info['state']);
                return ['down', get_string('publishportdown', 'mod_agora', s($why))];
            default:
                return ['unknown', get_string('publishportunknown', 'mod_agora')];
        }
    }

    /**
     * The metadata badge: phased activities have the badge read the phase, the
     * legacy ones — the stream state, as before.
     *
     * @param \stdClass $agora the activity record
     * @param stream_state $state stream state
     * @return array ['text' => …, 'class' => …]
     */
    public static function badge(\stdClass $agora, stream_state $state): array {
        $phase = broadcast_phase::of($agora);
        if ($phase === broadcast_phase::PLANNED) {
            return [
                'text' => get_string('badgeplanned', 'mod_agora'),
                'class' => 'mod-agora-state-badge-planned',
            ];
        }
        if ($phase === broadcast_phase::ENDED) {
            return [
                'text' => get_string('badgeended', 'mod_agora'),
                'class' => 'mod-agora-state-badge-ended',
            ];
        }
        return [
            'text' => $state->badge_text(),
            'class' => 'mod-agora-state-badge-' . str_replace('_', '-', $state->state),
        ];
    }

    /**
     * A warning for the course editor: the ticket protects nothing.
     *
     * The admission policy is a property of the Agora installation, so it is
     * read once per 60 seconds and only for someone who can fix it.
     *
     * @param \context $context activity context
     * @return string HTML
     */
    private static function open_playback_warning(\context $context): string {
        global $OUTPUT;

        if (!has_capability('moodle/course:manageactivities', $context)) {
            return '';
        }
        if (!policy::open_playback()) {
            return '';
        }
        return $OUTPUT->notification(
            get_string('openplaybackwarning', 'mod_agora'),
            \core\output\notification::NOTIFY_WARNING
        );
    }

    /**
     * The state could not be obtained: a placeholder with the cause code.
     *
     * @param stream_state $state state
     * @param \stdClass $agora the activity record
     * @param \context $context activity context
     * @return string HTML
     */
    private static function unknown(stream_state $state, \stdClass $agora, \context $context): string {
        global $OUTPUT;

        // The full response body goes to the log once — when the request was
        // actually made; from the cache the refusal arrives without the body.
        if ($state->error !== null) {
            failure::log($state->error, $context, 'opstreamstats', 'admin');
        }

        if ($state->failure_reason() === 'notfound') {
            return self::framed($OUTPUT->notification(
                get_string('placeholderstreammissing', 'mod_agora', s((string) $agora->stream_name)),
                \core\output\notification::NOTIFY_WARNING
            ));
        }
        if ($state->failure !== null) {
            return self::framed(
                $OUTPUT->notification($state->failure_message($context), \core\output\notification::NOTIFY_ERROR)
            );
        }
        return self::framed($OUTPUT->notification(
            get_string('stateunknown', 'mod_agora', $state->reason ?? ''),
            \core\output\notification::NOTIFY_ERROR
        ));
    }

    /**
     * The player or the placeholder by the stream state, the activity phase and
     * the display mode.
     *
     * @param \stdClass $agora the activity record
     * @param \cm_info $cm course module
     * @param int $userid learner
     * @param stream_state $state stream state
     * @param bool $wantrecord the learner pressed "watch the recording"
     * @param bool $canmanage the viewer leads the broadcast (the `mod/agora:broadcast` right)
     * @return string HTML
     */
    private static function body(
        \stdClass $agora,
        \cm_info $cm,
        int $userid,
        stream_state $state,
        bool $wantrecord,
        bool $canmanage
    ): string {
        $plan = self::plan($agora, $state, $wantrecord, $canmanage);
        switch ($plan['kind']) {
            case 'live':
                $html = '';
                if (!empty($plan['phasesignal'])) {
                    // There is a signal, but the broadcast is not announced
                    // yet: the teacher gets the checking player with an honest
                    // plate — learners see the placeholder at this time.
                    global $OUTPUT;
                    $html .= $OUTPUT->notification(
                        get_string('signalhidden', 'mod_agora'),
                        \core\output\notification::NOTIFY_INFO
                    );
                }
                return $html . self::iframe($agora, $cm, $userid, []);
            case 'record':
                // A phased activity with a bound recording plays it as a VOD
                // clip by the ready export; until readiness — the "the
                // recording will appear soon" placeholder (the teacher gets
                // the export state). Such activities are not shown the DVR
                // archive, and no `from`/`to` windows are built.
                if (!empty($agora->has_phases) && !empty($agora->recording_id)) {
                    return self::vod_record($agora, $cm, $userid, $canmanage);
                }
                // The recording of an ended broadcast opens with an archive
                // window: without `from`/`to` the embedded player lands on the
                // live edge, and there a stopped stream is empty — the tape
                // does not play at all. A running broadcast has no window: it
                // would close the "go live" transition. A signal interruption
                // is also "the broadcast is running" (it will resume in a
                // moment, as in the "recording only" mode): a tape without
                // bounds, and when the signal returns, "go live" from it
                // works. An ended activity plays the recording by the window,
                // even if the encoder is still sending.
                $phase = broadcast_phase::of($agora);
                $ended = $phase === broadcast_phase::ENDED
                    || !in_array($state->state, [stream_state::LIVE, stream_state::SIGNAL_LOST], true);
                return self::iframe($agora, $cm, $userid, ['dvr' => 'true'], $ended);
            case 'starting':
                return self::starting($agora, $cm, $userid);
            case 'norecordyet':
                return self::no_record_yet();
            default:
                return self::placeholder($state, $agora, $cm, !empty($plan['phaseplanned']));
        }
    }

    /**
     * What the scene shows now: `live`, `starting`, `record`, `placeholder`,
     * `norecordyet` or `unknown`.
     *
     * The state poll of the open page answers with the same word: the page
     * compares it with its own and decides whether it is time to change the
     * player.
     *
     * @param \stdClass $agora the activity record
     * @param stream_state $state stream state
     * @param bool $wantrecord the learner pressed "watch the recording"
     * @param bool|null $canmanage the viewer leads the broadcast; null — count as a learner
     * @return string
     */
    public static function kind(\stdClass $agora, stream_state $state, bool $wantrecord = false, ?bool $canmanage = null): string {
        if ($state->state === stream_state::UNKNOWN) {
            return 'unknown';
        }
        // An ended phased activity with a bound recording: the recording is
        // the VOD clip — the ready player or the "the recording will appear
        // soon" placeholder, which the poll will replace with the player when
        // the export is ready.
        if (broadcast_phase::of($agora) === broadcast_phase::ENDED && !empty($agora->recording_id)) {
            try {
                // A fresh read, not the cache: the "the recording will appear
                // soon" placeholder must change to the player within the next
                // polling interval, not on cache expiry.
                return recording_scope::vod_fresh((string) $agora->recording_id)['asset'] !== null ? 'record' : 'recordpending';
            } catch (agora_exception $error) {
                return 'recordpending';
            }
        }
        return self::plan($agora, $state, $wantrecord, $canmanage ?? false)['kind'];
    }

    /**
     * Whether the open page should ask if the broadcast has started.
     *
     * A page without a live player asks when a live player may arrive: not in
     * the "recording only" mode and not after an explicit "watch the
     * recording" — the learner went there for the recording on purpose.
     *
     * A live player usually has nothing to ask about — it updates itself. The
     * exception is an activity in the "running" phase: its live player must
     * change to the "the recording will appear soon" placeholder when the
     * broadcast is ended (by the button, by silence or by the system) —
     * without the poll the page would stay on the player with "signal lost"
     * forever. Legacy activities have no phases: their live player is as
     * before, without the poll.
     *
     * @param \stdClass $agora the activity record
     * @param stream_state $state stream state
     * @param bool $wantrecord the learner pressed "watch the recording"
     * @param bool|null $canmanage the viewer leads the broadcast; null — count as a learner
     * @return bool
     */
    public static function polls(\stdClass $agora, stream_state $state, bool $wantrecord = false, ?bool $canmanage = null): bool {
        if ($wantrecord || ($agora->display_mode ?? 'auto') === 'record_only') {
            return false;
        }
        $kind = self::kind($agora, $state, false, $canmanage);
        if ($kind === 'starting') {
            return false;
        }
        if ($kind === 'live') {
            // The live player of a phased "running" activity watches for the
            // broadcast end: without the poll the scene would never change
            // after "End broadcast".
            return !empty($agora->has_phases) && broadcast_phase::of($agora) === broadcast_phase::ONGOING;
        }
        return true;
    }

    /**
     * What to show and which transitions to offer — the decision in one place.
     *
     * The scene and the transitions are computed from one decision: otherwise
     * the "watch the recording" button and the placeholder under it would one
     * day disagree.
     *
     * @param \stdClass $agora the activity record
     * @param stream_state $state stream state
     * @param bool $wantrecord the learner pressed "watch the recording"
     * @param bool $canmanage the viewer leads the broadcast (the `mod/agora:broadcast` right)
     * @return array ['kind' => …, 'offerrecord' => bool, 'backtolive' => bool,
     *     'phaseplanned' => bool, 'phasesignal' => bool]
     */
    private static function plan(\stdClass $agora, stream_state $state, bool $wantrecord, bool $canmanage = false): array {
        $mode = $agora->display_mode ?? 'auto';
        $phase = broadcast_phase::of($agora);

        // In the "broadcast only" mode there is no recording: there is no
        // transition to it, and a hand-typed `?record=1` is ignored.
        $wantrecord = $wantrecord && $mode !== 'live_only';

        // The "planned" phase outweighs the stream state: the signal may be
        // running while the broadcast is not announced for learners yet. The
        // legacy (`has_phases = 0`) is not touched by this branch at all — it
        // lives by the state, as before.
        if ($phase === broadcast_phase::PLANNED && !$wantrecord && $mode !== 'record_only') {
            if ($canmanage && $state->state === stream_state::LIVE) {
                // The checking player for the presenter; the plate about
                // learners is drawn by `body()` by the `phasesignal` flag.
                return [
                    'kind' => 'live',
                    'offerrecord' => false,
                    'backtolive' => false,
                    'phaseplanned' => false,
                    'phasesignal' => true,
                ];
            }
            return [
                'kind' => 'placeholder',
                'offerrecord' => false,
                'backtolive' => false,
                'phaseplanned' => true,
                'phasesignal' => false,
            ];
        }

        $live = $state->state === stream_state::LIVE;
        if ($phase === broadcast_phase::ENDED) {
            // An ended activity shows the recording even when the encoder is
            // still sending: it never has "signal lost" — the broadcast is
            // decidedly over.
            $live = false;
        }
        $silencelost = $state->state === stream_state::SIGNAL_LOST && $phase !== broadcast_phase::ENDED;
        $record = $state->has_record();
        $offerrecord = $record && $mode !== 'live_only';

        // The display mode narrows the state, it does not replace it.
        if ($live && $mode !== 'record_only' && !$wantrecord) {
            return ['kind' => 'live', 'offerrecord' => false, 'backtolive' => false,
                'phaseplanned' => false, 'phasesignal' => false];
        }
        // The "Recording only" mode during a broadcast: the archive tape of a running
        // stream ends with a live edge with a "go live" button — that is the
        // broadcast the mode does not show (the `course-activity` spec,
        // "Display mode"). So the placeholder is "the recording will appear
        // after the broadcast", even if the archive is already being written,
        // and `?record=1` does not bypass it. A signal interruption is also
        // "the broadcast is running" (a spec decision: the broadcast will
        // resume soon, there is no recording as finished yet) — the same
        // placeholder, without the "watch the recording" transition.
        if (($live || $silencelost) && $mode === 'record_only') {
            return ['kind' => 'placeholder', 'offerrecord' => false, 'backtolive' => false,
                'phaseplanned' => false, 'phasesignal' => false];
        }
        if ($silencelost && !$wantrecord) {
            return ['kind' => 'placeholder', 'offerrecord' => $offerrecord, 'backtolive' => false,
                'phaseplanned' => false, 'phasesignal' => false];
        }
        // An ondemand stream is brought up by the first viewer, and the
        // archive does not replace that: otherwise a stream with a recording
        // from a course cannot be started at all. So the branch stands before
        // the archive one, and the recording is reached by the "watch the
        // recording" transition.
        if (self::ondemand_start($agora, $state, $mode, $wantrecord)) {
            return ['kind' => 'starting', 'offerrecord' => $offerrecord, 'backtolive' => false,
                'phaseplanned' => false, 'phasesignal' => false];
        }
        // The recording opens as a DVR tape; the `from`/`to` bounds are added
        // only for a stopped stream — otherwise the player lands on the live
        // edge, where it is empty (see `body()` and the `record-playback`
        // spec). A running stream has no bounds: the recording window would
        // close the "go live" transition.
        if ($record && $mode !== 'live_only' && $state->facts !== null) {
            return ['kind' => 'record', 'offerrecord' => false, 'backtolive' => false,
                'phaseplanned' => false, 'phasesignal' => false];
        }
        // The learner pressed "watch the recording", but there is no
        // recording: a placeholder, not an empty archive player, and a
        // transition back to the broadcast.
        if ($wantrecord && !$record) {
            return ['kind' => 'norecordyet', 'offerrecord' => false, 'backtolive' => true,
                'phaseplanned' => false, 'phasesignal' => false];
        }

        return ['kind' => 'placeholder', 'offerrecord' => false, 'backtolive' => false,
            'phaseplanned' => false, 'phasesignal' => false];
    }

    /**
     * Show the ondemand stream with a live player instead of a placeholder.
     *
     * The flag is three-valued: the decision is made only on an explicit
     * `true`. On `null` — "the template decides", and the plugin will not
     * guess for it.
     *
     * @param \stdClass $agora the activity record
     * @param stream_state $state stream state
     * @param string $mode the activity display mode
     * @param bool $wantrecord the learner pressed "watch the recording"
     * @return bool
     */
    private static function ondemand_start(\stdClass $agora, stream_state $state, string $mode, bool $wantrecord): bool {
        $ondemand = $agora->ondemand ?? null;
        if ($ondemand === null || $ondemand === '' || (int) $ondemand !== 1) {
            return false;
        }
        $states = [stream_state::NOT_STARTED, stream_state::ENDED_RECORDED, stream_state::ENDED_NO_RECORD];
        return $mode !== 'record_only' && !$wantrecord && in_array($state->state, $states, true);
    }

    /**
     * The live player of an ondemand stream with the caption "will start on your request".
     *
     * @param \stdClass $agora the activity record
     * @param \cm_info $cm course module
     * @param int $userid learner
     * @return string HTML
     */
    private static function starting(\stdClass $agora, \cm_info $cm, int $userid): string {
        global $OUTPUT;

        $html = $OUTPUT->notification(
            get_string('placeholderondemand', 'mod_agora'),
            \core\output\notification::NOTIFY_INFO
        );
        return $html . self::iframe($agora, $cm, $userid, []);
    }

    /**
     * There is no recording yet: a word placeholder, the transition back to the broadcast is given by `actions()`.
     *
     * @return string HTML
     */
    private static function no_record_yet(): string {
        global $OUTPUT;

        return self::framed($OUTPUT->notification(
            get_string('placeholdernorecordyet', 'mod_agora'),
            \core\output\notification::NOTIFY_INFO
        ));
    }

    /**
     * A word placeholder with the preview frame when there is one.
     *
     * @param stream_state $state stream state
     * @param \stdClass $agora the activity record
     * @param \cm_info $cm course module
     * @param bool $phaseplanned the activity is in the "planned" phase, and a learner is watching
     * @return string HTML
     */
    private static function placeholder(
        stream_state $state,
        \stdClass $agora,
        \cm_info $cm,
        bool $phaseplanned = false
    ): string {
        global $OUTPUT;

        $mode = $agora->display_mode ?? 'auto';
        $key = $state->placeholder_key();
        if ($phaseplanned) {
            // The "Planned" state is always "has not started yet" — even with a live
            // signal: the phase is decided by the teacher, not by the stream
            // state.
            $key = 'statenotstarted';
        } else if ($mode === 'record_only' && in_array($state->state, [stream_state::LIVE, stream_state::SIGNAL_LOST], true)) {
            $key = 'placeholdernorecord';
        } else if ($mode === 'live_only' && !in_array($state->state, [stream_state::LIVE], true)) {
            $key = 'placeholdernotlive';
        }

        $context = \context_module::instance($cm->id);
        // We do not show the live signal frame of a "planned" one to the
        // learner: the plate honestly says the broadcast has not started, and
        // the frame would give it away.
        $url = $phaseplanned ? null : preview::url($context, (string) $agora->stream_name, $state);

        return self::framed(
            $OUTPUT->notification(get_string($key, 'mod_agora'), \core\output\notification::NOTIFY_INFO),
            $url
        );
    }

    /**
     * Embeds the player page with the pair ticket.
     *
     * @param \stdClass $agora the activity record
     * @param \cm_info $cm course module
     * @param int $userid learner
     * @param array $params player page parameters
     * @param bool $archivewindow add the archive bounds `from`/`to`
     * @return string HTML
     */
    private static function iframe(
        \stdClass $agora,
        \cm_info $cm,
        int $userid,
        array $params,
        bool $archivewindow = false
    ): string {
        // The learner address is taken by the core: behind a reverse proxy it
        // honours `reverseproxy` and `getremoteaddrconf`, and the plugin keeps
        // no copy of those rules of its own.
        $grant = grant_manager::for_user($agora, $userid, (int) $cm->course, (int) $cm->id, getremoteaddr());

        if ($archivewindow) {
            $window = self::archive_window(
                (string) $agora->stream_name,
                \context_module::instance($cm->id)
            );
            if ($window !== null) {
                $params['from'] = $window['from'];
                $params['to'] = $window['to'];
            }
        }

        // The ondemand flag is three-valued: NULL — "the template decides",
        // and then the parameter is absent from the address at all.
        if (isset($agora->ondemand) && $agora->ondemand !== null && $agora->ondemand !== '') {
            $params['ondemand'] = ((int) $agora->ondemand) === 1 ? 'true' : 'false';
        }

        $url = playback::embed_url(
            (string) get_config('mod_agora', 'centralurl'),
            $agora->stream_name,
            (string) $grant->token,
            $params
        );

        return self::stage_iframe($url, format_string($agora->name));
    }

    /**
     * The recording of a phased activity: the VOD clip by the ready export or a placeholder.
     *
     * The ready clip plays in the iframe `/playback/embed-vod/{asset}/{file}`
     * with the pair ticket (its scope carries `vod/{asset_id}` too). While
     * there is no clip, the learner sees "the recording will appear soon", and
     * the presenter — the export state with the cause: "broken" is
     * distinguishable from "not ready yet".
     *
     * @param \stdClass $agora the activity record (with the bound `recording_id`)
     * @param \cm_info $cm course module
     * @param int $userid learner
     * @param bool $canmanage the viewer leads the broadcast
     * @return string HTML
     */
    private static function vod_record(\stdClass $agora, \cm_info $cm, int $userid, bool $canmanage): string {
        global $OUTPUT;

        $context = \context_module::instance($cm->id);
        try {
            $info = recording_scope::vod_with_status_fresh((string) $agora->recording_id);
            $vod = $info['vod'];
            $status = $info['status'];
        } catch (agora_exception $error) {
            // The exports could not be read: the "will appear soon"
            // placeholder is more honest than a fallen page; the cause is in
            // the log.
            failure::log($error, $context, 'oplistexports', 'admin');
            $vod = ['asset' => null, 'file' => null];
            $status = ['state' => 'processing', 'reason' => ''];
        }

        if ($vod['asset'] !== null) {
            // The learner address is taken by the core: behind a reverse proxy
            // it honours `reverseproxy` and `getremoteaddrconf`, and the plugin
            // keeps no copy of those rules of its own.
            $grant = grant_manager::for_user($agora, $userid, (int) $cm->course, (int) $cm->id, getremoteaddr());
            $url = playback::vod_embed_url(
                (string) get_config('mod_agora', 'centralurl'),
                (string) $vod['asset'],
                (string) $vod['file'],
                (string) $grant->token
            );
            return self::stage_iframe($url, format_string($agora->name));
        }

        $note = '';
        if ($canmanage) {
            $note = $status['state'] === 'error'
                ? get_string('recordexporterror', 'mod_agora', s($status['reason']))
                : get_string('recordprocessing', 'mod_agora');
        }

        return self::framed(
            $OUTPUT->notification(get_string('recordnotready', 'mod_agora'), \core\output\notification::NOTIFY_INFO)
                . ($note !== '' ? $OUTPUT->notification($note, \core\output\notification::NOTIFY_WARNING) : '')
        );
    }

    /**
     * A 16:9 field with an iframe: one markup for the live player and the recording player.
     *
     * @param \moodle_url $url the iframe address with the ticket
     * @param string $title a caption for screen readers
     * @return string HTML
     */
    private static function stage_iframe(\moodle_url $url, string $title): string {
        return \html_writer::div(
            \html_writer::tag('iframe', '', [
                'src' => $url->out(false),
                'class' => 'mod-agora-player',
                'allowfullscreen' => 'allowfullscreen',
                'allow' => 'autoplay; fullscreen; picture-in-picture',
                // The ticket travels in the iframe address: there is nowhere to serve it in the Referer.
                'referrerpolicy' => 'no-referrer',
                'title' => $title,
                'width' => '100%',
                'height' => '480',
                'frameborder' => '0',
            ]),
            'mod-agora-player-wrapper'
        );
    }

    /**
     * How many seconds to remember a refusal of the archive bounds request.
     *
     * Shorter than the answer (60 s, the `ttl` of the `dvrrange` cache): a
     * refusal is not a property of the stream, and there is no reason to
     * spread it to all learners for a minute. Fifteen seconds is the same
     * term as `unknown` and the page freshness threshold.
     */
    const ARCHIVE_FAIL_FRESH = 15;

    /**
     * The stream archive bounds: from the cache or with one request by the service ticket.
     *
     * The request is sent by the Moodle server, not the browser, so the ticket
     * here is the stream service ticket (`grant_manager::for_preview()`: one
     * stream, up to ten screens, no address binding), not the pair ticket: the
     * pair ticket is bound to the learner address, and the node would answer
     * the Moodle server 403.
     *
     * The answer (a window or "no archive", 404) is put into the cache for 60
     * seconds. A refusal (service ticket issue, network, 403, 5xx) — only for
     * `ARCHIVE_FAIL_FRESH`: the player is drawn without bounds, and the cause
     * with the operation name goes to the event log — no more than once per
     * this term per stream.
     *
     * @param string $streamname stream name
     * @param \context $context activity context — for the log entry
     * @return array|null ['from' => ms, 'to' => ms] or null
     */
    private static function archive_window(string $streamname, \context $context): ?array {
        $cache = \cache::make('mod_agora', 'dvrrange');

        $entry = $cache->get($streamname);
        if (is_array($entry)) {
            $failedat = (int) ($entry['failed_at'] ?? 0);
            if ($failedat === 0 || time() - $failedat < self::ARCHIVE_FAIL_FRESH) {
                return $entry['window'] ?? null;
            }
        }

        $jti = '';
        // The step the refusal happened on: first the service ticket issue,
        // then the bounds request itself. An Agora refusal names its operation
        // itself; a refusal of Moodle itself (lock, database) is signed with
        // the step it happened on.
        $stage = 'opissueservice';
        try {
            $grant = grant_manager::for_preview($streamname);
            $jti = (string) $grant->jti;
            $token = (string) $grant->token;
            $stage = 'oparchiverange';
            $range = connection::client()->archive_range($streamname, $token);
        } catch (agora_exception $error) {
            failure::log($error, $context, $stage, 'service');
            $atrange = $error->operation === 'oparchiverange';
            if ($atrange && in_array($error->httpstatus, [401, 403], true)) {
                // The node rejected the service ticket (revoked, expired
                // earlier than the table says): we redeem it, the next view
                // will issue a new one.
                grant_manager::forget_service($streamname, $jti);
            }
            if ($atrange && $error->reason === 'notfound') {
                // No archive — that is the node's answer to the bounds request
                // itself, not a refusal: we remember it as an answer. A 404 on
                // the way to it (service ticket issue, sign-in) is a refusal,
                // and it is remembered as a refusal.
                $cache->set($streamname, ['window' => null]);
                return null;
            }
            $cache->set($streamname, ['window' => null, 'failed_at' => time()]);
            return null;
        } catch (\Throwable $error) {
            // Not an Agora refusal (database, lock, code): the page still
            // draws the player without bounds, but the cause goes to the log
            // with the same entry — otherwise a "without bounds" entry would
            // be unexplainable.
            failure::log(agora_exception::from_throwable($error), $context, $stage, 'service');
            $cache->set($streamname, ['window' => null, 'failed_at' => time()]);
            return null;
        }

        $from = isset($range['from']) ? (int) $range['from'] : 0;
        $to = isset($range['to']) ? (int) $range['to'] : 0;
        // An empty window is the same "no archive": bounds with nothing to
        // play inside would be read by the player as a closed tape, and again
        // nothing would be shown.
        $window = $to > $from ? ['from' => $from, 'to' => $to] : null;
        $cache->set($streamname, ['window' => $window]);
        return $window;
    }
}
