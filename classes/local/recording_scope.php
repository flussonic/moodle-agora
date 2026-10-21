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
 * The recording clip and the ticket scope: the media identities the ticket must cover.
 *
 * For the learner a recording is a VOD clip: the last completed export of the
 * recording with an asset and a file. The ticket scope is the stream name,
 * plus `vod/{asset_id}` when the clip is ready (the typical reissue case — the
 * export finished after the ticket was issued). Exports without an asset are
 * skipped — there is nothing to play them with. "Playability" is a single
 * predicate `export_playable()`: status `done` and non-empty asset and file
 * (review R2, S-3): every place (showing the clip, the scope, the "no export"
 * counter, sync) answers this question the same way.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_scope {
    /**
     * Memoization of a fresh `info()` for one request: kind() and vod_record()
     * of the page go in a pair, and without memoization each view would cost
     * four central calls instead of two. The memoization key is the client
     * instance: changing the client (a new request, a test with a substitute
     * central) resets the memoization; freshness is not harmed.
     *
     * @var array client identifier => [recording identifier => info]
     */
    private static array $requestmemo = [];

    /**
     * The ticket scope of an activity: [stream name] (+ vod identities of the recording).
     *
     * An activity without a bound recording (a live broadcast, legacy) has only
     * the stream name, and central is not called for it. The composition is
     * read fresh: the scope decides whether to reissue the ticket when showing
     * (D8), and a fresh clip must not wait for a cache to expire to get into
     * the ticket. Memoization per request keeps the price at two central calls.
     *
     * @param \stdClass $agora the activity record
     * @return string[] media identities
     * @throws agora_exception the recording card or the exports could not be read
     */
    public static function of(\stdClass $agora): array {
        $scope = [(string) $agora->stream_name];
        if (empty($agora->has_phases) || empty($agora->recording_id)) {
            return $scope;
        }
        $vod = self::vod_fresh((string) $agora->recording_id);
        if ($vod['asset'] !== null) {
            $scope[] = 'vod/' . $vod['asset'];
        }
        return $scope;
    }

    /**
     * The recording clip by a fresh read (with per-request memoization).
     *
     * The page path reads readiness live: while we read the cache, the
     * "recording will appear shortly" placeholder hung until expiry although
     * the clip was ready.
     *
     * @param string $recordingid recording identifier
     * @return array ['asset' => string|null, 'file' => string|null]
     */
    public static function vod_fresh(string $recordingid): array {
        $info = self::info_fresh($recordingid);
        return ['asset' => $info['vod']['asset'], 'file' => $info['vod']['file']];
    }

    /**
     * The clip and the preparation state by one fresh read (both from one `info()`).
     *
     * @param string $recordingid recording identifier
     * @return array ['vod' => ['asset' => …, 'file' => …], 'status' => ['state' => …, 'reason' => …]]
     */
    public static function vod_with_status_fresh(string $recordingid): array {
        $info = self::info_fresh($recordingid);
        return ['vod' => $info['vod'], 'status' => $info['status']];
    }

    /**
     * All vod identities of a recording with a cache of minutes — sync counts
     * minutes by them: sessions were watched on previous clips of the recording
     * too (review R2, Н-2). Sync is a scheduled pass: it does not need
     * freshness, and the cache saves repeated reads within a pass and between
     * passes.
     *
     * @param string $recordingid recording identifier
     * @return string[] `vod/{asset_id}` of all playable exports
     */
    public static function assets_cached(string $recordingid): array {
        $cache = \cache::make('mod_agora', 'recordscope');
        $cached = $cache->get($recordingid);
        if (is_array($cached) && array_key_exists('vod', $cached)) {
            return $cached['assets'];
        }
        $info = self::info($recordingid);
        $cache->set($recordingid, $info + ['read_at' => time()]);
        return $info['assets'];
    }

    /**
     * The recording info by a fresh read, memoized per request.
     *
     * @param string $recordingid recording identifier
     * @return array
     */
    private static function info_fresh(string $recordingid): array {
        $clientid = spl_object_id(connection::client());
        if (!isset(self::$requestmemo[$clientid][$recordingid])) {
            self::$requestmemo[$clientid][$recordingid] = self::info($recordingid);
        }
        return self::$requestmemo[$clientid][$recordingid];
    }

    /**
     * The export is playable: completed with a non-empty asset and file.
     *
     * The only answer to "may it be shown?": the player, the ticket scope, the
     * "no export" counter and sync apply one check (S-3).
     *
     * @param array $export an export from the central list
     * @return bool
     */
    public static function export_playable(array $export): bool {
        if (($export['status'] ?? '') !== 'done') {
            return false;
        }
        return (string) ($export['asset_id'] ?? '') !== '' && (string) ($export['file'] ?? '') !== '';
    }

    /**
     * The parsed recording info: the clip, the state, all vod identities.
     *
     * @param string $recordingid recording identifier
     * @return array ['vod' => ['asset' => …, 'file' => …], 'status' => …, 'assets' => string[]]
     * @throws agora_exception the exports could not be read
     */
    private static function info(string $recordingid): array {
        $exports = self::exports($recordingid);
        $vod = null;
        $status = ['state' => 'processing', 'reason' => ''];
        foreach (array_reverse($exports) as $export) {
            if ($vod === null && self::export_playable($export)) {
                $vod = ['asset' => (string) $export['asset_id'], 'file' => (string) $export['file']];
            }
            // The state is read from the last export: its fate is the current
            // stage of the clip preparation.
            $status = self::export_status($export);
        }
        $assets = [];
        foreach ($exports as $export) {
            if (self::export_playable($export)) {
                $assets[] = 'vod/' . (string) $export['asset_id'];
            }
        }
        return [
            'vod' => $vod ?? ['asset' => null, 'file' => null],
            'status' => $status,
            'assets' => array_values(array_unique($assets)),
        ];
    }

    /**
     * The preparation stage of one export: a refusal with a cause or "being prepared".
     *
     * @param array $export an export
     * @return array ['state' => 'processing'|'error', 'reason' => string]
     */
    private static function export_status(array $export): array {
        $status = (string) ($export['status'] ?? '');
        if ($status === 'error') {
            $error = $export['error'] ?? null;
            $reason = is_array($error) ? (string) ($error['message'] ?? '') : '';
            return ['state' => 'error', 'reason' => $reason !== '' ? $reason : (string) ($export['reason'] ?? $status)];
        }
        return ['state' => 'processing', 'reason' => ''];
    }

    /**
     * The exports of a recording; a refusal by the card (the recording was
     * deleted, an old Agora) — an empty list: the scope stays the single stream
     * name.
     *
     * @param string $recordingid recording identifier
     * @return array
     * @throws agora_exception the export list could not be read
     */
    private static function exports(string $recordingid): array {
        try {
            connection::client()->get_recording($recordingid);
        } catch (agora_exception $error) {
            if ($error->reason === 'notfound') {
                return [];
            }
            throw $error;
        }
        $answer = connection::client()->list_recording_exports($recordingid);
        return array_values((array) ($answer['exports'] ?? []));
    }

    /**
     * Encodes the scope for the `agora_grants.scope` column.
     *
     * @param array $scope media identities
     * @return string JSON
     */
    public static function encode(array $scope): string {
        return json_encode(array_values($scope));
    }

    /**
     * Parses the scope from the `scope` column; null — the ticket is older than the column.
     *
     * @param string|null $stored the stored value
     * @return string[]
     */
    public static function decode(?string $stored): array {
        if ($stored === null || trim($stored) === '') {
            return [];
        }
        $decoded = json_decode($stored, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }
}
