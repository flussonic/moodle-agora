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
 * External functions of the plugin.
 *
 * One function — polling the broadcast state from the open activity page
 * (`amd/src/watch.js`). The player after the broadcast start is fetched by the
 * page as a core fragment (`core_get_fragment` →
 * `mod_agora_output_fragment_scene()` in `lib.php`), there is no dedicated
 * function for it.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_agora_get_stream_state' => [
        'classname' => 'mod_agora\external\get_stream_state',
        'methodname' => 'execute',
        'description' => 'Stream state of an Agora activity for the open activity page.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'mod/agora:view',
    ],
    'mod_agora_get_publish_ticket' => [
        'classname' => 'mod_agora\external\get_publish_ticket',
        'methodname' => 'execute',
        'description' => 'Publish ticket and WHIP endpoint for the in-browser publisher page.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'mod/agora:broadcast',
    ],
];

return $functions;
