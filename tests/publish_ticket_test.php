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

use mod_agora\external\get_publish_ticket;
use mod_agora\local\connection;
use mod_agora\local\grant_manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/fixtures/view_script.php');

/**
 * In-browser publishing: the publisher account, the publish ticket, its
 * revocation and reissue, the entry points of the publisher page.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\grant_manager
 * @covers     \mod_agora\external\get_publish_ticket
 */
final class publish_ticket_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the presenter */
    private $teacher;

    /**
     * A course, a presenter and a stub Agora.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        connection::set_client(null);
        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
        set_config('whipaddress', 'https://node.agora.example.com', 'mod_agora');
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
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
     * An activity with phases, created by the plugin, in the «planned» phase.
     *
     * @return \stdClass the activity record
     */
    private function element(): \stdClass {
        global $DB;

        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture',
            'stream_name' => 'optics-2026',
            'created_by_plugin' => 1,
            'has_phases' => 1,
        ]);
        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $row->cmid = $agora->cmid;
        return $row;
    }

    /**
     * A client that issues tickets of any role.
     *
     * @param array $routes additional routes
     * @return \mod_agora\local\agora_client the client
     */
    private function ticket_client(array $routes = []): \mod_agora\local\agora_client {
        return \mod_agora_mock_central::client([
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-pub-1',
                'token' => 'jwt-pub-1',
                'expires_at' => gmdate('c', time() + 4 * HOURSECS),
            ]),
        ] + $routes);
    }

    /**
     * Issuing a publish ticket: the call composition, the table record and the event.
     *
     * @return void
     */
    public function test_publish_ticket_is_issued_by_the_publisher_account(): void {
        global $DB;

        $agora = $this->element();
        $client = $this->ticket_client();
        connection::set_client($client);

        $sink = $this->redirectEvents();
        $grant = grant_manager::for_publish($agora, (int) $this->teacher->id, (int) $agora->cmid, '203.0.113.7');
        $issued = $this->events_of($sink, \mod_agora\event\publish_ticket_issued::class);
        $sink->close();

        $this->assertSame('jti-pub-1', $grant->jti);
        $body = \mod_agora_mock_central::last_body();
        $this->assertSame(['optics-2026'], $body['streams']);
        $this->assertSame('hr-lms:publish:' . $this->teacher->id . ':' . $this->teacher->username, $body['user_id']);
        $this->assertSame(4 * HOURSECS, $body['ttl_secs']);
        // A publish ticket is one session per ticket.
        $this->assertSame(1, $body['max_concurrent']);

        $row = $DB->get_record('agora_grants', ['id' => $grant->id], '*', MUST_EXIST);
        $this->assertSame('publish', $row->purpose);
        $this->assertSame('jti-pub-1', $row->jti);
        $this->assertCount(1, $issued, 'The issue event in the log');
    }

    /**
     * The address binding follows the shared bindip setting.
     *
     * @return void
     */
    public function test_publish_ticket_binding_follows_the_bindip_setting(): void {
        $agora = $this->element();

        set_config('bindip', 1, 'mod_agora');
        connection::set_client($this->ticket_client());
        grant_manager::for_publish($agora, (int) $this->teacher->id, (int) $agora->cmid, '203.0.113.7');
        $this->assertSame('203.0.113.7', \mod_agora_mock_central::last_body()['bind_ip']);

        set_config('bindip', 0, 'mod_agora');
        connection::set_client($this->ticket_client());
        grant_manager::for_publish($agora, (int) $this->teacher->id, (int) $agora->cmid, '203.0.113.7');
        $this->assertArrayNotHasKey('bind_ip', \mod_agora_mock_central::last_body());
    }

    /**
     * A healthy ticket (more than half of its lifetime left) is reused.
     *
     * @return void
     */
    public function test_healthy_publish_ticket_is_reused(): void {
        global $DB;

        $agora = $this->element();
        $client = $this->ticket_client();
        connection::set_client($client);

        $first = grant_manager::for_publish($agora, (int) $this->teacher->id, (int) $agora->cmid, '');
        $requests = count($client->request_log());
        $second = grant_manager::for_publish($agora, (int) $this->teacher->id, (int) $agora->cmid, '');

        $this->assertSame((int) $first->id, (int) $second->id, 'The same ticket');
        $this->assertSame($requests, count($client->request_log()), 'No new requests');
        $this->assertSame(1, $DB->count_records('agora_grants', ['purpose' => 'publish']));
    }

    /**
     * Closer than half of the lifetime: a reissue is a new ticket, the old one is not revoked.
     *
     * @return void
     */
    public function test_expiring_publish_ticket_is_reissued_without_revoking_the_old_grant(): void {
        global $DB;

        $agora = $this->element();
        connection::set_client($this->ticket_client());
        $old = grant_manager::for_publish($agora, (int) $this->teacher->id, (int) $agora->cmid, '');

        // Age the ticket to less than half of its lifetime.
        $DB->set_field('agora_grants', 'expires_at', time() + HOURSECS, ['id' => $old->id]);

        $client = $this->ticket_client([
            'DELETE /play-sessions/grants/*' => \mod_agora_mock_central::json(200, []),
        ]);
        connection::set_client($client);

        $fresh = grant_manager::for_publish($agora, (int) $this->teacher->id, (int) $agora->cmid, '');

        $this->assertNotSame($old->id, $fresh->id, 'A new ticket');
        $oldrow = $DB->get_record('agora_grants', ['id' => $old->id], '*', MUST_EXIST);
        // The pulse does not revoke the former grant: a revocation would kill the
        // live WHIP session with a ban-feed of the old jti and break the running
        // broadcast (review R2, Б-1/К-2). The old ticket lives out its lifetime naturally.
        $this->assertSame('issued', $oldrow->status, 'The old one not revoked: lives out its lifetime');
        $this->assertTrue(
            count(array_filter($client->request_log(), static function (string $line): bool {
                return strpos($line, 'DELETE') === 0;
            })) === 0,
            'No revoke to central'
        );
    }

    /**
     * The external function without the capability: a refusal before any call to Agora.
     *
     * @return void
     */
    public function test_ticket_endpoint_requires_the_lead_capability(): void {
        $agora = $this->element();
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $client = $this->ticket_client();
        connection::set_client($client);
        $this->setUser($student);

        $refusal = null;
        try {
            get_publish_ticket::execute((int) $agora->cmid);
        } catch (\moodle_exception $error) {
            $refusal = $error;
        }

        $this->assertNotNull($refusal, 'The viewer is refused');
        $this->assertSame([], array_filter($client->request_log(), static function (string $line): bool {
            return strpos($line, 'grants') !== false;
        }), 'The call never reached Agora');
    }

    /**
     * The external function for the presenter: the ticket, the lifetime and the WHIP address with the token.
     *
     * @return void
     */
    public function test_ticket_endpoint_returns_ticket_and_whip_url(): void {
        $agora = $this->element();
        connection::set_client($this->ticket_client());
        $this->setUser($this->teacher);

        $answer = get_publish_ticket::execute((int) $agora->cmid);

        $this->assertSame('jwt-pub-1', $answer['token']);
        $this->assertSame(4 * HOURSECS, $answer['ttl']);
        $this->assertStringContainsString(
            'https://node.agora.example.com/streaming/whip/optics-2026?token=jwt-pub-1',
            $answer['whipurl']
        );
    }

    /**
     * Without the WHIP address in the settings — the refusal names the setting, there is no call.
     *
     * @return void
     */
    public function test_ticket_endpoint_names_the_missing_whip_address(): void {
        $agora = $this->element();
        unset_config('whipaddress', 'mod_agora');
        $client = $this->ticket_client();
        connection::set_client($client);
        $this->setUser($this->teacher);

        $refusal = null;
        try {
            get_publish_ticket::execute((int) $agora->cmid);
        } catch (\moodle_exception $error) {
            $refusal = $error;
        }

        $this->assertNotNull($refusal);
        $this->assertSame([], $client->request_log(), 'The call never reached Agora');
    }

    /**
     * The publisher page for the presenter in the «planned» phase: the publisher
     * markup and the AMD module wiring.
     *
     * @return void
     */
    public function test_publisher_page_renders_for_the_teacher(): void {
        $agora = $this->element();
        connection::set_client($this->ticket_client());
        $this->setUser($this->teacher);

        $html = \mod_agora_view_script::run((int) $agora->cmid, [], 'publish.php');
        $this->assertStringContainsString('data-region="agora-publisher"', $html);
        $this->assertStringContainsString('data-stream="optics-2026"', $html);
        $this->assertStringContainsString('data-ticketttl="14400"', $html);
        $this->assertStringNotContainsString(
            get_string('publishnowhip', 'mod_agora'),
            $html,
            'WHIP address set — no warning'
        );
    }

    /**
     * The publisher page does not belong to the learner.
     *
     * @return void
     */
    public function test_publisher_page_refuses_the_student(): void {
        $agora = $this->element();
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        connection::set_client($this->ticket_client());
        $this->setUser($student);

        $refusal = \mod_agora_view_script::refusal((int) $agora->cmid, [], 'publish.php');
        $this->assertNotNull($refusal, 'The viewer is refused');
    }

    /**
     * The publisher page does not belong to a legacy activity: a redirect to the
     * ordinary activity page.
     *
     * @return void
     */
    public function test_publisher_page_redirects_legacy_elements(): void {
        $legacy = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Old lecture',
            'stream_name' => 'legacy-stream',
            'has_phases' => 0,
        ]);
        connection::set_client($this->ticket_client());
        $this->setUser($this->teacher);

        $refusal = \mod_agora_view_script::refusal((int) $legacy->cmid, [], 'publish.php');
        $this->assertNotNull($refusal, 'Legacy — redirect');
        $this->assertInstanceOf(\moodle_exception::class, $refusal);
        $this->assertSame('redirecterrordetected', $refusal->errorcode);
    }

    /**
     * The publisher page does not belong to an ended activity: a redirect.
     *
     * @return void
     */
    public function test_publisher_page_redirects_ended_elements(): void {
        global $DB;

        $agora = $this->element();
        $DB->set_field('agora', 'started_at', time() - 3600, ['id' => $agora->id]);
        $DB->set_field('agora', 'ended_at', time() - 600, ['id' => $agora->id]);
        connection::set_client($this->ticket_client());
        $this->setUser($this->teacher);

        $refusal = \mod_agora_view_script::refusal((int) $agora->cmid, [], 'publish.php');
        $this->assertNotNull($refusal, 'Ended — redirect');
        $this->assertInstanceOf(\moodle_exception::class, $refusal);
        $this->assertSame('redirecterrordetected', $refusal->errorcode);
    }

    /**
     * «End the broadcast» revokes the live publish ticket: the WHIP broadcast
     * goes past the srt_publish section, and removing the section would not
     * stop it (D10).
     *
     * @return void
     */
    public function test_end_broadcast_revokes_publish_tickets(): void {
        global $DB;

        $agora = $this->element();
        $DB->set_field('agora', 'started_at', time() - 600, ['id' => $agora->id]);

        $client = \mod_agora_mock_central::client([
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-pub-9',
                'token' => 'jwt-pub-9',
                'expires_at' => gmdate('c', time() + 4 * HOURSECS),
            ]),
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
            'PATCH /streams/configs/optics-2026' => \mod_agora_mock_central::json(200, ['ok' => true]),
            'DELETE /play-sessions/grants/*' => \mod_agora_mock_central::json(200, []),
        ]);
        connection::set_client($client);

        grant_manager::for_publish($agora, (int) $this->teacher->id, (int) $agora->cmid, '');

        $this->setUser($this->teacher);
        try {
            \mod_agora_view_script::run((int) $agora->cmid, ['action' => 'endbroadcast', 'sesskey' => sesskey()], 'view.php', true);
            $this->fail('The action must answer with a redirect');
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode);
        }

        $row = $DB->get_record('agora_grants', ['jti' => 'jti-pub-9'], '*', MUST_EXIST);
        $this->assertSame('revoked', $row->status, 'The publish ticket revoked');
        $this->assertTrue(
            count(array_filter($client->request_log(), static function (string $line): bool {
                return strpos($line, 'DELETE') === 0;
            })) === 1,
            'The revoke went to central'
        );
    }

    /**
     * The web service declaration does not lie about the rights: reading the
     * list of external functions must show `mod/agora:broadcast` right away, not
     * `view` (review R2, М-6).
     *
     * @return void
     */
    public function test_service_declaration_requires_the_broadcast_capability(): void {
        $services = include(__DIR__ . '/../db/services.php');
        $this->assertSame(
            'mod/agora:broadcast',
            $services['mod_agora_get_publish_ticket']['capabilities'] ?? null,
            'services.php declares the broadcast capability'
        );
    }

    /**
     * Filters events of the class out of the sink log.
     *
     * @param \phpunit_event_sink $sink the event sink
     * @param string $class the event class
     * @return \core\event\base[]
     */
    private function events_of($sink, string $class): array {
        return array_values(array_filter($sink->get_events(), static function (\core\event\base $event) use ($class): bool {
            return $event instanceof $class;
        }));
    }
}
