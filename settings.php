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

/**
 * Connecting the site to Agora. The page is guarded by `moodle/site:config`.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Where to look when something does not work.
    $settings->add(new admin_setting_description(
        'mod_agora/statuslink',
        get_string('settingsstatuslink', 'mod_agora'),
        get_string('settingsstatuslink_desc', 'mod_agora', (new moodle_url('/report/status/index.php'))->out())
    ));

    $settings->add(new admin_setting_heading(
        'mod_agora/connectionheading',
        get_string('settingsconnection', 'mod_agora'),
        get_string('settingsconnection_desc', 'mod_agora')
    ));

    $settings->add(new \mod_agora\admin\setting_centralurl(
        'mod_agora/centralurl',
        get_string('settingcentralurl', 'mod_agora'),
        get_string('settingcentralurl_desc', 'mod_agora'),
        '',
        PARAM_RAW,
        60
    ));

    $settings->add(new \mod_agora\admin\setting_sitelabel(
        'mod_agora/sitelabel',
        get_string('settingsitelabel', 'mod_agora'),
        get_string('settingsitelabel_desc', 'mod_agora'),
        '',
        PARAM_RAW,
        40
    ));

    $settings->add(new admin_setting_configtext(
        'mod_agora/playerlogin',
        get_string('settingplayerlogin', 'mod_agora'),
        get_string('settingplayerlogin_desc', 'mod_agora'),
        '',
        PARAM_RAW,
        40
    ));

    $settings->add(new admin_setting_encryptedpassword(
        'mod_agora/playerpassword',
        get_string('settingplayerpassword', 'mod_agora'),
        get_string('settingplayerpassword_desc', 'mod_agora')
    ));

    $settings->add(new admin_setting_configtext(
        'mod_agora/adminlogin',
        get_string('settingadminlogin', 'mod_agora'),
        get_string('settingadminlogin_desc', 'mod_agora'),
        '',
        PARAM_RAW,
        40
    ));

    $settings->add(new admin_setting_encryptedpassword(
        'mod_agora/adminpassword',
        get_string('settingadminpassword', 'mod_agora'),
        get_string('settingadminpassword_desc', 'mod_agora')
    ));

    // TODO(teacher-broadcast): frozen 2026-10-16 — while in-browser
    // broadcasting is hidden, its settings are hidden too: publisherlogin /
    // publisherpassword, publishticketttl, whipaddress. The config keys stay
    // readable (publish.php and the status report do not reference them
    // either); restore the fields together with the button on unfreeze.
    // For whipaddress on unfreeze: the preferred shape is direct proxying of
    // /whip on the central player front — then the installation address will
    // do instead of the node address, and the setting will go away.

    $settings->add(new admin_setting_heading(
        'mod_agora/playbackheading',
        get_string('settingsplayback', 'mod_agora'),
        ''
    ));

    $settings->add(new \mod_agora\admin\setting_template(
        'mod_agora/defaulttemplate',
        get_string('settingdefaulttemplate', 'mod_agora'),
        get_string('settingdefaulttemplate_desc', 'mod_agora'),
        '',
        PARAM_RAW,
        60
    ));

    $ttl = new \mod_agora\admin\setting_grantttl(
        'mod_agora/grantttl',
        get_string('settinggrantttl', 'mod_agora'),
        get_string('settinggrantttl_desc', 'mod_agora'),
        7200,
        3600
    );
    $settings->add($ttl);

    $settings->add(new \mod_agora\admin\setting_intrange(
        'mod_agora/maxconcurrent',
        get_string('settingmaxconcurrent', 'mod_agora'),
        get_string('settingmaxconcurrent_desc', 'mod_agora'),
        2,
        1,
        10,
        'errmaxconcurrentrange'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_agora/bindip',
        get_string('settingbindip', 'mod_agora'),
        get_string('settingbindip_desc', 'mod_agora'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_agora/allowuntagged',
        get_string('settingallowuntagged', 'mod_agora'),
        get_string('settingallowuntagged_desc', 'mod_agora'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_agora/comments',
        get_string('comments', 'mod_agora'),
        get_string('comments_desc', 'mod_agora'),
        1
    ));

    $settings->add(new \mod_agora\admin\setting_intrange(
        'mod_agora/tombstonedays',
        get_string('settingtombstonedays', 'mod_agora'),
        get_string('settingtombstonedays_desc', 'mod_agora'),
        7,
        1,
        3650,
        'errtombstonedaysrange'
    ));

    $settings->add(new \mod_agora\admin\setting_intrange(
        'mod_agora/endofsilence',
        get_string('settingendofsilence', 'mod_agora'),
        get_string('settingendofsilence_desc', 'mod_agora'),
        600,
        60,
        86400,
        'errendofsilencerange'
    ));

    $settings->add(new admin_setting_heading(
        'mod_agora/publishheading',
        get_string('settingspublish', 'mod_agora'),
        get_string('settingspublish_desc', 'mod_agora')
    ));

    $settings->add(new \mod_agora\admin\setting_portrange(
        'mod_agora/srtportrange',
        get_string('settingsrtportrange', 'mod_agora'),
        get_string('settingsrtportrange_desc', 'mod_agora')
    ));

    $settings->add(new admin_setting_configtext(
        'mod_agora/publishaddress',
        get_string('settingpublishaddress', 'mod_agora'),
        get_string('settingpublishaddress_desc', 'mod_agora'),
        '',
        PARAM_RAW,
        60
    ));
}
