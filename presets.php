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
 * Site administration: named admin presets, offered to every course's Bulk edit quizzes page.
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use tool_quizbulkedit\form\settings_form;
use tool_quizbulkedit\local\saved_configs;

admin_externalpage_setup('tool_quizbulkedit_presets');

$url = new moodle_url('/admin/tool/quizbulkedit/presets.php');
$editid = optional_param('edit', -1, PARAM_INT);
$deleteid = optional_param('delete', 0, PARAM_INT);

if ($deleteid) {
    $preset = saved_configs::get(saved_configs::PRESETS, $deleteid);
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        saved_configs::delete(saved_configs::PRESETS, $deleteid);
        redirect($url, get_string('presetdeleted', 'tool_quizbulkedit', s($preset->name)), null, notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('adminpresets', 'tool_quizbulkedit'));
    echo $OUTPUT->confirm(
        get_string('deletepresetconfirm', 'tool_quizbulkedit', s($preset->name)),
        new moodle_url($url, ['delete' => $deleteid, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url
    );
    echo $OUTPUT->footer();
    exit;
}

if ($editid >= 0) {
    $preset = $editid ? saved_configs::get(saved_configs::PRESETS, $editid) : null;
    $mform = new settings_form(new moodle_url($url, ['edit' => $editid]), [
        'mode' => settings_form::MODE_PRESET,
        'presetid' => $editid,
    ]);
    if ($mform->is_cancelled()) {
        redirect($url);
    }
    if ($data = $mform->get_data()) {
        $name = saved_configs::clean_name((string) $data->configname);
        saved_configs::save(saved_configs::PRESETS, $name, saved_configs::snapshot($data), $editid);
        redirect($url, get_string('presetsaved', 'tool_quizbulkedit', s($name)), null, notification::NOTIFY_SUCCESS);
    }
    if ($preset && !$mform->is_submitted()) {
        $snapshot = saved_configs::decode($preset);
        $mform->set_data((object) ($snapshot['fields'] + ['configname' => $preset->name]));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($preset ? 'editpreset' : 'addpreset', 'tool_quizbulkedit'));
    $mform->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('adminpresets', 'tool_quizbulkedit'));
echo html_writer::tag('p', s(get_string('adminpresets_desc', 'tool_quizbulkedit')));
$presets = saved_configs::list(saved_configs::PRESETS);
if ($presets) {
    $table = new html_table();
    $table->head = [
        get_string('presetname', 'tool_quizbulkedit'),
        get_string('configsavedon', 'tool_quizbulkedit'),
        get_string('actions'),
    ];
    foreach ($presets as $preset) {
        $actions = html_writer::link(
            new moodle_url($url, ['edit' => $preset->id]),
            get_string('edit'),
            ['class' => 'btn btn-secondary btn-sm']
        ) . ' ' . html_writer::link(
            new moodle_url($url, ['delete' => $preset->id]),
            get_string('delete'),
            ['class' => 'btn btn-outline-danger btn-sm']
        );
        $table->data[] = [
            s($preset->name),
            userdate($preset->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
            $actions,
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nopresets', 'tool_quizbulkedit'), notification::NOTIFY_INFO);
}
echo $OUTPUT->single_button(new moodle_url($url, ['edit' => 0]), get_string('addpreset', 'tool_quizbulkedit'), 'get');
echo $OUTPUT->footer();
