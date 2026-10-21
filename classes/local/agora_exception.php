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
 * An Agora refusal, parsed down to the cause and the settings field.
 *
 * The learner gets a short code (`AG-503`), the course editor — the code,
 * `error.code` and `error.message`, the event log — the full response body.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agora_exception extends \moodle_exception {
    /** @var int The HTTP response code; 0 — it never got to a response. */
    public int $httpstatus = 0;

    /**
     * @var string The cause as a word: network, http, password, role, license,
     *     ratelimited, notfound, conflict, badresponse, notconfigured, busy,
     *     validation; for a refusal not from Agora (`from_throwable()`) — the
     *     short exception class name.
     */
    public string $reason = 'http';

    /** @var string|null The `error.code` field of the response body. */
    public ?string $apicode = null;

    /** @var string|null The `error.message` field of the response body. */
    public ?string $apimessage = null;

    /** @var array Per-field validation errors: [['path' => 'srt_publish.port', 'message' => …]]. */
    public array $apifields = [];

    /** @var string The full response body — for the event log only. */
    public string $body = '';

    /** @var string The account the request ran under. */
    public string $role = '';

    /**
     * @var string The operation that failed — the key of an `op…` string (for
     *     example `oparchiverange`); empty — the refusal is not from a request
     *     to Agora.
     */
    public string $operation = '';

    /**
     * Builds an Agora refusal from the code, the cause and the message substitution.
     *
     * @param string $stringid key of the message string in mod_agora
     * @param mixed $a substitution into the string
     * @param string $reason the cause as a word
     * @param int $httpstatus the response code
     */
    public function __construct(string $stringid, $a = null, string $reason = 'http', int $httpstatus = 0) {
        $this->reason = $reason;
        $this->httpstatus = $httpstatus;
        parent::__construct($stringid, 'mod_agora', '', $a);
    }

    /**
     * A refusal not from Agora (database, lock, a code error) in the same form as an Agora refusal.
     *
     * Needed where a refusal must land in the event log as a `central_failed`
     * entry but is not raised by the Agora client: the cause is the short
     * exception class name, the response code is 0 (there was no response), the
     * body is the refusal text.
     *
     * @param \Throwable $error the original failure
     * @return self
     */
    public static function from_throwable(\Throwable $error): self {
        $reason = (new \ReflectionClass($error))->getShortName();
        $wrapped = new self('errinternal', s($error->getMessage()), $reason);
        $wrapped->body = $error->getMessage();
        $wrapped->apimessage = $error->getMessage();
        return $wrapped;
    }

    /**
     * The short cause code for the learner.
     *
     * @return string for example AG-503 or AG-NET
     */
    public function short_code(): string {
        if ($this->reason === 'busy') {
            return 'AG-BUSY';
        }
        return $this->httpstatus > 0 ? 'AG-' . $this->httpstatus : 'AG-NET';
    }

    /**
     * The line for a user allowed to edit the course: the code, error.code, error.message.
     *
     * The full response body is not here: `Internal` may carry central database
     * error strings.
     *
     * @return string
     */
    public function editor_details(): string {
        return failure::details($this->httpstatus, $this->apicode, $this->apimessage ?? $this->getMessage());
    }
}
