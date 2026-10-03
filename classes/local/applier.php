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

use mod_quiz\quiz_settings;

/**
 * Writes quiz plans (design spec section 7).
 *
 * Each quiz is written in its own delegated transaction: a quiz that throws is
 * rolled back and reported as failed, and the others carry on. Only the changed
 * settings are written; the edit form is never replayed (quiz_after_add_or_update()
 * would delete every quiz_feedback row and re-save every access rule).
 *
 * Per quiz, in order:
 * 1. the changed quiz columns (+ timemodified), in one update_record();
 * 2. grading method changed: recompute_all_final_grades() + quiz_update_grades();
 * 3. time limit or grace period changed: quiz_update_open_attempts();
 * 4. maximum grade changed: grade_calculator::update_quiz_maximum_grade() (rescales
 *    quiz_grades and quiz_feedback, updates the gradebook);
 * 5. quiz_grade_item_update() (the review marks options decide the grade item's
 *    visibility) — skipped when the grade item is locked, because core then prints a
 *    "regrade anyway" page; the planner refuses grade-affecting changes on such quizzes;
 * 6. grade to pass changed: the grade item's gradepass;
 * 7. completion changed: the course_modules completion columns (each quiz keeps its own
 *    completionexpected), marking the quiz for a completion reset unless tracking is
 *    manual (or none) both before and after;
 * 8. quiz_delete_previews(), as quiz_update_instance() does;
 * 9. a course_module_updated event, triggered right after the quiz's transaction commits
 *    (internal event observers and PHPUnit's event sink see events at trigger time, so an
 *    event triggered inside a transaction that is then rolled back would still be seen).
 * After all quizzes: rebuild_course_cache(), then completion_info::reset_all_state() for
 * the marked quizzes.
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class applier {
    /**
     * Apply plans for quizzes of one course.
     *
     * Plans with errors are skipped and plans without changes are left alone.
     *
     * @param int $courseid
     * @param quiz_plan[] $plans from {@see planner::plan()} for the same course
     * @return apply_result[] cmid => result, in plan order
     */
    public function apply(int $courseid, array $plans): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');

        if ($DB->is_transaction_started()) {
            // A failing quiz must not doom the others' writes through a shared outer transaction.
            throw new \coding_exception('The quiz bulk edit applier must not run inside a database transaction.');
        }

        $results = [];
        $toreset = [];
        $applied = false;
        foreach ($plans as $plan) {
            $cm = $plan->get_cm();
            $cmid = $plan->get_cmid();
            $name = (string) $cm->name;
            if ((int) $cm->course !== $courseid) {
                throw new \coding_exception('Quiz plan for course module ' . $cmid . ' is not in course ' . $courseid);
            }
            if ($plan->get_errors()) {
                $results[$cmid] = new apply_result($cmid, $name, apply_result::SKIPPED, array_values($plan->get_errors()));
                continue;
            }
            if (!$plan->has_changes()) {
                $results[$cmid] = new apply_result($cmid, $name, apply_result::NOCHANGE);
                continue;
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $this->apply_plan($plan);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                try {
                    $transaction->rollback($e);
                } catch (\Throwable $rethrown) {
                    // The rollback rethrows the original exception, which is reported below.
                    unset($rethrown);
                }
                $results[$cmid] = new apply_result($cmid, $name, apply_result::FAILED, [$e->getMessage()]);
                continue;
            }
            // 9. After the commit, so that a rolled-back quiz fires no event at all.
            \core\event\course_module_updated::create_from_cm($cm)->trigger();
            $applied = true;
            if ($plan->needs_completion_reset()) {
                $toreset[] = $cmid;
            }
            $results[$cmid] = new apply_result($cmid, $name, apply_result::APPLIED);
        }

        if ($applied) {
            rebuild_course_cache($courseid, true);
        }
        if ($toreset) {
            $modinfo = get_fast_modinfo($courseid);
            $completion = new \completion_info($modinfo->get_course());
            foreach ($toreset as $cmid) {
                try {
                    $completion->reset_all_state($modinfo->get_cm($cmid));
                } catch (\Throwable $e) {
                    $old = $results[$cmid];
                    $results[$cmid] = new apply_result($cmid, $old->name, apply_result::APPLIED, [$e->getMessage()]);
                }
            }
        }
        return $results;
    }

    /**
     * Write one quiz's plan (steps 1-8 of the class docblock). Runs inside a transaction.
     *
     * @param quiz_plan $plan
     */
    protected function apply_plan(quiz_plan $plan): void {
        global $DB;
        $cm = $plan->get_cm();
        $quizid = (int) $plan->get_quiz()->id;
        $now = time();

        // 1. Changed quiz columns.
        $columns = $plan->get_quiz_column_changes();
        if ($columns) {
            $DB->update_record('quiz', (object) (['id' => $quizid, 'timemodified' => $now] + $columns));
        }

        // 2. Grading method.
        if ($plan->is_changing('grademethod')) {
            $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
            $quiz->cmidnumber = $cm->idnumber;
            quiz_settings::create($quizid)->get_grade_calculator()->recompute_all_final_grades();
            quiz_update_grades($quiz);
        }

        // 3. Open attempts' due times.
        if ($plan->is_changing('timelimit') || $plan->is_changing('graceperiod')) {
            quiz_update_open_attempts(['quizid' => $quizid]);
        }

        // 4. Maximum grade (re-read the quiz first: quiz_settings::create() loads it from the database).
        if ($plan->is_changing('maxgrade')) {
            quiz_settings::create($quizid)->get_grade_calculator()
                ->update_quiz_maximum_grade((float) $plan->get_final('maxgrade'));
        }

        // 5. Grade item (visibility follows the review marks options).
        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $quiz->cmidnumber = $cm->idnumber;
        if (!$plan->is_gradeitem_locked()) {
            quiz_grade_item_update($quiz);
        }

        // 6. Grade to pass.
        if ($plan->is_changing('gradepass')) {
            $gradeitem = \grade_item::fetch([
                'courseid' => $cm->course,
                'itemtype' => 'mod',
                'itemmodule' => 'quiz',
                'iteminstance' => $quizid,
                'itemnumber' => 0,
            ]);
            if (!$gradeitem) {
                throw new \moodle_exception('error_nogradeitem', 'tool_quizbulkedit');
            }
            $gradeitem->gradepass = (float) $plan->get_final('gradepass');
            $gradeitem->update('tool_quizbulkedit');
        }

        // 7. Completion (course_modules part; the quiz part was written in step 1).
        // The course_modules table has no timemodified column (lib/db/install.xml, 5.2 and 5.3).
        $cmcolumns = $plan->get_cm_completion_changes();
        if ($cmcolumns) {
            $DB->update_record('course_modules', (object) (['id' => $cm->id] + $cmcolumns));
        }

        // 8. Preview attempts are stale now.
        quiz_delete_previews($quiz);
    }
}
