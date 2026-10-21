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
 * Core events on which the plugin revokes tickets.
 *
 * Revocation on activity deletion is not listed here: `delete_instance` does
 * it, because deleting a whole course does not publish `course_module_deleted`.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\mod_agora\observer::user_enrolment_deleted',
    ],
    [
        'eventname' => '\core\event\user_enrolment_updated',
        'callback' => '\mod_agora\observer::user_enrolment_updated',
    ],
    [
        'eventname' => '\core\event\group_member_removed',
        'callback' => '\mod_agora\observer::access_may_have_changed',
    ],
    [
        'eventname' => '\core\event\role_unassigned',
        'callback' => '\mod_agora\observer::access_may_have_changed',
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => '\mod_agora\observer::user_deleted',
    ],
];
