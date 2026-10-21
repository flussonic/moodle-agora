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
 * The Agora central stub for Behat.
 *
 * Answers the contract table rows and keeps the state in a file under
 * `$CFG->dataroot` — under Behat the core puts the Behat site path there, so
 * the file lives in its directory. Lives only on the Behat site: outside it — dies.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../../config.php');

defined('BEHAT_SITE_RUNNING') || die();

require_once(__DIR__ . '/fake_central_defaults.php');

$path = (string) ($_SERVER['PATH_INFO'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$statefile = $CFG->dataroot . '/agora_fake_central.json';

/**
 * Reads the stub state.
 *
 * @param string $statefile the state file path
 * @return array
 */
function agora_fake_state(string $statefile): array {
    $default = mod_agora_fake_central_defaults();
    if (!is_readable($statefile)) {
        return $default;
    }
    $decoded = json_decode((string) file_get_contents($statefile), true);
    return is_array($decoded) ? $decoded + $default : $default;
}

/**
 * Writes the stub state.
 *
 * @param string $statefile the state file path
 * @param array $state the state
 * @return void
 */
function agora_fake_save(string $statefile, array $state): void {
    file_put_contents($statefile, json_encode($state), LOCK_EX);
}

/**
 * Sends a JSON answer and exits.
 *
 * @param int $status the answer code
 * @param array $body the body
 * @return void
 */
function agora_fake_json(int $status, array $body): void {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($body);
    die();
}

/**
 * Sends a refusal as an API error body and exits.
 *
 * @param int $status the answer code
 * @param string $code the error.code field
 * @param string $message the error.message field
 * @return void
 */
function agora_fake_error(int $status, string $code, string $message): void {
    agora_fake_json($status, ['error' => ['code' => $code, 'message' => $message]]);
}

/**
 * The request header value: Apache with mod_php hides them in different places.
 *
 * @param string $name the header name
 * @return string
 */
function agora_fake_header(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return (string) $_SERVER[$key];
    }
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $header => $value) {
            if (strtolower($header) === strtolower($name)) {
                return (string) $value;
            }
        }
    }
    return '';
}

/**
 * The `streams/stats` answer for the named broadcast state.
 *
 * The six card states differ in exactly three facts: the liveness of the input,
 * the `written_until` label and `status_since` — «the broadcast was ever observed».
 *
 * @param array $stream the broadcast record from the stub state
 * @return array
 */
function agora_fake_stats(array $stream): array {
    $now = time();
    $answer = ['name' => $stream['name'], 'status' => 'idle'];
    $state = (string) ($stream['state'] ?? 'not_started');

    if ($state === 'live') {
        return $answer + [
            'status' => 'running',
            'status_since' => gmdate('c', $now - 600),
            'inputs' => [['current' => true, 'status' => 'working']],
            'dvr' => [
                'recording' => true,
                'from' => gmdate('c', $now - 3600),
                'to' => gmdate('c', $now + 3600),
                'written_until' => gmdate('c', $now - 1),
            ],
        ];
    }
    if ($state === 'signal_lost' || $state === 'ended_recorded') {
        $age = $state === 'signal_lost' ? 60 : 7200;
        return $answer + [
            'status_since' => gmdate('c', $now - $age),
            'inputs' => [['current' => true, 'status' => 'dead']],
            'dvr' => [
                'recording' => false,
                'from' => gmdate('c', $now - $age - 3600),
                'to' => gmdate('c', $now - $age),
                'written_until' => gmdate('c', $now - $age),
            ],
        ];
    }
    if ($state === 'ended_no_record') {
        // The broadcast was observed, but there is no recording: no counters, no archive.
        return $answer + [
            'status_since' => gmdate('c', $now - 3600),
            'dvr' => ['recording' => false],
        ];
    }
    // The not_started state: nobody observed a transition into a status, so `status_since`
    // is absent from the answer at all — like a record assembled by central from the config.
    return $answer;
}

$state = agora_fake_state($statefile);
$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];

// The player page: statics without authorization, so the iframe has something to show.
if (preg_match('#^/playback/embed/([^/]+)$#', $path, $matches)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><title>Agora player</title></head><body>';
    echo '<div id="agora-fake-player">stream ' . s(urldecode($matches[1])) . '</div>';
    echo '</body></html>';
    die();
}

// The archive bounds of the broadcast: the page of a stopped broadcast's recording asks for them.
if (preg_match('#^/playback/dvr-range/([^/]+)$#', $path, $matches)) {
    $token = optional_param('token', '', PARAM_RAW);
    if ($token === '') {
        agora_fake_error(403, 'forbidden', 'token required');
    }
    // The bounds request is sent by the Moodle server, not the browser: a ticket
    // bound to the learner's address would be refused (403) by the real node.
    // The stub behaves the same, otherwise Behat would not see this seam.
    $known = $state['tokens'][$token] ?? null;
    if ($known === null || !empty($known['bind_ip'])) {
        agora_fake_error(403, 'forbidden', 'ticket is bound to another address');
    }
    $name = urldecode($matches[1]);
    // Like the real node: a revoked ticket and a ticket for another broadcast — a refusal.
    $grant = $state['grants'][$known['jti']] ?? null;
    if ($grant === null || ($grant['status'] ?? '') === 'revoked') {
        agora_fake_error(403, 'forbidden', 'ticket revoked');
    }
    if (!in_array($name, (array) ($grant['streams'] ?? []), true)) {
        agora_fake_error(403, 'forbidden', 'ticket is for another stream');
    }
    foreach ($state['streams'] as $stream) {
        if ($stream['name'] !== $name) {
            continue;
        }
        $stats = agora_fake_stats($stream);
        $writtenuntil = $stats['dvr']['written_until'] ?? null;
        if ($writtenuntil === null) {
            agora_fake_error(404, 'not_found', 'no archive');
        }
        $to = strtotime($writtenuntil) * 1000;
        agora_fake_json(200, ['from' => $to - 3600 * 1000, 'to' => $to]);
    }
    agora_fake_error(404, 'not_found', 'no such stream');
}

// Preview frames: the same picture, as long as the browser has something to show.
// Both routes go by the ticket in the query string, without the authorization header.
if (preg_match('#^/playback/(live-preview-jpeg/[^/]+|v/[^/]+/image/[^/]+/[^/]+\.jpg)$#', $path)) {
    if (optional_param('token', '', PARAM_RAW) === '') {
        agora_fake_error(403, 'forbidden', 'token required');
    }
    header('Content-Type: image/jpeg');
    // A one-pixel JPEG: the frame content does not matter to the test, the frame itself does.
    echo base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
        . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
        . 'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
    );
    die();
}

// Login. The password is checked for real: a stub letting anyone in with any
// password would prove a connection that does not exist in production.
if ($path === '/central/api-v4/auth/login' && $method === 'POST') {
    $login = (string) ($body['login'] ?? '');
    $password = (string) ($body['password'] ?? '');
    $account = $state['accounts'][$login] ?? null;
    if ($account === null || $password === '' || $password !== $account['password']) {
        agora_fake_error(401, 'invalid_credentials', 'bad login or password');
    }
    agora_fake_json(200, [
        'access_token' => 'fake-access-' . $login,
        // The stub does not issue a `refresh_token` at all: the plugin does not
        // use renewal, and there is no `auth/refresh` route here either.
        'expires_in' => 900,
        'login' => $login,
        // The role is the one of the account that was asked to log in.
        'role' => $account['role'],
    ]);
}

// The authorization header is needed from here on. Apache with mod_php gives it
// differently, so we look in all three places it can end up in.
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $header => $value) {
        if (strtolower($header) === 'authorization') {
            $auth = (string) $value;
        }
    }
}
if (strpos($auth, 'Bearer fake-access-') !== 0) {
    agora_fake_error(401, 'unauthorized', 'bearer token required');
}
$who = substr($auth, strlen('Bearer fake-access-'));
$role = (string) ($state['accounts'][$who]['role'] ?? 'admin');

if ($path === '/central/api-v4/whoami') {
    agora_fake_json(200, ['login' => $who, 'role' => $role]);
}

if ($path === '/central/api-v4/auth') {
    agora_fake_json(200, ['open_playback' => (bool) $state['open_playback']]);
}

if ($path === '/central/api-v4/templates/configs') {
    agora_fake_json(200, ['templates' => $state['templates'], 'next' => null]);
}

if ($path === '/central/api-v4/streams/configs') {
    $label = optional_param('label', '', PARAM_RAW);
    $query = optional_param('q', '', PARAM_RAW);
    $streams = [];
    foreach ($state['streams'] as $stream) {
        if ($label !== '' && !in_array($label, $stream['labels'], true)) {
            continue;
        }
        if ($query !== '' && strpos($stream['name'], $query) === false) {
            continue;
        }
        $streams[] = ['name' => $stream['name'], 'labels' => $stream['labels']];
    }
    agora_fake_json(200, ['streams' => $streams, 'next' => null]);
}

if ($path === '/central/api-v4/streams/stats') {
    // The state list: the scheduled cache warming task walks it.
    $stats = [];
    foreach ($state['streams'] as $stream) {
        $stats[] = agora_fake_stats($stream);
    }
    agora_fake_json(200, ['streams' => $stats, 'next' => null]);
}

if (preg_match('#^/central/api-v4/streams/stats/(.+)$#', $path, $matches)) {
    $name = urldecode($matches[1]);
    foreach ($state['streams'] as $stream) {
        if ($stream['name'] !== $name) {
            continue;
        }
        agora_fake_json(200, agora_fake_stats($stream));
    }
    agora_fake_error(404, 'not_found', 'no such stream');
}

if (preg_match('#^/central/api-v4/streams/configs/(.+)$#', $path, $matches)) {
    $name = urldecode($matches[1]);
    if ($method === 'GET') {
        foreach ($state['streams'] as $stream) {
            if ($stream['name'] === $name) {
                agora_fake_json(200, [
                    'name' => $stream['name'],
                    'labels' => $stream['labels'],
                    'ondemand' => $stream['ondemand'] ?? null,
                    // The central gives the key out as plain text: this is how the
                    // read-only block of the «take existing» broadcast reads it.
                    'srt_publish' => $stream['srt_publish'] ?? null,
                ]);
            }
        }
        agora_fake_error(404, 'not_found', 'no such stream');
    }
    if ($method === 'PATCH') {
        // A frequent document edit: key rotation (the whole section) and
        // removing the section (srt_publish: null). The rest of the body fields
        // are not touched.
        foreach ($state['streams'] as $index => $stream) {
            if ($stream['name'] !== $name) {
                continue;
            }
            if (array_key_exists('srt_publish', $body)) {
                if ($body['srt_publish'] === null) {
                    unset($state['streams'][$index]['srt_publish']);
                } else {
                    $state['streams'][$index]['srt_publish'] = $body['srt_publish'];
                }
            }
            agora_fake_save($statefile, $state);
            agora_fake_json(200, ['name' => $name]);
        }
        agora_fake_error(404, 'not_found', 'no such stream');
    }
    if ($method === 'PUT') {
        $exclusive = agora_fake_header('If-None-Match') === '*';
        foreach ($state['streams'] as $index => $stream) {
            if ($stream['name'] !== $name) {
                continue;
            }
            if ($exclusive) {
                // A taken name is given by central as VersionMismatch: 412 and an
                // empty body. This answer has no error body at all.
                http_response_code(412);
                die();
            }
            // Without `If-None-Match` central does an upsert, not a refusal.
            $state['streams'][$index]['labels'] = $body['labels'] ?? [];
            agora_fake_save($statefile, $state);
            agora_fake_json(200, ['name' => $name, 'labels' => $body['labels'] ?? []]);
        }
        $created = [
            'name' => $name,
            'labels' => $body['labels'] ?? [],
            'state' => 'not_started',
        ];
        // The publication section lands in the same document as the template.
        if (!empty($body['srt_publish'])) {
            $created['srt_publish'] = $body['srt_publish'];
        }
        $state['streams'][] = $created;
        agora_fake_save($statefile, $state);
        agora_fake_json(201, ['name' => $name, 'labels' => $body['labels'] ?? []]);
    }
    if ($method === 'DELETE') {
        $state['streams'] = array_values(array_filter($state['streams'], function (array $stream) use ($name): bool {
            return $stream['name'] !== $name;
        }));
        agora_fake_save($statefile, $state);
        http_response_code(204);
        die();
    }
}

if ($path === '/central/api-v4/srt-ports') {
    // The occupied ports summary: the publication sections of broadcasts plus the publish address.
    $ports = [];
    foreach ($state['streams'] as $stream) {
        $publish = $stream['srt_publish'] ?? null;
        if (empty($publish['port'])) {
            continue;
        }
        $portstate = 'no_data';
        if (!empty($stream['port_broken'])) {
            $portstate = 'apply_error';
        } else if (($stream['state'] ?? '') === 'live') {
            $portstate = 'listening';
        }
        $info = [
            'port' => (int) $publish['port'],
            'kind' => 'publish',
            'stream' => $stream['name'],
            'passphrase_set' => !empty($publish['passphrase']),
            'state' => $portstate,
            'duplicate' => false,
        ];
        if ($portstate === 'apply_error') {
            $info['apply_error'] = ['node' => 'node1', 'message' => 'bind: address already in use'];
        }
        $ports[] = $info;
    }
    usort($ports, function (array $a, array $b): int {
        return $a['port'] <=> $b['port'];
    });
    agora_fake_json(200, [
        'publish_address' => ['address' => (string) $state['publish_address'], 'state' => 'nodes'],
        'ports' => $ports,
    ]);
}

if (preg_match('#^/central/api-v4/streams/([^/]+)/recording-policy$#', $path, $matches) && $method === 'PATCH') {
    agora_fake_json(200, ['auto_export' => (bool) ($body['auto_export'] ?? false)]);
}

// Broadcast recordings: the list (routing probe), start and stop.
if (preg_match('#^/central/api-v4/streams/([^/]+)/recordings$#', $path, $matches) && $method === 'GET') {
    $name = urldecode($matches[1]);
    $recordings = $state['recordings'][$name] ?? [];
    agora_fake_json(200, ['recordings' => array_values($recordings), 'next' => null]);
}

if (preg_match('#^/central/api-v4/streams/([^/]+)/recordings/start$#', $path, $matches) && $method === 'POST') {
    $name = urldecode($matches[1]);
    $recordings = $state['recordings'][$name] ?? [];
    // Start answers 409 when an open recording already exists.
    foreach ($recordings as $recording) {
        if (($recording['phase'] ?? '') === 'recording') {
            agora_fake_error(409, 'RECORDING_ACTIVE', 'already recording');
        }
    }
    $state['counter']++;
    $id = 'fake-rec-' . $state['counter'];
    $state['recordings'][$name][$id] = [
        'id' => $id,
        'stream' => $name,
        'name' => '',
        'phase' => 'recording',
        'started_at' => gmdate('c'),
        'exports' => [],
    ];
    agora_fake_save($statefile, $state);
    agora_fake_json(201, ['id' => $id, 'stream' => $name, 'phase' => 'recording']);
}

if (preg_match('#^/central/api-v4/streams/([^/]+)/recordings/stop$#', $path, $matches) && $method === 'POST') {
    $name = urldecode($matches[1]);
    $stopped = false;
    foreach (($state['recordings'][$name] ?? []) as $id => $recording) {
        if (($recording['phase'] ?? '') === 'recording') {
            $state['recordings'][$name][$id]['phase'] = 'ready';
            $state['recordings'][$name][$id]['ended_at'] = gmdate('c');
            $state['recordings'][$name][$id]['ready_at'] = gmdate('c');
            $state['recordings'][$name][$id]['stop_reason'] = 'manual';
            // A finished recording immediately gets a completed export: on the
            // stand auto_export gives it into VOD without a delay.
            $state['recordings'][$name][$id]['exports'] = [[
                'id' => 'fake-exp-' . $id,
                'status' => 'done',
                'file' => 'lecture.mp4',
                'asset_id' => 'asset-' . $id,
            ]];
            $stopped = true;
        }
    }
    agora_fake_save($statefile, $state);
    if (!$stopped) {
        agora_fake_error(409, 'RECORDING_NOT_RUNNING', 'no recording');
    }
    agora_fake_json(200, ['stopped' => true]);
}

// The recording card and its exports.
if (preg_match('#^/central/api-v4/recordings/([^/]+)$#', $path, $matches) && $method === 'GET') {
    foreach ($state['recordings'] as $recordings) {
        foreach ($recordings as $id => $recording) {
            if ($id === $matches[1]) {
                agora_fake_json(200, $recording);
            }
        }
    }
    agora_fake_error(404, 'not_found', 'no such recording');
}

if (preg_match('#^/central/api-v4/recordings/([^/]+)/exports$#', $path, $matches) && $method === 'GET') {
    foreach ($state['recordings'] as $recordings) {
        foreach ($recordings as $id => $recording) {
            if ($id === $matches[1]) {
                agora_fake_json(200, ['exports' => array_values($recording['exports'] ?? []), 'next' => null]);
            }
        }
    }
    agora_fake_error(404, 'not_found', 'no such recording');
}

if (preg_match('#^/central/api-v4/recordings/([^/]+)$#', $path, $matches) && $method === 'PATCH') {
    // Renaming the recording: the new name is the activity name.
    foreach ($state['recordings'] as $streamname => $recordings) {
        foreach ($recordings as $id => $recording) {
            if ($id === $matches[1]) {
                $state['recordings'][$streamname][$id]['name'] = (string) ($body['name'] ?? '');
                agora_fake_save($statefile, $state);
                agora_fake_json(200, ['id' => $id]);
            }
        }
    }
    agora_fake_error(404, 'not_found', 'no such recording');
}

if ($path === '/central/api-v4/play-sessions/grants' && $method === 'POST') {
    if ($role !== 'player') {
        agora_fake_error(403, 'forbidden', 'role may not issue grants');
    }
    // A ticket issue failure on the scenario's request: the page must survive it
    // without replacing the stub with something that has no player.
    if (!empty($state['refuse_grants'])) {
        agora_fake_error(503, 'Internal', 'grants are down');
    }
    $state['counter']++;
    $jti = 'fake-jti-' . $state['counter'];
    $grant = [
        'jti' => $jti,
        'user_id' => (string) ($body['user_id'] ?? ''),
        'streams' => $body['streams'] ?? [],
        'max_concurrent' => $body['max_concurrent'] ?? 1,
        'status' => 'issued',
        'created_at' => gmdate('c'),
        'expires_at' => gmdate('c', time() + (int) ($body['ttl_secs'] ?? 3600)),
    ];
    $state['grants'][$jti] = $grant;
    // Which token belongs to which ticket: the node gives the archive bounds
    // only to a ticket without address binding, and the Moodle server fetches
    // them itself.
    $token = 'fake-token-' . $state['counter'];
    $state['tokens'][$token] = ['jti' => $jti, 'user_id' => $grant['user_id'], 'bind_ip' => $body['bind_ip'] ?? null];
    agora_fake_save($statefile, $state);
    agora_fake_json(201, $grant + ['token' => $token]);
}

if (preg_match('#^/central/api-v4/play-sessions/grants/(.+)$#', $path, $matches)) {
    $jti = urldecode($matches[1]);
    if ($method === 'DELETE') {
        if (isset($state['grants'][$jti])) {
            $state['grants'][$jti]['status'] = 'revoked';
            agora_fake_save($statefile, $state);
        }
        http_response_code(204);
        die();
    }
    if ($method === 'GET') {
        if (!isset($state['grants'][$jti])) {
            agora_fake_error(404, 'not_found', 'no such grant');
        }
        agora_fake_json(200, $state['grants'][$jti]);
    }
}

if ($path === '/central/api-v4/play-sessions/closed') {
    if ($role !== 'admin') {
        agora_fake_error(403, 'forbidden', 'role may not read the history');
    }
    $stream = optional_param('stream', '', PARAM_RAW);
    $userid = optional_param('user_id', '', PARAM_RAW);
    $from = optional_param('from', 0, PARAM_INT);
    $to = optional_param('to', 0, PARAM_INT);
    $sessions = [];
    foreach ($state['sessions'] as $session) {
        if ($stream !== '' && $session['stream'] !== $stream) {
            continue;
        }
        // The central compares `user_id` by strict equality.
        if ($userid !== '' && $session['user_id'] !== $userid) {
            continue;
        }
        if ($to > 0 && $session['opened_at'] > $to) {
            continue;
        }
        if ($from > 0 && $session['closed_at'] < $from) {
            continue;
        }
        $sessions[] = [
            'stream' => $session['stream'],
            'user_id' => $session['user_id'],
            // The shape of the live central answer: labels are whole unix seconds.
            'opened_at' => (int) $session['opened_at'],
            'closed_at' => (int) $session['closed_at'],
            'duration' => (int) $session['closed_at'] - (int) $session['opened_at'],
            'close_reason' => (string) ($session['close_reason'] ?? 'client_teardown'),
            'nodes' => ['node1'],
        ];
    }
    agora_fake_json(200, ['sessions' => $sessions, 'next' => null]);
}

agora_fake_error(404, 'not_found', 'fake central has no route ' . $path);
