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
 * Choosing a stream in the activity form.
 *
 * The list comes from central under the `admin` account filtered by the
 * `moodle.<label>` tag; the same condition is re-checked on save. A stream
 * without the tag cannot be bound until the LMS administrator enables the
 * corresponding setting.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stream_picker {
    /** How many list pages to walk: beyond that it is no longer a list for a form. */
    const MAX_PAGES = 50;

    /**
     * Streams that can be bound: name => caption in the list.
     *
     * The list is paginated by the `next` cursor: on the hundred-and-first
     * stream a single page would reject a valid stream with the wrong cause
     * ("no such stream").
     *
     * @return array
     * @throws agora_exception when Agora does not answer or the connection is not configured
     */
    public static function options(): array {
        connection::require_label('opliststreams', 'admin');
        $label = self::filter_label();
        $client = connection::client();

        $options = [];
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $client->list_streams(null, $label, $cursor);
            foreach (($answer['streams'] ?? []) as $stream) {
                $name = (string) ($stream['name'] ?? '');
                if ($name !== '') {
                    $options[$name] = $name;
                }
            }
            $cursor = (string) ($answer['next'] ?? '');
            if ($cursor === '') {
                break;
            }
        }
        ksort($options);
        return $options;
    }

    /**
     * The tag the list is filtered by; null — no filter.
     *
     * @return string|null
     */
    private static function filter_label(): ?string {
        return empty(get_config('mod_agora', 'allowuntagged')) ? connection::tag() : null;
    }

    /**
     * Checks the chosen stream on save.
     *
     * @param string $name stream name
     * @return string|null the error text or null when everything is fine
     */
    public static function validation_error(string $name): ?string {
        $name = trim($name);
        if ($name === '') {
            return get_string('errstreamrequired', 'mod_agora');
        }
        try {
            $options = self::options();
        } catch (agora_exception $e) {
            // The field error is output by the form without escaping.
            return s($e->getMessage());
        }
        if (!isset($options[$name])) {
            // Two different causes — two different texts: "no such stream" is
            // fixed by the name, "a stream without the tag" — by the tag in the
            // Agora console.
            if (empty(get_config('mod_agora', 'allowuntagged'))) {
                $untagged = self::untagged_error($name);
                if ($untagged !== null) {
                    return $untagged;
                }
            }
            return get_string('errstreammissing', 'mod_agora', s($name));
        }
        return null;
    }

    /**
     * Agora has a stream with this name, but it carries no binding tag.
     *
     * The stream configuration is asked by name (`GET streams/configs/{name}`):
     * the answer is exact — 200 or 404 — and depends neither on substring
     * search nor on which list page the stream landed. Only names missing from
     * the tagged stream list get here, so "exists" means "exists, but without
     * the tag".
     *
     * The answer is the text for the form field: "the stream exists but has no
     * tag", the check refusal text (network, password, role — anything but 404)
     * or null when there is no such stream (404). The refusal is named as
     * itself, like the new stream name check
     * (`stream_creator::name_taken_error()`): "no such stream" where we could
     * not even ask would be the wrong cause.
     *
     * @param string $name stream name
     * @return string|null
     */
    private static function untagged_error(string $name): ?string {
        try {
            connection::client()->stream_config($name);
        } catch (agora_exception $e) {
            return $e->reason === 'notfound' ? null : s($e->getMessage());
        }
        return get_string('errstreamnotagged', 'mod_agora', (object) [
            'name' => s($name),
            'tag' => connection::tag(),
        ]);
    }
}
