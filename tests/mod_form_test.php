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
use mod_agora\local\stream_picker;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/agora/mod_form.php');
require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * The activity form: the broadcast list by tag, the save check, the connection gate.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\local\stream_picker
 * @covers     \mod_agora_mod_form
 */
final class mod_form_test extends \advanced_testcase {
    /** @var \stdClass the course */
    private $course;

    /** @var \stdClass the presenter with the right to edit the course */
    private $teacher;

    /**
     * A course, a presenter and a configured connection.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        connection::set_client(null);
        // The completion condition appears in the form only when completion is
        // enabled both on the site and in the course.
        $CFG->enablecompletion = 1;

        set_config('centralurl', \mod_agora_mock_central::HOST, 'mod_agora');
        set_config('sitelabel', 'hr-lms', 'mod_agora');

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($this->teacher);
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
     * A client giving the list of tagged broadcasts.
     *
     * The stub central answers by route, and the tag filter is applied by the
     * caller itself: otherwise the test «only tagged in the list» would prove
     * nothing but the answer queue.
     *
     * @param array $names the broadcast names with their tags: name => list of tags
     * @return \mod_agora\local\agora_client
     */
    private function client_with_streams(array $names) {
        $catalogue = [];
        foreach ($names as $name => $labels) {
            $catalogue[] = is_int($name)
                ? ['name' => $labels, 'labels' => ['moodle.hr-lms']]
                : ['name' => $name, 'labels' => $labels];
        }
        return \mod_agora_mock_central::client([
            'GET /streams/configs' => function (\Psr\Http\Message\RequestInterface $request) use ($catalogue) {
                parse_str($request->getUri()->getQuery(), $query);
                $label = (string) ($query['label'] ?? '');
                $search = (string) ($query['q'] ?? '');
                $streams = [];
                foreach ($catalogue as $stream) {
                    if ($label !== '' && !in_array($label, $stream['labels'], true)) {
                        continue;
                    }
                    if ($search !== '' && strpos($stream['name'], $search) === false) {
                        continue;
                    }
                    $streams[] = $stream;
                }
                return \mod_agora_mock_central::json(200, ['streams' => $streams, 'next' => null]);
            },
            // The broadcast config by name: 200 — exists (with or without a tag), 404 — not.
            'GET /streams/configs/*' => function (\Psr\Http\Message\RequestInterface $request) use ($catalogue) {
                $name = rawurldecode(basename($request->getUri()->getPath()));
                foreach ($catalogue as $stream) {
                    if ($stream['name'] === $name) {
                        return \mod_agora_mock_central::json(200, $stream + ['template' => 'lecture']);
                    }
                }
                return \mod_agora_mock_central::error(404, 'not_found', 'no stream ' . $name);
            },
            'GET /templates/configs' => \mod_agora_mock_central::json(200, [
                'templates' => [['name' => 'lecture']],
                'next' => null,
            ]),
        ]);
    }

    /**
     * The broadcast list is requested with the filter by the `moodle.<label>` tag.
     *
     * @return void
     */
    public function test_list_is_filtered_by_tag(): void {
        $client = $this->client_with_streams([
            'optics-2026' => ['moodle.hr-lms'],
            'townhall' => ['moodle.hr-lms'],
            'operator-only-stream' => [],
        ]);
        connection::set_client($client);

        $options = stream_picker::options();

        $this->assertSame(['optics-2026' => 'optics-2026', 'townhall' => 'townhall'], $options);
        $this->assertContains('GET /central/api-v4/streams/configs', $client->request_log());
    }

    /**
     * An untagged broadcast is refused on save with words naming the tag.
     *
     * @return void
     */
    public function test_untagged_stream_is_refused_by_name_of_tag(): void {
        // The broadcast exists in central, but has no tag.
        connection::set_client($this->client_with_streams([
            'optics-2026' => ['moodle.hr-lms'],
            'operator-only-stream' => [],
        ]));

        $error = stream_picker::validation_error('operator-only-stream');

        $this->assertSame(get_string('errstreamnotagged', 'mod_agora', (object) [
            'name' => 'operator-only-stream',
            'tag' => 'moodle.hr-lms',
        ]), $error);
        $this->assertStringContainsString('moodle.hr-lms', (string) $error);
    }

    /**
     * No broadcast at all — this is a different reason and a different text.
     *
     * @return void
     */
    public function test_missing_stream_is_refused_by_its_own_text(): void {
        connection::set_client($this->client_with_streams(['optics-2026' => ['moodle.hr-lms']]));

        $error = stream_picker::validation_error('there-is-no-such-stream');

        $this->assertSame(get_string('errstreammissing', 'mod_agora', 'there-is-no-such-stream'), $error);
        $this->assertNotSame(get_string('errstreamnotagged', 'mod_agora', (object) [
            'name' => 'there-is-no-such-stream',
            'tag' => 'moodle.hr-lms',
        ]), $error);
    }

    /**
     * The broadcast name in the refusal text is escaped, not sent to the form as markup.
     *
     * The expectation is a ready escaped string, not `s($name)`: otherwise the
     * test would repeat the same function as the code and pass with any of its
     * behaviour.
     *
     * @return void
     */
    public function test_refused_name_is_escaped(): void {
        connection::set_client($this->client_with_streams(['optics-2026' => ['moodle.hr-lms']]));

        $error = (string) stream_picker::validation_error('lec<script>alert("x")</script>');

        $this->assertStringContainsString('lec&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $error);
        $this->assertStringNotContainsString('<script>', $error);
    }

    /**
     * The form distinguishes «no broadcast» and «broadcast without a tag» by its own check.
     *
     * @return void
     */
    public function test_form_validation_tells_the_two_cases_apart(): void {
        connection::set_client($this->client_with_streams([
            'optics-2026' => ['moodle.hr-lms'],
            'operator-only-stream' => [],
        ]));
        $form = $this->make_form();

        $untagged = $form->validation($this->form_data(['stream_name' => 'operator-only-stream']), []);
        $missing = $form->validation($this->form_data(['stream_name' => 'no-such-stream']), []);

        $this->assertStringContainsString('moodle.hr-lms', $untagged['stream_name']);
        $this->assertStringContainsString('no-such-stream', $missing['stream_name']);
        $this->assertNotSame($untagged['stream_name'], $missing['stream_name']);
    }

    /**
     * The broadcast list walks the pages by the `next` cursor.
     *
     * At the hundred-and-first broadcast a single page would refuse a correct
     * broadcast for the wrong reason — «no broadcast» instead of «here it is».
     *
     * @return void
     */
    public function test_stream_list_walks_the_pages(): void {
        $client = \mod_agora_mock_central::client([
            'GET /streams/configs' => [
                \mod_agora_mock_central::json(200, [
                    'streams' => [['name' => 'page1-stream', 'labels' => ['moodle.hr-lms']]],
                    'next' => 'cursor-2',
                ]),
                \mod_agora_mock_central::json(200, [
                    'streams' => [['name' => 'page2-stream', 'labels' => ['moodle.hr-lms']]],
                    'next' => null,
                ]),
            ],
        ]);
        connection::set_client($client);

        $options = stream_picker::options();

        $this->assertArrayHasKey('page2-stream', $options, 'The page-two broadcast is linked too');
        $this->assertNull(stream_picker::validation_error('page2-stream'));
    }

    /**
     * The template list walks the pages too.
     *
     * @return void
     */
    public function test_template_list_walks_the_pages(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /templates/configs' => [
                \mod_agora_mock_central::json(200, ['templates' => [['name' => 'lecture']], 'next' => 'cursor-2']),
                \mod_agora_mock_central::json(200, ['templates' => [['name' => 'townhall']], 'next' => null]),
            ],
        ]));

        $templates = \mod_agora\local\stream_creator::templates();

        $this->assertSame(['lecture' => 'lecture', 'townhall' => 'townhall'], $templates);
    }

    /**
     * The watched-duration completion condition is visible in the activity form.
     *
     * @return void
     */
    public function test_completion_rule_is_visible_in_the_form(): void {
        connection::set_client($this->client_with_streams(['optics-2026' => ['moodle.hr-lms']]));

        $html = $this->make_form()->render();

        $this->assertStringContainsString(get_string('completionminutes', 'mod_agora'), $html);
        $this->assertStringContainsString(get_string('completionminutesgroup', 'mod_agora'), $html);
    }

    /**
     * The condition help names the price of the merge: a pause up to 30 minutes counts.
     *
     * Without this the presenter sets the threshold by the pure session time,
     * and gets a mark for the one who left for half an hour and came back.
     *
     * @return void
     */
    public function test_completion_help_names_the_merge_window(): void {
        global $CFG;

        // The language files are read directly: there is no Russian language
        // pack in the test environment, and `get_string()` would silently give
        // an English string for a Russian one.
        foreach (['en', 'ru'] as $lang) {
            $string = [];
            include($CFG->dirroot . '/mod/agora/lang/' . $lang . '/agora.php');
            $this->assertArrayHasKey('completionminutesgroup_help', $string, $lang);
            $this->assertStringContainsString('30', $string['completionminutesgroup_help'], $lang);
        }
    }

    /**
     * The condition help names until which moment the watching counts.
     *
     * The counted time ends at the player's last request, and the session in
     * the Agora console closes a minute after it; without this phrase a
     * presenter comparing the minutes with the console sees a shortfall.
     *
     * @return void
     */
    public function test_completion_help_names_the_last_request(): void {
        global $CFG;

        $phrases = ['en' => 'last request', 'ru' => 'последнего обращения'];
        foreach ($phrases as $lang => $phrase) {
            $string = [];
            include($CFG->dirroot . '/mod/agora/lang/' . $lang . '/agora.php');
            $this->assertStringContainsString($phrase, $string['completionminutesgroup_help'], $lang);
        }
    }

    /**
     * The help names the exception: a session closed by stopping the broadcast
     * or revoking the ticket is counted until the close, — and the interval
     * line next to the field speaks about a minute to close the session.
     *
     * @return void
     */
    public function test_completion_help_names_the_explicit_close_and_the_minute(): void {
        global $CFG;

        $phrases = [
            'en' => ['help' => 'ticket being revoked', 'interval' => 'about a minute after the player is closed'],
            'ru' => ['help' => 'отзыв билета', 'interval' => 'примерно минута после закрытия плеера'],
        ];
        foreach ($phrases as $lang => $phrase) {
            $string = [];
            include($CFG->dirroot . '/mod/agora/lang/' . $lang . '/agora.php');
            $this->assertStringContainsString($phrase['help'], $string['completionminutesgroup_help'], $lang);
            $this->assertStringContainsString($phrase['interval'], $string['completionminutesinterval'], $lang);
        }
    }

    /**
     * The form itself refuses a too long name of a new broadcast.
     *
     * @return void
     */
    public function test_too_long_new_stream_title_is_refused(): void {
        connection::set_client($this->client_with_streams(['optics-2026' => ['moodle.hr-lms']]));

        $form = $this->make_form();
        $errors = $form->validation($this->form_data([
            'source_mode' => 'template',
            'newstreamtitle' => str_repeat('a', \mod_agora_mod_form::TITLE_MAX + 1),
            'template' => 'lecture',
        ]), []);

        $this->assertArrayHasKey('newstreamtitle', $errors);
    }

    /**
     * A tagged broadcast passes the save check.
     *
     * @return void
     */
    public function test_tagged_stream_passes(): void {
        connection::set_client($this->client_with_streams(['optics-2026' => ['moodle.hr-lms']]));

        $this->assertNull(stream_picker::validation_error('optics-2026'));
    }

    /**
     * The «allow untagged broadcasts» setting removes the tag filter.
     *
     * @return void
     */
    public function test_allow_untagged_removes_the_filter(): void {
        set_config('allowuntagged', 1, 'mod_agora');
        $client = $this->client_with_streams(['operator-only-stream' => []]);
        connection::set_client($client);

        $this->assertNull(stream_picker::validation_error('operator-only-stream'));
        $this->assertStringNotContainsString('label=', implode("\n", $client->request_log()));
    }

    /**
     * An empty site label refuses the save and names the settings field.
     *
     * @return void
     */
    public function test_empty_site_label_names_the_field(): void {
        set_config('sitelabel', '', 'mod_agora');
        $client = $this->client_with_streams(['optics-2026' => ['moodle.hr-lms']]);
        connection::set_client($client);

        $error = stream_picker::validation_error('optics-2026');

        $this->assertSame(get_string('errnositelabel', 'mod_agora'), $error);
        $this->assertStringContainsString(get_string('settingsitelabel', 'mod_agora'), (string) $error);
        $this->assertSame([], $client->request_log(), 'Without a label there is nothing to ask central');
    }

    /**
     * The activity form with an unanswering central names the check and the field.
     *
     * @return void
     */
    public function test_form_gate_names_check_and_field(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'POST /auth/login' => new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('POST', \mod_agora_mock_central::HOST . '/central/api-v4/auth/login')
            ),
        ]));

        try {
            $this->make_form();
            $this->fail('The form must refuse, naming the check and the field');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(get_string('checknetwork', 'mod_agora'), $e->getMessage());
            $this->assertStringContainsString(get_string('settingcentralurl', 'mod_agora'), $e->getMessage());
        }
    }

    /**
     * An empty site label: the form refuses on open; the message names the
     * field, and the Continue button (the exception's link) leads to the
     * plugin settings page where the label is set.
     *
     * @return void
     */
    public function test_form_gate_links_to_the_settings_page(): void {
        set_config('sitelabel', '', 'mod_agora');
        connection::set_client($this->client_with_streams(['optics-2026' => ['moodle.hr-lms']]));

        try {
            $this->make_form();
            $this->fail('The form without a site label must refuse to open');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(get_string('settingsitelabel', 'mod_agora'), $e->getMessage());
            $this->assertStringContainsString('admin/settings.php?section=modsettingagora', (string) $e->link);
        }
    }

    /**
     * A proxy answers the broadcast list with the code 200 and an empty JSON
     * array: the form refuses and shows the beginning of the answer, and the
     * list does not look empty.
     *
     * @return void
     */
    public function test_json_array_instead_of_the_list_is_refused(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/configs' => new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '[]'),
        ]));

        try {
            $this->make_form();
            $this->fail('The form must refuse, not show an empty list');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(get_string('opliststreams', 'mod_agora'), $e->getMessage());
            $this->assertStringContainsString('[]', $e->getMessage());
        }
    }

    /**
     * The markup from the proxy answer gets into the form message only
     * escaped: both in the refusal on form open and in the «Broadcast» field error.
     *
     * @return void
     */
    public function test_proxy_markup_is_escaped_in_the_form_error(): void {
        $page = new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html'],
            '<b id="proxy">proxy</b><script>alert(1)</script>'
        );
        connection::set_client(\mod_agora_mock_central::client(['GET /streams/configs' => $page]));

        try {
            $this->make_form();
            $this->fail('The form must refuse');
        } catch (\moodle_exception $e) {
            $this->assertStringNotContainsString('<b id="proxy">', $e->getMessage());
            $this->assertStringNotContainsString('<script>', $e->getMessage());
            $this->assertStringContainsString('&lt;b id=&quot;proxy&quot;&gt;', $e->getMessage());
        }

        $error = (string) stream_picker::validation_error('optics-2026');
        $this->assertStringNotContainsString('<script>', $error);
        $this->assertStringContainsString('&lt;script&gt;', $error);
    }

    /**
     * The «create from a template» form data.
     *
     * @param string $title the name of the new broadcast
     * @return array
     */
    private function template_data(string $title): array {
        return $this->form_data([
            'source_mode' => 'template',
            'newstreamtitle' => $title,
            'template' => 'lecture',
            'stream_name' => '',
        ]);
    }

    /**
     * A taken name is a form field error, not an error page after the submit:
     * the entered value stays in the form, and the message names the name and the way out.
     *
     * @return void
     */
    public function test_taken_name_is_a_field_error(): void {
        $client = $this->client_with_streams(['hr-lms-optics' => ['moodle.hr-lms']]);
        connection::set_client($client);

        $form = $this->make_form();
        $errors = $form->validation($this->template_data('Optics'), []);

        $this->assertSame(
            get_string('errstreamnametaken', 'mod_agora', 'hr-lms-optics'),
            $errors['newstreamtitle'] ?? null
        );
        $this->assertNotContains(
            'PUT /central/api-v4/streams/configs/hr-lms-optics',
            $client->request_log(),
            'The name check creates no broadcast'
        );
    }

    /**
     * A free name passes the check.
     *
     * @return void
     */
    public function test_free_name_passes(): void {
        connection::set_client($this->client_with_streams(['optics-2026' => ['moodle.hr-lms']]));

        $errors = $this->make_form()->validation($this->template_data('New lecture'), []);

        $this->assertArrayNotHasKey('newstreamtitle', $errors);
    }

    /**
     * A broadcast without a tag is recognized by name, not by a substring
     * search over the first page of the list: `q=` does not go to central.
     *
     * @return void
     */
    public function test_untagged_check_asks_the_stream_by_name(): void {
        $client = $this->client_with_streams([
            'optics-2026' => ['moodle.hr-lms'],
            'operator-only-stream' => [],
        ]);
        connection::set_client($client);

        $error = stream_picker::validation_error('operator-only-stream');

        $this->assertStringContainsString('moodle.hr-lms', (string) $error);
        $this->assertContains('GET /central/api-v4/streams/configs/operator-only-stream', $client->request_log());
        foreach (\mod_agora_mock_central::queries_for('/streams/configs') as $query) {
            $this->assertArrayNotHasKey('q', $query, 'No substring search');
        }
    }

    /**
     * The «is there a broadcast without a tag» check failed (not 404): the form
     * names the refusal, not «no broadcast».
     *
     * @return void
     */
    public function test_untagged_check_refusal_is_named(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /streams/configs' => \mod_agora_mock_central::json(200, ['streams' => [], 'next' => null]),
            'GET /streams/configs/*' => \mod_agora_mock_central::error(500, 'internal', 'config store is down'),
        ]));

        $error = (string) stream_picker::validation_error('operator-only-stream');

        $this->assertStringContainsString('config store is down', $error);
        $this->assertNotSame(get_string('errstreammissing', 'mod_agora', 'operator-only-stream'), $error);
    }

    /**
     * 601 minutes is beyond 1–600: the form names the range.
     *
     * @return void
     */
    public function test_601_minutes_is_refused(): void {
        connection::set_client($this->client_with_streams(['optics-2026' => ['moodle.hr-lms']]));

        $form = $this->make_form();
        $this->assertSame(
            get_string('errcompletionminutesrange', 'mod_agora'),
            $form->validation($this->form_data(['completion_minutes' => 601]), [])['completionminutesgroup'] ?? null
        );
        $this->assertArrayNotHasKey(
            'completionminutesgroup',
            $form->validation($this->form_data(['completion_minutes' => 600]), [])
        );
    }

    /**
     * The default display mode is «Auto».
     *
     * @return void
     */
    public function test_display_mode_defaults_to_auto(): void {
        connection::set_client($this->client_with_streams(['optics-2026' => ['moodle.hr-lms']]));

        // The form as the presenter sees it: the selected item in the markup.
        $html = $this->make_form()->render();

        $this->assertMatchesRegularExpression(
            '#<select[^>]+name="display_mode"[^>]*>.*?<option value="auto" selected#s',
            $html
        );
    }

    /**
     * Zero completion minutes is refused by the form with the named range.
     *
     * @return void
     */
    public function test_zero_completion_minutes_is_refused(): void {
        connection::set_client($this->client_with_streams(['optics-2026' => ['moodle.hr-lms']]));

        $form = $this->make_form();
        $errors = $form->validation($this->form_data(['completion_minutes' => 0]), []);

        $this->assertArrayHasKey('completionminutesgroup', $errors);
        $this->assertSame(get_string('errcompletionminutesrange', 'mod_agora'), $errors['completionminutesgroup']);

        $errors = $form->validation($this->form_data(['completion_minutes' => 30]), []);
        $this->assertArrayNotHasKey('completionminutesgroup', $errors);
    }

    /**
     * Without the `mod/agora:addinstance` capability the activity is absent from the picker.
     *
     * @return void
     */
    public function test_activity_is_absent_without_the_capability(): void {
        // Exactly this service assembles the «Add an activity or resource»
        // list: it is the one that accounts for the `addinstance` capability,
        // not the activity store.
        $service = new \core_course\local\service\content_item_service(
            new \core_course\local\repository\content_item_readonly_repository()
        );

        // The editing presenter has the add capability.
        $this->assertContains(
            'agora',
            $this->content_item_names($service->get_content_items_for_user_in_course($this->teacher, $this->course))
        );

        // The learner does not have it, and the activity is not in the list either.
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->assertFalse(has_capability(
            'mod/agora:addinstance',
            \context_course::instance($this->course->id),
            $student
        ));
        $this->assertNotContains(
            'agora',
            $this->content_item_names($service->get_content_items_for_user_in_course($student, $this->course))
        );
    }

    /**
     * The module names in the «Add an activity or resource» list.
     *
     * @param array $items the list items
     * @return array
     */
    private function content_item_names(array $items): array {
        return array_map(function ($item): string {
            return (string) (is_object($item) ? $item->name : $item['name']);
        }, $items);
    }

    /**
     * The submitted add-activity form data.
     *
     * @param array $extra the fields to override
     * @return array
     */
    private function form_data(array $extra = []): array {
        return $extra + [
            'name' => 'Optics lecture',
            'stream_name' => 'optics-2026',
            'display_mode' => 'auto',
            'timestart' => 0,
            'completionminutesenabled' => 1,
            'completion_minutes' => 30,
            'modulename' => 'agora',
            'instance' => 0,
            'coursemodule' => 0,
            'course' => $this->course->id,
            'visible' => 1,
            'cmidnumber' => '',
            'availabilityconditionsjson' => '',
            'groupmode' => 0,
            'groupingid' => 0,
            'completion' => 2,
            'completionview' => 0,
            'completionexpected' => 0,
        ];
    }

    /**
     * Assembles the add-activity form into the course.
     *
     * @return \mod_agora_mod_form
     */
    private function make_form(): \mod_agora_mod_form {
        global $PAGE;

        global $COURSE;

        $PAGE->set_url('/course/modedit.php');
        $PAGE->set_course($this->course);
        $COURSE = $this->course;
        $current = (object) [
            'course' => $this->course->id,
            'coursemodule' => 0,
            'instance' => 0,
            'add' => 'agora',
            'update' => 0,
            'return' => 0,
            'sr' => 0,
            'modulename' => 'agora',
            'visible' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 0,
        ];
        return new \mod_agora_mod_form($current, 0, null, $this->course);
    }
}
