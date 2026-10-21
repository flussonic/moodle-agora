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

use core\check\result;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use mod_agora\local\agora_client;
use mod_agora\local\connection;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * The four connection checks in the «System status» report.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\check\network
 * @covers     \mod_agora\check\playerrole
 * @covers     \mod_agora\check\adminrole
 * @covers     \mod_agora\check\openplayback
 */
final class checks_test extends \advanced_testcase {
    /**
     * Clean state.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        connection::set_client(null);
    }

    /**
     * Removes the stub client afterwards.
     *
     * @return void
     */
    protected function tearDown(): void {
        connection::set_client(null);
        parent::tearDown();
    }

    /**
     * A client whose network does not answer.
     *
     * @return agora_client
     */
    private function dead_client(): agora_client {
        return \mod_agora_mock_central::client([
            'POST /auth/login' => new ConnectException(
                'Connection refused',
                new Request('POST', 'https://agora.example.com/central/api-v4/auth/login')
            ),
        ]);
    }

    /**
     * A client whose connection is not filled in at all.
     *
     * @return agora_client
     */
    private function unconfigured_client(): agora_client {
        return new agora_client('https://agora.example.com', [
            'player' => ['login' => '', 'password' => ''],
            'admin' => ['login' => '', 'password' => ''],
        ]);
    }

    /**
     * A client whose server answers, but the login of one account is refused.
     *
     * The password of the second account is correct: this way it is visible
     * that the refusal is named where it is, and does not smear the check of
     * the neighbouring account.
     *
     * @param string $badlogin the login central answers 401 to
     * @return agora_client
     */
    private function badpassword_client(string $badlogin): agora_client {
        return \mod_agora_mock_central::client([
            'POST /auth/login' => function (\Psr\Http\Message\RequestInterface $request) use ($badlogin) {
                $body = json_decode((string) $request->getBody(), true);
                $login = (string) ($body['login'] ?? '');
                if ($login === $badlogin) {
                    return \mod_agora_mock_central::error(401, 'unauthorized', 'invalid login or password');
                }
                return \mod_agora_mock_central::login(3600, $login === 'moodle-player' ? 'player' : 'admin', $login);
            },
            'GET /whoami' => function (\Psr\Http\Message\RequestInterface $request) {
                $who = str_replace('Bearer access-', '', $request->getHeaderLine('Authorization'));
                return \mod_agora_mock_central::json(200, [
                    'login' => $who,
                    'role' => $who === 'moodle-player' ? 'player' : 'admin',
                ]);
            },
            'GET /streams/configs' => \mod_agora_mock_central::json(200, ['streams' => []]),
        ]);
    }

    /**
     * A client whose login passes, but the broadcast list is refused with its own code.
     *
     * @param \GuzzleHttp\Psr7\Response $answer the answer to `GET /streams/configs`
     * @return agora_client
     */
    private function liststreams_client(\GuzzleHttp\Psr7\Response $answer): agora_client {
        return \mod_agora_mock_central::client(['GET /streams/configs' => $answer]);
    }

    /**
     * The callback gives seven checks, and each leads to where it is fixed.
     *
     * The six connection and observability checks are fixed in the plugin
     * settings, the warming lag — in «Scheduled tasks»: the link names its
     * place.
     *
     * @return void
     */
    public function test_five_checks_are_registered(): void {
        require_once(__DIR__ . '/../lib.php');
        $checks = mod_agora_status_checks();

        $this->assertCount(7, $checks);
        $links = [];
        foreach ($checks as $check) {
            $this->assertInstanceOf(\core\check\check::class, $check);
            $this->assertNotNull($check->get_action_link());
            $links[get_class($check)] = $check->get_action_link()->url->out(false);
        }
        foreach ($links as $class => $link) {
            $expected = $class === \mod_agora\check\states_refresh::class ? 'scheduledtasks' : 'modsettingagora';
            $this->assertStringContainsString($expected, $link, $class);
        }
    }

    /**
     * Central does not answer: the network is ERROR with a reason, the other three are UNKNOWN.
     *
     * @return void
     */
    public function test_dead_central_is_error_and_three_unknown(): void {
        connection::set_client($this->dead_client());

        $network = (new \mod_agora\check\network())->get_result();
        $this->assertSame(result::ERROR, $network->get_status());
        $this->assertStringContainsString(get_string('checknetwork', 'mod_agora'), $network->get_details());

        $needconnection = [
            new \mod_agora\check\playerrole(),
            new \mod_agora\check\adminrole(),
            new \mod_agora\check\openplayback(),
        ];
        foreach ($needconnection as $check) {
            $this->assertSame(result::UNKNOWN, $check->get_result()->get_status(), get_class($check));
        }
    }

    /**
     * The player account with the viewer role — ERROR, and the role is named in the details.
     *
     * @return void
     */
    public function test_player_with_wrong_role_is_error(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /whoami' => \mod_agora_mock_central::json(200, ['login' => 'moodle-player', 'role' => 'viewer']),
        ]));

        $outcome = (new \mod_agora\check\playerrole())->get_result();

        $this->assertSame(result::ERROR, $outcome->get_status());
        $this->assertSame(get_string('checkplayerroleerror', 'mod_agora'), $outcome->get_summary());
        $this->assertStringContainsString('viewer', $outcome->get_details());
    }

    /**
     * A wrong password of the `player` account: the role check speaks about the password.
     *
     * Before the fix it said «the role does not allow issuing tickets» — the
     * reason was wrong, and the operator would fix it with the wrong field.
     *
     * @return void
     */
    public function test_wrong_player_password_names_password_not_role(): void {
        connection::set_client($this->badpassword_client('moodle-player'));

        $outcome = (new \mod_agora\check\playerrole())->get_result();

        $this->assertSame(result::ERROR, $outcome->get_status());
        $this->assertSame(
            get_string('checkpassworderror', 'mod_agora', get_string('accountplayer', 'mod_agora')),
            $outcome->get_summary()
        );
        $this->assertNotSame(get_string('checkplayerroleerror', 'mod_agora'), $outcome->get_summary());
        // The field that fixes it is named in the details.
        $this->assertStringContainsString(get_string('settingplayerpassword', 'mod_agora'), $outcome->get_details());

        // The `admin` password is correct — its check stays green.
        $this->assertSame(result::OK, (new \mod_agora\check\adminrole())->get_result()->get_status());
    }

    /**
     * A wrong password of the `admin` account: the network and the role speak about the password.
     *
     * Before the fix the network said «the Agora server does not answer», though it did.
     *
     * @return void
     */
    public function test_wrong_admin_password_names_password_not_network(): void {
        connection::set_client($this->badpassword_client('moodle-admin'));

        $network = (new \mod_agora\check\network())->get_result();
        $adminrole = (new \mod_agora\check\adminrole())->get_result();
        $expected = get_string('checkpassworderror', 'mod_agora', get_string('accountadmin', 'mod_agora'));

        $this->assertSame(result::ERROR, $network->get_status());
        $this->assertSame($expected, $network->get_summary());
        $this->assertNotSame(get_string('checknetworkerror', 'mod_agora'), $network->get_summary());
        $this->assertStringContainsString(get_string('settingadminpassword', 'mod_agora'), $network->get_details());

        $this->assertSame(result::ERROR, $adminrole->get_status());
        $this->assertSame($expected, $adminrole->get_summary());
        $this->assertNotSame(get_string('checkadminroleerror', 'mod_agora'), $adminrole->get_summary());

        // The `player` password is correct — its check stays green.
        $this->assertSame(result::OK, (new \mod_agora\check\playerrole())->get_result()->get_status());
    }

    /**
     * Open playback is on — WARNING with an explanation.
     *
     * @return void
     */
    public function test_open_playback_is_warning(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /auth' => \mod_agora_mock_central::json(200, ['open_playback' => true]),
        ]));

        $outcome = (new \mod_agora\check\openplayback())->get_result();

        $this->assertSame(result::WARNING, $outcome->get_status());
        $this->assertSame(get_string('openplaybackwarning', 'mod_agora'), $outcome->get_details());
    }

    /**
     * Everything is fine: the network is OK with the broadcast count, the roles are OK, playback is by ticket.
     *
     * @return void
     */
    public function test_all_ok(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::json(200, [
                'streams' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']],
            ]),
            'GET /whoami' => function (\Psr\Http\Message\RequestInterface $request) {
                $who = str_replace('Bearer access-', '', $request->getHeaderLine('Authorization'));
                return \mod_agora_mock_central::json(200, [
                    'login' => $who,
                    'role' => $who === 'moodle-player' ? 'player' : 'admin',
                ]);
            },
            'GET /auth' => \mod_agora_mock_central::json(200, ['open_playback' => false]),
        ]));

        $network = (new \mod_agora\check\network())->get_result();
        $this->assertSame(result::OK, $network->get_status());
        $this->assertSame(get_string('checknetworkdetails', 'mod_agora', 3), $network->get_details());

        // All four, including the `player` role: otherwise the green report
        // would be silent about the only account that tickets are issued by.
        $this->assertSame(result::OK, (new \mod_agora\check\playerrole())->get_result()->get_status());
        $this->assertSame(result::OK, (new \mod_agora\check\adminrole())->get_result()->get_status());
        $this->assertSame(result::OK, (new \mod_agora\check\openplayback())->get_result()->get_status());
    }

    /**
     * The network does not answer: the summary names exactly that, the details — the address field.
     *
     * A control branch for the three neighbours: if the «does not answer»
     * summary were given to every reason in a row, the difference between them
     * would not be visible.
     *
     * @return void
     */
    public function test_dead_network_names_the_address(): void {
        connection::set_client($this->dead_client());

        $network = (new \mod_agora\check\network())->get_result();

        $this->assertSame(result::ERROR, $network->get_status());
        $this->assertSame(get_string('checknetworkerror', 'mod_agora'), $network->get_summary());
        // The field that fixes it is named: «Agora server address».
        $this->assertStringContainsString(get_string('settingcentralurl', 'mod_agora'), $network->get_details());
    }

    /**
     * The account role does not allow the broadcast list (403): this is not «does not answer».
     *
     * @return void
     */
    public function test_forbidden_list_names_the_role_not_the_address(): void {
        connection::set_client($this->liststreams_client(
            \mod_agora_mock_central::error(403, 'forbidden', 'role is not allowed')
        ));

        $network = (new \mod_agora\check\network())->get_result();

        $this->assertSame(result::ERROR, $network->get_status());
        $this->assertSame(
            get_string('checknetworkdenied', 'mod_agora', get_string('accountadmin', 'mod_agora')),
            $network->get_summary()
        );
        $this->assertNotSame(get_string('checknetworkerror', 'mod_agora'), $network->get_summary());
        // The details name the operation the role lacked.
        $this->assertStringContainsString(get_string('opliststreams', 'mod_agora'), $network->get_details());
    }

    /**
     * The login rate (429): the summary is about frequent logins, not about a silent address.
     *
     * @return void
     */
    public function test_rate_limited_login_names_the_rate(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'POST /auth/login' => \mod_agora_mock_central::error(429, 'too_many_requests', 'slow down'),
        ]));

        $network = (new \mod_agora\check\network())->get_result();

        $this->assertSame(result::ERROR, $network->get_status());
        $this->assertSame(get_string('checkratelimited', 'mod_agora'), $network->get_summary());
        $this->assertNotSame(get_string('checknetworkerror', 'mod_agora'), $network->get_summary());
        // The reason is named by the «address and login» pair, and it is fixed by waiting.
        $this->assertSame(
            get_string('errloginratelimited', 'mod_agora', (object) [
                'role' => get_string('accountadmin', 'mod_agora'),
            ]),
            $network->get_details()
        );
    }

    /**
     * An error on the Agora side (5xx): the summary names the answer code.
     *
     * @return void
     */
    public function test_server_fault_names_the_code(): void {
        connection::set_client($this->liststreams_client(
            \mod_agora_mock_central::error(503, 'unavailable', 'database is down')
        ));

        $network = (new \mod_agora\check\network())->get_result();

        $this->assertSame(result::ERROR, $network->get_status());
        $this->assertSame(get_string('checkserverfault', 'mod_agora', 503), $network->get_summary());
        $this->assertNotSame(get_string('checknetworkerror', 'mod_agora'), $network->get_summary());
        // The refusal body from Agora is in the details: it is fixed on its side.
        $this->assertStringContainsString('database is down', $network->get_details());
    }

    /**
     * The login rate (429) is named by its reason in the role checks too.
     *
     * The invariant «a check names the reason and the field» stands on all
     * three checks that go to Agora under an account: otherwise the same
     * reason would be called by three different names in the report.
     *
     * @return void
     */
    public function test_rate_limited_login_names_the_rate_in_role_checks(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'POST /auth/login' => \mod_agora_mock_central::error(429, 'too_many_requests', 'slow down'),
        ]));

        $expected = get_string('checkratelimited', 'mod_agora');

        $player = (new \mod_agora\check\playerrole())->get_result();
        $this->assertSame(result::ERROR, $player->get_status());
        $this->assertSame($expected, $player->get_summary());
        $this->assertNotSame(get_string('checknetworkerror', 'mod_agora'), $player->get_summary());
        $this->assertNotSame(get_string('checkplayerroleerror', 'mod_agora'), $player->get_summary());
        $this->assertSame(
            get_string('errloginratelimited', 'mod_agora', (object) [
                'role' => get_string('accountplayer', 'mod_agora'),
            ]),
            $player->get_details()
        );

        $admin = (new \mod_agora\check\adminrole())->get_result();
        $this->assertSame(result::ERROR, $admin->get_status());
        $this->assertSame($expected, $admin->get_summary());
        $this->assertNotSame(get_string('checknetworkerror', 'mod_agora'), $admin->get_summary());
        $this->assertNotSame(get_string('checkadminroleerror', 'mod_agora'), $admin->get_summary());
        $this->assertSame(
            get_string('errloginratelimited', 'mod_agora', (object) [
                'role' => get_string('accountadmin', 'mod_agora'),
            ]),
            $admin->get_details()
        );
    }

    /**
     * An error on the Agora side (5xx) is named by the code in the role checks too.
     *
     * Before the fix both said «the role does not allow», and the operator went
     * to fix the role, which was fine.
     *
     * @return void
     */
    public function test_server_fault_names_the_code_in_role_checks(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /whoami' => \mod_agora_mock_central::error(502, 'badgateway', 'upstream is down'),
        ]));

        $expected = get_string('checkserverfault', 'mod_agora', 502);

        $player = (new \mod_agora\check\playerrole())->get_result();
        $this->assertSame(result::ERROR, $player->get_status());
        $this->assertSame($expected, $player->get_summary());
        $this->assertNotSame(get_string('checknetworkerror', 'mod_agora'), $player->get_summary());
        $this->assertNotSame(get_string('checkplayerroleerror', 'mod_agora'), $player->get_summary());
        $this->assertStringContainsString('upstream is down', $player->get_details());

        $admin = (new \mod_agora\check\adminrole())->get_result();
        $this->assertSame(result::ERROR, $admin->get_status());
        $this->assertSame($expected, $admin->get_summary());
        $this->assertNotSame(get_string('checknetworkerror', 'mod_agora'), $admin->get_summary());
        $this->assertNotSame(get_string('checkadminroleerror', 'mod_agora'), $admin->get_summary());
        $this->assertStringContainsString('upstream is down', $admin->get_details());
    }

    /**
     * The connection is not filled in: the network check is UNKNOWN, not «red».
     *
     * There is nothing to judge by, and ERROR would name the wrong one of the
     * two possible reasons.
     *
     * @return void
     */
    public function test_unconfigured_connection_is_unknown_not_error(): void {
        connection::set_client($this->unconfigured_client());

        $network = (new \mod_agora\check\network())->get_result();

        $this->assertSame(result::UNKNOWN, $network->get_status());
        $this->assertSame(get_string('checkunknown', 'mod_agora'), $network->get_summary());
    }

    /**
     * The plugin check results as the «System status» report assembles them:
     * through the registration callback, by the check class.
     *
     * @return result[] check class => result
     */
    private function report(): array {
        require_once(__DIR__ . '/../lib.php');
        $results = [];
        foreach (mod_agora_status_checks() as $check) {
            $results[get_class($check)] = $check->get_result();
        }
        return $results;
    }

    /**
     * A licence without accounts (404 on login): all three checks that log into
     * Agora name the licence — not the role and not «did not give the list».
     *
     * @return void
     */
    public function test_licence_without_accounts_is_named_by_every_check(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'POST /auth/login' => \mod_agora_mock_central::error(404, 'not_found', 'no accounts in this licence'),
        ]));

        $report = $this->report();
        $licence = get_string('checklicense', 'mod_agora');

        foreach (
            [\mod_agora\check\network::class, \mod_agora\check\playerrole::class,
                \mod_agora\check\adminrole::class] as $class
        ) {
            $this->assertSame(result::ERROR, $report[$class]->get_status(), $class);
            $this->assertSame($licence, $report[$class]->get_summary(), $class);
        }
    }

    /**
     * A garbage body with the code 200 (a proxy page): «the answer was not
     * parsed», not «the role does not allow».
     *
     * @return void
     */
    public function test_garbage_answer_is_not_a_role_refusal(): void {
        $page = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/html'], '<html>proxy login</html>');
        connection::set_client(\mod_agora_mock_central::client([
            'GET /whoami' => $page,
            'GET /streams/configs' => $page,
        ]));

        $report = $this->report();
        $garbage = get_string('checkbadresponse', 'mod_agora');

        foreach (
            [\mod_agora\check\network::class, \mod_agora\check\playerrole::class,
                \mod_agora\check\adminrole::class] as $class
        ) {
            $this->assertSame($garbage, $report[$class]->get_summary(), $class);
            $this->assertStringContainsString('proxy login', $report[$class]->get_details(), $class);
        }
    }

    /**
     * 404 on `whoami` (not on login) and other codes — a common summary with
     * the code, not «the role does not allow»: the role is unknown in this case.
     *
     * @return void
     */
    public function test_other_codes_are_not_named_a_role_refusal(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /whoami' => \mod_agora_mock_central::error(404, 'not_found', 'no whoami here'),
        ]));

        $report = $this->report();

        foreach (
            [\mod_agora\check\playerrole::class => 'checkplayerroleerror',
                \mod_agora\check\adminrole::class => 'checkadminroleerror'] as $class => $roleerror
        ) {
            $this->assertSame(result::ERROR, $report[$class]->get_status(), $class);
            $this->assertSame(get_string('checkfailed', 'mod_agora', 404), $report[$class]->get_summary(), $class);
            $this->assertNotSame(get_string($roleerror, 'mod_agora'), $report[$class]->get_summary(), $class);
        }
    }

    /**
     * The role does not allow (403) — still «the role does not allow»: the
     * narrowing did not eat the only case where this summary is right.
     *
     * @return void
     */
    public function test_forbidden_whoami_still_names_the_role(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /whoami' => \mod_agora_mock_central::error(403, 'forbidden', 'no'),
        ]));

        $report = $this->report();

        $this->assertSame(
            get_string('checkplayerroleerror', 'mod_agora'),
            $report[\mod_agora\check\playerrole::class]->get_summary()
        );
        $this->assertSame(
            get_string('checkadminroleerror', 'mod_agora'),
            $report[\mod_agora\check\adminrole::class]->get_summary()
        );
    }

    /**
     * `ticket_keys` in the admission policy is not a sign of disabled tickets:
     * only `open_playback` judges.
     *
     * @return void
     */
    public function test_ticket_keys_do_not_decide_open_playback(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /auth' => \mod_agora_mock_central::json(200, ['open_playback' => false, 'ticket_keys' => []]),
        ]));
        $this->assertSame(result::OK, $this->report()[\mod_agora\check\openplayback::class]->get_status());

        connection::set_client(\mod_agora_mock_central::client([
            'GET /auth' => \mod_agora_mock_central::json(200, ['open_playback' => true, 'ticket_keys' => ['k1']]),
        ]));
        $this->assertSame(result::WARNING, $this->report()[\mod_agora\check\openplayback::class]->get_status());
    }

    /**
     * The server does not answer: the role checks are UNKNOWN with a summary
     * about the network, not a common «no data» and not «code -».
     *
     * @return void
     */
    public function test_role_checks_name_the_network_when_central_is_down(): void {
        connection::set_client($this->dead_client());

        $report = $this->report();

        foreach ([\mod_agora\check\playerrole::class, \mod_agora\check\adminrole::class] as $class) {
            $this->assertSame(result::UNKNOWN, $report[$class]->get_status(), $class);
            $this->assertSame(get_string('checkunknownnetwork', 'mod_agora'), $report[$class]->get_summary(), $class);
        }
    }

    /**
     * `whoami` answered 200 with an empty object: «the answer was not parsed»,
     * not «the role does not allow» — there is no role in the answer at all.
     *
     * @return void
     */
    public function test_whoami_without_a_role_is_not_a_role_refusal(): void {
        connection::set_client(\mod_agora_mock_central::client([
            // Exactly `{}`: an empty JSON array is already rejected by the answer parsing.
            'GET /whoami' => new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{}'),
        ]));

        $report = $this->report();

        foreach ([\mod_agora\check\playerrole::class, \mod_agora\check\adminrole::class] as $class) {
            $this->assertSame(result::ERROR, $report[$class]->get_status(), $class);
            $this->assertSame(get_string('checkbadresponse', 'mod_agora'), $report[$class]->get_summary(), $class);
        }
    }

    /**
     * A refusal without an answer and without a known reason (a lock, a Moodle
     * failure) — the summary «there was no answer», not «code -».
     *
     * @return void
     */
    public function test_refusal_without_an_answer_has_no_dash_code(): void {
        $check = new class extends \mod_agora\check\base {
            /**
             * The name.
             *
             * @return string
             */
            public function get_name(): string {
                return 'probe';
            }

            /**
             * Parsing a refusal without an answer.
             *
             * @return result
             */
            public function get_result(): result {
                return $this->account_refusal(
                    new \mod_agora\local\agora_exception('errservicebusy', null, 'busy'),
                    'player',
                    'checkplayerroleerror'
                );
            }
        };

        $this->assertSame(get_string('checkfailednoresponse', 'mod_agora'), $check->get_result()->get_summary());
    }
}
