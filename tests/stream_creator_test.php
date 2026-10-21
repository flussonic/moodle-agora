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
use mod_agora\local\connection;
use mod_agora\local\stream_creator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/../lib.php');

/**
 * Creating a broadcast from a template: the name, the tag, a taken name, a central refusal.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\stream_creator
 */
final class stream_creator_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /**
     * A connection to the stub Agora.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        connection::set_client(null);
        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
        set_config('srtportrange', '30000:39999', 'mod_agora');
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * The broadcast creation client: an empty port summary, PUT — the caller's
     * answer, the recording policy accepted.
     *
     * @param mixed $put the answer of the broadcast config PUT endpoint
     * @return \mod_agora\local\agora_client the client
     */
    private function creation_client($put): \mod_agora\local\agora_client {
        return \mod_agora_mock_central::client([
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary(),
            'PUT /streams/configs/*' => $put,
            'PATCH /streams/*/recording-policy' => \mod_agora_mock_central::json(200, ['auto_export' => true]),
        ]);
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
     * Turning a title into a slug.
     *
     * @return array
     */
    public static function slug_provider(): array {
        return [
            'cyrillic' => ['Оптика, лекция 3', 'optika-lekciya-3'],
            'latin' => ['Optics Lecture 3', 'optics-lecture-3'],
            'edge punctuation' => ['  ??Optics!!  ', 'optics'],
            'underscore preserved' => ['town_hall', 'town_hall'],
            'nothing left' => ['!!!', ''],
            'empty' => ['', ''],
        ];
    }

    /**
     * The slug is built by transliteration and keeps only `[a-z0-9_-]`.
     *
     * @param string $title the name from the form
     * @param string $expected the expected slug
     * @return void
     * @dataProvider slug_provider
     */
    public function test_slug(string $title, string $expected): void {
        $this->assertSame($expected, stream_creator::slug($title));
    }

    /**
     * The broadcast name is the site label plus the slug.
     *
     * @return void
     */
    public function test_stream_name_carries_the_label(): void {
        $this->assertSame('hr-lms-optika-lekciya-3', stream_creator::stream_name('Оптика, лекция 3'));
    }

    /**
     * Nothing remains of the name `!!!` after the transformation — this is a refusal in words.
     *
     * @return void
     */
    public function test_empty_slug_is_refused(): void {
        $this->expectException(agora_exception::class);
        $this->expectExceptionMessage(get_string('errslugempty', 'mod_agora'));
        stream_creator::stream_name('!!!');
    }

    /**
     * The broadcast is created with the name, the tag and a reference to the template.
     *
     * @return void
     */
    public function test_create_puts_name_tag_and_template(): void {
        $client = $this->creation_client(\mod_agora_mock_central::json(201, [
            'name' => 'hr-lms-optika-lekciya-3',
        ]));
        connection::set_client($client);

        $name = stream_creator::create('Оптика, лекция 3', 'lecture');

        $this->assertSame('hr-lms-optika-lekciya-3', $name);
        $this->assertContains('PUT /central/api-v4/streams/configs/hr-lms-optika-lekciya-3', $client->request_log());
        // The request body is what the operator will see: the template, the binding
        // tag and the publication section with a port from the settings range. The
        // body is taken specifically from PUT: it is followed by the policy PATCH,
        // and the «last request» is already that one.
        $put = null;
        foreach (\mod_agora_mock_central::requests() as $request) {
            if ($request->getMethod() === 'PUT') {
                $put = $request;
            }
        }
        $this->assertNotNull($put);
        $putbody = json_decode((string) $put->getBody(), true);
        $this->assertSame('lecture', $putbody['template']);
        $this->assertSame(['moodle.hr-lms'], $putbody['labels']);
        $this->assertSame(30000, $putbody['srt_publish']['port']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', $putbody['srt_publish']['passphrase']);
        $this->assertSame('*', $put->getHeaderLine('If-None-Match'));
    }

    /**
     * The 409 answer (not only 412) is the same «name taken» reason.
     *
     * @return void
     */
    public function test_conflict_409_is_the_taken_name_too(): void {
        connection::set_client($this->creation_client(new \GuzzleHttp\Psr7\Response(409, [], '')));

        $this->expectException(agora_exception::class);
        $this->expectExceptionMessage(get_string('errstreamnametaken', 'mod_agora', 'hr-lms-townhall'));
        stream_creator::create('townhall', 'lecture');
    }

    /**
     * A taken name arrives as VersionMismatch and becomes a message in words.
     *
     * @return void
     */
    public function test_taken_name_is_named_in_words(): void {
        // The central answers a taken name with a 412 and no body at all.
        connection::set_client($this->creation_client(new \GuzzleHttp\Psr7\Response(412, [], '')));

        $this->expectException(agora_exception::class);
        $this->expectExceptionMessage(get_string('errstreamnametaken', 'mod_agora', 'hr-lms-townhall'));
        stream_creator::create('townhall', 'lecture');
    }

    /**
     * A central failure leaves no half-activity: no record in the database.
     *
     * @return void
     */
    public function test_central_failure_leaves_no_activity(): void {
        global $DB;

        connection::set_client($this->creation_client(
            \mod_agora_mock_central::error(503, 'Internal', 'db is down')
        ));

        $data = (object) [
            'course' => $this->course->id,
            'name' => 'Lecture',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'source_mode' => 'template',
            'newstreamtitle' => 'Optics',
            'template' => 'lecture',
            'display_mode' => 'auto',
            'timestart' => 0,
            'completion_minutes' => 0,
        ];

        $thrown = false;
        try {
            agora_add_instance($data);
        } catch (agora_exception $error) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'The central refusal must propagate');
        $this->assertSame(0, $DB->count_records('agora'));
    }

    /**
     * The created broadcast is marked as plugin-made.
     *
     * @return void
     */
    public function test_created_stream_is_marked_as_plugin_made(): void {
        global $DB;

        connection::set_client(\mod_agora_mock_central::client([
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary(),
            'PUT /streams/configs/hr-lms-optics' => \mod_agora_mock_central::json(201, ['name' => 'hr-lms-optics']),
            'PATCH /streams/hr-lms-optics/recording-policy'
                => \mod_agora_mock_central::json(200, ['auto_export' => true]),
            'GET /streams/configs/hr-lms-optics' => \mod_agora_mock_central::json(200, [
                'name' => 'hr-lms-optics',
                'ondemand' => true,
            ]),
        ]));

        $data = (object) [
            'course' => $this->course->id,
            'name' => 'Lecture',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'source_mode' => 'template',
            'newstreamtitle' => 'Optics',
            'template' => 'lecture',
            'display_mode' => 'auto',
            'timestart' => 0,
            'completion_minutes' => 0,
        ];

        $id = agora_add_instance($data);
        $record = $DB->get_record('agora', ['id' => $id]);

        $this->assertSame('hr-lms-optics', $record->stream_name);
        $this->assertSame(1, (int) $record->created_by_plugin);
        $this->assertSame(1, (int) $record->ondemand);
    }
}
