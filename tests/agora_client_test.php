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

use mod_agora\local\agora_exception;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * The central client: login, token cache, retry on 401, refusal parsing.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\agora_client
 */
final class agora_client_test extends \advanced_testcase {
    /**
     * Prepares a clean state: the token cache lives between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The first request logs in, the second takes the token from the cache.
     *
     * @return void
     */
    public function test_login_once_and_cache_token(): void {
        $client = \mod_agora_mock_central::client([
            'GET /whoami' => \mod_agora_mock_central::json(200, ['login' => 'moodle-admin', 'role' => 'admin']),
        ]);

        $client->whoami('admin');
        $client->whoami('admin');

        $this->assertSame([
            'POST /central/api-v4/auth/login',
            'GET /central/api-v4/whoami',
            'GET /central/api-v4/whoami',
        ], $client->request_log());
    }

    /**
     * An expired token leads to a new login, not to a renewal.
     *
     * Renewal is one-shot per session family: two Moodle processes presenting
     * one `refresh_token` would extinguish the whole family.
     *
     * @return void
     */
    public function test_expired_token_leads_to_new_login(): void {
        $client = \mod_agora_mock_central::client([
            // The lifetime 0: the token expires right away.
            'POST /auth/login' => \mod_agora_mock_central::login(0),
            'GET /whoami' => \mod_agora_mock_central::json(200, ['role' => 'admin']),
        ]);

        $client->whoami('admin');
        $client->whoami('admin');

        $this->assertSame([
            'POST /central/api-v4/auth/login',
            'GET /central/api-v4/whoami',
            'POST /central/api-v4/auth/login',
            'GET /central/api-v4/whoami',
        ], $client->request_log());
        $this->assertNotContains('POST /central/api-v4/auth/refresh', $client->request_log());
    }

    /**
     * The first 401 on a request is a retry with a fresh login, not a refusal.
     *
     * @return void
     */
    public function test_401_is_retried_once_after_relogin(): void {
        $client = \mod_agora_mock_central::client([
            'GET /whoami' => [
                \mod_agora_mock_central::error(401, 'invalid_credentials', 'token is gone'),
                \mod_agora_mock_central::json(200, ['role' => 'admin']),
            ],
        ]);

        $answer = $client->whoami('admin');

        $this->assertSame('admin', $answer['role']);
        $this->assertSame([
            'POST /central/api-v4/auth/login',
            'GET /central/api-v4/whoami',
            'POST /central/api-v4/auth/login',
            'GET /central/api-v4/whoami',
        ], $client->request_log());
    }

    /**
     * The second 401 in a row is already the password, and it names its field.
     *
     * @return void
     */
    public function test_401_names_password_field(): void {
        $client = \mod_agora_mock_central::client([
            'GET /whoami' => \mod_agora_mock_central::error(401, 'unauthorized', 'bad credentials'),
        ]);

        try {
            $client->whoami('admin');
            $this->fail('Expected Agora to refuse');
        } catch (agora_exception $e) {
            $this->assertSame('password', $e->reason);
            $this->assertSame('errpassword', $e->errorcode);
            // The message names exactly the settings field that fixes it.
            $this->assertStringContainsString(get_string('settingadminpassword', 'mod_agora'), $e->getMessage());
            // Exactly one retry, no more.
            $this->assertCount(2, array_keys($client->request_log(), 'GET /central/api-v4/whoami'));
        }
    }

    /**
     * 403 names the role and the operation.
     *
     * @return void
     */
    public function test_403_names_role_and_operation(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::error(403, 'forbidden', 'role has no scope'),
        ]);

        try {
            $client->list_streams();
            $this->fail('Expected Agora to refuse');
        } catch (agora_exception $e) {
            $this->assertSame('role', $e->reason);
            $this->assertSame(403, $e->httpstatus);
            $this->assertSame('errrole', $e->errorcode);
            $this->assertStringContainsString(get_string('accountadmin', 'mod_agora'), $e->getMessage());
            $this->assertStringContainsString(get_string('opliststreams', 'mod_agora'), $e->getMessage());
        }
    }

    /**
     * 404 on login is the licence gate, not «no such object».
     *
     * @return void
     */
    public function test_404_on_login_names_licence(): void {
        $client = \mod_agora_mock_central::client([
            'POST /auth/login' => \mod_agora_mock_central::json(404, []),
        ]);

        try {
            $client->whoami('player');
            $this->fail('Expected Agora to refuse');
        } catch (agora_exception $e) {
            $this->assertSame('license', $e->reason);
            $this->assertSame('errlicense', $e->errorcode);
            $this->assertSame(get_string('errlicense', 'mod_agora'), $e->getMessage());
        }
    }

    /**
     * 429 happens only at login and speaks about the login rate.
     *
     * @return void
     */
    public function test_429_on_login_names_rate_limit(): void {
        $client = \mod_agora_mock_central::client([
            'POST /auth/login' => \mod_agora_mock_central::error(429, 'rate_limited', 'too many attempts'),
        ]);

        try {
            $client->stream_stats('optics-2026');
            $this->fail('Expected Agora to refuse');
        } catch (agora_exception $e) {
            $this->assertSame('ratelimited', $e->reason);
            $this->assertSame('errloginratelimited', $e->errorcode);
            $this->assertStringContainsString(get_string('accountadmin', 'mod_agora'), $e->getMessage());
        }
    }

    /**
     * A non-JSON answer does not count as a success.
     *
     * @return array
     */
    public static function garbage_provider(): array {
        return [
            'streams/stats' => ['stream_stats', ['optics-2026'], 'GET /streams/stats/optics-2026'],
            'grants' => ['create_grant', [['streams' => ['optics-2026']]], 'POST /play-sessions/grants'],
            'streams/configs' => ['list_streams', [], 'GET /streams/configs'],
            'play-sessions/closed' => ['closed_sessions', [[]], 'GET /play-sessions/closed'],
        ];
    }

    /**
     * A non-JSON body in a successful answer is a refusal, not an empty array.
     *
     * @param string $method the client method
     * @param array $args the method arguments
     * @param string $route the stub central route
     * @return void
     * @dataProvider garbage_provider
     */
    public function test_non_json_body_is_a_failure(string $method, array $args, string $route): void {
        $client = \mod_agora_mock_central::client([
            $route => new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/html'], '<html>proxy says no</html>'),
        ]);

        try {
            $client->$method(...$args);
            $this->fail('Expected Agora to refuse on garbage in the response body');
        } catch (agora_exception $e) {
            $this->assertSame('badresponse', $e->reason);
            $this->assertSame('errbadresponse', $e->errorcode);
            $this->assertStringContainsString('proxy says no', $e->getMessage());
        }
    }

    /**
     * A JSON array is not an object either: `[]` and `["x"]` are a refusal, not
     * an empty success.
     *
     * In PHP both `{}` and `[]` parse into one empty array, and the former
     * parsing let `[]` pass as a success: the broadcast list looked empty, and
     * the ticket had no `jti`.
     *
     * @param string $method the client method
     * @param array $args the method arguments
     * @param string $route the stub central route
     * @return void
     * @dataProvider garbage_provider
     */
    public function test_json_array_body_is_a_failure(string $method, array $args, string $route): void {
        foreach (['[]', '["x"]'] as $body) {
            $client = \mod_agora_mock_central::client([
                $route => new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $body),
            ]);
            try {
                $client->$method(...$args);
                $this->fail('A JSON array instead of an object must be a refusal: ' . $body);
            } catch (agora_exception $e) {
                $this->assertSame('badresponse', $e->reason, $body);
                // The beginning of the body is in the message, escaped: it goes into the markup.
                $this->assertStringContainsString($body, $e->getMessage());
            }
        }
    }

    /**
     * An empty object `{}` is a legal answer: an object, just without fields.
     *
     * @return void
     */
    public function test_empty_object_is_not_a_failure(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/configs' => new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{}'),
        ]);

        $this->assertSame([], $client->list_streams());
    }

    /**
     * An empty body of a successful answer is a legal 204, not a refusal.
     *
     * @return void
     */
    public function test_empty_body_is_not_a_failure(): void {
        $client = \mod_agora_mock_central::client([
            'DELETE /play-sessions/grants/jti-1' => new \GuzzleHttp\Psr7\Response(204, [], ''),
        ]);

        $client->revoke_grant('jti-1');

        $log = $client->request_log();
        $this->assertSame('DELETE /central/api-v4/play-sessions/grants/jti-1', end($log));
    }

    /**
     * The refusal carries error.code, error.message and the full body for the log.
     *
     * @return void
     */
    public function test_failure_carries_code_message_and_body(): void {
        $client = \mod_agora_mock_central::client([
            'POST /play-sessions/grants' => \mod_agora_mock_central::error(
                503,
                'Internal',
                'ERROR: relation "streams" does not exist'
            ),
        ]);

        try {
            $client->create_grant(['streams' => ['optics-2026']]);
            $this->fail('Expected Agora to refuse');
        } catch (agora_exception $e) {
            $this->assertSame(503, $e->httpstatus);
            $this->assertSame('Internal', $e->apicode);
            $this->assertSame('ERROR: relation "streams" does not exist', $e->apimessage);
            $this->assertStringContainsString('relation', $e->body);
            $this->assertSame('AG-503', $e->short_code());
            $this->assertStringContainsString('error.code Internal', $e->editor_details());
        }
    }

    /**
     * The 302 answer is a hop to the node, not a refusal.
     *
     * @return void
     */
    public function test_302_is_followed_not_refused(): void {
        $client = \mod_agora_mock_central::client([
            'GET /whoami' => [
                new \GuzzleHttp\Psr7\Response(302, ['Location' => \mod_agora_mock_central::HOST . '/central/api-v4/whoami']),
                \mod_agora_mock_central::json(200, ['role' => 'admin', 'login' => 'moodle-admin']),
            ],
        ]);

        $answer = $client->whoami('admin');

        $this->assertSame('admin', $answer['role']);
    }

    /**
     * The preview frame follows the 302 to the issuing node.
     *
     * @return void
     */
    public function test_302_on_playback_is_followed_to_the_node(): void {
        $client = \mod_agora_mock_central::client([
            'GET /playback/live-preview-jpeg/optics-2026' => new \GuzzleHttp\Psr7\Response(302, [
                'Location' => \mod_agora_mock_central::HOST . '/playback/node/optics-2026.jpg',
            ]),
            'GET /playback/node/optics-2026.jpg' => \mod_agora_mock_central::image(200, 'NODEJPEG'),
        ]);

        $answer = $client->preview_frame('/playback/live-preview-jpeg/optics-2026', 'jwt-preview');

        $this->assertSame(200, $answer['status']);
        $this->assertSame('NODEJPEG', $answer['body']);
        $this->assertSame('image/jpeg', $answer['type']);
    }

    /**
     * A network failure names the check from the report and the «Agora server address» field.
     *
     * @return void
     */
    public function test_network_failure_names_check_and_field(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/configs' => new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('GET', \mod_agora_mock_central::HOST . '/central/api-v4/streams/configs')
            ),
        ]);

        try {
            $client->list_streams();
            $this->fail('Expected Agora to refuse');
        } catch (agora_exception $e) {
            $this->assertSame('network', $e->reason);
            // The message names the check from the report and the settings field that fixes it.
            $this->assertStringContainsString(get_string('checknetwork', 'mod_agora'), $e->getMessage());
            $this->assertStringContainsString(get_string('settingcentralurl', 'mod_agora'), $e->getMessage());
        }
    }

    /**
     * The query string is cut out of the network failure message: the ticket rides in it.
     *
     * @return void
     */
    public function test_network_failure_hides_token_in_url(): void {
        $client = \mod_agora_mock_central::client([
            'GET /playback/live-preview-jpeg/optics-2026' => new \GuzzleHttp\Exception\ConnectException(
                'cURL error 28: timeout for https://agora.example.com/playback/live-preview-jpeg/optics-2026?token=SECRET-JWT',
                new \GuzzleHttp\Psr7\Request('GET', '/')
            ),
        ]);

        try {
            $client->preview_frame('/playback/live-preview-jpeg/optics-2026', 'SECRET-JWT');
            $this->fail('Expected Agora to refuse');
        } catch (agora_exception $e) {
            $this->assertStringNotContainsString('SECRET-JWT', $e->body);
            $this->assertStringNotContainsString('token=', $e->body);
            $this->assertStringContainsString('live-preview-jpeg', $e->body);
        }
    }

    /**
     * The query string does not get into the request log.
     *
     * @return void
     */
    public function test_request_log_has_no_query(): void {
        $client = \mod_agora_mock_central::client([
            'GET /playback/live-preview-jpeg/optics-2026' => \mod_agora_mock_central::image(),
        ]);

        $client->preview_frame('/playback/live-preview-jpeg/optics-2026', 'SECRET-JWT');

        $this->assertSame(['GET /playback/live-preview-jpeg/optics-2026'], $client->request_log());
    }

    /**
     * The tag filter goes into the broadcast list request.
     *
     * @return void
     */
    public function test_label_filter_goes_into_query(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::json(200, ['streams' => []]),
        ]);

        $client->list_streams(null, 'moodle.hr-lms');

        $uri = urldecode((string) \mod_agora_mock_central::last_request()->getUri());
        $this->assertStringContainsString('label=moodle.hr-lms', $uri);
        // Not a single request went past the described routes: otherwise the
        // client could be knocking on the wrong door, and the test would not
        // notice it.
        $this->assertSame([], \mod_agora_mock_central::misses());
    }

    /**
     * Broadcast creation sends `If-None-Match: *` — otherwise central would do an upsert.
     *
     * @return void
     */
    public function test_create_stream_sends_if_none_match(): void {
        $client = \mod_agora_mock_central::client([
            'PUT /streams/configs/hr-lms-optics' => \mod_agora_mock_central::json(201, ['name' => 'hr-lms-optics']),
        ]);

        $client->create_stream('hr-lms-optics', 'lecture', ['moodle.hr-lms']);

        $request = \mod_agora_mock_central::last_request();
        $this->assertSame('*', $request->getHeaderLine('If-None-Match'));
        $this->assertSame([], \mod_agora_mock_central::misses());
    }

    /**
     * A taken name arrives as a 412 answer without a body and is read as a conflict.
     *
     * @return void
     */
    public function test_taken_name_is_412_without_body(): void {
        $client = \mod_agora_mock_central::client([
            'PUT /streams/configs/hr-lms-optics' => new \GuzzleHttp\Psr7\Response(412, [], ''),
        ]);

        try {
            $client->create_stream('hr-lms-optics', 'lecture', ['moodle.hr-lms']);
            $this->fail('Expected Agora to refuse');
        } catch (agora_exception $e) {
            $this->assertSame('conflict', $e->reason);
            $this->assertSame(412, $e->httpstatus);
            $this->assertNull($e->apicode);
        }
    }

    /**
     * The login answers with the role of the requested account, not someone else's.
     *
     * @return void
     */
    public function test_login_answers_with_role_of_requested_account(): void {
        $client = \mod_agora_mock_central::client([
            'GET /whoami' => function (\Psr\Http\Message\RequestInterface $request) {
                // Whoever presented the token, answers.
                $token = str_replace('Bearer access-', '', $request->getHeaderLine('Authorization'));
                return \mod_agora_mock_central::json(200, [
                    'login' => $token,
                    'role' => $token === 'moodle-player' ? 'player' : 'admin',
                ]);
            },
        ]);

        $this->assertSame('player', $client->whoami('player')['role']);
        $this->assertSame('admin', $client->whoami('admin')['role']);
        $this->assertSame([], \mod_agora_mock_central::misses());
    }
}
