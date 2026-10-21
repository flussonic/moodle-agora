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
use mod_agora\local\stream_config;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/../lib.php');

/**
 * The `ondemand` flag: three states of the broadcast config and three forms of the player address.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\stream_config
 */
final class ondemand_test extends \advanced_testcase {
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

    /** @var \stdClass the learner */
    private $student;

    /**
     * A course, a learner and a connection to the stub Agora.
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
        set_config('grantttl', 7200, 'mod_agora');
        set_config('maxconcurrent', 2, 'mod_agora');

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $PAGE->set_url('/mod/agora/view.php');
        \cache::make('mod_agora', 'policy')->set(
            \mod_agora\local\policy::KEY,
            ['open' => false, 'fetched_at' => time()]
        );
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
     * The three states of the `ondemand` field in the broadcast config.
     *
     * @return array
     */
    public static function config_provider(): array {
        return [
            'enabled' => [['name' => 'optics-2026', 'ondemand' => true], 1],
            'disabled explicitly' => [['name' => 'optics-2026', 'ondemand' => false], 0],
            'template decides' => [['name' => 'optics-2026'], null],
            'field present but empty' => [['name' => 'optics-2026', 'ondemand' => null], null],
        ];
    }

    /**
     * The value is read from the broadcast config as a three-valued one.
     *
     * @param array $config the body of the `streams/configs/{name}` answer
     * @param int|null $expected the expected column value
     * @return void
     * @dataProvider config_provider
     */
    public function test_ondemand_is_read_from_the_config(array $config, ?int $expected): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/configs/optics-2026' => \mod_agora_mock_central::json(200, $config),
        ]));

        $this->assertSame($expected, stream_config::ondemand('optics-2026'));
    }

    /**
     * The player address carries the parameter only when the value is known.
     *
     * @return array
     */
    public static function url_provider(): array {
        return [
            'enabled' => [1, 'ondemand=true'],
            'disabled' => [0, 'ondemand=false'],
            'template decides' => [null, null],
        ];
    }

    /**
     * The parameter is not in the address until the value is known.
     *
     * @param int|null $ondemand the column value
     * @param string|null $expected what should be in the address; null — no parameter
     * @return void
     * @dataProvider url_provider
     */
    public function test_player_url_carries_ondemand_only_when_known(?int $ondemand, ?string $expected): void {
        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'stream_name' => 'optics-2026',
            'ondemand' => $ondemand,
        ]);
        $cm = get_fast_modinfo($this->course)->get_cm($agora->cmid);
        $this->setUser($this->student);

        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026' => \mod_agora_mock_central::live_stats(),
            'POST /play-sessions/grants' => \mod_agora_mock_central::json(201, [
                'jti' => 'jti-1',
                'token' => 'jwt-1',
                'expires_at' => gmdate('c', time() + 7200),
            ]),
        ]));

        $html = $this->page($agora, $cm, (int) $this->student->id);

        if ($expected === null) {
            $this->assertStringNotContainsString('ondemand=', $html);
        } else {
            $this->assertStringContainsString($expected, $html);
        }
    }
}
