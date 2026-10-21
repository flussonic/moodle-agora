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
use mod_agora\local\publish_manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock_central.php');
require_once(__DIR__ . '/fixtures/view_script.php');
require_once(__DIR__ . '/../lib.php');

/**
 * Broadcast publication: the srt_publish section on creation, the recording
 * policy, the port registry, the «How to broadcast» block, showing and
 * rotating the key, removing the section.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\publish_manager
 * @covers     \mod_agora\local\port_registry
 * @covers     \mod_agora\local\stream_creator
 * @covers     \mod_agora\local\player_view
 */
final class publish_block_test extends \advanced_testcase {
    /** The test key: 24 latin letters and digits. */
    const KEY = 'KeyKeyKeyKeyKeyKeyKeyKeyKeyKey12';

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
        // Sink the enrolment welcome message: it renders through $PAGE and
        // claims the theme before the page fixtures run (see
        // broadcast_lead_test::setUp for why this only bites on mysql-family).
        $this->redirectMessages();
        connection::set_client(null);
        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');
        set_config('srtportrange', '30000:30002', 'mod_agora');
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
     * The template activity creation form data.
     *
     * @return \stdClass
     */
    private function template_form(): \stdClass {
        return (object) [
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
    }

    /**
     * An activity with phases and a publication section (created by the plugin).
     *
     * @param string $streamname the broadcast name
     * @param int $port the section port
     * @return \stdClass the activity record
     */
    private function published_element(string $streamname = 'optics-2026', int $port = 30000): \stdClass {
        global $DB;

        $agora = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Lecture',
            'stream_name' => $streamname,
            'created_by_plugin' => 1,
            'has_phases' => 1,
        ]);
        $DB->set_field('agora', 'srt_port', $port, ['id' => $agora->id]);
        $DB->set_field('agora', 'srt_key', \core\encryption::encrypt(self::KEY), ['id' => $agora->id]);
        $record = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        // The create_module gives a record with cmid, a repeated SELECT — without:
        // the page needs the course module id.
        $record->cmid = $agora->cmid;
        return $record;
    }

    /**
     * Opens the activity page as the current user.
     *
     * @param \stdClass $agora the activity record
     * @param array $params the request parameters
     * @param bool $post open with a POST request
     * @return string HTML
     */
    private function open(\stdClass $agora, array $params = [], bool $post = false): string {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        return \mod_agora_view_script::run((int) $agora->cmid, $params, 'view.php', $post);
    }

    /**
     * A button action opens the real view.php; the page answers with a
     * redirect (an exception in CLI), the side effects are already applied.
     *
     * @param \stdClass $agora the activity record
     * @param string $action revealkey | rotatekey
     * @return void
     */
    private function click(\stdClass $agora, string $action): void {
        try {
            $this->open($agora, ['action' => $action, 'sesskey' => sesskey()], true);
            $this->fail('The action must answer with a redirect');
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode, 'The answer is a redirect, not: ' . $e->getMessage());
        }
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

    /**
     * The activity page client in the «planned» phase: the broadcast has no
     * signal, the port summary is the argument.
     *
     * @param mixed $srtports the answer of the ports summary endpoint
     * @param array $routes additional routes
     * @return \mod_agora\local\agora_client the client
     */
    private function page_client($srtports, array $routes = []): \mod_agora\local\agora_client {
        return \mod_agora_mock_central::client([
            'GET /streams/stats/optics-2026'
                => \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started()),
            'GET /srt-ports' => $srtports,
        ] + $routes);
    }

    /**
     * The client log contains a request to a path.
     *
     * @param \mod_agora\local\agora_client $client the client
     * @param string $needle the path substring
     * @return bool
     */
    private static function client_went_to(\mod_agora\local\agora_client $client, string $needle): bool {
        return count(array_filter($client->request_log(), static function (string $line) use ($needle): bool {
            return strpos($line, $needle) !== false;
        })) > 0;
    }

    /**
     * Creating an activity puts the publication section into the same PUT and
     * sets the recording policy; the port and the (encrypted) key — into the
     * activity record.
     *
     * @return void
     */
    public function test_add_instance_stores_publish_section_and_policy(): void {
        global $DB;

        $client = $this->creation_client(\mod_agora_mock_central::json(201, ['name' => 'hr-lms-optika']));
        connection::set_client($client);

        $id = agora_add_instance($this->template_form());

        $row = $DB->get_record('agora', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $row->created_by_plugin);
        $this->assertSame(30000, (int) $row->srt_port);
        $key = publish_manager::key_of($row);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', (string) $key);

        $requests = \mod_agora_mock_central::requests();
        $put = null;
        $policy = null;
        foreach ($requests as $request) {
            $path = $request->getUri()->getPath();
            if (strpos($path, '/streams/configs/') !== false && $request->getMethod() === 'PUT') {
                $put = $request;
            }
            if (strpos($path, '/recording-policy') !== false) {
                $policy = $request;
            }
        }
        $this->assertNotNull($put, 'The broadcast-creation PUT happened');
        $this->assertNotNull($policy, 'The recording-policy PATCH happened');
        $putbody = json_decode((string) $put->getBody(), true);
        $this->assertSame(30000, $putbody['srt_publish']['port']);
        $this->assertSame($key, $putbody['srt_publish']['passphrase']);
        $policybody = json_decode((string) $policy->getBody(), true);
        $this->assertSame(['auto_export' => true], $policybody);
    }

    /**
     * A recording policy refusal drops the creation: no activity record, the
     * created broadcast is removed, otherwise a retry with the same name would
     * stumble on «name taken».
     *
     * @return void
     */
    public function test_policy_refusal_rolls_creation_back(): void {
        global $DB;

        $client = \mod_agora_mock_central::client([
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary(),
            'PUT /streams/configs/*' => \mod_agora_mock_central::json(201, ['name' => 'hr-lms-optika']),
            'PATCH /streams/*/recording-policy'
                => \mod_agora_mock_central::error(409, 'policy_locked', 'policy is locked'),
            'DELETE /streams/configs/*' => \mod_agora_mock_central::json(200, []),
        ]);
        connection::set_client($client);

        $thrown = null;
        try {
            agora_add_instance($this->template_form());
        } catch (agora_exception $error) {
            $thrown = $error;
        }

        $this->assertNotNull($thrown, 'The policy refusal propagated');
        $this->assertSame(0, $DB->count_records('agora'), 'No half-activity left');
        $this->assertTrue(
            count(array_filter($client->request_log(), static function (string $line): bool {
                return strpos($line, 'DELETE') === 0;
            })) === 1,
            'The created broadcast is removed by the rollback'
        );
    }

    /**
     * The allocation skips the ports occupied by the cluster and by the plugin itself.
     *
     * @return void
     */
    public function test_port_allocation_skips_occupied_ports(): void {
        global $DB;

        // Port 30001 is occupied by another activity of the plugin.
        $other = $this->published_element('other-lecture', 30001);
        $this->assertSame(30001, (int) $other->srt_port);

        $client = \mod_agora_mock_central::client([
            // 30000 is occupied by an operator broadcast from the summary.
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary([
                [30000, 'publish', 'operator-stream', 'listening'],
            ]),
            'PUT /streams/configs/*' => \mod_agora_mock_central::json(201, ['name' => 'hr-lms-optika']),
            'PATCH /streams/*/recording-policy' => \mod_agora_mock_central::json(200, ['auto_export' => true]),
        ]);
        connection::set_client($client);

        $id = agora_add_instance($this->template_form());

        $row = $DB->get_record('agora', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(30002, (int) $row->srt_port, 'The first free port of the range');
    }

    /**
     * A 422 port conflict on PUT: mark it occupied and take the next one; after
     * three retries the refusal names the occupying broadcast and advises to
     * shift the range.
     *
     * @return void
     */
    public function test_port_conflict_retries_then_names_the_foreign_stream(): void {
        $conflict = \mod_agora_mock_central::json(422, [
            'error' => [
                'code' => 'validation_error',
                'message' => 'port 30000 is already taken by stream "operator-console" (srt_publish)',
                'errors' => [
                    [
                        'path' => ['srt_publish', 'port'],
                        'message' => 'port 30000 is already taken by stream "operator-console" (srt_publish)',
                    ],
                ],
            ],
        ]);
        connection::set_client(\mod_agora_mock_central::client([
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary(),
            'PUT /streams/configs/*' => $conflict,
        ]));

        $thrown = null;
        try {
            agora_add_instance($this->template_form());
        } catch (agora_exception $error) {
            $thrown = $error;
        }

        $this->assertNotNull($thrown, 'Retry exhaustion propagated');
        $this->assertSame('validation', $thrown->reason);
        $this->assertStringContainsString('operator-console', $thrown->getMessage(), 'The refusal names the occupying broadcast');
        $this->assertStringContainsString('30000', $thrown->getMessage(), 'The refusal names the range');
    }

    /**
     * A fully occupied range — the «ports exhausted» refusal, different from a
     * foreign port conflict: the broadcast is not named, the range is named.
     *
     * @return void
     */
    public function test_exhausted_range_is_its_own_reason(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /srt-ports' => \mod_agora_mock_central::srt_ports_summary([
                [30000, 'publish', 'operator-a', 'listening'],
                [30001, 'publish', 'operator-b', 'listening'],
                [30002, 'play', 'operator-c', 'intent'],
            ]),
            'PUT /streams/configs/*' => \mod_agora_mock_central::json(201, ['name' => 'x']),
        ]));

        $thrown = null;
        try {
            agora_add_instance($this->template_form());
        } catch (agora_exception $error) {
            $thrown = $error;
        }

        $this->assertNotNull($thrown);
        // Different from a foreign port conflict: the broadcast is not named, the range is named.
        $this->assertStringNotContainsString('operator-a', $thrown->getMessage());
        $this->assertStringContainsString('30000', $thrown->getMessage());
    }

    /**
     * Without a range in the settings the creation refuses and names the setting.
     *
     * @return void
     */
    public function test_missing_port_range_refuses_creation(): void {
        unset_config('srtportrange', 'mod_agora');
        connection::set_client(\mod_agora_mock_central::client([]));

        $thrown = null;
        try {
            agora_add_instance($this->template_form());
        } catch (agora_exception $error) {
            $thrown = $error;
        }

        $this->assertNotNull($thrown);
        $this->assertSame('notconfigured', $thrown->reason, 'The refusal is «not configured»');
    }

    /**
     * The connection string is fully hidden by default: the markup has neither
     * the address, nor the port, nor the masked key — they were copy-pasted by
     * mistake. The collapsed look is a block button «Show address» without a
     * hint text (D12); «Change key» and «Hide address» — only after showing.
     *
     * @return void
     */
    public function test_publish_block_hides_the_connection_string_until_revealed(): void {
        $agora = $this->published_element();
        $listening = \mod_agora_mock_central::srt_ports_summary([
            [30000, 'publish', 'optics-2026', 'listening'],
        ]);
        connection::set_client($this->page_client($listening));
        $this->setUser($this->teacher);

        $html = $this->open($agora);

        $this->assertStringContainsString(get_string('publishblocktitle', 'mod_agora'), $html);
        $this->assertStringNotContainsString('srt://', $html, 'No connection string in the markup');
        $this->assertStringNotContainsString('publish.agora.example.com:30000', $html, 'No address with port');
        $this->assertStringNotContainsString(
            get_string('publishobshelp', 'mod_agora'),
            $html,
            'Collapsed view — no hints next to the address'
        );
        $this->assertStringContainsString(get_string('publishportlistening', 'mod_agora'), $html);
        $this->assertMatchesRegularExpression(
            '#<button type="submit" class="btn btn-outline-secondary w-100"\s+data-action="agora-revealkey"#',
            $html,
            '«Show address» is a button block'
        );
        $this->assertDoesNotMatchRegularExpression(
            '#data-action="agora-rotatekey"#',
            $html,
            '«Change key» is hidden until the line is shown'
        );
        $this->assertDoesNotMatchRegularExpression(
            '#data-action="agora-hidekey"#',
            $html,
            '«Hide address» is hidden until the line is shown'
        );
    }

    /**
     * The «How to broadcast» block is not drawn for the learner.
     *
     * @return void
     */
    public function test_publish_block_is_hidden_from_the_student(): void {
        $agora = $this->published_element();
        connection::set_client($this->page_client(\mod_agora_mock_central::srt_ports_summary()));
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $html = $this->open($agora);

        $this->assertStringNotContainsString(get_string('publishblocktitle', 'mod_agora'), $html, 'No block for the viewer');
        $this->assertStringNotContainsString('srt://', $html, 'No connection string in the markup');
    }

    /**
     * FROZEN 2026-10-16 (#65205): browser broadcasting is removed from the
     * interface by the owner's decision — there is no «Start broadcasting from
     * the browser» button on the activity page, the code and publish.php are
     * kept (player_view.php, `browserurl => ''`). On unfreezing, return the
     * button checks: btn btn-primary, target="_blank" rel="noopener", below
     * the address block.
     *
     * @return void
     */
    public function test_browser_publisher_link_opens_a_new_tab(): void {
        $agora = $this->published_element();
        connection::set_client($this->page_client(\mod_agora_mock_central::srt_ports_summary([
            [30000, 'publish', 'optics-2026', 'listening'],
        ])));
        $this->setUser($this->teacher);

        $html = $this->open($agora);

        $this->assertStringNotContainsString(
            'data-action="agora-publishopen"',
            $html,
            'Frozen: no publisher buttons on the activity page'
        );
    }

    /**
     * The «How to broadcast» block is not drawn for a legacy activity.
     *
     * @return void
     */
    public function test_publish_block_is_hidden_from_legacy(): void {
        $legacy = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Old lecture',
            'stream_name' => 'optics-2026',
            'has_phases' => 0,
        ]);
        connection::set_client($this->page_client(\mod_agora_mock_central::srt_ports_summary()));
        $this->setUser($this->teacher);

        $html = $this->open($legacy);

        $this->assertStringNotContainsString(get_string('publishblocktitle', 'mod_agora'), $html, 'No block for the legacy one');
    }

    /**
     * The port is not up: the block honestly names the error and sends to the administrator.
     *
     * @return void
     */
    public function test_publish_block_shows_a_failed_port(): void {
        $agora = $this->published_element();
        $failed = \mod_agora_mock_central::srt_ports_summary([
            [30000, 'publish', 'optics-2026', 'apply_error'],
        ]);
        connection::set_client($this->page_client($failed));
        $this->setUser($this->teacher);

        $html = $this->open($agora);

        $this->assertStringContainsString(
            get_string('publishportdown', 'mod_agora', 'bind: address already in use'),
            $html
        );
        $this->assertStringNotContainsString(get_string('publishportlistening', 'mod_agora'), $html);
    }

    /**
     * «Show address»: after the action the string is fully in the markup, «Hide
     * address» and «Change key» appear, the log has an event without the
     * address and key values.
     *
     * @return void
     */
    public function test_reveal_key_shows_it_once_and_logs_the_event(): void {
        $agora = $this->published_element();
        connection::set_client($this->page_client(\mod_agora_mock_central::srt_ports_summary([
            [30000, 'publish', 'optics-2026', 'listening'],
        ])));
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'revealkey');
        $revealed = $this->events_of($sink, \mod_agora\event\key_revealed::class);
        $sink->close();

        $this->assertCount(1, $revealed, 'The address-reveal event in the log');
        $this->assertArrayNotHasKey('key', $revealed[0]->other, 'The event has no key value');
        $this->assertArrayNotHasKey('address', $revealed[0]->other, 'The event has no address value');

        $html = $this->open($agora);
        $this->assertStringContainsString(self::KEY, $html, 'After the reveal the key is visible in the line');
        $this->assertStringContainsString('srt://publish.agora.example.com:30000?passphrase=' . self::KEY, $html);
        $this->assertMatchesRegularExpression(
            '#data-action="agora-hidekey"#',
            $html,
            '«Hide address» visible when the line is shown'
        );
        $this->assertDoesNotMatchRegularExpression(
            '#data-action="agora-revealkey"#',
            $html,
            '«Show address» hidden when the line is shown'
        );
        $this->assertMatchesRegularExpression(
            '#data-action="agora-rotatekey"#',
            $html,
            '«Change key» visible when the line is shown'
        );
    }

    /**
     * «Hide address»: the show flag is lifted, the block collapses back;
     * hiding reveals nothing — there is no log event (D12).
     *
     * @return void
     */
    public function test_hide_key_collapses_the_block_without_a_log_event(): void {
        $agora = $this->published_element();
        connection::set_client($this->page_client(\mod_agora_mock_central::srt_ports_summary([
            [30000, 'publish', 'optics-2026', 'listening'],
        ])));
        $this->setUser($this->teacher);

        $this->click($agora, 'revealkey');
        $this->assertStringContainsString('srt://', $this->open($agora), 'The string is shown');

        $sink = $this->redirectEvents();
        $this->click($agora, 'hidekey');
        $events = array_merge(
            $this->events_of($sink, \mod_agora\event\key_revealed::class),
            $this->events_of($sink, \mod_agora\event\key_rotated::class)
        );
        $sink->close();

        $this->assertCount(0, $events, 'Hiding the address has no log event');
        $html = $this->open($agora);
        $this->assertStringNotContainsString('srt://', $html, 'The block is collapsed again');
        $this->assertMatchesRegularExpression('#data-action="agora-revealkey"#', $html, 'The "Show address" action is back');
    }

    /**
     * «Change key»: PATCH to Agora first, the database after; both values
     * match, the log has an event without the value.
     *
     * @return void
     */
    public function test_rotate_key_changes_agora_first_then_the_database(): void {
        global $DB;

        $agora = $this->published_element();
        $client = $this->page_client(\mod_agora_mock_central::srt_ports_summary([
            [30000, 'publish', 'optics-2026', 'listening'],
        ]), [
            'PATCH /streams/configs/optics-2026' => \mod_agora_mock_central::json(200, ['ok' => true]),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'rotatekey');
        $rotated = $this->events_of($sink, \mod_agora\event\key_rotated::class);
        $sink->close();

        $this->assertCount(1, $rotated, 'The rotation event in the log');
        $this->assertArrayNotHasKey('key', $rotated[0]->other, 'The event has no key value');

        // PATCH carried the new key on the same port.
        $patched = false;
        $newkey = null;
        foreach (\mod_agora_mock_central::requests() as $request) {
            if ($request->getMethod() === 'PATCH' && strpos($request->getUri()->getPath(), '/streams/configs/') !== false) {
                $patched = true;
                $body = json_decode((string) $request->getBody(), true);
                $this->assertSame(30000, $body['srt_publish']['port']);
                $newkey = $body['srt_publish']['passphrase'];
            }
        }
        $this->assertTrue($patched, 'The section PATCH happened');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', (string) $newkey);
        $this->assertNotSame(self::KEY, $newkey);

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertSame($newkey, publish_manager::key_of($row), 'The DB stores the same new key');
    }

    /**
     * An Agora refusal during the rotation changes nothing: the former key in
     * the database, the reason is shown to the presenter.
     *
     * @return void
     */
    public function test_failed_rotation_keeps_the_old_key(): void {
        global $DB;

        $agora = $this->published_element();
        $client = $this->page_client(\mod_agora_mock_central::srt_ports_summary([
            [30000, 'publish', 'optics-2026', 'listening'],
        ]), [
            'PATCH /streams/configs/optics-2026'
                => \mod_agora_mock_central::error(409, 'policy_locked', 'config is locked'),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $sink = $this->redirectEvents();
        $this->click($agora, 'rotatekey');
        $events = $this->events_of($sink, \mod_agora\event\key_rotated::class);
        $sink->close();

        $this->assertCount(0, $events, 'No rotation — no event');
        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertSame(self::KEY, publish_manager::key_of($row), 'The key in the DB did not change');
    }

    /**
     * A «take existing» broadcast: a read-only block with the string from the
     * broadcast config, without the rotation button; no section — no block.
     *
     * @return void
     */
    public function test_existing_stream_block_is_read_only(): void {
        $existing = $this->getDataGenerator()->create_module('agora', [
            'course' => $this->course->id,
            'name' => 'Foreign broadcast',
            'stream_name' => 'operator-stream',
            'created_by_plugin' => 0,
            'has_phases' => 1,
        ]);

        $withsection = $this->page_client(\mod_agora_mock_central::srt_ports_summary(), [
            'GET /streams/stats/operator-stream'
                => \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started('operator-stream')),
            'GET /streams/configs/operator-stream' => \mod_agora_mock_central::json(200, [
                'name' => 'operator-stream',
                'srt_publish' => ['port' => 31000, 'passphrase' => 'OperatorKey12345678901234'],
            ]),
        ]);
        connection::set_client($withsection);
        $this->setUser($this->teacher);

        $html = $this->open($existing);
        $this->assertStringNotContainsString('srt://', $html, 'The string is hidden by default here as well');
        $this->assertStringNotContainsString('OperatorKey12345678901234', $html, 'No key in the markup');
        $this->assertMatchesRegularExpression('#data-action="agora-revealkey"#', $html);
        $this->assertDoesNotMatchRegularExpression(
            '#data-action="agora-rotatekey"#',
            $html,
            'A read-only block has no rotation buttons'
        );

        // The address show — and the string is read fully from the broadcast config.
        $this->click($existing, 'revealkey');
        $html = $this->open($existing);
        $this->assertStringContainsString(
            'srt://publish.agora.example.com:31000?passphrase=OperatorKey12345678901234',
            $html,
            'After «Show address» the read-only line is fully visible'
        );

        // A direct rotation request of a foreign broadcast — a refusal before any call to Agora.
        $refused = false;
        try {
            $this->open($existing, ['action' => 'rotatekey', 'sesskey' => sesskey()], true);
        } catch (\moodle_exception $e) {
            $refused = $e->errorcode === 'errunknownaction';
        }
        $this->assertTrue($refused, 'Rotating a foreign broadcast is refused');
        $this->assertFalse(
            count(array_filter(
                \mod_agora_mock_central::requests(),
                static function ($request): bool {
                    return $request->getMethod() === 'PATCH'
                        && strpos($request->getUri()->getPath(), '/streams/configs/') !== false;
                }
            )) > 0,
            'The call never reached Agora'
        );

        // The broadcast has no section — no block at all.
        $without = $this->page_client(\mod_agora_mock_central::srt_ports_summary(), [
            'GET /streams/stats/operator-stream'
                => \mod_agora_mock_central::json(200, \mod_agora_mock_central::stats_not_started('operator-stream')),
            'GET /streams/configs/operator-stream' => \mod_agora_mock_central::json(200, [
                'name' => 'operator-stream',
            ]),
        ]);
        connection::set_client($without);
        $html = $this->open($existing);
        $this->assertStringNotContainsString(get_string('publishblocktitle', 'mod_agora'), $html);
    }

    /**
     * «End the broadcast» removes the publication section and frees the port:
     * PATCH with srt_publish: null, the activity has port 0 and no key.
     *
     * @return void
     */
    public function test_end_broadcast_removes_the_publish_section(): void {
        global $DB;

        $agora = $this->published_element();
        $DB->set_field('agora', 'started_at', time() - 600, ['id' => $agora->id]);

        $client = \mod_agora_mock_central::client([
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
            'PATCH /streams/configs/optics-2026' => \mod_agora_mock_central::json(200, ['ok' => true]),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $this->click($agora, 'endbroadcast');

        $removed = false;
        foreach (\mod_agora_mock_central::requests() as $request) {
            if ($request->getMethod() === 'PATCH' && strpos($request->getUri()->getPath(), '/streams/configs/') !== false) {
                $body = json_decode((string) $request->getBody(), true);
                if (array_key_exists('srt_publish', $body) && $body['srt_publish'] === null) {
                    $removed = true;
                }
            }
        }
        $this->assertTrue($removed, 'The section-removal PATCH happened');

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->ended_at);
        $this->assertSame(0, (int) $row->srt_port, 'The port is returned to the registry');
        $this->assertNull($row->srt_key);
    }

    /**
     * A rotation with the section removed (broadcast ended, port 0) is refused
     * before a call to Agora: a garbage PATCH with port 0 does not go into the
     * config (review R2, М-3).
     *
     * @return void
     */
    public function test_rotation_without_a_publish_section_is_refused_before_any_call(): void {
        global $DB;

        $agora = $this->published_element();
        // The section is removed: the broadcast is ended, the port is returned.
        $DB->set_field('agora', 'started_at', time() - 3600, ['id' => $agora->id]);
        $DB->set_field('agora', 'ended_at', time() - 600, ['id' => $agora->id]);
        $DB->set_field('agora', 'srt_port', 0, ['id' => $agora->id]);
        $DB->set_field('agora', 'srt_key', null, ['id' => $agora->id]);

        $client = \mod_agora_mock_central::client([
            'PATCH /streams/configs/optics-2026' => \mod_agora_mock_central::json(200, ['ok' => true]),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $refusal = \mod_agora_view_script::refusal((int) $agora->cmid, [
            'action' => 'rotatekey',
            'sesskey' => sesskey(),
        ], 'view.php', true);
        $this->assertNotNull($refusal, 'Refusal');
        $this->assertSame('errunknownaction', $refusal->errorcode, 'No section — action unknown');

        foreach (\mod_agora_mock_central::requests() as $request) {
            $this->assertFalse(
                $request->getMethod() === 'PATCH' && strpos($request->getUri()->getPath(), '/streams/configs/') !== false,
                'No PATCH went to the broadcast config'
            );
        }
    }

    /**
     * A refused section removal is not terminal: the activity is «ongoing»
     * with a pending completion, the port is occupied; a button repeat
     * completes it (the removal is idempotent).
     *
     * @return void
     */
    public function test_failed_section_removal_is_not_terminal_and_repeats(): void {
        global $DB;

        $agora = $this->published_element();
        $DB->set_field('agora', 'started_at', time() - 600, ['id' => $agora->id]);

        $client = \mod_agora_mock_central::client([
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
            'PATCH /streams/configs/optics-2026'
                => \mod_agora_mock_central::error(503, 'Internal', 'central is down'),
        ]);
        connection::set_client($client);
        $this->setUser($this->teacher);

        $this->click($agora, 'endbroadcast');

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->ended_at, 'The refusal is not terminal');
        $this->assertSame(1, (int) $row->end_pending);
        $this->assertStringContainsString(get_string('actionremovepublish', 'mod_agora'), $row->end_failure);
        $this->assertSame(30000, (int) $row->srt_port, 'The port is held until confirmed removal');

        // A repeat: central is alive — the activity is ended, the section removed.
        $client = \mod_agora_mock_central::client([
            'POST /streams/optics-2026/recordings/stop' => \mod_agora_mock_central::json(200, ['id' => 'rec-1']),
            'PATCH /streams/configs/optics-2026' => \mod_agora_mock_central::json(200, ['ok' => true]),
        ]);
        connection::set_client($client);
        $this->click($agora, 'endbroadcast');

        $row = $DB->get_record('agora', ['id' => $agora->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $row->ended_at, 'The retry finished it');
        $this->assertSame(0, (int) $row->srt_port);
    }

    /**
     * Deleting an activity in the «planned» phase removes the section before the tombstone.
     *
     * @return void
     */
    public function test_deletion_of_a_planned_element_removes_the_section(): void {
        global $DB;

        $agora = $this->published_element();
        $this->assertSame(0, (int) $agora->started_at, 'The activity is «planned»');

        $client = \mod_agora_mock_central::client([
            'PATCH /streams/configs/optics-2026' => \mod_agora_mock_central::json(200, ['ok' => true]),
        ]);
        connection::set_client($client);

        course_delete_module((int) $agora->cmid);

        $removed = false;
        foreach (\mod_agora_mock_central::requests() as $request) {
            if ($request->getMethod() === 'PATCH') {
                $body = json_decode((string) $request->getBody(), true);
                if (is_array($body) && array_key_exists('srt_publish', $body) && $body['srt_publish'] === null) {
                    $removed = true;
                }
            }
        }
        $this->assertTrue($removed, 'Section removed before the tombstone');
        $this->assertFalse($DB->record_exists('agora', ['id' => $agora->id]));
    }

    /**
     * A refused completion on deletion does not block it: the activity is
     * deleted, the reason is in the log; the scheduled task completes the
     * divergence by the tombstone (deleting the broadcast removes the section
     * and the cluster port entirely).
     *
     * @return void
     */
    public function test_failed_section_removal_does_not_block_deletion(): void {
        global $DB;

        $agora = $this->published_element();

        $client = \mod_agora_mock_central::client([
            'PATCH /streams/configs/optics-2026'
                => \mod_agora_mock_central::error(503, 'Internal', 'central is down'),
        ]);
        connection::set_client($client);

        $sink = $this->redirectEvents();
        course_delete_module((int) $agora->cmid);
        $failed = array_values(array_filter($sink->get_events(), static function ($event): bool {
            return $event instanceof \mod_agora\event\central_failed;
        }));
        $sink->close();

        $this->assertFalse($DB->record_exists('agora', ['id' => $agora->id]), 'The activity is deleted despite the refusal');
        $this->assertGreaterThanOrEqual(1, count($failed), 'The completion refusal is in the log');
        $this->assertSame('opremovepublish', $failed[0]->other['operation']);
    }
}
