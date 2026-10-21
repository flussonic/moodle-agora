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
 * The address of the Agora player page.
 *
 * The ticket is written into the address by the server when the page is
 * served: there is no separate request from the browser, so the ticket
 * lifetime must cover the session.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class playback {
    /** The languages the player page understands. */
    const LANGS = ['en', 'ru', 'es', 'pt'];

    /**
     * The iframe address of the player page.
     *
     * @param string $host address of the Agora installation
     * @param string $stream stream name
     * @param string $token the ticket JWT
     * @param array $params additional parameters: dvr, from, to (ms), ondemand
     * @return \moodle_url
     */
    public static function embed_url(string $host, string $stream, string $token, array $params = []): \moodle_url {
        $query = ['token' => $token, 'lang' => self::lang()];
        foreach (['dvr', 'from', 'to', 'autoplay', 'llhls', 'ondemand'] as $name) {
            if (array_key_exists($name, $params) && $params[$name] !== null) {
                $query[$name] = is_bool($params[$name]) ? ($params[$name] ? 'true' : 'false') : $params[$name];
            }
        }
        return new \moodle_url(rtrim($host, '/') . '/playback/embed/' . rawurlencode($stream), $query);
    }

    /**
     * The iframe address of a recording clip: `/playback/embed-vod/{asset}/{file}`.
     *
     * For the learner a recording is a VOD clip built on a ready export (the v3
     * decision: the `/playback/recordings/{id}` recordings routing was removed
     * from the dependencies). The DVR archive is not shown for activities with
     * phases.
     *
     * @param string $host address of the Agora installation
     * @param string $asset the export asset
     * @param string $file the clip file
     * @param string $token the ticket JWT
     * @param array $params additional parameters
     * @return \moodle_url
     */
    public static function vod_embed_url(
        string $host,
        string $asset,
        string $file,
        string $token,
        array $params = []
    ): \moodle_url {
        $query = ['token' => $token, 'lang' => self::lang()];
        foreach (['autoplay'] as $name) {
            if (array_key_exists($name, $params) && $params[$name] !== null) {
                $query[$name] = $params[$name];
            }
        }
        return new \moodle_url(
            rtrim($host, '/') . '/playback/embed-vod/' . rawurlencode($asset) . '/' . rawurlencode($file),
            $query
        );
    }

    /**
     * The player language from the Moodle user language.
     *
     * @return string one of en, ru, es, pt
     */
    public static function lang(): string {
        return self::lang_from(current_language());
    }

    /**
     * The player language from a Moodle language code.
     *
     * Kept apart from `lang()` so the mapping can be checked by a test without
     * installing a language pack: `current_language()` returns only the
     * languages whose packs exist on the site.
     *
     * @param string $language a Moodle language code, for example `ru` or `pt_br`
     * @return string one of en, ru, es, pt
     */
    public static function lang_from(string $language): string {
        $lang = strtolower(substr($language, 0, 2));
        return in_array($lang, self::LANGS, true) ? $lang : 'en';
    }

    /**
     * The stream address in the Agora console — for the course editor.
     *
     * @param string $host address of the Agora installation
     * @param string $stream stream name
     * @return \moodle_url
     */
    public static function console_url(string $host, string $stream): \moodle_url {
        // The Agora console is a single-page application with routes after `#`:
        // a path without the hash is unknown to the server and answers 404.
        return new \moodle_url(rtrim($host, '/') . '/#/streams/' . rawurlencode($stream));
    }
}
