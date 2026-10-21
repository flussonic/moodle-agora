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

use mod_agora\admin\setting_centralurl;
use mod_agora\admin\setting_grantttl;
use mod_agora\admin\setting_intrange;
use mod_agora\admin\setting_sitelabel;
use mod_agora\admin\setting_template;
use mod_agora\local\connection;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/fixtures/mock_central.php');

/**
 * The connection settings: every typo is rejected with its own text.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_agora\admin\setting_centralurl
 * @covers     \mod_agora\admin\setting_sitelabel
 * @covers     \mod_agora\admin\setting_grantttl
 * @covers     \mod_agora\admin\setting_intrange
 * @covers     \mod_agora\admin\setting_template
 */
final class settings_test extends \advanced_testcase {
    /**
     * Clean state: the settings and the stub client.
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
     * A mistyped address is not saved and names the address form.
     *
     * @return void
     */
    public function test_central_url_typo_is_refused(): void {
        $setting = new setting_centralurl('mod_agora/centralurl', 'Address', '', '', PARAM_RAW, 60);

        $this->assertSame(get_string('errcentralurlform', 'mod_agora'), $setting->write_setting('htp:/agora'));
        $withpath = $setting->write_setting('https://agora.example.com/central');
        $this->assertSame(get_string('errcentralurlform', 'mod_agora'), $withpath);
        $this->assertNull(get_config('mod_agora', 'centralurl') ?: null);

        $this->assertSame('', $setting->write_setting('https://agora.example.com:8443'));
        $this->assertSame('https://agora.example.com:8443', get_config('mod_agora', 'centralurl'));
    }

    /**
     * A label outside the tag alphabet is not saved and names the rule.
     *
     * @return void
     */
    public function test_site_label_out_of_alphabet_is_refused(): void {
        $setting = new setting_sitelabel('mod_agora/sitelabel', 'Label', '', '', PARAM_RAW, 40);

        $this->assertSame(get_string('errsitelabelform', 'mod_agora'), $setting->write_setting('HR LMS'));
        $this->assertSame(get_string('errsitelabelform', 'mod_agora'), $setting->write_setting(str_repeat('a', 41)));

        $this->assertSame('', $setting->write_setting('hr-lms'));
        $this->assertSame('hr-lms', get_config('mod_agora', 'sitelabel'));
    }

    /**
     * A 72-hour ticket lifetime is not saved and names the whole range.
     *
     * @return void
     */
    public function test_grant_ttl_out_of_range_is_refused(): void {
        $setting = new setting_grantttl('mod_agora/grantttl', 'Lifetime', '', 7200, 3600);

        $this->assertSame(
            get_string('errgrantttlrange', 'mod_agora'),
            $setting->write_setting(['v' => 72, 'u' => 3600])
        );
        $this->assertSame(
            get_string('errgrantttlrange', 'mod_agora'),
            $setting->write_setting(['v' => 5, 'u' => 60])
        );

        $this->assertSame('', $setting->write_setting(['v' => 2, 'u' => 3600]));
        $this->assertEquals(7200, get_config('mod_agora', 'grantttl'));
    }

    /**
     * Zero screens is not saved and names the 1–10 range.
     *
     * @return void
     */
    public function test_zero_screens_is_refused(): void {
        $setting = new setting_intrange('mod_agora/maxconcurrent', 'Screens', '', 2, 1, 10, 'errmaxconcurrentrange');

        $this->assertSame(get_string('errmaxconcurrentrange', 'mod_agora'), $setting->write_setting('0'));
        $this->assertSame(get_string('errmaxconcurrentrange', 'mod_agora'), $setting->write_setting('11'));
        $this->assertSame(get_string('errmaxconcurrentrange', 'mod_agora'), $setting->write_setting('two'));

        $this->assertSame('', $setting->write_setting('3'));
        $this->assertEquals(3, get_config('mod_agora', 'maxconcurrent'));
    }

    /**
     * A template missing in central is not saved and is named by its name.
     *
     * @return void
     */
    public function test_unknown_template_is_refused(): void {
        connection::set_client(\mod_agora_mock_central::client([
            'GET /templates/configs' => \mod_agora_mock_central::json(200, [
                'templates' => [['name' => 'lecture'], ['name' => 'townhall']],
                'next' => null,
            ]),
        ]));
        $setting = new setting_template('mod_agora/defaulttemplate', 'Template', '', '', PARAM_RAW, 60);

        $this->assertSame(
            get_string('errtemplatemissing', 'mod_agora', 'no-such-template'),
            $setting->write_setting('no-such-template')
        );
        $this->assertSame('', $setting->write_setting('lecture'));
        $this->assertSame('lecture', get_config('mod_agora', 'defaulttemplate'));
    }

    /**
     * A template from the second page of the list is accepted: the list is
     * walked by the `next` cursor, as in the activity form.
     *
     * @return void
     */
    public function test_template_from_the_second_page_is_accepted(): void {
        $client = \mod_agora_mock_central::client([
            'GET /templates/configs' => function (\Psr\Http\Message\RequestInterface $request) {
                parse_str($request->getUri()->getQuery(), $query);
                if (($query['cursor'] ?? '') === 'page-2') {
                    return \mod_agora_mock_central::json(200, ['templates' => [['name' => 'townhall']], 'next' => null]);
                }
                return \mod_agora_mock_central::json(200, ['templates' => [['name' => 'lecture']], 'next' => 'page-2']);
            },
        ]);
        connection::set_client($client);
        $setting = new setting_template('mod_agora/defaulttemplate', 'Template', '', '', PARAM_RAW, 60);

        $this->assertSame('', $setting->write_setting('townhall'));
        $this->assertSame('townhall', get_config('mod_agora', 'defaulttemplate'));
    }

    /**
     * The account password is not in the settings page markup — only «set».
     *
     * @return void
     */
    public function test_password_is_not_in_html(): void {
        $setting = new \admin_setting_encryptedpassword('mod_agora/playerpassword', 'Password', '');
        $this->assertSame('', $setting->write_setting('s3cr3t-player'));

        $stored = get_config('mod_agora', 'playerpassword');
        $html = $setting->output_html($setting->get_setting());

        $this->assertStringNotContainsString('s3cr3t-player', $html);
        $this->assertStringNotContainsString('s3cr3t-player', (string) $stored);
        $this->assertSame('s3cr3t-player', \core\encryption::decrypt($stored));
    }

    /**
     * The plugin settings page as the core builds it for the LMS administrator.
     *
     * @return \admin_settingpage
     */
    private function settings_page(): \admin_settingpage {
        global $CFG;

        require_once($CFG->libdir . '/adminlib.php');
        $this->setAdminUser();
        $page = admin_get_root(true, true)->locate('modsettingagora');
        $this->assertInstanceOf(\admin_settingpage::class, $page);
        return $page;
    }

    /**
     * The defaults of the real settings page are those the spec promises: two
     * hours, two screens, binding on, untagged broadcasts off, seven days of
     * tombstone, comments on.
     *
     * @return void
     */
    public function test_defaults_of_the_real_settings_page(): void {
        $settings = $this->settings_page()->settings;

        // The duration field stores the default as a value and a unit.
        $this->assertEquals(['v' => 2, 'u' => HOURSECS], $settings->{'mod_agoragrantttl'}->get_defaultsetting());
        // The installer puts the page defaults into the site settings — a fresh
        // installation works with them.
        $this->assertEquals(7200, get_config('mod_agora', 'grantttl'));
        $this->assertEquals(2, $settings->{'mod_agoramaxconcurrent'}->get_defaultsetting());
        $this->assertEquals(1, $settings->{'mod_agorabindip'}->get_defaultsetting());
        $this->assertEquals(0, $settings->{'mod_agoraallowuntagged'}->get_defaultsetting());
        $this->assertEquals(7, $settings->{'mod_agoratombstonedays'}->get_defaultsetting());
        $this->assertEquals(1, $settings->{'mod_agoracomments'}->get_defaultsetting());
        $this->assertInstanceOf(\admin_setting_encryptedpassword::class, $settings->{'mod_agoraplayerpassword'});
        $this->assertInstanceOf(\admin_setting_encryptedpassword::class, $settings->{'mod_agoraadminpassword'});
    }

    /**
     * Frozen 2026-10-16: while in-browser broadcasting is hidden, its settings
     * (the publisher account, the publish ticket lifetime, the WHIP address)
     * are not on the settings page; the SRT publication stays — it is not the
     * frozen feature.
     *
     * @return void
     */
    public function test_frozen_publisher_settings_are_not_on_the_page(): void {
        $settings = $this->settings_page()->settings;

        $this->assertFalse(isset($settings->{'mod_agorapublisherlogin'}));
        $this->assertFalse(isset($settings->{'mod_agorapublisherpassword'}));
        $this->assertFalse(isset($settings->{'mod_agorapublishticketttl'}));
        $this->assertFalse(isset($settings->{'mod_agorawhipaddress'}));
        $this->assertTrue(isset($settings->{'mod_agorasrtportrange'}));
        $this->assertTrue(isset($settings->{'mod_agorapublishaddress'}));
    }

    /**
     * The ranges of the real settings page: screens 1–10, tombstone 1–3650 days,
     * the ticket lifetime from 10 minutes to 24 hours — the boundary is accepted,
     * beyond the boundary the refusal has its own text.
     *
     * @return void
     */
    public function test_ranges_of_the_real_settings_page(): void {
        $settings = $this->settings_page()->settings;
        $screens = get_string('errmaxconcurrentrange', 'mod_agora');
        $days = get_string('errtombstonedaysrange', 'mod_agora');
        $ttl = get_string('errgrantttlrange', 'mod_agora');

        $this->assertSame($screens, $settings->{'mod_agoramaxconcurrent'}->write_setting('0'));
        $this->assertSame($screens, $settings->{'mod_agoramaxconcurrent'}->write_setting('11'));
        $this->assertSame('', $settings->{'mod_agoramaxconcurrent'}->write_setting('10'));
        $this->assertSame('', $settings->{'mod_agoramaxconcurrent'}->write_setting('1'));

        $this->assertSame($days, $settings->{'mod_agoratombstonedays'}->write_setting('0'));
        $this->assertSame($days, $settings->{'mod_agoratombstonedays'}->write_setting('3651'));
        $this->assertSame('', $settings->{'mod_agoratombstonedays'}->write_setting('3650'));

        // The ticket lifetime is a duration field: a value and a unit.
        $this->assertSame($ttl, $settings->{'mod_agoragrantttl'}->write_setting(['v' => 9, 'u' => MINSECS]));
        $this->assertSame($ttl, $settings->{'mod_agoragrantttl'}->write_setting(['v' => 25, 'u' => HOURSECS]));
        $this->assertSame('', $settings->{'mod_agoragrantttl'}->write_setting(['v' => 10, 'u' => MINSECS]));
        $this->assertSame('', $settings->{'mod_agoragrantttl'}->write_setting(['v' => 24, 'u' => HOURSECS]));
    }

    /**
     * The account password does not leak into the log: neither into the login
     * failure event record, nor into the client request log.
     *
     * @return void
     */
    public function test_password_is_not_in_the_log(): void {
        $secret = 'Very-Secret-Pass-42';
        $handler = new \mod_agora_mock_central([
            'POST /auth/login' => \mod_agora_mock_central::error(401, 'unauthorized', 'invalid login or password'),
        ]);
        $client = new \mod_agora\local\agora_client(\mod_agora_mock_central::HOST, [
            'player' => ['login' => 'moodle-player', 'password' => $secret],
            'admin' => ['login' => 'moodle-admin', 'password' => $secret],
        ], $handler);

        $sink = $this->redirectEvents();
        try {
            $client->create_grant(['streams' => ['optics-2026']]);
            $this->fail('The login must be refused');
        } catch (\mod_agora\local\agora_exception $error) {
            \mod_agora\local\failure::log($error, \context_system::instance(), 'opcreategrant', 'player');
        }
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertStringNotContainsString($secret, json_encode($events[0]->get_data()));
        $this->assertStringNotContainsString($secret, $events[0]->get_description());
        $this->assertStringNotContainsString($secret, $error->getMessage());
        $this->assertStringNotContainsString($secret, implode("\n", $client->request_log()));
    }
}
