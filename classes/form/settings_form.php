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

namespace tool_quizbulkedit\form;

use tool_quizbulkedit\local\change_request;
use tool_quizbulkedit\local\settings_catalogue;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The quiz settings to change in bulk (design spec section 8).
 *
 * Every setting of {@see settings_catalogue} is one row: its own "Change" checkbox
 * (change_<key>) followed by its value element(s), which are disabled while the box is
 * unticked. Only ticked settings are read ({@see change_request::from_form_data()}) and
 * written; an unticked setting keeps each quiz's own value. The review options are an
 * 8-row by 4-column grid, one Change box per row. The element names follow the
 * contract in the {@see change_request} class docblock.
 *
 * The quiz selection checkboxes are rendered outside this form (template
 * tool_quizbulkedit/quiztable) and carry form="FORM_ID" so they post with it.
 *
 * Custom data:
 * - courseid int
 * - showcompletion bool: add the Completion section (completion enabled for site and course)
 * - previewhtml string|null: the rendered preview; when set, a Preview section shows it
 * - canapply bool: add the Apply changes button (a preview with something to apply)
 * - needsconfirm bool: add the completion-reset confirmation checkbox
 * - fingerprint string: the preview's fingerprint, posted back with Apply changes
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_form extends \moodleform {
    /** @var string the form's HTML id, referenced by the quiz table's checkboxes. */
    public const FORM_ID = 'tool_quizbulkedit_settings';

    /** @var string[] form element name => name of the group it is in, to place validation errors. */
    protected array $groupof = [];

    /**
     * Define the form elements.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->updateAttributes(['id' => self::FORM_ID]);
        $customdata = $this->_customdata;

        $mform->addElement('hidden', 'courseid', (int) $customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $quizconfig = get_config('quiz');
        foreach (settings_catalogue::get_groups() as $section => $keys) {
            if ($section === 'completion' && empty($customdata['showcompletion'])) {
                continue;
            }
            $mform->addElement('header', 'section_' . $section, get_string('section_' . $section, 'tool_quizbulkedit'));
            $mform->setExpanded('section_' . $section, $section === 'grade');
            if ($section === 'grade') {
                $mform->addElement('static', 'settingsintro', '', get_string('settingsintro', 'tool_quizbulkedit'));
            }
            if ($section === 'review') {
                $mform->addElement('static', 'reviewintro', '', get_string('reviewintro', 'tool_quizbulkedit'));
            }
            foreach ($keys as $key) {
                $this->add_setting($key, $quizconfig);
            }
        }

        if (isset($customdata['previewhtml'])) {
            $mform->addElement('header', 'section_preview', get_string('previewheading', 'tool_quizbulkedit'));
            $mform->setExpanded('section_preview', true);
            $mform->addElement('html', $customdata['previewhtml']);
            if (!empty($customdata['needsconfirm'])) {
                $mform->addElement(
                    'advcheckbox',
                    'confirmreset',
                    get_string('confirmresetlabel', 'tool_quizbulkedit'),
                    get_string('confirmreset', 'tool_quizbulkedit')
                );
                // A confirmation always applies to the preview on screen, never to an earlier one.
                $mform->setConstant('confirmreset', 0);
            }
            $mform->addElement('hidden', 'fingerprint');
            $mform->setType('fingerprint', PARAM_ALPHANUM);
            $mform->setConstant('fingerprint', (string) ($customdata['fingerprint'] ?? ''));
        }

        $buttons = [$mform->createElement('submit', 'preview', get_string('preview', 'tool_quizbulkedit'))];
        if (!empty($customdata['canapply'])) {
            $buttons[] = $mform->createElement('submit', 'apply', get_string('apply', 'tool_quizbulkedit'));
        }
        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Add one setting: its Change checkbox and value element(s) in one group.
     *
     * @param string $key a settings_catalogue key
     * @param \stdClass $quizconfig the quiz plugin's site defaults, used as the initial values
     */
    protected function add_setting(string $key, \stdClass $quizconfig): void {
        $mform = $this->_form;
        $type = settings_catalogue::get_type($key);
        $groupname = $key . '_grp';

        $change = 'change_' . $key;
        $elements = [$mform->createElement('advcheckbox', $change, '', get_string('change', 'tool_quizbulkedit'))];
        // Value element names, and the names that disabledIf needs (a duration is two inputs).
        $values = [];
        $disable = [];

        switch ($type) {
            case settings_catalogue::TYPE_REVIEW:
                foreach (array_keys(settings_catalogue::REVIEW_TIMES) as $time) {
                    $name = $key . '_' . $time;
                    $elements[] = $mform->createElement(
                        'advcheckbox',
                        $name,
                        '',
                        get_string('reviewtime_' . $time, 'tool_quizbulkedit')
                    );
                    $values[] = $name;
                }
                break;
            case settings_catalogue::TYPE_GRADEPASS:
                $elements[] = $mform->createElement('select', 'gradepasstype', get_string('gradepasstype', 'tool_quizbulkedit'), [
                    settings_catalogue::GRADEPASS_PERCENT => get_string('gradepasstype_percent', 'tool_quizbulkedit'),
                    settings_catalogue::GRADEPASS_ABSOLUTE => get_string('gradepasstype_absolute', 'tool_quizbulkedit'),
                ]);
                $elements[] = $mform->createElement('text', 'gradepass', get_string('setting_gradepass', 'tool_quizbulkedit'), [
                    'size' => 6,
                ]);
                $mform->setType('gradepass', PARAM_RAW_TRIMMED);
                $values = ['gradepasstype', 'gradepass'];
                break;
            case settings_catalogue::TYPE_FLOAT:
            case settings_catalogue::TYPE_INT:
                // Plain text, validated by change_request: a 'float' or PARAM_INT element would
                // silently turn a typing mistake into a number.
                $elements[] = $mform->createElement('text', $key, settings_catalogue::get_label($key), ['size' => 6]);
                $mform->setType($key, PARAM_RAW_TRIMMED);
                $values[] = $key;
                break;
            case settings_catalogue::TYPE_DURATION:
                $elements[] = $mform->createElement('duration', $key, settings_catalogue::get_label($key), ['optional' => false]);
                $values[] = $key;
                $disable = [$key . '[number]', $key . '[timeunit]'];
                break;
            default:
                $elements[] = $mform->createElement(
                    'select',
                    $key,
                    settings_catalogue::get_label($key),
                    settings_catalogue::get_choices($key)
                );
                $values[] = $key;
        }

        $mform->addGroup($elements, $groupname, settings_catalogue::get_label($key), ' ', false);
        foreach ($disable ?: $values as $name) {
            $mform->disabledIf($name, $change, 'notchecked');
        }
        foreach (array_merge([$change], $values) as $name) {
            $this->groupof[$name] = $groupname;
        }
        $this->set_initial_values($key, $quizconfig);
    }

    /**
     * Start each value at the quiz plugin's site default, as a new quiz would.
     *
     * The initial value is only a starting point: nothing is written unless the
     * setting's Change box is ticked.
     *
     * @param string $key
     * @param \stdClass $quizconfig
     */
    protected function set_initial_values(string $key, \stdClass $quizconfig): void {
        $mform = $this->_form;
        $type = settings_catalogue::get_type($key);
        if ($type === settings_catalogue::TYPE_REVIEW) {
            $bitmask = (int) ($quizconfig->{'review' . substr($key, 7)} ?? 0);
            foreach (settings_catalogue::REVIEW_TIMES as $time => $bit) {
                $mform->setDefault($key . '_' . $time, ($bitmask & $bit) ? 1 : 0);
            }
            return;
        }
        if ($key === 'maxgrade') {
            $mform->setDefault('maxgrade', format_float((float) ($quizconfig->maximumgrade ?? 10), -1));
            return;
        }
        if ($key === 'gradepass') {
            $mform->setDefault('gradepasstype', settings_catalogue::GRADEPASS_PERCENT);
            return;
        }
        if (isset($quizconfig->$key)) {
            $mform->setDefault($key, $quizconfig->$key);
        }
    }

    /**
     * Expand every section with a ticked Change box, so a re-displayed form shows what was asked.
     */
    public function definition_after_data() {
        parent::definition_after_data();
        $mform = $this->_form;
        foreach (settings_catalogue::get_groups() as $section => $keys) {
            if (!$mform->elementExists('section_' . $section)) {
                continue;
            }
            foreach ($keys as $key) {
                if (!empty($mform->getSubmitValue('change_' . $key))) {
                    $mform->setExpanded('section_' . $section, true);
                    break;
                }
            }
        }
    }

    /**
     * Validate the ticked settings (only those: an unticked setting is never read).
     *
     * @param array $data
     * @param array $files
     * @return array element or group name => error message
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        foreach (change_request::validate_form_data($data) as $name => $message) {
            // Errors show on the group a value element sits in.
            $target = $this->groupof[$name] ?? $name;
            $errors[$target] = isset($errors[$target]) ? $errors[$target] . ' ' . $message : $message;
        }
        return $errors;
    }
}
