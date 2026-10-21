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
 * An Agora refusal on the activity page: what the learner sees, what the course editor sees.
 *
 * The learner gets a phrase and a short code. The course editor gets the HTTP
 * code, `error.code` and `error.message`, but not the whole response body:
 * `Internal` may carry central database error strings. The full body goes to
 * the Moodle event log.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failure {
    /**
     * Writes the full central response to the Moodle event log.
     *
     * The entry always names the operation and who presented it. A refusal
     * raised by the Agora client carries them itself; a refusal that happened
     * before the request (the central address is not set, the client could not
     * be built) does not know them — then the caller names them: it knows what
     * it was doing. Without this the entry would read as "unknown operation",
     * while "403 issuing a ticket" and "403 reading the archive bounds" are
     * fixed by different fields.
     *
     * @param agora_exception $error refusal
     * @param \context $context activity context
     * @param string $operation the caller's operation — when the refusal did not name it
     * @param string $account the caller's account (`player`, `admin`,
     *     `service`) — when the refusal did not name it
     * @return void
     */
    public static function log(agora_exception $error, \context $context, string $operation, string $account): void {
        \mod_agora\event\central_failed::create([
            'context' => $context,
            'other' => [
                'operation' => $error->operation !== '' ? $error->operation : $operation,
                'account' => $error->role !== '' ? $error->role : $account,
                'status' => $error->httpstatus,
                'reason' => $error->reason,
                'errorcode' => (string) ($error->apicode ?? ''),
                'body' => $error->body,
            ],
        ])->trigger();
    }

    /**
     * The line for a user allowed to edit the course: the code, `error.code`,
     * `error.message`. The full response body is not here.
     *
     * @param int $status the HTTP code; 0 — it never got to a response
     * @param string|null $code the `error.code` field
     * @param string|null $message the `error.message` field
     * @return string
     */
    public static function details(int $status, ?string $code, ?string $message): string {
        // The `error.code` and `error.message` come from central: they enter the
        // markup only escaped.
        return get_string('errdetails', 'mod_agora', (object) [
            'status' => $status > 0 ? (string) $status : get_string('errnoresponse', 'mod_agora'),
            'code' => s((string) ($code ?? '-')),
            'message' => s((string) ($message ?? '')),
        ]);
    }

    /**
     * The refusal text according to the viewer's rights.
     *
     * @param agora_exception $error refusal
     * @param \context $context activity context
     * @return string
     */
    public static function message(agora_exception $error, \context $context): string {
        if (has_capability('moodle/course:manageactivities', $context)) {
            return $error->editor_details();
        }
        return get_string('errforviewer', 'mod_agora', $error->short_code());
    }
}
