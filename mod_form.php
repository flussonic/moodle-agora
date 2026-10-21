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
 * The form of the Agora broadcast course activity.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_agora\local\agora_exception;
use mod_agora\local\connection;
use mod_agora\local\stream_creator;
use mod_agora\local\stream_picker;

/**
 * The activity settings form: binding to an existing Agora broadcast.
 *
 * @package    mod_agora
 * @copyright  2026 Flussonic
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_agora_mod_form extends moodleform_mod {
    /** Minimum minutes of the completion condition. */
    const MINUTES_MIN = 1;

    /** Maximum minutes of the completion condition. */
    const MINUTES_MAX = 600;

    /** The length limit of a new broadcast name. */
    const TITLE_MAX = 200;

    /** Form mode: take an existing broadcast. */
    const MODE_EXISTING = 'existing';

    /** Form mode: create a broadcast from a template. */
    const MODE_TEMPLATE = 'template';

    /**
     * The form fields.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('agoraname', 'mod_agora'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $mform->addElement('header', 'agorasource', get_string('sourceheading', 'mod_agora'));
        $mform->setExpanded('agorasource');

        // A broadcast can be created only when adding the activity: a saved
        // activity already has its broadcast and does not need a second one.
        $adding = empty($this->_instance);

        try {
            $options = stream_picker::options();
            $templates = $adding ? stream_creator::templates() : [];
        } catch (agora_exception $e) {
            // The operation needs Agora: name the cause, the check from the
            // report and the settings field. The refusal arrives when the form
            // is opened — it needs the broadcast list for the choice field —
            // and leads to where it is fixed: the plugin settings page (the
            // LMS administrator opens it).
            throw new moodle_exception(
                'errformgate',
                'mod_agora',
                new moodle_url('/admin/settings.php', ['section' => 'modsettingagora']),
                s($e->getMessage())
            );
        }

        if ($adding) {
            $mform->addElement('select', 'source_mode', get_string('sourcemode', 'mod_agora'), [
                self::MODE_EXISTING => get_string('sourcemodeexisting', 'mod_agora'),
                self::MODE_TEMPLATE => get_string('sourcemodetemplate', 'mod_agora'),
            ]);
            $mform->setDefault('source_mode', self::MODE_EXISTING);
            $mform->addHelpButton('source_mode', 'sourcemode', 'mod_agora');
        } else {
            $mform->addElement('hidden', 'source_mode', self::MODE_EXISTING);
            $mform->setType('source_mode', PARAM_ALPHAEXT);
        }

        $mform->addElement(
            'autocomplete',
            'stream_name',
            get_string('streamname', 'mod_agora'),
            $options,
            ['noselectionstring' => get_string('streamnoselection', 'mod_agora')]
        );
        $mform->addHelpButton('stream_name', 'streamname', 'mod_agora');
        $mform->setType('stream_name', PARAM_TEXT);
        $mform->hideIf('stream_name', 'source_mode', 'neq', self::MODE_EXISTING);
        if (!$options) {
            $mform->addElement(
                'static',
                'streamempty',
                '',
                get_string('streamlistempty', 'mod_agora', connection::tag())
            );
            $mform->hideIf('streamempty', 'source_mode', 'neq', self::MODE_EXISTING);
        }

        if ($adding) {
            $mform->addElement('text', 'newstreamtitle', get_string('newstreamtitle', 'mod_agora'), ['size' => 64]);
            $mform->setType('newstreamtitle', PARAM_TEXT);
            // The broadcast name is `<label>-<slug>` in a char(255) column:
            // without a limit a long name would end in an Agora refusal
            // rather than a field error.
            $toolong = get_string('maximumchars', '', self::TITLE_MAX);
            $mform->addRule('newstreamtitle', $toolong, 'maxlength', self::TITLE_MAX, 'client');
            $mform->addHelpButton('newstreamtitle', 'newstreamtitle', 'mod_agora');
            $mform->hideIf('newstreamtitle', 'source_mode', 'neq', self::MODE_TEMPLATE);

            $mform->addElement('select', 'template', get_string('templatename', 'mod_agora'), $templates);
            $mform->setDefault('template', (string) get_config('mod_agora', 'defaulttemplate'));
            $mform->addHelpButton('template', 'templatename', 'mod_agora');
            $mform->hideIf('template', 'source_mode', 'neq', self::MODE_TEMPLATE);
            if (!$templates) {
                $mform->addElement('static', 'templateempty', '', get_string('templatelistempty', 'mod_agora'));
                $mform->hideIf('templateempty', 'source_mode', 'neq', self::MODE_TEMPLATE);
            }

            // The price of creating a broadcast from a course: emptying the
            // recycle bin takes the recording away too.
            $mform->addElement('static', 'tombstonehelp', '', get_string('tombstonehelp', 'mod_agora'));
            $mform->hideIf('tombstonehelp', 'source_mode', 'neq', self::MODE_TEMPLATE);
        }

        $mform->addElement('select', 'display_mode', get_string('displaymode', 'mod_agora'), [
            'auto' => get_string('displaymodeauto', 'mod_agora'),
            'live_only' => get_string('displaymodelive', 'mod_agora'),
            'record_only' => get_string('displaymoderecord', 'mod_agora'),
        ]);
        $mform->setDefault('display_mode', 'auto');
        $mform->addHelpButton('display_mode', 'displaymode', 'mod_agora');

        $mform->addElement('date_time_selector', 'timestart', get_string('timestart', 'mod_agora'), ['optional' => true]);
        $mform->addHelpButton('timestart', 'timestart', 'mod_agora');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * The fields of the completion condition by viewing duration.
     *
     * @return array names of the field groups
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;

        $group = [
            $mform->createElement('checkbox', 'completionminutesenabled', '', get_string('completionminutes', 'mod_agora')),
            $mform->createElement('text', 'completion_minutes', '', ['size' => 4]),
        ];
        $mform->setType('completion_minutes', PARAM_INT);
        $mform->addGroup($group, 'completionminutesgroup', get_string('completionminutesgroup', 'mod_agora'), [' '], false);
        $mform->hideIf('completion_minutes', 'completionminutesenabled', 'notchecked');
        $mform->addHelpButton('completionminutesgroup', 'completionminutesgroup', 'mod_agora');

        // The actual interval is taken from the task record: the schedule is
        // set under "Server → Scheduled tasks", the plugin has no "interval"
        // setting.
        $mform->addElement(
            'static',
            'completionminutesinterval',
            '',
            get_string('completionminutesinterval', 'mod_agora', self::task_interval())
        );
        $mform->hideIf('completionminutesinterval', 'completionminutesenabled', 'notchecked');

        return ['completionminutesgroup', 'completionminutesinterval'];
    }

    /**
     * The actual schedule of the background task, read from its record.
     *
     * @return string a cron schedule string or "unknown"
     */
    public static function task_interval(): string {
        $task = \core\task\manager::get_scheduled_task(\mod_agora\task\sync::class);
        if (!$task) {
            return get_string('unknown', 'mod_agora');
        }
        return $task->get_minute() . ' ' . $task->get_hour() . ' ' . $task->get_day()
            . ' ' . $task->get_month() . ' ' . $task->get_day_of_week();
    }

    /**
     * Whether the own completion rule is enabled.
     *
     * @param array $data form data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionminutesenabled']) && (int) $data['completion_minutes'] > 0;
    }

    /**
     * Prepares data for display in the form.
     *
     * @param array $defaultvalues activity values
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        $defaultvalues['source_mode'] = self::MODE_EXISTING;
        $minutes = (int) ($defaultvalues['completion_minutes'] ?? 0);
        $defaultvalues['completionminutesenabled'] = $minutes > 0 ? 1 : 0;
        if ($minutes === 0) {
            $defaultvalues['completion_minutes'] = self::MINUTES_MIN;
        }
    }

    /**
     * Form validation.
     *
     * @param array $data form data
     * @param array $files form files
     * @return array errors by field names
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $mode = (string) ($data['source_mode'] ?? self::MODE_EXISTING);
        if ($mode === self::MODE_TEMPLATE) {
            $title = (string) ($data['newstreamtitle'] ?? '');
            if (stream_creator::slug($title) === '') {
                $errors['newstreamtitle'] = get_string('errslugempty', 'mod_agora');
            } else if (\core_text::strlen($title) > self::TITLE_MAX) {
                $errors['newstreamtitle'] = get_string('maximumchars', '', self::TITLE_MAX);
            } else {
                // A taken name is a field error, not an error page after
                // submission: what the presenter typed stays in the form. The
                // broadcast is created later, in `agora_add_instance()`, with
                // `If-None-Match: *` — the other half of the guard in case
                // the name gets taken between the check and the creation.
                $taken = stream_creator::name_taken_error($title);
                if ($taken !== null) {
                    $errors['newstreamtitle'] = $taken;
                }
            }
            if (trim((string) ($data['template'] ?? '')) === '') {
                $errors['template'] = get_string('errtemplaterequired', 'mod_agora');
            }
        } else {
            $error = stream_picker::validation_error((string) ($data['stream_name'] ?? ''));
            if ($error !== null) {
                $errors['stream_name'] = $error;
            }
        }

        if (!empty($data['completionminutesenabled'])) {
            $minutes = (int) ($data['completion_minutes'] ?? 0);
            if ($minutes < self::MINUTES_MIN || $minutes > self::MINUTES_MAX) {
                $errors['completionminutesgroup'] = get_string('errcompletionminutesrange', 'mod_agora');
            }
        }

        return $errors;
    }
}
