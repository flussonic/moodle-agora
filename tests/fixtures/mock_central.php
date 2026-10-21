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
 * The stub central for PHPUnit: answers bound to the path and the method.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use mod_agora\local\agora_client;
use Psr\Http\Message\RequestInterface;

/**
 * The stub central: an HTTP handler answering by route, not by queue.
 *
 * The queue of answers lied about the contract: a test passed only with the
 * exact order of requests the code had at the moment of writing, and any edit
 * of the order broke the tests without saying anything about the behaviour.
 * Here the answer is chosen by the «method + path» pair, as with the real
 * central, and the login answers with the role of the requested account.
 *
 * The route key is `'METHOD /path'`, where the path is written without the
 * `/central/api-v4` mounting; the tail can be replaced with `*`. The value is
 * an answer, an exception, a closure of the request or a list of them: the
 * list is consumed one per call, the last element repeats from then on.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_agora_mock_central {
    /** The stub installation address. */
    const HOST = 'https://agora.example.com';

    /** The API mounting — the same as the client's. */
    const API = '/central/api-v4';

    /** @var self|null The last assembled handler: a test has only one. */
    private static ?self $last = null;

    /** @var array Routes: key => list of answers. */
    private array $routes = [];

    /** @var RequestInterface[] All requests that passed through the handler. */
    private array $requests = [];

    /** @var array Options of each request — in the same order as `$requests`. */
    private array $options = [];

    /** @var array Paths for which no route was found. */
    private array $misses = [];

    /**
     * Assembles the handler with the route table.
     *
     * @param array $routes the routes: `'METHOD /path'` => answer, list or closure
     */
    public function __construct(array $routes) {
        foreach ($routes as $key => $value) {
            $this->routes[self::normalise_key((string) $key)] = is_array($value) ? array_values($value) : [$value];
        }
    }

    /**
     * Brings the route key to the «METHOD /path» form.
     *
     * @param string $key the key from the route description
     * @return string
     */
    private static function normalise_key(string $key): string {
        $parts = preg_split('/\s+/', trim($key), 2);
        $method = strtoupper($parts[0] ?? 'GET');
        $path = $parts[1] ?? '/';
        return $method . ' ' . $path;
    }

    // Client assembly and access to the log.

    /**
     * An Agora client on top of the stub handler.
     *
     * @param array $routes the routes
     * @param string $host the installation address
     * @return agora_client
     */
    public static function client(array $routes, string $host = self::HOST): agora_client {
        self::$last = new self($routes);
        return new agora_client($host, [
            'player' => ['login' => 'moodle-player', 'password' => 'p1'],
            'admin' => ['login' => 'moodle-admin', 'password' => 'p2'],
            'publisher' => ['login' => 'moodle-publisher', 'password' => 'p3'],
        ], self::$last);
    }

    /**
     * The last request that passed through the stub central.
     *
     * @return RequestInterface|null
     */
    public static function last_request(): ?RequestInterface {
        $requests = self::$last !== null ? self::$last->requests : [];
        return $requests ? end($requests) : null;
    }

    /**
     * All requests that passed through the stub central.
     *
     * @return RequestInterface[]
     */
    public static function requests(): array {
        return self::$last !== null ? self::$last->requests : [];
    }

    /**
     * The last request body, parsed as JSON.
     *
     * @return array
     */
    public static function last_body(): array {
        $request = self::last_request();
        if ($request === null) {
            return [];
        }
        $decoded = json_decode((string) $request->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The options of the request to a path containing the substring: timeouts and the rest.
     *
     * The handler receives the options already merged — the request's own
     * options over the client shared options — so by them it is visible with
     * which limit exactly this request went out.
     *
     * @param string $needle the path substring
     * @return array|null the options of the first matching request or null
     */
    public static function options_for(string $needle): ?array {
        if (self::$last === null) {
            return null;
        }
        foreach (self::$last->requests as $index => $request) {
            if (strpos($request->getUri()->getPath(), $needle) !== false) {
                return self::$last->options[$index] ?? [];
            }
        }
        return null;
    }

    /**
     * The query strings of all requests to a path containing the substring — parsed.
     *
     * This way the test sees with which `user_id`, window and limit the task
     * asked for the history, not only what it asked.
     *
     * @param string $needle the path substring
     * @return array the list of parameter arrays
     */
    public static function queries_for(string $needle): array {
        $found = [];
        foreach (self::requests() as $request) {
            if (strpos($request->getUri()->getPath(), $needle) === false) {
                continue;
            }
            parse_str($request->getUri()->getQuery(), $query);
            $found[] = $query;
        }
        return $found;
    }

    /**
     * The requests for which no route was found.
     *
     * @return array
     */
    public static function misses(): array {
        return self::$last !== null ? self::$last->misses : [];
    }

    // The handler.

    /**
     * Answers the request by the route.
     *
     * @param RequestInterface $request the request
     * @param array $options the request options
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface {
        $this->requests[] = $request;
        $this->options[] = $options;

        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        if (strpos($path, self::API) === 0) {
            $path = substr($path, strlen(self::API));
        }
        $key = $method . ' ' . ($path === '' ? '/' : $path);

        $answer = $this->match($key);
        if ($answer === null && $key === 'POST /auth/login') {
            // The login answers with the role of the account that was asked:
            // otherwise the `player` role check would pass on the `admin` answer.
            $answer = self::login_for($request);
        }
        if ($answer === null) {
            $this->misses[] = $key;
            return Create::promiseFor(self::error(501, 'mock_no_route', 'no route ' . $key));
        }
        if (is_callable($answer)) {
            $answer = $answer($request);
        }
        if ($answer instanceof \Throwable) {
            return Create::rejectionFor($answer);
        }
        return Create::promiseFor($answer);
    }

    /**
     * The route answer: the exact key or a key with an asterisk — prefix match
     * for a trailing asterisk or match by any path segment for an asterisk
     * inside the key (for example, a PATCH with an asterisk in the middle of
     * the path).
     *
     * @param string $key the request key
     * @return mixed the answer, closure, exception or null if there is no route
     */
    private function match(string $key) {
        $found = null;
        if (array_key_exists($key, $this->routes)) {
            $found = $key;
        } else {
            foreach (array_keys($this->routes) as $route) {
                if (strpos($route, '*') === false) {
                    continue;
                }
                if (substr($route, -1) === '*') {
                    $matched = strpos($key, rtrim($route, '*')) === 0;
                } else {
                    $pattern = '#^' . str_replace('\\*', '[^?]*', preg_quote($route, '#')) . '$#';
                    $matched = preg_match($pattern, $key) === 1;
                }
                if ($matched) {
                    $found = $route;
                    break;
                }
            }
        }
        if ($found === null) {
            return null;
        }
        $queue = $this->routes[$found];
        return count($queue) > 1 ? array_shift($this->routes[$found]) : $queue[0];
    }

    // Answer templates.

    /**
     * The answer with a JSON body.
     *
     * @param int $status the answer code
     * @param array $body the body
     * @param array $headers the headers
     * @return Response
     */
    public static function json(int $status, array $body, array $headers = []): Response {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body));
    }

    /**
     * A successful login of the named role.
     *
     * @param int $expiresin the token lifetime in seconds
     * @param string $role the role in the answer
     * @param string $login the login in the answer
     * @return Response
     */
    public static function login(int $expiresin = 3600, string $role = 'admin', string $login = 'moodle-admin'): Response {
        return self::json(200, [
            'access_token' => 'access-' . $login,
            'expires_in' => $expiresin,
            'login' => $login,
            'role' => $role,
        ]);
    }

    /**
     * The login answer by the login from the request body.
     *
     * @param RequestInterface $request the login request
     * @return Response
     */
    private static function login_for(RequestInterface $request): Response {
        $body = json_decode((string) $request->getBody(), true);
        $login = is_array($body) ? (string) ($body['login'] ?? '') : '';
        $password = is_array($body) ? (string) ($body['password'] ?? '') : '';
        if ($login === '' || $password === '') {
            return self::error(401, 'unauthorized', 'login and password required');
        }
        return self::login(3600, $login === 'moodle-player' ? 'player' : 'admin', $login);
    }

    /**
     * A refusal with an API error body.
     *
     * @param int $status the answer code
     * @param string $code the error.code field
     * @param string $message the error.message field
     * @return Response
     */
    public static function error(int $status, string $code, string $message): Response {
        return self::json($status, ['error' => ['code' => $code, 'message' => $message]]);
    }

    /**
     * The answer with a picture body.
     *
     * @param int $status the answer code
     * @param string $body the body
     * @return Response
     */
    public static function image(int $status = 200, string $body = 'JPEGBYTES'): Response {
        return new Response($status, ['Content-Type' => 'image/jpeg'], $body);
    }

    // Broadcast state templates.

    /**
     * The `streams/stats` answer of a broadcast that is running.
     *
     * @param string $name the broadcast name
     * @return Response
     */
    public static function live_stats(string $name = 'optics-2026'): Response {
        return self::json(200, self::stats_live($name));
    }

    /**
     * The `streams/stats` answer of a broadcast that ended with an archive.
     *
     * @param int $agesecs how many seconds ago the archive was finished
     * @param string $name the broadcast name
     * @return Response
     */
    public static function recorded_stats(int $agesecs = 3600, string $name = 'optics-2026'): Response {
        return self::json(200, self::stats_recorded($agesecs, $name));
    }

    /**
     * The `streams/stats` body of a running broadcast.
     *
     * @param string $name the broadcast name
     * @return array
     */
    public static function stats_live(string $name = 'optics-2026'): array {
        return [
            'name' => $name,
            'status' => 'running',
            'status_since' => gmdate('c', time() - 600),
            'inputs' => [['current' => true, 'status' => 'working']],
            'in_media_bytes_total' => 1024,
        ];
    }

    /**
     * The `streams/stats` body of a broadcast with an archive: the age sets an
     * interruption or an end.
     *
     * @param int $agesecs how many seconds ago the archive was finished
     * @param string $name the broadcast name
     * @return array
     */
    public static function stats_recorded(int $agesecs = 3600, string $name = 'optics-2026'): array {
        $writtenuntil = time() - $agesecs;
        return [
            'name' => $name,
            'status' => 'idle',
            'status_since' => gmdate('c', $writtenuntil),
            'in_media_bytes_total' => 4096,
            'dvr' => [
                'recording' => false,
                'from' => gmdate('c', $writtenuntil - 7200),
                'to' => gmdate('c', $writtenuntil),
                'written_until' => gmdate('c', $writtenuntil),
            ],
        ];
    }

    /**
     * The `streams/stats` body of a broadcast that ran and was not recorded.
     *
     * @param string $name the broadcast name
     * @return array
     */
    public static function stats_ended_no_record(string $name = 'optics-2026'): array {
        return [
            'name' => $name,
            'status' => 'idle',
            // The broadcast was observed: central saw the transition into this status.
            'status_since' => gmdate('c', time() - 3600),
            'dvr' => ['recording' => false],
        ];
    }

    /**
     * The `streams/stats` body of a broadcast that never ran.
     *
     * No counters, no `inputs`, no `status_since`: the contract omits zero
     * fields, and nobody observed a transition into a status.
     *
     * @param string $name the broadcast name
     * @return array
     */
    public static function stats_not_started(string $name = 'optics-2026'): array {
        return [
            'name' => $name,
            'status' => 'idle',
        ];
    }

    /**
     * The `GET /srt-ports` answer: the occupied ports summary and the publish address.
     *
     * @param array $ports the summary rows: [port, kind, broadcast, state, error?]
     * @param string|null $address the publish address; null — central does not know it
     * @return Response
     */
    public static function srt_ports_summary(array $ports = [], ?string $address = 'publish.agora.example.com'): Response {
        $infos = [];
        foreach ($ports as $port) {
            [$value, $kind, $stream, $state] = $port + [null, 'publish', 'operator-stream', 'listening'];
            $info = [
                'port' => $value,
                'kind' => $kind,
                'stream' => $stream,
                'passphrase_set' => true,
                'state' => $state,
                'duplicate' => false,
            ];
            if ($state === 'apply_error') {
                $info['apply_error'] = ['node' => 'node-1', 'message' => 'bind: address already in use'];
            }
            $infos[] = $info;
        }
        $body = ['ports' => $infos];
        if ($address !== null) {
            $body['publish_address'] = ['address' => $address, 'state' => 'nodes'];
        }
        return self::json(200, $body);
    }

    /**
     * The recording card for the `GET /recordings/{id}` endpoint.
     *
     * @param string $id the recording identifier
     * @param string $stream the broadcast name
     * @return array
     */
    public static function recording_card(string $id = 'rec-1', string $stream = 'optics-2026'): array {
        return [
            'id' => $id,
            'stream' => $stream,
            'name' => 'Lecture',
            'phase' => 'ready',
            'started_at' => gmdate('c', time() - HOURSECS),
        ];
    }

    /**
     * The `GET /recordings/{id}/exports` answer: one asset per export; null —
     * an export without an asset (not finished yet).
     *
     * @param array $assets the export assets; null — without an asset
     * @return Response
     */
    public static function recording_exports(array $assets = ['asset-1']): Response {
        $exports = [];
        foreach ($assets as $index => $asset) {
            $exports[] = [
                'id' => 'exp-' . $index,
                'recording_id' => 'rec-1',
                'status' => 'done',
                'file' => 'lecture.mp4',
                'asset_id' => $asset,
            ];
        }
        return self::json(200, ['exports' => $exports]);
    }
}
