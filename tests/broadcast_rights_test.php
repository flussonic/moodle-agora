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
use mod_agora\external\get_stream_state;
use mod_agora\local\connection;
use mod_agora\local\stream_state;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/fixtures/view_script.php');

/**
 * The right to lead a broadcast (`mod/agora:broadcast`): registration, access
 * points, negative scenarios without a single call to Agora; the «Publication
 * is set up» check.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\check\publish_setup
 */
final class broadcast_rights_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /**
     * A course and a stub Agora.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        connection::set_client(null);
        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
        $this->course = $this->getDataGenerator()->create_course();
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
     * An activity with phases, created by the plugin.
     *
     * @param string $streamname the broadcast name
     * @return \stdClass the activity record
     */
    private function element(string $streamname = 'rights-1'): \stdClass {
        global $DB;

        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture',
            'stream_name' => $streamname,
            'created_by_plugin' => 1,
            'has_phases' => 1,
        ]);
        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $row->cmid = $agora->cmid;
        return $row;
    }

    /**
     * The capability is registered and given to the presenter (editing and
     * non-editing) and to the manager; not to the student.
     *
     * @return void
     */
    public function test_capability_is_registered_with_teacher_archetypes(): void {
        $info = get_capability_info('mod/agora:broadcast');
        $this->assertNotFalse($info, 'The capability is registered');

        $agora = $this->element();
        $context = \context_module::instance((int) $agora->cmid);
        foreach (['teacher', 'editingteacher', 'manager'] as $role) {
            $user = $this->getDataGenerator()->create_and_enrol($this->course, $role);
            $this->assertTrue(has_capability('mod/agora:broadcast', $context, (int) $user->id), $role);
        }
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->assertFalse(has_capability('mod/agora:broadcast', $context, (int) $student->id));
    }

    /**
     * A non-editing teacher leads the broadcast: they see the «How to broadcast»
     * block and the button, but not the link to the Agora console (it is for
     * the site administrator).
     *
     * @return void
     */
    public function test_non_editing_teacher_leads_but_does_not_get_the_console(): void {
        $agora = $this->element();
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/rights-1' => \mod_agora_mock_central::live_stats('rights-1'),
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary([
                [30000, 'publish', 'rights-1', 'listening'],
            ]),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-r1',
                'token' => 'jwt-r1',
                'expires_at' => gmdate('c', time() + HOURSECS),
            ]),
        ]);
        connection::set_client($client);
        $this->setUser($teacher);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        global $DB;
        $DB->set_field('agora', 'srt_port', 30000, ['id' => $agora->id]);
        $DB->set_field('agora', 'srt_key', \core\encryption::encrypt('KeyKeyKeyKeyKeyKeyKeyKeyKeyKey12'), ['id' => $agora->id]);

        $html = \mod_agora_view_script::run((int) $agora->cmid);

        $this->assertStringContainsString(
            get_string('publishblocktitle', 'mod_agora'),
            $html,
            'The «How to broadcast» block is there'
        );
        $this->assertMatchesRegularExpression(
            '#data-action="agora-start"#',
            $html,
            'The "Start broadcast" button is present'
        );
        $this->assertStringNotContainsString(
            'data-action="agora-console"',
            $html,
            'The console is for the site admin, not the teacher'
        );
    }

    /**
     * A learner crafting a direct action request gets 403 before any call to
     * Agora — at every access point (D11).
     *
     * @return void
     */
    public function test_direct_action_requests_are_refused_without_the_capability(): void {
        $agora = $this->element();
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/rights-1' => \mod_agora_mock_central::live_stats('rights-1'),
        ]);
        connection::set_client($client);
        $this->setUser($student);

        foreach (['startbroadcast', 'endbroadcast', 'revealkey', 'rotatekey'] as $action) {
            $refusal = \mod_agora_view_script::refusal((int) $agora->cmid, [
                'action' => $action,
                'sesskey' => sesskey(),
            ], 'view.php', true);
            $this->assertNotNull($refusal, $action . ': refused');
            $this->assertInstanceOf(\required_capability_exception::class, $refusal, $action . ': 403 by capability');
        }
        $this->assertSame([], $client->request_log(), 'The call never reached Agora');
    }

    /**
     * The state poll carries no lead actions for the learner.
     *
     * @return void
     */
    public function test_poll_carries_no_lead_actions_for_the_student(): void {
        $agora = $this->element();
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/rights-1' => \mod_agora_mock_central::live_stats('rights-1'),
        ]));
        stream_state::for_page('rights-1');
        $this->setUser($student);

        $answer = get_stream_state::execute((int) $agora->cmid);
        $keys = array_column($answer['actions'], 'key');

        $this->assertNotContains('start', $keys);
        $this->assertNotContains('end', $keys);
        $this->assertNotContains('startdisabled', $keys);
    }

    /**
     * The matrix of the «Publication is set up» check: the address and the
     * range, each refusal names its field. Frozen 2026-10-16: the publisher
     * account is not required and not looked at while in-browser broadcasting
     * is hidden.
     *
     * @return void
     */
    public function test_publish_setup_check_matrix(): void {
        $agora = $this->element();
        $this->setAdminUser();
        set_config('srtportrange', '30000:30002', 'mod_agora');

        $base = [
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary(),
        ];

        // Everything is set up — green, with free ports and a zero of «without
        // export». The publisher account is not set at all: the check does not
        // need it while in-browser broadcasting is frozen.
        connection::set_client(\mod_agora_mock_central::client($base));
        $result = (new \mod_agora\check\publish_setup())->get_result();
        $this->assertSame(result::OK, $result->get_status());
        $this->assertStringContainsString('publish.agora.example.com', $result->get_details());

        // The address only as a fallback setting — still green.
        connection::set_client(\mod_agora_mock_central::client([
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary([], null),
        ]));
        set_config('publishaddress', 'fallback.example.com', 'mod_agora');
        $result = (new \mod_agora\check\publish_setup())->get_result();
        $this->assertSame(result::OK, $result->get_status(), 'Fallback address from the setting');
        set_config('publishaddress', '', 'mod_agora');

        // Neither an address in Agora nor a fallback — a reason with a field.
        connection::set_client(\mod_agora_mock_central::client([
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary([], null),
        ]));
        $result = (new \mod_agora\check\publish_setup())->get_result();
        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString(get_string('settingpublishaddress', 'mod_agora'), $result->get_details());

        // The range is not set — its own reason.
        unset_config('srtportrange', 'mod_agora');
        connection::set_client(\mod_agora_mock_central::client($base));
        $result = (new \mod_agora\check\publish_setup())->get_result();
        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString(get_string('settingsrtportrange', 'mod_agora'), $result->get_details());
        set_config('srtportrange', '30000:30002', 'mod_agora');

        // The range is fully occupied — «free ports: 0».
        $client = \mod_agora_mock_central::client([
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary([
                [30000, 'publish', 'operator-a', 'listening'],
                [30001, 'publish', 'operator-b', 'listening'],
                [30002, 'play', 'operator-c', 'intent'],
            ]),
        ]);
        connection::set_client($client);
        $result = (new \mod_agora\check\publish_setup())->get_result();
        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString(get_string('settingsrtportrange', 'mod_agora'), $result->get_details());

        // The check never asks Agora who the publisher is: no whoami calls.
        $this->assertEmpty(
            array_filter($client->request_log(), static function (string $line): bool {
                return strpos($line, 'whoami') !== false;
            }),
            'No sign-in probes while in-browser broadcasting is frozen'
        );
    }

    /**
     * The «recordings without export for over an hour» line is read from the
     * setting that the scheduled task writes: the check does not go to central.
     *
     * @return void
     */
    public function test_check_shows_the_exportless_counter_from_the_task(): void {
        $this->element();
        $this->setAdminUser();
        set_config('srtportrange', '30000:30002', 'mod_agora');
        set_config('recordexportless', 3, 'mod_agora');

        $client = \mod_agora_mock_central::client([
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary(),
        ]);
        connection::set_client($client);

        $result = (new \mod_agora\check\publish_setup())->get_result();

        $this->assertSame(result::OK, $result->get_status());
        $this->assertStringContainsString(
            get_string('checkrecordexportless', 'mod_agora', 3),
            $result->get_details()
        );
        // The port summary — yes; for the recordings and exports the check
        // does not go to central: the scheduled task writes the counter.
        $this->assertFalse(
            count(array_filter($client->request_log(), static function (string $line): bool {
                return strpos($line, 'recordings') !== false;
            })) > 0,
            'The check does not go for recordings'
        );
    }
}
