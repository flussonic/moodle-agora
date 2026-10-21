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
 * The stream preview frame: two sources, both through the Moodle server.
 *
 * The server fetches the frame with a service ticket and caches it for 60
 * seconds; the browser gets it through `pluginfile`, where the plugin itself
 * checks the rights. The source is chosen by the state: a live frame only for
 * a running broadcast (it brings up the input of an ondemand stream), an
 * archive one by the `written_until` mark.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview {
    /** How many seconds a frame counts as fresh. */
    const FRESH = 60;

    /**
     * How many seconds to wait for a connection to the player front.
     *
     * The same limit as the synchronous state request
     * (`stream_state::CONNECT_TIMEOUT`): an unreachable node must fall off
     * noticeably earlier than the general client limit.
     */
    const CONNECT_TIMEOUT = 3;

    /**
     * How many seconds to wait for the whole frame, including the redirect to the delivery node.
     *
     * The browser requests a frame with a separate request for every card of a
     * course section, and each such request occupies a PHP worker. With the
     * general client limit (20 seconds) a hung node would hold workers until
     * the pool is exhausted; here the wait ends earlier, and the card stays
     * without a picture.
     */
    const FETCH_TIMEOUT = 5;

    /**
     * The frame track in the archive address.
     *
     * The node accepts only an image track (`i1`, `i2`, …): a video track is
     * rejected with a 400 "track is not an image track" response. The
     * thumbnails track number does not come in the `streams/stats` answer —
     * there is only video and audio — so the first one is taken.
     */
    const IMAGE_TRACK = 'i1';

    /** @var array States in which no frame is requested. */
    const SILENT_STATES = [
        stream_state::NOT_STARTED,
        stream_state::ENDED_NO_RECORD,
        stream_state::UNKNOWN,
    ];

    /**
     * How the frame is served to the browser: the `send_file` arguments in one place.
     *
     * Extracted from `mod_agora_pluginfile()` so that the response lifetime can
     * be checked by a test: `send_file` itself never gets to serving the body
     * under PHPUnit.
     *
     * @return array
     */
    public static function send_options(): array {
        return [
            'filename' => 'preview.jpg',
            // The browser must not call PHP on every picture view.
            'lifetime' => self::FRESH,
            'filter' => 0,
            'pathisstring' => true,
            'forcedownload' => false,
            'mimetype' => 'image/jpeg',
        ];
    }

    /**
     * Whether a frame is requested for this state at all.
     *
     * @param stream_state $state stream state
     * @return bool
     */
    public static function wanted(stream_state $state): bool {
        return !in_array($state->state, self::SILENT_STATES, true);
    }

    /**
     * The frame address for the browser or null when there will be no frame.
     *
     * No frame is requested here: only the decision "show a picture or not" is
     * made; the frame itself is fetched by `mod_agora_pluginfile()`.
     *
     * @param \context $context activity context
     * @param string $streamname stream name
     * @param stream_state $state stream state
     * @return \moodle_url|null
     */
    public static function url(\context $context, string $streamname, stream_state $state): ?\moodle_url {
        if (!self::wanted($state) || self::marked_absent($streamname)) {
            return null;
        }
        return \moodle_url::make_pluginfile_url($context->id, 'mod_agora', 'preview', 0, '/', 'preview.jpg');
    }

    /**
     * The "frame from cache only" address marker: the `cached` path segment.
     *
     * Such an address is issued to neighbours on the activity page: they never
     * call central, and the frame handler with this marker does not either.
     */
    const CACHED_MARK = 'cached';

    /**
     * The neighbour frame address: by the state from the cache, with the `cached` marker.
     *
     * The frame cache is not consulted here: it fills only when someone has
     * already requested the frame, and a "frame already exists" check would
     * keep neighbours empty forever. The expensive part — the frame itself —
     * goes into a separate browser request, and the page promises only that the
     * state was taken from the cache.
     *
     * @param \context $context context of the neighbour activity
     * @param string $streamname stream name
     * @param stream_state $state stream state from the cache
     * @return \moodle_url|null
     */
    public static function cached_url(\context $context, string $streamname, stream_state $state): ?\moodle_url {
        if (!self::wanted($state)) {
            return null;
        }
        return \moodle_url::make_pluginfile_url(
            $context->id,
            'mod_agora',
            'preview',
            0,
            '/' . self::CACHED_MARK . '/',
            'preview.jpg'
        );
    }

    /**
     * A short "no frame in the cache" refusal: a 404 header and end of response.
     *
     * A full Moodle error page would not render for an `img` tag anyway, while
     * it costs a page build and database queries.
     *
     * Under PHPUnit there is neither a header nor an exit: `header()` fails
     * there with a core warning, and `die` would take down the whole run. The
     * response code is returned — the test tells a short 404 from an error page
     * by it. The rights gate is unaffected: it ran above and is the same in
     * both modes.
     *
     * @return int the response code
     */
    public static function send_missing(): int {
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            return 404;
        }
        send_header_404();
        die();
    }

    /**
     * The stream frame: from the cache or with one request to the player front.
     *
     * @param string $streamname stream name
     * @param \context $context activity context — for writing to the event log
     * @param stream_state|null $state a ready state; null — ask for it here
     * @return string|null the JPEG body or null when there is no frame
     */
    public static function frame(string $streamname, \context $context, ?stream_state $state = null): ?string {
        $cache = \cache::make('mod_agora', 'previews');
        $now = time();

        $entry = $cache->get($streamname);
        if (is_array($entry) && $now - (int) ($entry['fetched_at'] ?? 0) < self::FRESH) {
            $body = $entry['body'] ?? null;
            return is_string($body) && $body !== '' ? $body : null;
        }

        // A neighbour frame arrives with a ready state from the cache: asking
        // central for it a second time is exactly the request the page promised
        // not to make.
        $state = $state ?? stream_state::for_stream($streamname);
        if (!self::wanted($state)) {
            return null;
        }

        $path = self::source_path($streamname, $state);
        if ($path === null) {
            return null;
        }

        // The refusing side: before the frame — issuing the service ticket
        // (central), then the frame itself (the node). An Agora refusal names
        // its operation itself; a refusal not from Agora (lock, database) gets
        // the operation of the step it happened on.
        $stage = 'opissueservice';
        try {
            $grant = grant_manager::for_preview($streamname);
            $stage = 'oppreviewframe';
            $answer = connection::client()->preview_frame($path, (string) $grant->token, [
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::FETCH_TIMEOUT,
            ]);
        } catch (\Throwable $error) {
            // The ticket was not issued, the node did not answer or the wait
            // ran out. There is no frame — and this state is named, not
            // swallowed silently: the cause goes to the log, and the "no frame"
            // mark lives the same 60 seconds as the frame itself. Without the
            // mark the next page view would wait out the limit on every card
            // again.
            $operation = $error instanceof agora_exception && $error->operation !== '' ? $error->operation : $stage;
            self::log_absent($streamname, $context, self::status_of($error), self::reason_of($error), $operation);
            $cache->set($streamname, ['body' => null, 'fetched_at' => $now]);
            return null;
        }

        if (in_array((int) $answer['status'], [401, 403], true)) {
            // The node rejected the service ticket (revoked, expired earlier
            // than the table says): we redeem exactly it — the next view issues
            // a new one instead of presenting the rejected one for an hour.
            grant_manager::forget_service($streamname, (string) $grant->jti);
        }
        if ((int) $answer['status'] !== 200 || $answer['body'] === '') {
            // There is no frame: the stream has no `thumbnails` section
            // configured or the node did not return it. The card stays without
            // a frame, and the log gets one entry per stream and cause per hour.
            self::log_absent($streamname, $context, (int) $answer['status'], 'http', 'oppreviewframe');
            $cache->set($streamname, ['body' => null, 'fetched_at' => $now]);
            return null;
        }

        $cache->set($streamname, ['body' => $answer['body'], 'fetched_at' => $now]);
        return $answer['body'];
    }

    /**
     * The frame path by the stream state.
     *
     * @param string $streamname stream name
     * @param stream_state $state stream state
     * @return string|null a path inside `<host>` or null when no frame is possible
     */
    public static function source_path(string $streamname, stream_state $state): ?string {
        if ($state->state === stream_state::LIVE) {
            return '/playback/live-preview-jpeg/' . rawurlencode($streamname);
        }
        if ($state->state === stream_state::SIGNAL_LOST || $state->state === stream_state::ENDED_RECORDED) {
            $mark = $state->facts !== null ? $state->facts->written_until_ms() : null;
            if ($mark === null) {
                return null;
            }
            return '/playback/v/' . rawurlencode($streamname) . '/image/' . self::IMAGE_TRACK . '/' . $mark . '.jpg';
        }
        return null;
    }

    /**
     * The stream is marked as "no frame possible" — the card is drawn without a picture.
     *
     * @param string $streamname stream name
     * @return bool
     */
    private static function marked_absent(string $streamname): bool {
        $cache = \cache::make('mod_agora', 'previews');
        $entry = $cache->get($streamname);
        return is_array($entry)
            && time() - (int) ($entry['fetched_at'] ?? 0) < self::FRESH
            && empty($entry['body']);
    }

    /**
     * Writes one entry per stream and cause per hour to the log.
     *
     * The deduplication key is the stream, the operation, the cause word and
     * the response code: a 404 entry ("no thumbnails section") must not hide
     * the next, different cause for an hour — the node stopped answering or
     * rejected the service ticket. The same cause is not written again within
     * an hour: otherwise the log would fill with one line per course page view.
     *
     * @param string $streamname stream name
     * @param \context $context activity context
     * @param int $status the player front response code; 0 — there was no response
     * @param string $reason the cause as a word: http, network, notconfigured, exception class
     * @param string $operation at which step: `oppreviewframe` — the frame from the node, the
     *     rest — issuing the service ticket at central (`oplogin`, `opcreategrant`, `opissueservice`)
     * @return void
     */
    private static function log_absent(
        string $streamname,
        \context $context,
        int $status,
        string $reason,
        string $operation
    ): void {
        $dedup = \cache::make('mod_agora', 'previewlog');
        $key = $streamname . '|' . $operation . '|' . $reason . '|' . $status;
        if ($dedup->get($key)) {
            return;
        }
        $dedup->set($key, time());
        \mod_agora\event\preview_missing::create([
            'context' => $context,
            'other' => ['stream' => $streamname, 'status' => $status, 'reason' => $reason, 'operation' => $operation],
        ])->trigger();
    }

    /**
     * The response code from a refusal: a network refusal had no response at all.
     *
     * @param \Throwable $error refusal
     * @return int
     */
    private static function status_of(\Throwable $error): int {
        return $error instanceof agora_exception ? $error->httpstatus : 0;
    }

    /**
     * The refusal cause as a word: the parsed Agora cause or the exception class.
     *
     * @param \Throwable $error refusal
     * @return string
     */
    private static function reason_of(\Throwable $error): string {
        if ($error instanceof agora_exception) {
            return $error->reason;
        }
        $class = get_class($error);
        $tail = strrchr($class, '\\');
        return $tail === false ? $class : substr($tail, 1);
    }
}
