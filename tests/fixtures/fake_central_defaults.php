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
 * The default composition of the central stub for Behat.
 *
 * Extracted from `fake_central.php` so that the Behat step that changes the
 * broadcast state takes the same composition instead of keeping a second copy.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The stub state before anyone changed it.
 *
 * The `state` field is one of the six card states: the stub knows them all,
 * otherwise the badge scenario would check the same thing twice.
 *
 * @return array
 */
function mod_agora_fake_central_defaults(): array {
    return [
        'streams' => [
            ['name' => 'optics-2026', 'labels' => ['moodle.hr-lms'], 'state' => 'live'],
            ['name' => 'townhall', 'labels' => ['moodle.hr-lms'], 'state' => 'ended_recorded'],
            ['name' => 'operator-only', 'labels' => [], 'state' => 'not_started'],
        ],
        'templates' => [['name' => 'lecture'], ['name' => 'townhall-template']],
        'grants' => [],
        'tokens' => [],
        'sessions' => [],
        'counter' => 0,
        'open_playback' => false,
        // Publish address: what the port summary reads from the installation.
        'publish_address' => 'publish.agora.example.com',
        // Broadcast recordings: id => card; a card has exports.
        'recordings' => [],
        'accounts' => [
            'moodle-player' => ['password' => 'player-secret', 'role' => 'player'],
            'moodle-admin' => ['password' => 'admin-secret', 'role' => 'admin'],
            'moodle-publisher' => ['password' => 'publisher-secret', 'role' => 'publish'],
        ],
    ];
}
