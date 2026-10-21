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

use mod_agora\local\connection;
use mod_agora\local\grant_manager;
use mod_agora\local\player_view;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * Binding the ticket to the learner's address: issuing, reuse, address change.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\grant_manager
 */
final class bindip_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the learner */
    private $student;

    /** @var \stdClass the activity record */
    private $agora;

    /** @var \cm_info the course module */
    private $cm;

    /**
     * A course, a learner, an activity for a broadcast and the address they come from.
     *
     * @return void
     */
    protected function setUp(): void {
        global $PAGE;

        parent::setUp();
        $this->resetAfterTest();
        connection::set_client(null);

        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
        set_config('grantttl', 7200, 'mod_agora');
        set_config('maxconcurrent', 2, 'mod_agora');

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user(['username' => 'ivanov']);
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'stream_name' => 'optics-2026',
            'display_mode' => 'auto',
        ]);
        $this->cm = get_fast_modinfo($this->course)->get_cm($this->agora->cmid);

        // The admission policy is read from central by the activity page once in 60 seconds.
        \cache::make('mod_agora', 'policy')->set(
            \mod_agora\local\policy::KEY,
            ['open' => false, 'fetched_at' => time()]
        );

        $PAGE->set_url('/mod/agora/view.php', ['id' => $this->agora->cmid]);
        $this->from('203.0.113.7');
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
     * Sets the address the learner comes from.
     *
     * The same door as the browser: the request address is read by the core's
     * `getremoteaddr()`, not by the plugin.
     *
     * @param string $address the request address
     * @return void
     */
    private function from(string $address): void {
        $_SERVER['REMOTE_ADDR'] = $address;
    }

    /**
     * Opens the activity page the way `view.php` assembles it.
     *
     * @return string HTML
     */
    private function page(): string {
        global $OUTPUT;

        $scene = player_view::scene($this->agora, $this->cm, (int) $this->student->id, false);
        return $OUTPUT->render(new \mod_agora\output\watch_page($this->agora, $this->cm, $scene, false));
    }

    /**
     * The routes of the stub Agora: the broadcast state and the ticket issue.
     *
     * @param array $extra additional routes
     * @param mixed $grant the ticket issue answer or a list of answers
     * @return array
     */
    private function routes(array $extra = [], $grant = null): array {
        return $extra + [
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /play-sessions/grants' => $grant ?? $this->grant(),
        ];
    }

    /**
     * The «ticket issued» answer.
     *
     * @param string $jti the ticket identifier
     * @return \GuzzleHttp\Psr7\Response
     */
    private function grant(string $jti = 'jti-1'): \GuzzleHttp\Psr7\Response {
        return \mod_agora_mock_central::json(201, [
            'jti' => $jti,
            'token' => 'jwt-' . $jti,
            'streams' => ['optics-2026'],
            'status' => 'issued',
            'expires_at' => gmdate('c', time() + 7200),
        ]);
    }

    /**
     * How many times the plugin asked for a new ticket.
     *
     * @param \mod_agora\local\agora_client $client the stub client
     * @return int
     */
    private function grant_posts(\mod_agora\local\agora_client $client): int {
        return count(array_filter($client->request_log(), function (string $line): bool {
            return $line === 'POST /central/api-v4/play-sessions/grants';
        }));
    }

    /**
     * In a fresh installation the address binding is on.
     *
     * @return void
     */
    public function test_binding_is_on_out_of_the_box(): void {
        $this->assertEquals(1, get_config('mod_agora', 'bindip'));
    }

    /**
     * The ticket goes to central with the learner's address and is stored in the table with it.
     *
     * @return void
     */
    public function test_grant_carries_the_listener_address(): void {
        global $DB;

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes()));

        $this->page();

        $this->assertSame('203.0.113.7', \mod_agora_mock_central::last_body()['bind_ip']);
        $this->assertSame('203.0.113.7', $DB->get_field('agora_grants', 'bind_ip', ['jti' => 'jti-1']));
    }

    /**
     * From the same address a live ticket is reused: there is no second request.
     *
     * @return void
     */
    public function test_same_address_reuses_the_grant(): void {
        global $DB;

        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes());
        connection::set_client($client);

        $this->page();
        $html = $this->page();

        $this->assertSame(1, $this->grant_posts($client));
        $this->assertSame(1, $DB->count_records('agora_grants', ['agoraid' => $this->agora->id]));
        $this->assertStringContainsString('token=jwt-jti-1', $html);
        $this->assertNotContains('DELETE /central/api-v4/play-sessions/grants/jti-1', $client->request_log());
    }

    /**
     * From another address the former ticket is revoked, and a new one is issued to the new address.
     *
     * @return void
     */
    public function test_new_address_revokes_the_old_grant_and_issues_a_new_one(): void {
        global $DB;

        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes(
            ['DELETE /play-sessions/grants/jti-1' => new \GuzzleHttp\Psr7\Response(204, [], '')],
            [$this->grant('jti-1'), $this->grant('jti-2')]
        ));
        connection::set_client($client);

        $this->page();
        $this->from('198.51.100.4');
        $html = $this->page();

        $this->assertContains('DELETE /central/api-v4/play-sessions/grants/jti-1', $client->request_log());
        $this->assertSame('revoked', $DB->get_field('agora_grants', 'status', ['jti' => 'jti-1']));
        $this->assertNull($DB->get_field('agora_grants', 'token', ['jti' => 'jti-1']));
        $this->assertSame('198.51.100.4', $DB->get_field('agora_grants', 'bind_ip', ['jti' => 'jti-2']));
        $this->assertSame('198.51.100.4', \mod_agora_mock_central::last_body()['bind_ip']);
        $this->assertStringContainsString('token=jwt-jti-2', $html);
    }

    /**
     * A central refusal on revoking the former ticket does not block issuing the new one.
     *
     * @return void
     */
    public function test_refused_revoke_does_not_block_the_new_grant(): void {
        global $DB;

        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes(
            ['DELETE /play-sessions/grants/jti-1' => \mod_agora_mock_central::error(500, 'internal', 'database is tired')],
            [$this->grant('jti-1'), $this->grant('jti-2')]
        ));
        connection::set_client($client);

        $this->page();
        $this->from('198.51.100.4');
        $sink = $this->redirectEvents();
        $html = $this->page();
        $failures = array_values(array_filter($sink->get_events(), static function ($event): bool {
            return $event instanceof \mod_agora\event\central_failed;
        }));
        $sink->close();

        // The refusal reason goes into the event log, the page does not fail because of it.
        $this->assertCount(1, $failures, 'The failed revoke is recorded in the event log');
        $this->assertSame('oprevokegrant', $failures[0]->other['operation']);
        $this->assertSame('player', $failures[0]->other['account']);
        $this->assertSame(500, (int) $failures[0]->other['status']);
        $this->assertSame((int) $this->cm->context->id, (int) $failures[0]->contextid, 'The record is in the activity context');
        $this->assertStringContainsString('token=jwt-jti-2', $html);
        $this->assertSame('revoked', $DB->get_field('agora_grants', 'status', ['jti' => 'jti-1']));
    }

    /**
     * One and the same IPv6 record in two forms is one address.
     *
     * @return void
     */
    public function test_ipv6_written_two_ways_is_one_address(): void {
        global $DB;

        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes());
        connection::set_client($client);

        $this->from('2001:0db8:0000:0000:0000:0000:0000:0001');
        $this->page();
        $this->from('2001:db8::1');
        $this->page();

        $this->assertSame(1, $this->grant_posts($client));
        $this->assertSame(1, $DB->count_records('agora_grants', ['agoraid' => $this->agora->id]));
        $this->assertSame(
            '2001:db8:0:0:0:0:0:1',
            $DB->get_field('agora_grants', 'bind_ip', ['jti' => 'jti-1'])
        );
    }

    /**
     * Two IPv6 records fed straight to the manager are also one address.
     *
     * Through the page door different records are already reduced to one by
     * `getremoteaddr()`; own normalization is needed at the parameter boundary,
     * where nobody promised a clean address.
     *
     * @return void
     */
    public function test_ipv6_forms_through_the_manager_are_one_address(): void {
        global $DB;

        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes());
        connection::set_client($client);

        foreach (['2001:0DB8:0000:0000:0000:0000:0000:0001', '2001:db8::1'] as $address) {
            grant_manager::for_user(
                $this->agora,
                (int) $this->student->id,
                (int) $this->course->id,
                (int) $this->cm->id,
                $address
            );
        }

        $this->assertSame(1, $this->grant_posts($client));
        $this->assertSame(1, $DB->count_records('agora_grants', ['agoraid' => $this->agora->id]));
    }

    /**
     * With binding off the address is not compared and not written.
     *
     * @return void
     */
    public function test_binding_off_ignores_the_address(): void {
        global $DB;

        set_config('bindip', 0, 'mod_agora');

        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes());
        connection::set_client($client);

        $this->page();
        $this->from('198.51.100.4');
        $this->page();

        $this->assertSame(1, $this->grant_posts($client));
        $this->assertArrayNotHasKey('bind_ip', \mod_agora_mock_central::last_body());
        $this->assertNull($DB->get_field('agora_grants', 'bind_ip', ['jti' => 'jti-1']));
    }

    /**
     * Garbage instead of an address: a ticket without binding and a warning in the log.
     *
     * Through the learner's door garbage arrives already depersonalized:
     * `getremoteaddr()` itself cleans the address and gives `0.0.0.0` instead of
     * an unparsed one. A binding to such an address would silently kill watching
     * for everyone, so there is none.
     *
     * @return void
     */
    public function test_unusable_address_gives_a_grant_without_binding(): void {
        global $DB;

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes()));

        $this->from('not-an-address');
        $sink = $this->redirectEvents();
        $html = $this->page();
        $unbound = $this->unbound($sink->get_events());
        $sink->close();

        $this->assertCount(1, $unbound, 'A warning in the event log');
        $this->assertSame('jti-1', $unbound[0]->other['jti']);
        // Through the page door garbage arrives already as `0.0.0.0`.
        $this->assertSame('0.0.0.0', $unbound[0]->other['address']);
        $this->assertStringContainsString('reverseproxy', $unbound[0]->get_description());
        $this->assertArrayNotHasKey('bind_ip', \mod_agora_mock_central::last_body());
        $this->assertNull($DB->get_field('agora_grants', 'bind_ip', ['jti' => 'jti-1']));
        $this->assertStringContainsString('token=jwt-jti-1', $html);
    }

    /**
     * An address that the plugin itself cannot parse does not spoil the ticket either.
     *
     * The core does not deliver garbage to `for_user`, but the method contract
     * admits it: the address arrives as a parameter, and the guard stands on it.
     *
     * @return void
     */
    public function test_garbage_address_passed_to_the_manager_is_refused(): void {
        global $DB;

        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes()));

        $sink = $this->redirectEvents();
        $grant = grant_manager::for_user(
            $this->agora,
            (int) $this->student->id,
            (int) $this->course->id,
            (int) $this->cm->id,
            'not-an-address'
        );
        $unbound = $this->unbound($sink->get_events());
        $sink->close();

        $this->assertCount(1, $unbound, 'A warning in the event log');
        $this->assertSame('not-an-address', $unbound[0]->other['address']);
        $this->assertArrayNotHasKey('bind_ip', \mod_agora_mock_central::last_body());
        $this->assertNull($DB->get_field('agora_grants', 'bind_ip', ['id' => $grant->id]));
    }

    /**
     * The «ticket without binding» records from the intercepted events.
     *
     * @param array $events the events
     * @return array
     */
    private function unbound(array $events): array {
        return array_values(array_filter($events, static function ($event): bool {
            return $event instanceof \mod_agora\event\grant_unbound;
        }));
    }

    /**
     * Binding is off — the `0.0.0.0` address is no reason for a warning: there
     * would be no binding anyway.
     *
     * @return void
     */
    public function test_binding_off_gives_no_unbound_warning(): void {
        set_config('bindip', 0, 'mod_agora');
        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes()));

        $this->from('0.0.0.0');
        $sink = $this->redirectEvents();
        $this->page();
        $unbound = $this->unbound($sink->get_events());
        $sink->close();

        $this->assertSame([], $unbound);
    }

    /**
     * The «without binding» warning is one per learner per hour, not per issue:
     * behind a broken proxy the address is not parsed at every activity.
     *
     * @return void
     */
    public function test_unbound_warning_is_logged_once_an_hour_per_listener(): void {
        $second = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'stream_name' => 'townhall',
            'display_mode' => 'auto',
        ]);
        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/townhall' => \mod_agora_mock_central::live_stats('townhall'),
        ] + $this->routes([], [$this->grant('jti-1'), $this->grant('jti-2')])));

        $this->from('not-an-address');
        $sink = $this->redirectEvents();
        $this->page();
        $cm = get_fast_modinfo($this->course)->get_cm($second->cmid);
        player_view::scene($second, $cm, (int) $this->student->id, false);
        $unbound = $this->unbound($sink->get_events());
        $issued = array_filter($sink->get_events(), static function ($event): bool {
            return $event instanceof \mod_agora\event\grant_issued;
        });
        $sink->close();

        $this->assertCount(2, $issued, 'Two unbound tickets issued');
        $this->assertCount(1, $unbound, 'A single warning');
    }

    /**
     * A usable address gives no warning: the record is only where there is no binding.
     *
     * @return void
     */
    public function test_usable_address_gives_no_unbound_warning(): void {
        $this->setUser($this->student);
        connection::set_client(\mod_agora_mock_central::client($this->routes()));

        $sink = $this->redirectEvents();
        $this->page();
        $unbound = $this->unbound($sink->get_events());
        $sink->close();

        $this->assertSame([], $unbound);
    }

    /**
     * The pair's expiry is the greatest of the two: a ticket with a shorter
     * lifetime, issued after an address change, does not shorten the pair's age.
     *
     * @return void
     */
    public function test_pair_keeps_the_latest_expiry_of_two_tickets(): void {
        global $DB;

        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes([], function (\Psr\Http\Message\RequestInterface $request) {
            $body = json_decode((string) $request->getBody(), true);
            $ttl = (int) ($body['ttl_secs'] ?? 0);
            return \mod_agora_mock_central::json(201, [
                'jti' => 'jti-' . $ttl,
                'token' => 'jwt-' . $ttl,
                'expires_at' => gmdate('c', time() + $ttl),
            ]);
        }));
        connection::set_client($client);

        $this->page();
        $first = (int) $DB->get_field('agora_watch', 'lastexpiry', ['agoraid' => $this->agora->id]);
        // The LMS administrator shortened the ticket lifetime, the learner came
        // from another address: the new ticket lives shorter than the former one.
        set_config('grantttl', 600, 'mod_agora');
        $this->from('198.51.100.4');
        $this->page();

        $this->assertSame('jti-600', $DB->get_field('agora_grants', 'jti', ['status' => 'issued']));
        $this->assertSame($first, (int) $DB->get_field('agora_watch', 'lastexpiry', ['agoraid' => $this->agora->id]));
    }

    /**
     * The preview service ticket knows no binding: it is presented by the Moodle server.
     *
     * @return void
     */
    public function test_preview_grant_has_no_binding(): void {
        global $DB;

        connection::set_client(\mod_agora_mock_central::client([
            'POST /play-sessions/grants' => $this->grant('jti-preview'),
        ]));

        $grant = grant_manager::for_preview('optics-2026');

        $this->assertArrayNotHasKey('bind_ip', \mod_agora_mock_central::last_body());
        $this->assertNull($DB->get_field('agora_grants', 'bind_ip', ['id' => $grant->id]));
    }

    /**
     * A former unbound ticket on a site where binding was turned on is reissued.
     *
     * @return void
     */
    public function test_grant_issued_before_binding_was_on_is_replaced(): void {
        global $DB;

        $old = $this->getDataGenerator()->get_plugin_generator('mod_agora')->create_grant(
            $this->agora,
            (int) $this->student->id,
            ['jti' => 'jti-old', 'token' => 'jwt-old', 'bind_ip' => null]
        );

        $this->setUser($this->student);
        $client = \mod_agora_mock_central::client($this->routes(
            ['DELETE /play-sessions/grants/jti-old' => new \GuzzleHttp\Psr7\Response(204, [], '')]
        ));
        connection::set_client($client);

        $html = $this->page();

        $this->assertContains('DELETE /central/api-v4/play-sessions/grants/jti-old', $client->request_log());
        $this->assertSame('revoked', $DB->get_field('agora_grants', 'status', ['id' => $old->id]));
        $this->assertStringContainsString('token=jwt-jti-1', $html);
        $this->assertSame('203.0.113.7', $DB->get_field('agora_grants', 'bind_ip', ['jti' => 'jti-1']));
    }
}
