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
 * Runs `mod/agora/view.php` — the same file the browser opens.
 *
 * The access gate lives in `view.php`, not in the plugin classes: a test that
 * calls `require_login()` itself checks Moodle core and stays green even if the
 * gate is removed from the page. So the real page source is executed here.
 *
 * Only the loader is stubbed: `view.php` starts with `require(config.php)`, and
 * the real `config.php` does `$CFG = new stdClass()` — under PHPUnit that would
 * wipe the prepared environment. The page copy is placed into a directory where
 * `../../config.php` is an empty stub that only imports the global variables
 * into the scope. The stub touches no access check.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_agora_view_script {
    /**
     * Prepares a directory with the page copy and the loader stub.
     *
     * @param string $script the script name in `mod/agora/` (view.php, publish.php)
     * @return string the path to the page copy
     */
    private static function prepare(string $script = 'view.php'): string {
        global $CFG;

        $base = make_temp_directory('mod_agora_view/' . random_string(12));
        $dir = $base . '/mod/agora';
        if (!is_dir($dir)) {
            mkdir($dir, $CFG->directorypermissions, true);
        }

        // The copy is made on every run: the test must execute the file that is
        // in the plugin right now, otherwise an edit of the page would not affect it.
        copy($CFG->dirroot . '/mod/agora/' . $script, $dir . '/' . $script);

        // The `view.php` requires its own lib.php relative to itself.
        file_put_contents(
            $dir . '/lib.php',
            "<?php\nrequire_once(\$GLOBALS['CFG']->dirroot . '/mod/agora/lib.php');\n"
        );

        // Loader stub: only the import of the global variables.
        file_put_contents(
            $base . '/config.php',
            "<?php\nglobal \$CFG, \$DB, \$USER, \$SESSION, \$SITE, \$COURSE, \$PAGE, \$OUTPUT;\n"
        );

        return $dir . '/' . $script;
    }

    /**
     * Opens the activity page and returns its markup.
     *
     * @param int $cmid the course module
     * @param array $params the remaining request parameters, e.g. ['record' => 1]
     * @param string $script the plugin page: view.php or publish.php
     * @param bool $post send the parameters as POST (button actions: view.php
     *     accepts them only this way, review R2 Ф-1)
     * @return string the page HTML
     */
    public static function run(int $cmid, array $params = [], string $script = 'view.php', bool $post = false): string {
        $file = self::prepare($script);

        $savedget = $_GET;
        $savedpost = $_POST;
        $savedrequest = $_REQUEST;
        $savedmethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($post) {
            $_GET = ['id' => $cmid];
            $_POST = $params;
            $_REQUEST = $_POST;
            $_SERVER['REQUEST_METHOD'] = 'POST';
        } else {
            $_POST = [];
            $_GET = ['id' => $cmid] + $params;
            $_REQUEST = $_GET;
        }

        ob_start();
        try {
            require($file);
        } finally {
            $html = ob_get_clean();
            $_GET = $savedget;
            $_POST = $savedpost;
            $_REQUEST = $savedrequest;
            $_SERVER['REQUEST_METHOD'] = $savedmethod;
        }
        return $html;
    }

    /**
     * Opens the page and returns the refusal it answered with.
     *
     * @param int $cmid the course module
     * @param array $params the remaining request parameters
     * @param string $script the plugin page: view.php or publish.php
     * @param bool $post send the parameters as POST
     * @return \Throwable|null null — the page opened
     */
    public static function refusal(int $cmid, array $params = [], string $script = 'view.php', bool $post = false): ?\Throwable {
        try {
            self::run($cmid, $params, $script, $post);
        } catch (\Throwable $e) {
            return $e;
        }
        return null;
    }
}
