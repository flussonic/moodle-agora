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

namespace mod_agora\local;

/**
 * The Agora admission policy: whether open playback is on.
 *
 * Read via `GET {central}/auth` under the `admin` account. The activity page
 * shows a warning to the course editor, so the value is stored in MUC for 60
 * seconds with the same technique as the stream state: otherwise every page
 * view would poke central.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class policy {
    /** How many seconds the value counts as fresh. */
    const FRESH = 60;

    /** Cache key: there is one policy per installation. */
    const KEY = 'openplayback';

    /**
     * Open playback is on.
     *
     * No answer — count it as off: the warning about lifted protection must
     * rest on a fact, not on silence. A connectivity failure is visible
     * separately — in the "Agora: network to the Agora server" check of the
     * System status report.
     *
     * @return bool
     */
    public static function open_playback(): bool {
        $cache = \cache::make('mod_agora', 'policy');
        $now = time();

        $entry = $cache->get(self::KEY);
        if (is_array($entry) && isset($entry['fetched_at']) && $now - (int) $entry['fetched_at'] < self::FRESH) {
            return !empty($entry['open']);
        }

        $open = false;
        try {
            $answer = connection::client()->auth_policy();
            $open = !empty($answer['open_playback']);
        } catch (\Throwable $error) {
            // The previous value beats fresh lying: if there was one, serve it.
            if (is_array($entry) && array_key_exists('open', $entry)) {
                return !empty($entry['open']);
            }
            return false;
        }

        $cache->set(self::KEY, ['open' => $open, 'fetched_at' => $now]);
        return $open;
    }
}
