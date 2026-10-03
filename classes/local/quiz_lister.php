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

namespace tool_quizbulkedit\local;

/**
 * Lists the quizzes of a course the current user may bulk edit (design spec section 3).
 *
 * A quiz is eligible when its course module is in the course, is not being
 * deleted, and the user has both moodle/course:manageactivities and
 * mod/quiz:manage in the quiz's module context. Client-supplied course module
 * ids must always be filtered through this list.
 *
 * Course-wide data is loaded with one query per table, not one per quiz.
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_lister {
    /**
     * The quizzes of a course the user may bulk edit, in course-page order (subsection content inline).
     *
     * Each entry is an object with:
     * - cm: \cm_info
     * - quiz: \stdClass the full quiz record
     * - cmrecord: \stdClass the course_modules record (current completion columns)
     * - gradepass: float|null the grade item's grade to pass (null when there is no grade item)
     * - gradeitemlocked: bool whether the quiz's grade item is locked in the gradebook
     *
     * @param int $courseid
     * @param int|null $userid the user to check capabilities for; null = current user
     * @return \stdClass[] cmid => entry
     */
    public static function get_eligible(int $courseid, ?int $userid = null): array {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/gradelib.php');

        $userid = $userid ?? (int) $USER->id;
        $modinfo = get_fast_modinfo($courseid, $userid);
        $cms = [];
        foreach ($modinfo->get_instances_of('quiz') as $cm) {
            if (!empty($cm->deletioninprogress) || (int) $cm->course !== $courseid) {
                continue;
            }
            $context = $cm->context;
            if (
                !has_capability('moodle/course:manageactivities', $context, $userid)
                || !has_capability('mod/quiz:manage', $context, $userid)
            ) {
                continue;
            }
            $cms[(int) $cm->id] = $cm;
        }
        if (!$cms) {
            return [];
        }

        $quizids = array_map(fn(\cm_info $cm) => (int) $cm->instance, $cms);
        $quizzes = $DB->get_records_list('quiz', 'id', array_values($quizids));
        $cmrecords = $DB->get_records_list('course_modules', 'id', array_keys($cms));

        $gradeitems = [];
        $items = \grade_item::fetch_all([
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'itemnumber' => 0,
        ]);
        foreach ($items ?: [] as $item) {
            $gradeitems[(int) $item->iteminstance] = $item;
        }

        // Course-page order: get_cms() lists subsection content after every listed section,
        // sort_cm_array() puts it inline where the subsection sits.
        $modinfo->sort_cm_array($cms);

        $result = [];
        foreach ($cms as $cmid => $cm) {
            if (!isset($quizzes[$cm->instance]) || !isset($cmrecords[$cmid])) {
                continue;
            }
            $item = $gradeitems[(int) $cm->instance] ?? null;
            $result[$cmid] = (object) [
                'cm' => $cm,
                'quiz' => $quizzes[$cm->instance],
                'cmrecord' => $cmrecords[$cmid],
                'gradepass' => $item ? (float) $item->gradepass : null,
                'gradeitemlocked' => $item ? (bool) $item->is_locked() : false,
            ];
        }
        return $result;
    }

    /**
     * Keep only the course module ids that are eligible, dropping anything else.
     *
     * @param int[] $cmids client-supplied course module ids
     * @param array $eligible the result of {@see get_eligible()}
     * @return int[] eligible ids in course order, without duplicates
     */
    public static function filter_cmids(array $cmids, array $eligible): array {
        $wanted = array_flip(array_map('intval', $cmids));
        return array_values(array_filter(array_keys($eligible), fn($cmid) => isset($wanted[$cmid])));
    }
}
