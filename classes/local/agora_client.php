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

use Psr\Http\Message\ResponseInterface;

/**
 * The Agora central client on top of `\core\http_client`.
 *
 * Keeps the `access_token` of each account (`player`, `admin`) in the Moodle
 * cache until `expires_in` minus a margin. `POST auth/refresh` is not used:
 * a refresh token is single-use per session family, and two Moodle processes
 * (a page and cron) presenting the same refresh token redeem the whole
 * family — after that central answers 401, and the plugin would read it as a
 * wrong password. Signing in is cheap and lives 15 minutes, so on expiry a new
 * sign-in is made, and on a 401 in a request — one cache reset, a sign-in and
 * a retry.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agora_client {
    /** The central API mount point. */
    const API = '/central/api-v4';

    /** The margin with which a token counts as stale, seconds. */
    const EXPIRY_MARGIN = 15;

    /** How many 3xx redirects to follow on the way to the delivery node. */
    const MAX_REDIRECTS = 5;

    /** @var string The installation address: scheme, host, port. */
    private string $host;

    /** @var array Accounts: role => ['login' => …, 'password' => …]. */
    private array $accounts;

    /** @var \core\http_client */
    private \core\http_client $http;

    /** @var \cache_application|\cache_session|\cache_store The token cache. */
    private $tokencache;

    /** @var array The request log "METHOD path" — for tests and diagnostics. */
    private array $log = [];

    /**
     * Builds a client of one Agora installation.
     *
     * @param string $host address of the Agora installation: scheme, host, port, no path
     * @param array $accounts role => ['login' => …, 'password' => …]
     * @param callable|null $mock a substitute HTTP handler for tests
     */
    public function __construct(string $host, array $accounts, ?callable $mock = null) {
        $this->host = rtrim($host, '/');
        $this->accounts = $accounts;
        $options = ['timeout' => 20, 'connect_timeout' => 5];
        if ($mock !== null) {
            $options['mock'] = $mock;
        }
        $this->http = new \core\http_client($options);
        $this->tokencache = \cache::make('mod_agora', 'tokens');
    }

    /**
     * The client from the site settings.
     *
     * @param callable|null $mock a substitute HTTP handler for tests
     * @return self
     * @throws agora_exception when the connection is not configured
     */
    public static function instance(?callable $mock = null): self {
        $host = (string) get_config('mod_agora', 'centralurl');
        if ($host === '') {
            throw new agora_exception('errnocentralurl', null, 'notconfigured');
        }
        return new self($host, [
            'player' => [
                'login' => (string) get_config('mod_agora', 'playerlogin'),
                'password' => self::decrypt((string) get_config('mod_agora', 'playerpassword')),
            ],
            'admin' => [
                'login' => (string) get_config('mod_agora', 'adminlogin'),
                'password' => self::decrypt((string) get_config('mod_agora', 'adminpassword')),
            ],
            'publisher' => [
                'login' => (string) get_config('mod_agora', 'publisherlogin'),
                'password' => self::decrypt((string) get_config('mod_agora', 'publisherpassword')),
            ],
        ], $mock);
    }

    /**
     * Decrypts the account password from the settings.
     *
     * @param string $stored the setting value
     * @return string
     */
    private static function decrypt(string $stored): string {
        if ($stored === '') {
            return '';
        }
        try {
            return \core\encryption::decrypt($stored);
        } catch (\Throwable $e) {
            // There is no key or the value is not encrypted — the password counts as not set.
            return '';
        }
    }

    /**
     * The installation address: also the root of the player front `<host>/playback`.
     *
     * @return string
     */
    public function host(): string {
        return $this->host;
    }

    /**
     * The client request log.
     *
     * @return array lines like "GET /central/api-v4/whoami"
     */
    public function request_log(): array {
        return $this->log;
    }

    // Contract methods.

    /**
     * Who is signed in under this account.
     *
     * @param string $role player or admin
     * @return array response body
     */
    public function whoami(string $role): array {
        return $this->call($role, 'GET', '/whoami', [], 'opwhoami');
    }

    /**
     * Issues a viewing ticket.
     *
     * @param array $body the fields streams, user_id, ttl_secs, max_concurrent, bind_ip, note
     * @param string $role the issuing account: player or publisher
     * @return array response body with jti and token
     */
    public function create_grant(array $body, string $role = 'player'): array {
        return $this->call($role, 'POST', '/play-sessions/grants', ['json' => $body], 'opcreategrant');
    }

    /**
     * Revokes a ticket. Idempotent.
     *
     * @param string $jti ticket identifier
     * @return void
     */
    public function revoke_grant(string $jti): void {
        $this->call('player', 'DELETE', '/play-sessions/grants/' . rawurlencode($jti), [], 'oprevokegrant');
    }

    /**
     * The viewing history: closed sessions by stream and person.
     *
     * @param array $query stream, user_id, from, to, limit — from/to in unix seconds
     * @return array response body
     */
    public function closed_sessions(array $query): array {
        return $this->call('admin', 'GET', '/play-sessions/closed', ['query' => $query], 'opclosedsessions');
    }

    /**
     * The stream state in one call.
     *
     * @param string $name stream name
     * @param int|null $connecttimeout own connection wait limit, seconds
     * @param int|null $timeout own whole-response limit, seconds
     * @return array response body
     */
    public function stream_stats(string $name, ?int $connecttimeout = null, ?int $timeout = null): array {
        $options = $connecttimeout !== null ? ['connect_timeout' => $connecttimeout] : [];
        if ($timeout !== null) {
            $options['timeout'] = $timeout;
        }
        return $this->call('admin', 'GET', '/streams/stats/' . rawurlencode($name), $options, 'opstreamstats');
    }

    /**
     * The stream list with a name search and a tag filter.
     *
     * @param string|null $q substring search in the name
     * @param string|null $label content tag
     * @param string|null $cursor page cursor
     * @param int $limit page size
     * @return array response body with the streams and next keys
     */
    public function list_streams(?string $q = null, ?string $label = null, ?string $cursor = null, int $limit = 100): array {
        $query = ['limit' => $limit];
        if ($q !== null && $q !== '') {
            $query['q'] = $q;
        }
        if ($label !== null && $label !== '') {
            $query['label'] = $label;
        }
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        return $this->call('admin', 'GET', '/streams/configs', ['query' => $query], 'opliststreams');
    }

    /**
     * A page of stream states: the same list as `streams/stats/{name}`,
     * only whole and with a cursor.
     *
     * @param string|null $cursor page cursor
     * @param int $limit page size
     * @return array response body with the streams and next keys
     */
    public function list_stream_stats(?string $cursor = null, int $limit = 100): array {
        $query = ['limit' => $limit];
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        return $this->call('admin', 'GET', '/streams/stats', ['query' => $query], 'opliststats');
    }

    /**
     * Creates a stream from a template; a taken name comes back as a VersionMismatch answer.
     *
     * The extra body fields (`$extra`) — the `srt_publish` section: it goes
     * into the same document as the template reference, so reapplying the
     * template will not erase it.
     *
     * @param string $name stream name
     * @param string $template template name
     * @param array $labels content tags
     * @param array|null $extra extra fields of the stream document
     * @return array response body
     */
    public function create_stream(string $name, string $template, array $labels, ?array $extra = null): array {
        return $this->call('admin', 'PUT', '/streams/configs/' . rawurlencode($name), [
            'json' => ['template' => $template, 'labels' => array_values($labels)] + ($extra ?? []),
            'headers' => ['If-None-Match' => '*'],
        ], 'opcreatestream');
    }

    /**
     * Deletes a stream. The archive blobs are cleaned by the Agora cleaner.
     *
     * @param string $name stream name
     * @return void
     */
    public function delete_stream(string $name): void {
        $this->call('admin', 'DELETE', '/streams/configs/' . rawurlencode($name), [], 'opdeletestream');
    }

    /**
     * The stream template list.
     *
     * @param string|null $q substring search in the name
     * @param int $limit page size
     * @param string|null $cursor page cursor
     * @return array response body with the templates and next keys
     */
    public function list_templates(?string $q = null, int $limit = 200, ?string $cursor = null): array {
        $query = ['limit' => $limit];
        if ($q !== null && $q !== '') {
            $query['q'] = $q;
        }
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        return $this->call('admin', 'GET', '/templates/configs', ['query' => $query], 'oplisttemplates');
    }

    /**
     * The playback admission policy: the `open_playback` field.
     *
     * @return array response body
     */
    public function auth_policy(): array {
        return $this->call('admin', 'GET', '/auth', [], 'opauthpolicy');
    }

    /**
     * The preview frame from the player front by ticket.
     *
     * Goes without the authorization header: the player front knows only the
     * ticket in the query string. The client follows a 302 answer to the
     * delivery node, so the node must be reachable from the Moodle server.
     *
     * @param string $path a path inside `<host>`, starting with a slash
     * @param string $token the ticket JWT
     * @param array $options request wait limits: `connect_timeout`, `timeout`
     * @return array ['status' => code, 'body' => body, 'type' => Content-Type]
     */
    public function preview_frame(string $path, string $token, array $options = []): array {
        $url = new \moodle_url($this->host . $path, ['token' => $token]);
        $connect = isset($options['connect_timeout']) ? (float) $options['connect_timeout'] : null;
        $total = isset($options['timeout']) ? (float) $options['timeout'] : null;
        $response = $total !== null
            ? $this->get_within($url->out(false), $total, $connect, 'oppreviewframe', 'service')
            : $this->raw(
                'GET',
                $url->out(false),
                ['allow_redirects' => ['max' => self::MAX_REDIRECTS]],
                'oppreviewframe',
                'service'
            );
        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
            'type' => $response->getHeaderLine('Content-Type') ?: 'image/jpeg',
        ];
    }

    /**
     * The archive bounds of a stream from the player front by ticket.
     *
     * Goes without the plugin credentials: the player front knows only the
     * ticket in the query string. The ticket here is the stream service ticket
     * without address binding (the request is sent by the Moodle server, not by
     * the learner browser), so a refusal is signed "service ticket", not the
     * `player` account. The client follows a 302 answer to the delivery node.
     *
     * @param string $stream stream name
     * @param string $token the ticket JWT
     * @return array ['from' => ms, 'to' => ms]
     * @throws agora_exception 404 — no archive; anything else — a node refusal or network
     */
    public function archive_range(string $stream, string $token): array {
        $url = new \moodle_url($this->host . '/playback/dvr-range/' . rawurlencode($stream), ['token' => $token]);
        $response = $this->raw(
            'GET',
            $url->out(false),
            ['allow_redirects' => ['max' => self::MAX_REDIRECTS]],
            'oparchiverange',
            'service'
        );
        $status = $response->getStatusCode();
        if ($status === 404) {
            throw $this->failure($response, 'service', 'oparchiverange', 'errnotfound', 'notfound');
        }
        if ($status !== 200) {
            throw $this->failure($response, 'service', 'oparchiverange', null, 'http');
        }
        return $this->decode($response, 'oparchiverange', 'service');
    }

    /**
     * The stream configuration: needed for the `ondemand` field.
     *
     * @param string $name stream name
     * @return array response body
     */
    public function stream_config(string $name): array {
        return $this->call('admin', 'GET', '/streams/configs/' . rawurlencode($name), [], 'opstreamconfig');
    }

    /**
     * Starts the recording of a stream.
     *
     * 409 `RECORDING_ACTIVE` means "the recording is already running" — the
     * plugin reads the recording list and adopts the open one; other 409
     * (`RECORDING_NO_DVR`, `NOT_PLACED`, `NODE_UNREACHABLE`,
     * `STREAM_NOT_RUNNING`, `ARCHIVE_NOT_WRITING`) — readiness has not come
     * yet, and the cause travels in the body.
     *
     * @param string $name stream name
     * @return array response body with the recording
     */
    public function start_recording(string $name): array {
        return $this->call('admin', 'POST', '/streams/' . rawurlencode($name) . '/recordings/start', [], 'opstartrecording');
    }

    /**
     * Stops the recording of a stream.
     *
     * The `{confirm: true}` body — the plugin may end any recording of its own
     * stream; 409 `RECORDING_NOT_RUNNING` ("no recording") is read by the
     * caller as "already stopped", not as a refusal.
     *
     * @param string $name stream name
     * @return array response body
     */
    public function stop_recording(string $name): array {
        return $this->call('admin', 'POST', '/streams/' . rawurlencode($name) . '/recordings/stop', [
            'json' => ['confirm' => true],
        ], 'opstoprecording');
    }

    /**
     * Names a recording: when a recording starts, its name is the activity name.
     *
     * @param string $id recording identifier
     * @param string $newname the new name
     * @return array response body
     */
    public function rename_recording(string $id, string $newname): array {
        return $this->call('admin', 'PATCH', '/recordings/' . rawurlencode($id), [
            'json' => ['name' => $newname],
        ], 'oprenamerecording');
    }

    /**
     * The list of recordings of a stream.
     *
     * @param string $name stream name
     * @return array response body with the recording list
     */
    public function list_recordings(string $name): array {
        return $this->call('admin', 'GET', '/streams/' . rawurlencode($name) . '/recordings', [], 'oplistrecordings');
    }

    /**
     * The cluster SRT port summary: occupied ports, their state and the publish address.
     *
     * @return array response body {publish_address?, ports: […]}
     */
    public function get_srt_ports(): array {
        return $this->call('admin', 'GET', '/srt-ports', [], 'opsrtports');
    }

    /**
     * The recording policy of a stream: every stream from a course gets
     * auto_export, so a closed recording is exported to VOD without operator
     * actions.
     *
     * @param string $name stream name
     * @return array response body with the policy
     */
    public function set_recording_policy(string $name): array {
        return $this->call('admin', 'PATCH', '/streams/' . rawurlencode($name) . '/recording-policy', [
            'json' => ['auto_export' => true],
        ], 'opsetpolicy');
    }

    /**
     * Changes the `srt_publish` section of a stream (publish key rotation).
     *
     * @param string $name stream name
     * @param int $port publish port
     * @param string $passphrase publish key
     * @return array response body
     */
    public function patch_srt_publish(string $name, int $port, string $passphrase): array {
        return $this->call('admin', 'PATCH', '/streams/configs/' . rawurlencode($name), [
            'json' => ['srt_publish' => ['port' => $port, 'passphrase' => $passphrase]],
        ], 'oppatchpublish');
    }

    /**
     * Removes the `srt_publish` section from a stream. Idempotent: a repeat
     * with no section is the same success.
     *
     * @param string $name stream name
     * @return array response body
     */
    public function remove_srt_publish(string $name): array {
        return $this->call('admin', 'PATCH', '/streams/configs/' . rawurlencode($name), [
            'json' => ['srt_publish' => null],
        ], 'opremovepublish');
    }

    /**
     * The recording card of a stream.
     *
     * @param string $id recording identifier
     * @return array response body with the recording
     */
    public function get_recording(string $id): array {
        return $this->call('admin', 'GET', '/recordings/' . rawurlencode($id), [], 'opgetrecording');
    }

    /**
     * The export list of a recording.
     *
     * @param string $id recording identifier
     * @return array response body with the export list
     */
    public function list_recording_exports(string $id): array {
        return $this->call('admin', 'GET', '/recordings/' . rawurlencode($id) . '/exports', [], 'oplistexports');
    }

    // Sign-in and tokens.

    /**
     * The token cache key of an account.
     *
     * @param string $role player or admin
     * @return string
     */
    private function cache_key(string $role): string {
        return sha1($this->host . '|' . $role . '|' . ($this->accounts[$role]['login'] ?? ''));
    }

    /**
     * The current `access_token` of an account: from the cache or by a new sign-in.
     *
     * @param string $role player or admin
     * @return string
     */
    private function access_token(string $role): string {
        $entry = $this->tokencache->get($this->cache_key($role));
        if (is_array($entry) && !empty($entry['access']) && $entry['expires'] > time()) {
            return $entry['access'];
        }
        return $this->login($role);
    }

    /**
     * Forgets the account token: the next request signs in anew.
     *
     * @param string $role player or admin
     * @return void
     */
    private function forget_token(string $role): void {
        $this->tokencache->delete($this->cache_key($role));
    }

    /**
     * Sign-in with the account login and password.
     *
     * @param string $role player or admin
     * @return string `access_token`
     */
    private function login(string $role): string {
        $account = $this->accounts[$role] ?? ['login' => '', 'password' => ''];
        if ($account['login'] === '' || $account['password'] === '') {
            $error = new agora_exception('errnoaccount', get_string('account' . $role, 'mod_agora'), 'notconfigured');
            $error->operation = 'oplogin';
            $error->role = $role;
            throw $error;
        }
        $response = $this->raw('POST', $this->host . self::API . '/auth/login', [
            'json' => ['login' => $account['login'], 'password' => $account['password']],
        ], 'oplogin', $role);
        $status = $response->getStatusCode();
        if ($status === 404) {
            throw $this->failure($response, $role, 'oplogin', 'errlicense', 'license');
        }
        if ($status === 401) {
            throw $this->failure($response, $role, 'oplogin', 'errpassword', 'password');
        }
        if ($status === 429) {
            // The only place where central measures the rate: 10 sign-in
            // attempts a minute per "address + login" pair. There is no 429 in
            // the management API.
            throw $this->failure($response, $role, 'oplogin', 'errloginratelimited', 'ratelimited');
        }
        if ($status !== 200) {
            throw $this->failure($response, $role, 'oplogin', null, 'http');
        }
        return $this->store_token($role, $this->decode($response, 'oplogin', $role));
    }

    /**
     * Puts the `access_token` into the cache by the lifetime from the response, with a margin.
     *
     * The refresh token is never stored: it is single-use per session family,
     * and keeping it would one day redeem the family by a race.
     *
     * @param string $role player or admin
     * @param array $body the sign-in response body
     * @return string `access_token`
     */
    private function store_token(string $role, array $body): string {
        $access = (string) ($body['access_token'] ?? '');
        $expiresin = (int) ($body['expires_in'] ?? 0);
        $this->tokencache->set($this->cache_key($role), [
            'access' => $access,
            'expires' => time() + max($expiresin - self::EXPIRY_MARGIN, 0),
        ]);
        return $access;
    }

    // Transport.

    /**
     * An API request under an account.
     *
     * @param string $role player or admin
     * @param string $method the HTTP method
     * @param string $path a path inside the API mount point
     * @param array $options Guzzle request options
     * @param string $operation key of the string with the operation name — for the refusal message
     * @param bool $retried this is already a retry after a token reset
     * @return array the parsed response body; an empty array for 204
     */
    private function call(
        string $role,
        string $method,
        string $path,
        array $options,
        string $operation,
        bool $retried = false
    ): array {
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer ' . $this->access_token($role)];
        $response = $this->raw($method, $this->host . self::API . $path, $options, $operation, $role);
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return $this->decode($response, $operation, $role);
        }
        if ($status === 401) {
            // The first 401 means "the token is no longer good": reset the
            // cache, sign in anew and repeat the request once. A second 401 is
            // already the password.
            if (!$retried) {
                $this->forget_token($role);
                unset($options['headers']['Authorization']);
                return $this->call($role, $method, $path, $options, $operation, true);
            }
            throw $this->failure($response, $role, $operation, 'errpassword', 'password');
        }
        if ($status === 403) {
            throw $this->failure($response, $role, $operation, 'errrole', 'role');
        }
        if ($status === 404) {
            throw $this->failure($response, $role, $operation, 'errnotfound', 'notfound');
        }
        if ($status === 409 || $status === 412) {
            throw $this->failure($response, $role, $operation, null, 'conflict');
        }
        if ($status === 422) {
            // A data validation refusal: the body carries errors[] with the
            // field path — for example, a port conflict leads via
            // srt_publish.port.
            throw $this->failure($response, $role, $operation, 'errvalidation', 'validation');
        }
        throw $this->failure($response, $role, $operation, null, 'http');
    }

    /**
     * Sends the request; a network failure turns into a refusal with the "network" cause.
     *
     * @param string $method the HTTP method
     * @param string $url full address
     * @param array $options Guzzle request options
     * @param string $operation key of the string with the operation name — for the refusal log
     * @param string $role who presented: an account (player, admin) or
     *     `service` — the stream service ticket; a network refusal is signed with it
     * @return ResponseInterface
     */
    private function raw(
        string $method,
        string $url,
        array $options,
        string $operation,
        string $role
    ): ResponseInterface {
        $options['http_errors'] = false;
        // Into the log — the path without the query string: the preview ticket
        // JWT travels in it.
        $this->log[] = $method . ' ' . strtok(self::path_of($url, $this->host), '?');
        try {
            return $this->http->request($method, $url, $options);
        } catch (\Throwable $e) {
            // The Guzzle message carries the whole request address, and the
            // preview frame has the ticket JWT in the query string: cut the
            // query off before logging.
            throw $this->network_failure(self::strip_query($e->getMessage()), $operation, $role);
        }
    }

    /**
     * A network refusal: there was no response at all.
     *
     * @param string $message the refusal text without the query string
     * @param string $operation key of the string with the operation name
     * @param string $role who presented
     * @return agora_exception
     */
    private function network_failure(string $message, string $operation, string $role): agora_exception {
        $error = new agora_exception('errnetwork', $this->host, 'network');
        $error->operation = $operation;
        $error->role = $role;
        $error->body = $message;
        $error->apimessage = $message;
        return $error;
    }

    /**
     * The request path for the client log: for a delivery node — together with its address.
     *
     * @param string $url full address
     * @param string $host the installation address
     * @return string
     */
    private static function path_of(string $url, string $host): string {
        return strpos($url, $host) === 0 ? substr($url, strlen($host)) : $url;
    }

    /**
     * GET with one total deadline for the whole path, including the redirects to the delivery node.
     *
     * The Guzzle response limit (`timeout`) applies to each redirect
     * separately: with `allow_redirects` five redirects of five seconds would
     * give twenty-five. So the redirects here are our own, and each gets only
     * the remainder of the total deadline; the deadline running out is the same
     * "network" refusal as the wait limit of a single request.
     *
     * @param string $url address
     * @param float $total the total deadline, seconds
     * @param float|null $connect the connection limit of each redirect, seconds
     * @param string $operation key of the string with the operation name
     * @param string $role who presented
     * @return ResponseInterface the last response (not 3xx, or 3xx past the redirect limit)
     */
    private function get_within(
        string $url,
        float $total,
        ?float $connect,
        string $operation,
        string $role
    ): ResponseInterface {
        $started = microtime(true);
        $response = null;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            // The first request gets the deadline as is: so the limit in the
            // request options equals the promised number, not the number minus
            // microseconds.
            $left = $hop === 0 ? $total : $total - (microtime(true) - $started);
            if ($left <= 0) {
                throw $this->network_failure(
                    'deadline of ' . $total . ' s passed after ' . $hop . ' redirect(s)',
                    $operation,
                    $role
                );
            }
            $options = ['timeout' => $left, 'allow_redirects' => false];
            if ($connect !== null) {
                $options['connect_timeout'] = min($connect, $left);
            }
            $response = $this->raw('GET', $url, $options, $operation, $role);
            $status = $response->getStatusCode();
            $location = $response->getHeaderLine('Location');
            if ($status < 300 || $status >= 400 || $location === '') {
                return $response;
            }
            $url = (string) \GuzzleHttp\Psr7\UriResolver::resolve(
                new \GuzzleHttp\Psr7\Uri($url),
                new \GuzzleHttp\Psr7\Uri($location)
            );
        }
        return $response;
    }

    /**
     * Removes the query string from every address in the text.
     *
     * @param string $text text with addresses
     * @return string
     */
    private static function strip_query(string $text): string {
        return (string) preg_replace('#(https?://[^\s"\']+)\?[^\s"\']*#i', '$1', $text);
    }

    /**
     * Parses the response body as a JSON object.
     *
     * An empty body is a legitimate 204 answer. Everything else that does not
     * parse into an object (a proxy HTML, a string, a number, an array —
     * including an empty `[]`) is a refusal: silently turning garbage into an
     * empty array would hand a "success without an effect" upwards. Every
     * contract operation answers with an object, so an object and an array are
     * told apart by the JSON itself, not by the parse result: in PHP both `{}`
     * and `[]` parse into the same empty array.
     *
     * @param ResponseInterface $response response
     * @param string $operation key of the string with the operation name
     * @param string $role account
     * @return array
     * @throws agora_exception when the body is non-empty and not a JSON object
     */
    private function decode(ResponseInterface $response, string $operation, string $role): array {
        $body = (string) $response->getBody();
        if (trim($body) === '') {
            return [];
        }
        $decoded = json_decode($body);
        if ($decoded instanceof \stdClass) {
            return (array) json_decode($body, true);
        }
        $error = new agora_exception('errbadresponse', (object) [
            'operation' => get_string($operation, 'mod_agora'),
            // A foreign answer body (a proxy page) is plain text: it is escaped
            // by whoever outputs it as markup (the form itself, Mustache for
            // templates).
            'head' => \core_text::substr(trim($body), 0, 120),
        ], 'badresponse', $response->getStatusCode());
        $error->role = $role;
        $error->operation = $operation;
        $error->body = $body;
        throw $error;
    }

    /**
     * Builds a refusal: the code, `error.code`, `error.message`, the full body.
     *
     * @param ResponseInterface $response the central response
     * @param string $role account
     * @param string $operation key of the string with the operation name
     * @param string|null $stringid key of the message string; null — the generic message by the code
     * @param string $reason the cause as a word
     * @return agora_exception
     */
    private function failure(
        ResponseInterface $response,
        string $role,
        string $operation,
        ?string $stringid,
        string $reason
    ): agora_exception {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        $apicode = is_array($error) ? ($error['code'] ?? null) : null;
        $apimessage = is_array($error) ? ($error['message'] ?? null) : null;
        // Per-field validation errors: the path in segments ("srt_publish.port")
        // plus a message — by them the caller tells a port conflict from other
        // 422 refusals.
        $apifields = [];
        if (is_array($error) && isset($error['errors']) && is_array($error['errors'])) {
            foreach ($error['errors'] as $field) {
                if (!is_array($field) || !isset($field['message'])) {
                    continue;
                }
                $apifields[] = [
                    'path' => implode('.', array_map('strval', (array) ($field['path'] ?? []))),
                    'message' => (string) $field['message'],
                ];
            }
        }

        $a = (object) [
            'role' => get_string('account' . $role, 'mod_agora'),
            'operation' => get_string($operation, 'mod_agora'),
            'status' => $status,
            // The text from central is substituted into a string that travels into markup.
            'message' => s((string) ($apimessage ?? '')),
            'host' => $this->host,
        ];
        $error = new agora_exception($stringid ?? 'errhttp', $a, $reason, $status);
        $error->role = $role;
        $error->operation = $operation;
        $error->apicode = $apicode !== null ? (string) $apicode : null;
        $error->apimessage = $apimessage !== null ? (string) $apimessage : null;
        $error->apifields = $apifields;
        $error->body = $body;
        return $error;
    }
}
