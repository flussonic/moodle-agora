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
 * Runs `tests/fixtures/cli_runner.php` as a separate PHP process from a test.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * A separate "as in cron" process on top of PHPUnit.
 *
 * The process sees only what the test truly wrote to the database, so the test
 * that invokes it must call `preventResetByRollback()`: otherwise its data
 * lives in an uncommitted transaction and is not visible from outside.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_agora_cli_process {
    /** @var resource the process */
    private $process;

    /** @var array the process pipes: 0 — stdin, 1 — stdout, 2 — stderr */
    private array $pipes = [];

    /**
     * Starts the process and does not wait for it.
     *
     * @param string $mode the `cli_runner.php` mode
     * @param array $job the job: routes of the stub central and the rest
     */
    public function __construct(string $mode, array $job) {
        global $CFG;

        $jobfile = make_request_directory() . '/job.json';
        file_put_contents($jobfile, json_encode($job));
        $php = !empty($CFG->pathtophp) ? $CFG->pathtophp : PHP_BINARY;
        $runner = $CFG->dirroot . '/mod/agora/tests/fixtures/cli_runner.php';
        $this->process = proc_open(
            [$php, $runner, $mode, $jobfile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes
        );
        if (!is_resource($this->process)) {
            throw new \coding_exception('The cli_runner.php process did not start');
        }
    }

    /**
     * Waits for the output line by which the process reports readiness.
     *
     * @param string $line the expected line
     * @return void
     */
    public function wait_for(string $line): void {
        while (($got = fgets($this->pipes[1])) !== false) {
            if (trim($got) === $line) {
                return;
            }
        }
        throw new \coding_exception('The process ended without saying «' . $line . '»: ' . stream_get_contents($this->pipes[2]));
    }

    /**
     * Writes a line to the process stdin.
     *
     * @param string $line the line
     * @return void
     */
    public function send(string $line): void {
        fwrite($this->pipes[0], $line . "\n");
        fflush($this->pipes[0]);
    }

    /**
     * Waits for the process to end and parses its answer.
     *
     * @return array the process answer — the last output line, JSON — plus
     *     `stdout`, `stderr` and `exitcode`
     */
    public function finish(): array {
        fclose($this->pipes[0]);
        $stdout = stream_get_contents($this->pipes[1]);
        $stderr = stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        $exitcode = proc_close($this->process);

        $lines = array_values(array_filter(explode("\n", (string) $stdout), 'strlen'));
        $answer = $lines ? json_decode((string) end($lines), true) : null;
        if (!is_array($answer)) {
            throw new \coding_exception('The cli_runner.php process did not answer JSON (code ' . $exitcode . "):\n"
                . $stdout . "\n" . $stderr);
        }
        return $answer + ['stdout' => (string) $stdout, 'stderr' => (string) $stderr, 'exitcode' => $exitcode];
    }

    /**
     * Starts the process and waits for the answer.
     *
     * @param string $mode the mode
     * @param array $job the job
     * @return array the process answer
     */
    public static function run(string $mode, array $job): array {
        return (new self($mode, $job))->finish();
    }
}
