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

namespace tool_quizbulkedit\output;

use tool_quizbulkedit\local\settings_catalogue;

/**
 * The table of the course's quizzes the user may bulk edit, with a selection checkbox per quiz.
 *
 * Template: tool_quizbulkedit/quiztable. The checkboxes sit outside the settings form and
 * carry form="<formid>" so they post with it as cmids[].
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiztable implements \renderable, \templatable {
    /**
     * Constructor.
     *
     * @param array $eligible the result of {@see \tool_quizbulkedit\local\quiz_lister::get_eligible()}
     * @param int[] $selected the course module ids to show ticked (already filtered to eligible ones)
     * @param bool $showcompletion whether to show the Completion tracking column
     * @param string $formid the id of the form the checkboxes submit with
     */
    public function __construct(
        /** @var array cmid => quiz_lister entry */
        protected array $eligible,
        /** @var int[] ticked course module ids */
        protected array $selected,
        /** @var bool whether to show the Completion tracking column */
        protected bool $showcompletion,
        /** @var string the settings form's id */
        protected string $formid,
    ) {
    }

    /**
     * The quiz name as HTML (format_string()) and as plain text (for the name filter).
     *
     * @param \cm_info $cm
     * @return string[] [html, text]
     */
    public static function quiz_name(\cm_info $cm): array {
        $html = format_string($cm->name, true, ['context' => $cm->context]);
        return [$html, html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
    }

    /**
     * Data for the template.
     *
     * @param \renderer_base $output
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $selected = array_flip(array_map('intval', $this->selected));
        $quizzes = [];
        foreach ($this->eligible as $cmid => $entry) {
            [$namehtml, $nametext] = self::quiz_name($entry->cm);
            $decimals = (int) $entry->quiz->decimalpoints;
            $quizzes[] = [
                'cmid' => (int) $cmid,
                'name' => $namehtml,
                'filtername' => $nametext,
                'selectlabel' => get_string('selectquiz', 'tool_quizbulkedit', $nametext),
                'url' => (new \moodle_url('/mod/quiz/view.php', ['id' => $cmid]))->out(false),
                'selected' => isset($selected[(int) $cmid]),
                'maxgrade' => settings_catalogue::format_value('maxgrade', $entry->quiz->grade, $decimals),
                'gradepass' => settings_catalogue::format_value('gradepass', $entry->gradepass, $decimals),
                'attempts' => settings_catalogue::format_value('attempts', $entry->quiz->attempts),
                'completion' => $this->showcompletion
                    ? settings_catalogue::format_value('completion', $entry->cmrecord->completion) : '',
            ];
        }
        return [
            'formid' => $this->formid,
            'hasrows' => !empty($quizzes),
            'showcompletion' => $this->showcompletion,
            'colcount' => $this->showcompletion ? 6 : 5,
            'quizzes' => $quizzes,
        ];
    }
}
