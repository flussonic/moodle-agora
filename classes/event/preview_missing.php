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

namespace mod_agora\event;

/**
 * The broadcast has no preview frame.
 *
 * The card stays without a frame, and an entry is made once per broadcast and
 * cause per hour: otherwise the log would fill with the same line on every
 * course page view.
 *
 * There is more than one cause, and the entry tells them apart:
 * `other[status]` — the response code (of the player front or of central when
 * issuing the service ticket; 0 — there was no answer at all), `other[reason]`
 * — the cause word from the refusal vocabulary `agora_exception::$reason`
 * (`http` — the node answered without a frame, `network` — the node did not
 * answer or the wait ran out, `role`, `password`, `notconfigured`, `license`,
 * `ratelimited`, `busy`, `badresponse`, `notfound`, `conflict` — the service
 * ticket was not issued) or the short exception class name when Moodle itself
 * failed; `other[operation]` — the failing step: `oppreviewframe` — the frame
 * request to the node itself, the rest (`oplogin`, `opcreategrant`,
 * `opissueservice`) — issuing the service ticket at central. The description
 * names the side, its cause and what fixes it — in English, like the core
 * events: a log entry has no reader language.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_missing extends \core\event\base {
    /**
     * The event description.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventpreviewmissing', 'mod_agora');
    }

    /**
     * The log entry description.
     *
     * @return string
     */
    public function get_description() {
        // Entries made before the cause appeared have no `reason` field: back
        // then the log explained only the node answer without a frame. Entries
        // made before the operation appeared have no operation either — the
        // side is then derived from the cause.
        $reason = (string) ($this->other['reason'] ?? 'http');
        $status = (int) ($this->other['status'] ?? 0);
        $operation = (string) ($this->other['operation'] ?? '');
        $side = self::at_node($operation, $reason) ? 'the frame request to the delivery node'
            : 'the service ticket from the Agora server';
        return "The preview frame of the stream '{$this->other['stream']}' was not received: code {$status}, "
            . "reason {$reason}, step: {$side}. " . self::hint($reason, $status, $operation);
    }

    /**
     * Whether the node failed (the frame request itself) or central (issuing the service ticket).
     *
     * @param string $operation the failing operation; empty — an entry from before the field appeared
     * @param string $reason the cause word
     * @return bool
     */
    public static function at_node(string $operation, string $reason): bool {
        if ($operation === '') {
            return in_array($reason, ['http', 'network'], true);
        }
        return $operation === 'oppreviewframe';
    }

    /**
     * What the cause means and what fixes it.
     *
     * @param string $reason the cause word
     * @param int $status the response code; 0 — there was no answer
     * @param string $operation the failing operation: `oppreviewframe` — the node, the rest — central
     * @return string
     */
    public static function hint(string $reason, int $status, string $operation = ''): string {
        if (!self::at_node($operation, $reason)) {
            // Issuing the service ticket: central refused, it never reached the node.
            if ($reason === 'network') {
                return 'The Agora server did not answer the service ticket request: see the «Agora: network to the '
                    . 'Agora server» check; the field is «Agora server address».';
            }
            if ($reason === 'http') {
                return 'The Agora server answered the service ticket request with an error; the details are in '
                    . 'the «Agora refused» records and the System status report.';
            }
        }
        switch ($reason) {
            case 'http':
                if ($status === 404) {
                    return 'The node has no frame: the stream template has no thumbnails section, '
                        . 'or the archive has no frame at this mark.';
                }
                if ($status === 401 || $status === 403) {
                    return 'The node refused the service ticket of the stream; the ticket is dropped '
                        . 'and the next show issues a new one.';
                }
                return 'The node answered with an error instead of a frame.';
            case 'network':
                return 'The node did not answer, or the frame did not arrive within 5 seconds together with '
                    . 'the redirect to the node. The Moodle server must reach the delivery nodes, not only the '
                    . 'Agora server.';
            case 'role':
                return 'The service ticket was not issued: the role of the player account does not allow '
                    . 'issuing tickets (see the «Agora: the player role issues tickets» check).';
            case 'password':
                return 'The service ticket was not issued: Agora rejected the sign-in of the player account '
                    . '(fix «Password of the player account» in the Agora broadcast settings).';
            case 'notconfigured':
                return 'The service ticket was not issued: the connection is not configured — the Agora server '
                    . 'address, the site label or the player account is empty in the Agora broadcast settings.';
            case 'license':
                return 'The service ticket was not issued: the Agora licence does not include accounts.';
            case 'ratelimited':
                return 'The service ticket was not issued: too many sign-ins to Agora; this passes by itself '
                    . 'within a minute.';
            case 'busy':
                return 'Moodle did not get the service ticket: another request was issuing it and did not finish '
                    . 'within the wait (not a refusal by Agora).';
            case 'badresponse':
                return 'The service ticket was not issued: Agora answered with something that is not a JSON object — '
                    . 'usually a proxy page or a wrong Agora server address.';
            case 'notfound':
                return 'The service ticket was not issued: the Agora server answered 404 to the ticket request — '
                    . 'the Agora server address likely points at another service.';
            case 'conflict':
                return 'The service ticket was not issued: Agora answered with a conflict.';
            default:
                return self::at_node($operation, $reason)
                    ? "Moodle failed on its side ({$reason}) while requesting the frame."
                    : "Moodle failed on its side ({$reason}) before the frame was requested.";
        }
    }

    /**
     * Checks the composition of the event.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['stream', 'status', 'reason'] as $field) {
            if (!array_key_exists($field, $this->other ?? [])) {
                throw new \coding_exception('Event preview_missing requires the other[' . $field . '] field.');
            }
        }
    }
}
