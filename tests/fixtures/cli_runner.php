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
 * A separate PHP process "as in cron" on top of the PHPUnit database and dataroot.
 *
 * Why. PHPUnit itself preloads half of the core (data generator, test
 * libraries), and code that forgot `require_once` of a needed library works
 * under it but fails in cron: that is how the per-minute completion never got
 * set once (`Class "completion_info" not found`). This process boots Moodle
 * exactly the way `admin/cli/scheduled_task.php` does: `CLI_SCRIPT`, `config.php`,
 * `lib/setup.php` — without `lib/phpunit/lib.php` and without `PHPUNIT_TEST`.
 * The only difference: the database and the dataroot are the test ones
 * (`phpunit_prefix`, `phpunit_dataroot`), so the test sees everything the
 * process wrote.
 *
 * Usage: `php cli_runner.php <mode> <job JSON file>`. Modes:
 *  - `warmup` — just boot: warms the core caches after the PHPUnit reset;
 *  - `task` — a scheduled task through the same path as `scheduled_task.php --execute`;
 *  - `pagestate` — reading the broadcast state by the activity page
 *    (`stream_state::for_page()`), on the «go» signal from stdin — to check the
 *    herd protection with several processes at once.
 *
 * Central is stubbed with the same `mod_agora_mock_central` as in PHPUnit: the
 * routes come in the job file. The process answer is a JSON line as the last
 * output line.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
// phpcs:disable moodle.Files.RequireLogin.Missing

if (isset($_SERVER['REMOTE_ADDR'])) {
    die; // Command line only.
}

define('CLI_SCRIPT', true);

// The first pass of `config.php` — settings only, before the database: further
// on they are switched to the test ones, as `lib/phpunit/bootstrap.php` does.
define('ABORT_AFTER_CONFIG', true);
require(__DIR__ . '/../../../../config.php');

if (empty($CFG->phpunit_prefix) || empty($CFG->phpunit_dataroot) || !is_dir($CFG->phpunit_dataroot)) {
    fwrite(STDERR, "cli_runner: no phpunit_prefix/phpunit_dataroot — PHPUnit not initialized\n");
    exit(2);
}

// Everything the first pass pulled from the production `dataroot` (temp, cache,
// localcache) is thrown out together with it: otherwise the process would write
// caches into the production directory.
$allowed = ['dirroot', 'admin', 'directorypermissions', 'filepermissions', 'dbtype', 'dblibrary', 'dbhost',
    'dbname', 'dbuser', 'dbpass', 'dboptions', 'proxyhost', 'proxyport', 'proxytype', 'proxyuser', 'proxypassword',
    'proxybypass', 'pathtophp'];
$productioncfg = (array) $CFG;
$CFG = new stdClass();
foreach ($productioncfg as $key => $value) {
    if (in_array($key, $allowed, true) || strpos($key, 'phpunit_') === 0) {
        $CFG->{$key} = $value;
    }
}
unset($productioncfg, $allowed, $key, $value);

$CFG->wwwroot = 'https://www.example.com/moodle';
$CFG->dataroot = realpath($CFG->phpunit_dataroot);
$CFG->prefix = $CFG->phpunit_prefix;
foreach (['dbtype', 'dblibrary', 'dbhost', 'dbname', 'dbuser', 'dbpass', 'dboptions'] as $key) {
    if (isset($CFG->{'phpunit_' . $key})) {
        $CFG->{$key} = $CFG->{'phpunit_' . $key};
    }
}
$CFG->directorypermissions = $CFG->phpunit_directorypermissions ?? 02777;
$CFG->filepermissions = ($CFG->directorypermissions & 0666);
$CFG->debug = (E_ALL | E_STRICT);
$CFG->debugdisplay = 1;
// The test site has no mail.
$CFG->noemailever = true;

define('ABORT_AFTER_CONFIG_CANCEL', true);
require($CFG->dirroot . '/lib/setup.php');

// Safeguard: the process must stand on the PHPUnit database. Without the test
// site mark it does nothing — otherwise the task would run over production data.
if ($CFG->prefix !== $CFG->phpunit_prefix || !$DB->get_field('config', 'value', ['name' => 'phpunittest'])) {
    fwrite(STDERR, "cli_runner: process is not on the PHPUnit database — refusal\n");
    exit(3);
}

$mode = (string) ($argv[1] ?? '');
$job = json_decode((string) file_get_contents((string) ($argv[2] ?? '')), true);
if (!is_array($job)) {
    fwrite(STDERR, "cli_runner: no job file\n");
    exit(4);
}

/**
 * Is the completion library already preloaded and by whom.
 *
 * Measured right before the work (the task or the state read), not right after
 * the boot: everything the process required on the way — the stub central, the
 * cron user, locks — could have pulled it in too, and then the test would prove
 * operation in the environment where the defect is invisible.
 *
 * @return array ['preloaded' => bool, 'preloadedby' => files]
 */
function mod_agora_cli_runner_preloaded(): array {
    $preloaded = class_exists('completion_info', false);
    $by = [];
    if ($preloaded) {
        // Who pulled it in: without this an «environment not like cron» cannot be investigated.
        foreach (get_included_files() as $file) {
            if (strpos($file, 'completion') !== false || strpos($file, '/course/lib.php') !== false) {
                $by[] = $file;
            }
        }
    }
    return ['preloaded' => $preloaded, 'preloadedby' => $by];
}

require_once($CFG->dirroot . '/mod/agora/tests/fixtures/mock_central.php');
$routes = [];
foreach (($job['routes'] ?? []) as $route => $answers) {
    $list = [];
    foreach ($answers as $answer) {
        $list[] = mod_agora_mock_central::json((int) $answer['status'], (array) $answer['body']);
    }
    $delay = (float) ($job['delay'] ?? 0);
    if ($delay > 0) {
        // Slow central: the window in which the neighbour processes manage to
        // come for the same state.
        $queue = $list;
        $list = [function () use (&$queue, $delay) {
            usleep((int) ($delay * 1000000));
            return count($queue) > 1 ? array_shift($queue) : $queue[0];
        }];
    }
    $routes[$route] = $list;
}
$client = mod_agora_mock_central::client($routes);
\mod_agora\local\connection::set_client($client);

$result = [];

if ($mode === 'warmup') {
    // Does nothing: the first process after the PHPUnit cache reset assembles
    // the plugin function list and on the way loads the `lib.php` of every
    // plugin — completionlib among them. Cron in steady state does not start
    // like that, so the test first warms the caches with this mode.
    $result['warmed'] = true;
    $result += mod_agora_cli_runner_preloaded();
} else if ($mode === 'task') {
    // The same path as `admin/cli/scheduled_task.php --execute`.
    $task = \core\task\manager::get_scheduled_task((string) $job['task']);
    \core\task\manager::scheduled_task_starting($task);
    \core\cron::setup_user();
    $factory = \core\lock\lock_config::get_lock_factory('cron');
    $cronlock = $factory->get_lock('core_cron', 10);
    $lock = $factory->get_lock('\\' . get_class($task), 10);
    if (!$cronlock || !$lock) {
        fwrite(STDERR, "cli_runner: cron lock not acquired\n");
        exit(5);
    }
    $task->set_lock($lock);
    $cronlock->release();
    $result += mod_agora_cli_runner_preloaded();
    ob_start();
    $failed = null;
    try {
        \core\cron::run_inner_scheduled_task($task);
    } catch (\Throwable $error) {
        $failed = get_class($error) . ': ' . $error->getMessage();
    }
    $result['output'] = (string) ob_get_clean();
    $result['failed'] = $failed;
} else if ($mode === 'pagestate') {
    // Start signal: the processes boot for different lengths of time, and a herd
    // is when they come for the state simultaneously.
    echo "ready\n";
    fgets(STDIN);
    $result += mod_agora_cli_runner_preloaded();
    $state = \mod_agora\local\stream_state::for_page((string) $job['stream']);
    $result['state'] = $state->state;
} else if ($mode === 'holdservicelock') {
    // Holds the broadcast service ticket issue lock until the test says «go»:
    // this is what a neighbour request stuck on issuing a ticket looks like.
    $factory = \core\lock\lock_config::get_lock_factory('mod_agora_servicegrant');
    $lock = $factory->get_lock(sha1((string) $job['stream']), 5);
    echo ($lock ? "locked" : "nolock") . "\n";
    fgets(STDIN);
    if ($lock) {
        $lock->release();
    }
    $result['held'] = (bool) $lock;
} else if ($mode === 'holdstatelock') {
    // Holds the activity page broadcast state request lock until the test says
    // «go»: this is what a neighbour page whose request to central hung looks like.
    $factory = \core\lock\lock_config::get_lock_factory('mod_agora_streamstate');
    $lock = $factory->get_lock(sha1((string) $job['stream']), 5);
    echo ($lock ? "locked" : "nolock") . "\n";
    fgets(STDIN);
    if ($lock) {
        $lock->release();
    }
    $result['held'] = (bool) $lock;
} else if ($mode === 'servicegrant') {
    // A herd for the broadcast service ticket: the same «ready» and «go» as for the state.
    echo "ready\n";
    fgets(STDIN);
    $result += mod_agora_cli_runner_preloaded();
    try {
        $result['token'] = (string) \mod_agora\local\grant_manager::for_preview((string) $job['stream'])->token;
    } catch (\Throwable $error) {
        $result['error'] = get_class($error) . ': ' . $error->getMessage();
    }
} else {
    fwrite(STDERR, "cli_runner: unknown mode {$mode}\n");
    exit(6);
}

$result['requests'] = $client->request_log();
echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
