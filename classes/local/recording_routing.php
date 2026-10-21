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
 * The recordings facet probe: whether the recordings endpoints
 * (`/streams/{name}/recordings`) are mounted in the Agora installation. The
 * universal recordings routing v3 is not used (the customer's decision): the
 * plugin relies only on the recordings facet and exports.
 *
 * The probe is a LIST of the recordings of a stream known to exist: from a
 * single recording "no recording" and "no facet" are indistinguishable, while
 * LIST answers 404 only when the recordings endpoints are not mounted at all.
 * The answer is cached for a long time: the facet is mounted on an Agora
 * upgrade — it is a property of the installation, not of the moment. The cache
 * is dropped on success: a facet that came up is visible at once, without
 * waiting for the refusal to expire.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_routing {
    /** Probe state: the recordings facet is mounted. */
    const OK = 'ok';

    /** No recordings facet (404 on LIST): recordings play through the archive window. */
    const MISSING = 'missing';

    /** The admin account is not admitted (401/403). */
    const AUTH = 'auth';

    /** Central is unreachable: network, 5xx. */
    const DOWN = 'down';

    /**
     * A cached probe: for serving the page.
     *
     * A probe refusal (network, 5xx, 401/403) is logged once — when the probe
     * really went to central, not read the cache.
     *
     * @param string $streamname the name of a stream known to exist
     * @param \context $context context — for logging the refusal
     * @return string one of `OK`, `MISSING`, `AUTH`, `DOWN`
     */
    public static function available(string $streamname, \context $context): string {
        $cache = \cache::make('mod_agora', 'recordrouting');
        $entry = $cache->get($streamname);
        if (is_array($entry)) {
            return (string) $entry['state'];
        }
        $state = self::probe($streamname, $context);
        $cache->set($streamname, ['state' => $state, 'probed_at' => time()]);
        return $state;
    }

    /**
     * A live probe without the cache: for the System status report check.
     *
     * @param string $streamname the name of a stream known to exist
     * @param \context $context context — for logging the refusal
     * @return string one of `OK`, `MISSING`, `AUTH`, `DOWN`
     */
    public static function probe(string $streamname, \context $context): string {
        try {
            connection::client()->list_recordings($streamname);
            return self::OK;
        } catch (agora_exception $error) {
            if ($error->reason === 'notfound') {
                return self::MISSING;
            }
            if (in_array($error->httpstatus, [401, 403], true)) {
                failure::log($error, $context, 'oplistrecordings', 'admin');
                return self::AUTH;
            }
            failure::log($error, $context, 'oplistrecordings', 'admin');
            return self::DOWN;
        }
    }
}
