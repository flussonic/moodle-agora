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
 * Creating a stream from a template from the activity form.
 *
 * The name is built as `<site label>-<slug>`: the operator sees the course
 * streams by the prefix in any list, and the names do not clash with the
 * operator's ones. The `moodle.<label>` tag is set by the same call — it is
 * the binding condition itself.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stream_creator {
    /** The Cyrillic transliteration table. */
    const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    /**
     * The slug of a name: transliteration, lowercase, only `[a-z0-9_-]`.
     *
     * @param string $title the name the teacher entered
     * @return string empty string when nothing is left after the conversion
     */
    public static function slug(string $title): string {
        $slug = \core_text::strtolower(trim($title));
        $slug = strtr($slug, self::TRANSLIT);
        $slug = preg_replace('/[^a-z0-9_-]+/u', '-', $slug);
        $slug = trim((string) $slug, '-');
        $slug = preg_replace('/-{2,}/', '-', $slug);
        return (string) $slug;
    }

    /**
     * The stream name from the name the teacher entered.
     *
     * @param string $title the name from the form
     * @return string `<label>-<slug>`
     * @throws agora_exception when nothing is left of the name after the conversion
     */
    public static function stream_name(string $title): string {
        connection::require_label('opcreatestream', 'admin');
        $slug = self::slug($title);
        if ($slug === '') {
            throw new agora_exception('errslugempty', null, 'validation');
        }
        return connection::label() . '-' . $slug;
    }

    /**
     * Stream templates: name => caption in the list.
     *
     * The list is paginated by the `next` cursor the same way as the stream list.
     *
     * @return array
     * @throws agora_exception when Agora does not answer
     */
    public static function templates(): array {
        $client = connection::client();
        $options = [];
        $cursor = null;
        for ($page = 0; $page < stream_picker::MAX_PAGES; $page++) {
            $answer = $client->list_templates(null, 200, $cursor);
            foreach (($answer['templates'] ?? []) as $template) {
                $name = (string) ($template['name'] ?? '');
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
     * Checking the new stream name before the form is saved: whether it is taken in Agora.
     *
     * Asks the configuration of the stream with the very name the entered one
     * will produce: a 200 answer — the name is taken, 404 — free. Another
     * refusal (network, password, role) is also returned as text: the form will
     * show it at the field, and the entered value is not lost.
     *
     * @param string $title the name from the form
     * @return string|null the field error text or null when the name is free
     */
    public static function name_taken_error(string $title): ?string {
        try {
            $name = self::stream_name($title);
        } catch (agora_exception $error) {
            // The field error is output by the form without escaping.
            return s($error->getMessage());
        }
        try {
            connection::client()->stream_config($name);
        } catch (agora_exception $error) {
            return $error->reason === 'notfound' ? null : s($error->getMessage());
        }
        return get_string('errstreamnametaken', 'mod_agora', $name);
    }

    /**
     * Creates a stream from a template with publication and a recording policy.
     *
     * One PUT carries both the template reference and the `srt_publish`
     * section: the port is the first free one of the settings range under the
     * registry lock, the key is freshly generated. Then a PATCH of the
     * `auto_export` policy: a policy refusal drops the whole creation, and the
     * created stream is removed, otherwise a retry with the same name would
     * stumble on "name taken". A taken name comes from central not as a field
     * error but as `VersionMismatch` — the plugin itself turns it into the
     * "name taken" message.
     *
     * @param string $title the name from the form
     * @param string $template template name
     * @param array|null $publish ['port' => …, 'key' => …] is written here in plain text
     * @return string the name of the created stream
     * @throws agora_exception
     */
    public static function create(string $title, string $template, ?array &$publish = null): string {
        $name = self::stream_name($title);
        $client = connection::client();
        $key = publish_manager::generate_key();
        try {
            $port = (new port_registry())->allocate(function (int $port) use ($client, $name, $template, $key) {
                $client->create_stream($name, $template, [connection::tag()], [
                    'srt_publish' => ['port' => $port, 'passphrase' => $key],
                ]);
            });
        } catch (agora_exception $error) {
            if ($error->reason === 'conflict') {
                throw new agora_exception('errstreamnametaken', $name, 'conflict', $error->httpstatus);
            }
            throw $error;
        }

        // The recording policy: without it the closed recording is not exported
        // to VOD, and a "success without an effect" (the activity exists, the
        // recording does not) never happens silently (О-3).
        try {
            $client->set_recording_policy($name);
        } catch (agora_exception $error) {
            try {
                $client->delete_stream($name);
            } catch (agora_exception $cleanup) {
                // The stream stayed in Agora without an activity: the cause is
                // in the log, and the operator will see the orphan in the
                // stream list.
                failure::log($cleanup, \context_system::instance(), 'opdeletestream', 'admin');
            }
            throw $error;
        }

        $publish = ['port' => $port, 'key' => $key];
        return $name;
    }
}
