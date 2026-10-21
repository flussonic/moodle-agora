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

declare(strict_types=1);

namespace mod_agora\completion;

use core_completion\activity_custom_completion;

/**
 * The completion rule "watched for at least M minutes".
 *
 * The mark is set by the plugin background task from the Agora viewing history:
 * the spec forbids counting it in the browser. Until the task has run, the rule
 * state is "not completed", and the accumulated time is shown to the learner in
 * the rule card.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * The state of the rule for the user.
     *
     * @param string $rule rule name
     * @return int a COMPLETION_* constant
     */
    public function get_state(string $rule): int {
        $this->validate_rule($rule);

        $minutes = (int) ($this->cm->customdata['customcompletionrules']['completionminutes'] ?? 0);
        if ($minutes <= 0) {
            return COMPLETION_INCOMPLETE;
        }
        // The accumulated time is written by the background task from the Agora
        // viewing history; here it is only compared against the threshold.
        $watched = \mod_agora\local\watch::seconds((int) $this->cm->instance, $this->userid);
        return $watched >= $minutes * MINSECS ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * The rules the activity declares.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionminutes'];
    }

    /**
     * Rule descriptions for the completion card.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        $minutes = (int) ($this->cm->customdata['customcompletionrules']['completionminutes'] ?? 0);
        $watched = \mod_agora\local\watch::seconds((int) $this->cm->instance, $this->userid);
        if ($watched > 0) {
            return ['completionminutes' => get_string('completionminutesprogress', 'mod_agora', (object) [
                'watched' => (int) floor($watched / MINSECS),
                'minutes' => $minutes,
            ])];
        }
        return ['completionminutes' => get_string('completionminutesdesc', 'mod_agora', $minutes)];
    }

    /**
     * The order of the rules in the card.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionminutes'];
    }
}
