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
 * Bulk edit the settings of a course's quizzes (design spec section 8).
 *
 * Preview plans the ticked settings for the ticked quizzes and shows what would change;
 * Apply changes plans again, refuses when anything changed since the preview (the
 * fingerprint), applies, and redirects back with a summary (POST-redirect-GET).
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/completionlib.php');

use core\output\notification;
use tool_quizbulkedit\form\settings_form;
use tool_quizbulkedit\local\applier;
use tool_quizbulkedit\local\apply_result;
use tool_quizbulkedit\local\change_request;
use tool_quizbulkedit\local\planner;
use tool_quizbulkedit\local\quiz_lister;
use tool_quizbulkedit\output\preview;
use tool_quizbulkedit\output\quiztable;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);

require_login($course);
$context = context_course::instance($courseid);
require_capability('tool/quizbulkedit:manage', $context);

$url = new moodle_url('/admin/tool/quizbulkedit/index.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'tool_quizbulkedit'));
$PAGE->set_heading($course->fullname);
navigation_node::override_active_url($url);

// Every request re-derives the quizzes this user may edit; posted ids are only ever filtered through it.
$eligible = quiz_lister::get_eligible($courseid);
if (!$eligible) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pluginname', 'tool_quizbulkedit'));
    echo $OUTPUT->notification(get_string('noquizzes', 'tool_quizbulkedit'), notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$showcompletion = (bool) (new completion_info($course))->is_enabled();
$formdata = ['courseid' => $courseid, 'showcompletion' => $showcompletion];
$mform = new settings_form($url, $formdata);

$postedcmids = optional_param_array('cmids', [], PARAM_INT);
$selected = quiz_lister::filter_cmids($postedcmids, $eligible);

$displayform = $mform;
if ($data = $mform->get_data()) {
    // The form has checked the sesskey and validated every ticked setting.
    $request = change_request::from_form_data($data);
    if (!$selected) {
        \core\notification::error(get_string('error_noquizzes', 'tool_quizbulkedit'));
    } else if ($request->is_empty()) {
        \core\notification::error(get_string('error_nosettings', 'tool_quizbulkedit'));
    } else {
        $planner = new planner();
        $plans = $planner->plan($courseid, $postedcmids, $request);
        $fingerprint = planner::fingerprint_plans($request, $plans);
        $preview = new preview($plans);
        if ($planner->get_rejected_cmids()) {
            \core\notification::warning(get_string('rejectedcmids', 'tool_quizbulkedit', count($planner->get_rejected_cmids())));
        }

        // Apply changes, the fingerprint and the confirmation are only in the form once a
        // preview is shown, so they are read here (the sesskey was checked above).
        // The button is read with PARAM_RAW: PARAM_INT would clean its label to 0.
        $applying = optional_param('apply', '', PARAM_RAW) !== '';
        if ($applying) {
            $postedfingerprint = optional_param('fingerprint', '', PARAM_ALPHANUM);
            if (!hash_equals($fingerprint, $postedfingerprint)) {
                \core\notification::warning(get_string('previewchanged', 'tool_quizbulkedit'));
            } else if ($preview->needs_confirmation() && !optional_param('confirmreset', 0, PARAM_BOOL)) {
                \core\notification::error(get_string('error_confirmreset', 'tool_quizbulkedit'));
            } else {
                $results = (new applier())->apply($courseid, $plans);
                $counts = [
                    apply_result::APPLIED => 0,
                    apply_result::NOCHANGE => 0,
                    apply_result::SKIPPED => 0,
                    apply_result::FAILED => 0,
                ];
                foreach ($results as $result) {
                    $counts[$result->status]++;
                    if ($result->status === apply_result::APPLIED && !$result->messages) {
                        continue;
                    }
                    if ($result->status === apply_result::NOCHANGE) {
                        continue;
                    }
                    [$name] = quiztable::quiz_name($eligible[$result->cmid]->cm);
                    $a = (object) ['name' => $name, 'messages' => s(implode(' ', $result->messages))];
                    if ($result->status === apply_result::FAILED) {
                        \core\notification::error(get_string('resultfailed', 'tool_quizbulkedit', $a));
                    } else if ($result->status === apply_result::SKIPPED) {
                        \core\notification::warning(get_string('resultskipped', 'tool_quizbulkedit', $a));
                    } else {
                        \core\notification::warning(get_string('resultappliedwithmessages', 'tool_quizbulkedit', $a));
                    }
                }
                $summary = get_string('resultsummary', 'tool_quizbulkedit', (object) $counts);
                $type = notification::NOTIFY_SUCCESS;
                if ($counts[apply_result::FAILED] || $counts[apply_result::SKIPPED]) {
                    $type = notification::NOTIFY_WARNING;
                } else if (!$counts[apply_result::APPLIED]) {
                    $type = notification::NOTIFY_INFO;
                }
                redirect($url, $summary, null, $type);
            }
        }

        $displayform = new settings_form($url, $formdata + [
            'previewhtml' => $OUTPUT->render_from_template('tool_quizbulkedit/preview', $preview->export_for_template($OUTPUT)),
            'canapply' => $preview->can_apply(),
            'needsconfirm' => $preview->needs_confirmation(),
            'fingerprint' => $fingerprint,
        ]);
    }
}

$PAGE->requires->js_call_amd('tool_quizbulkedit/quiztable', 'init', [settings_form::FORM_ID]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'tool_quizbulkedit'));
echo html_writer::tag('p', s(get_string('intro', 'tool_quizbulkedit')));
$table = new quiztable($eligible, $selected, $showcompletion, settings_form::FORM_ID);
echo $OUTPUT->render_from_template('tool_quizbulkedit/quiztable', $table->export_for_template($OUTPUT));
$displayform->display();
echo $OUTPUT->footer();
