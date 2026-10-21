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
use mod_agora\local\player_view;
use mod_agora\local\policy;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * The open playback warning: the course editor sees it, the learner does not.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\policy
 */
final class openplayback_test extends \advanced_testcase {
    /**
     * The activity page markup as assembled by `view.php`.
     *
     * The scene plus the `watch_page` body: the transitions there are already
     * buttons, and the test looks at the markup a human sees, not at an
     * intermediate glue.
     *
     * @param \stdClass $agora the activity record
     * @param \cm_info $cm the course module
     * @param int $userid the learner
     * @param bool $wantrecord the learner pressed «watch the recording»
     * @return string HTML
     */
    private function page(\stdClass $agora, \cm_info $cm, int $userid, bool $wantrecord = false): string {
        global $OUTPUT;

        $scene = player_view::scene($agora, $cm, $userid, $wantrecord);
        return $OUTPUT->render(new \mod_agora\output\watch_page($agora, $cm, $scene, $wantrecord));
    }
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the activity record */
    private $agora;

    /** @var \cm_info the course module */
    private $cm;

    /**
     * A course and an activity for a broadcast.
     *
     * @return void
     */
    protected function setUp(): void {
        global $PAGE;

        parent::setUp();
        $this->resetAfterTest();
        // The learner address: it is always present in the browser request, and
        // the ticket is bound to it.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        connection::set_client(null);

        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');

        $this->course = $this->getDataGenerator()->create_course();
        $this->agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'stream_name' => 'optics-2026',
        ]);
        $this->cm = get_fast_modinfo($this->course)->get_cm($this->agora->cmid);
        $PAGE->set_url('/mod/agora/view.php', ['id' => $this->agora->cmid]);
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
     * Routes for showing the page with open playback enabled.
     *
     * @return array
     */
    private function routes(): array {
        return [
            'GET /auth' => \mod_agora_mock_central::json(200, ['open_playback' => true]),
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::json(200, ['status' => 'idle']),
        ];
    }

    /**
     * The course editor sees the warning that the ticket protects nothing.
     *
     * @return void
     */
    public function test_editor_sees_the_warning(): void {
        connection::set_client(\mod_agora_mock_central::client($this->routes()));
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);

        $html = $this->page($this->agora, $this->cm, (int) $teacher->id);

        $this->assertStringContainsString(get_string('openplaybackwarning', 'mod_agora'), $html);
    }

    /**
     * The learner sees no warning, and the policy is not requested for them.
     *
     * @return void
     */
    public function test_learner_sees_no_warning_and_costs_no_request(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::json(200, ['status' => 'idle']),
        ]);
        connection::set_client($client);
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $html = $this->page($this->agora, $this->cm, (int) $student->id);

        $this->assertStringNotContainsString(get_string('openplaybackwarning', 'mod_agora'), $html);
        $this->assertNotContains('GET /central/api-v4/auth', $client->request_log());
    }

    /**
     * The policy is read from central no more than once a minute.
     *
     * @return void
     */
    public function test_policy_is_cached(): void {
        $client = \mod_agora_mock_central::client([
            'GET /auth' => \mod_agora_mock_central::json(200, ['open_playback' => true]),
        ]);
        connection::set_client($client);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(policy::open_playback());
        }

        $calls = array_filter($client->request_log(), function (string $line): bool {
            return $line === 'GET /central/api-v4/auth';
        });
        $this->assertCount(1, $calls);
    }
}
