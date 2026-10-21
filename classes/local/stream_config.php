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
 * The stream configuration: the little of it the plugin needs.
 *
 * So far it is the single `ondemand` flag. It is tri-state: `true`, `false`
 * and the field being absent — "the template decides". The plugin reads it
 * when the activity form is saved and stores it locally: the player page
 * address is built when the page is served, and fetching the stream config on
 * every view would be a needless request to central.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stream_config {
    /**
     * The `ondemand` flag of the stream.
     *
     * @param string $streamname stream name
     * @return int|null 1, 0 or null — "the template decides" or there is no answer
     */
    public static function ondemand(string $streamname): ?int {
        if ($streamname === '') {
            return null;
        }
        try {
            $config = connection::client()->stream_config($streamname);
        } catch (\Throwable $error) {
            // The value is unknown — the player address gets no parameter, and
            // the decision stays with the stream template. The refusal is
            // visible in the "Agora: network to the Agora server" check of the
            // System status report.
            return null;
        }
        if (!array_key_exists('ondemand', $config) || $config['ondemand'] === null) {
            return null;
        }
        return !empty($config['ondemand']) ? 1 : 0;
    }
}
