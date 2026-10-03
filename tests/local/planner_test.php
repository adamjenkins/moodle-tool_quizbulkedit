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
 * Tests for the planner and quiz plans.
 *
 * @package    tool_quizbulkedit
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_quizbulkedit\local;

use mod_quiz\question\display_options;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the planner and quiz plans.
 */
#[CoversClass(planner::class)]
#[CoversClass(quiz_plan::class)]
final class planner_test extends \advanced_testcase {
    /** @var int all four review times. */
    private const ALL = display_options::DURING | display_options::IMMEDIATELY_AFTER
        | display_options::LATER_WHILE_OPEN | display_options::AFTER_CLOSE;

    /** @var int the three times after the attempt. */
    private const AFTER = display_options::IMMEDIATELY_AFTER | display_options::LATER_WHILE_OPEN
        | display_options::AFTER_CLOSE;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enablecompletion = 1;
    }

    /**
     * A course with completion enabled.
     *
     * @param bool $completion
     * @return \stdClass
     */
    private function course(bool $completion = true): \stdClass {
        return $this->getDataGenerator()->create_course(['enablecompletion' => $completion ? 1 : 0]);
    }

    /**
     * A quiz in a course.
     *
     * @param \stdClass $course
     * @param array $settings quiz settings for the generator
     * @return \stdClass the quiz record with cmid
     */
    private function quiz(\stdClass $course, array $settings = []): \stdClass {
        return $this->getDataGenerator()->create_module('quiz', ['course' => $course->id] + $settings);
    }

    /**
     * Plan one quiz.
     *
     * @param \stdClass $course
     * @param \stdClass $quiz
     * @param array $changes for change_request::from_array()
     * @return quiz_plan
     */
    private function plan(\stdClass $course, \stdClass $quiz, array $changes): quiz_plan {
        $plans = (new planner())->plan($course->id, [$quiz->cmid], change_request::from_array($changes));
        $this->assertArrayHasKey($quiz->cmid, $plans);
        return $plans[$quiz->cmid];
    }

    /**
     * Grade to pass as a percentage uses each quiz's own maximum grade and decimal places.
     *
     * Fails if the percentage is applied to a shared maximum or rounded with the wrong precision.
     */
    public function test_gradepass_percentage_per_quiz(): void {
        $course = $this->course();
        $a = $this->quiz($course, ['grade' => 10, 'decimalpoints' => 2]);
        $b = $this->quiz($course, ['grade' => 7, 'decimalpoints' => 1]);
        $request = change_request::from_array(['gradepass' => ['type' => 'percent', 'value' => 33.333]]);
        $plans = (new planner())->plan($course->id, [$a->cmid, $b->cmid], $request);

        // 10 * 33.333 / 100 = 3.3333 -> 3.33; 7 * 33.333 / 100 = 2.33331 -> 2.3.
        $this->assertSame(3.33, $plans[$a->cmid]->get_final('gradepass'));
        $this->assertSame(2.3, $plans[$b->cmid]->get_final('gradepass'));
        $this->assertTrue($plans[$a->cmid]->can_apply());
        $this->assertTrue($plans[$b->cmid]->can_apply());
    }

    /**
     * The percentage uses the NEW maximum grade and the FINAL decimal places when those change too.
     *
     * Fails if the planner reads the current maximum grade or current decimal places.
     */
    public function test_gradepass_percentage_uses_final_max_and_decimals(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, ['grade' => 10, 'decimalpoints' => 2]);

        $plan = $this->plan($course, $quiz, ['maxgrade' => 20, 'gradepass' => ['type' => 'percent', 'value' => 50]]);
        $this->assertSame(10.0, $plan->get_final('gradepass'));

        // 7 * 45 / 100 = 3.15, rounded to 0 decimal places = 3.
        $plan = $this->plan($course, $quiz, [
            'maxgrade' => 7,
            'decimalpoints' => 0,
            'gradepass' => ['type' => 'percent', 'value' => 45],
        ]);
        $this->assertSame(3.0, $plan->get_final('gradepass'));
        $this->assertSame([], $plan->get_errors());
    }

    /**
     * Absolute grade to pass is used as entered.
     */
    public function test_gradepass_absolute(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, ['grade' => 10]);
        $plan = $this->plan($course, $quiz, ['gradepass' => ['type' => 'absolute', 'value' => 6.25]]);
        $this->assertSame(6.25, $plan->get_final('gradepass'));
        $this->assertSame(['gradepass'], array_keys($plan->get_changes()));
    }

    /**
     * Grade to pass above the maximum grade is an error, including when only the maximum is lowered.
     *
     * Fails if the check uses the requested gradepass only, or the current maximum.
     */
    public function test_gradepass_above_max_is_error(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, ['grade' => 10, 'gradepass' => 8]);

        $plan = $this->plan($course, $quiz, ['gradepass' => ['type' => 'absolute', 'value' => 12]]);
        $this->assertArrayHasKey('gradepassabovemax', $plan->get_errors());
        $this->assertFalse($plan->can_apply());

        // Lowering the maximum below the unchanged grade to pass.
        $plan = $this->plan($course, $quiz, ['maxgrade' => 5]);
        $this->assertArrayHasKey('gradepassabovemax', $plan->get_errors());
        $this->assertFalse($plan->can_apply());

        // Fixed by setting the grade to pass in the same request.
        $plan = $this->plan($course, $quiz, ['maxgrade' => 5, 'gradepass' => ['type' => 'percent', 'value' => 80]]);
        $this->assertSame([], $plan->get_errors());
        $this->assertSame(4.0, $plan->get_final('gradepass'));
    }

    /**
     * With certainty-based marking the grade to pass may exceed the maximum (mod_form.php:621).
     *
     * Fails if the CBM exception is dropped or uses the current instead of the final behaviour.
     */
    public function test_cbm_exception(): void {
        $course = $this->course();
        $cbm = $this->quiz($course, ['grade' => 10, 'preferredbehaviour' => 'deferredcbm']);
        $plan = $this->plan($course, $cbm, ['gradepass' => ['type' => 'absolute', 'value' => 12]]);
        $this->assertSame([], $plan->get_errors());

        $plain = $this->quiz($course, ['grade' => 10, 'preferredbehaviour' => 'deferredfeedback']);
        $plan = $this->plan($course, $plain, [
            'preferredbehaviour' => 'immediatecbm',
            'gradepass' => ['type' => 'absolute', 'value' => 12],
        ]);
        $this->assertSame([], $plan->get_errors());

        // Leaving CBM with a grade to pass above the maximum is an error.
        $over = $this->quiz($course, ['grade' => 10, 'gradepass' => 12, 'preferredbehaviour' => 'deferredcbm']);
        $plan = $this->plan($course, $over, ['preferredbehaviour' => 'deferredfeedback']);
        $this->assertArrayHasKey('gradepassabovemax', $plan->get_errors());
    }

    /**
     * Rule 1: the attempt is always shown during the attempt; overall feedback never is.
     */
    public function test_review_rule_attempt_and_overallfeedback_during(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, ['preferredbehaviour' => 'interactive']);
        $plan = $this->plan($course, $quiz, [
            'review_attempt' => display_options::IMMEDIATELY_AFTER,
            'review_overallfeedback' => self::ALL,
        ]);
        $this->assertSame(display_options::DURING | display_options::IMMEDIATELY_AFTER, $plan->get_final('review_attempt'));
        $this->assertSame(self::AFTER, $plan->get_final('review_overallfeedback'));
    }

    /**
     * Rule 2: options the final behaviour does not use are cleared from the DURING column.
     *
     * Fails if the rule is skipped or uses the current behaviour instead of the requested one.
     */
    public function test_review_rule_unused_behaviour_options(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, ['preferredbehaviour' => 'deferredfeedback']);

        $plan = $this->plan($course, $quiz, ['review_correctness' => self::ALL, 'review_maxmarks' => self::ALL]);
        $this->assertSame(self::AFTER, $plan->get_final('review_correctness'));
        // Maximum marks is used by deferred feedback, so it keeps DURING.
        $this->assertSame(self::ALL, $plan->get_final('review_maxmarks'));
        $this->assertArrayHasKey('notereviewadjusted', $plan->get_notes());

        // Switching to interactive in the same request: correctness during is kept.
        $plan = $this->plan($course, $quiz, ['preferredbehaviour' => 'interactive', 'review_correctness' => self::ALL]);
        $this->assertSame(self::ALL, $plan->get_final('review_correctness'));
        $this->assertArrayNotHasKey('notereviewadjusted', $plan->get_notes());
    }

    /**
     * Rule 3: marks at a time need maximum marks at that time (final value).
     */
    public function test_review_rule_marks_need_maxmarks(): void {
        global $DB;
        $course = $this->course();
        $quiz = $this->quiz($course, ['preferredbehaviour' => 'interactive']);
        $DB->set_field(
            'quiz',
            'reviewmaxmarks',
            display_options::DURING | display_options::IMMEDIATELY_AFTER,
            ['id' => $quiz->id]
        );

        $plan = $this->plan($course, $quiz, ['review_marks' => self::ALL]);
        $this->assertSame(display_options::DURING | display_options::IMMEDIATELY_AFTER, $plan->get_final('review_marks'));
        $this->assertArrayHasKey('notereviewadjusted', $plan->get_notes());

        $plan = $this->plan($course, $quiz, ['review_marks' => self::ALL, 'review_maxmarks' => self::ALL]);
        $this->assertSame(self::ALL, $plan->get_final('review_marks'));
    }

    /**
     * Rule 4: after the attempt, correctness and the feedback rows need the attempt row at that time.
     */
    public function test_review_rule_attempt_dependent_rows(): void {
        global $DB;
        $course = $this->course();
        $quiz = $this->quiz($course, ['preferredbehaviour' => 'interactive']);
        $DB->set_field(
            'quiz',
            'reviewattempt',
            display_options::DURING | display_options::IMMEDIATELY_AFTER,
            ['id' => $quiz->id]
        );

        $changes = [];
        foreach (['correctness', 'specificfeedback', 'generalfeedback', 'rightanswer'] as $field) {
            $changes['review_' . $field] = self::ALL;
        }
        // Not attempt-dependent: maxmarks.
        $changes['review_maxmarks'] = self::ALL;
        $plan = $this->plan($course, $quiz, $changes);
        foreach (['correctness', 'specificfeedback', 'generalfeedback', 'rightanswer'] as $field) {
            $this->assertSame(
                display_options::DURING | display_options::IMMEDIATELY_AFTER,
                $plan->get_final('review_' . $field),
                $field
            );
        }
        $this->assertSame(self::ALL, $plan->get_final('review_maxmarks'));

        // Opening the attempt row in the same request lets them through.
        $changes['review_attempt'] = self::ALL;
        $plan = $this->plan($course, $quiz, $changes);
        $this->assertSame(self::ALL, $plan->get_final('review_rightanswer'));
    }

    /**
     * Rule 5 and individual settability: review rows not in the request are never adjusted,
     * even when their stored bits break the rules.
     *
     * Fails if the planner normalises all eight rows when one is changed.
     */
    public function test_review_unchanged_rows_kept_verbatim(): void {
        global $DB;
        $course = $this->course();
        $quiz = $this->quiz($course, ['preferredbehaviour' => 'deferredfeedback']);
        // Inconsistent stored state: marks without maxmarks; correctness after close without the attempt then.
        $DB->update_record('quiz', (object) [
            'id' => $quiz->id,
            'reviewmaxmarks' => 0,
            'reviewmarks' => self::ALL,
            'reviewattempt' => display_options::DURING,
            'reviewcorrectness' => self::ALL,
        ]);

        $plan = $this->plan($course, $quiz, ['review_generalfeedback' => display_options::IMMEDIATELY_AFTER]);
        $this->assertSame(['review_generalfeedback'], array_keys($plan->get_changes()));
        $this->assertSame([], $plan->get_cm_completion_changes());

        $plan = $this->plan($course, $quiz, ['shuffleanswers' => 0]);
        $this->assertSame(['shuffleanswers'], array_keys($plan->get_changes()));
        $this->assertSame(['shuffleanswers' => 0], $plan->get_quiz_column_changes());
    }

    /**
     * A note warns that "after close" review options need a close date.
     */
    public function test_note_after_close(): void {
        global $DB;
        $course = $this->course();
        $open = $this->quiz($course, ['preferredbehaviour' => 'interactive']);
        $DB->set_field('quiz', 'reviewrightanswer', 0, ['id' => $open->id]);
        $plan = $this->plan($course, $open, ['review_rightanswer' => display_options::AFTER_CLOSE]);
        $this->assertArrayHasKey('noteafterclose', $plan->get_notes());

        $closing = $this->quiz($course, ['preferredbehaviour' => 'interactive', 'timeclose' => time() + DAYSECS]);
        $DB->set_field('quiz', 'reviewrightanswer', 0, ['id' => $closing->id]);
        $plan = $this->plan($course, $closing, ['review_rightanswer' => display_options::AFTER_CLOSE]);
        $this->assertArrayNotHasKey('noteafterclose', $plan->get_notes());
    }

    /**
     * The grace period must exceed the site minimum when overdue attempts get a grace period.
     */
    public function test_graceperiod_validation(): void {
        $course = $this->course();
        set_config('graceperiodmin', 60, 'quiz');
        $quiz = $this->quiz($course, ['overduehandling' => 'autosubmit', 'graceperiod' => 30]);

        $plan = $this->plan($course, $quiz, ['overduehandling' => 'graceperiod']);
        $this->assertArrayHasKey('graceperiodtoosmall', $plan->get_errors());

        $plan = $this->plan($course, $quiz, ['overduehandling' => 'graceperiod', 'graceperiod' => 120]);
        $this->assertSame([], $plan->get_errors());

        // Not relevant when overdue attempts are not given a grace period.
        $plan = $this->plan($course, $quiz, ['graceperiod' => 10]);
        $this->assertSame([], $plan->get_errors());
    }

    /**
     * Completion validation mirrors core's forms, on final values.
     */
    public function test_completion_validation(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, ['completion' => COMPLETION_TRACKING_MANUAL, 'attempts' => 2]);

        $plan = $this->plan($course, $quiz, ['completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $this->assertArrayHasKey('badautocompletion', $plan->get_errors());

        $plan = $this->plan($course, $quiz, ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionpassgrade' => 1]);
        $this->assertArrayHasKey('passgradeneedsusegrade', $plan->get_errors());

        $plan = $this->plan($course, $quiz, [
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1, 'completionattemptsexhausted' => 1,
        ]);
        $this->assertArrayHasKey('exhaustedneedspassgrade', $plan->get_errors());

        // Pass grade needs a grade to pass > 0 (final value).
        $plan = $this->plan($course, $quiz, [
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1, 'completionpassgrade' => 1,
        ]);
        $this->assertArrayHasKey('activitygradetopassnotset', $plan->get_errors());
        $plan = $this->plan($course, $quiz, [
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1, 'completionpassgrade' => 1,
            'gradepass' => ['type' => 'percent', 'value' => 50],
        ]);
        $this->assertSame([], $plan->get_errors());

        // Minimum attempts above the final attempts allowed.
        $plan = $this->plan($course, $quiz, ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionminattempts' => 3]);
        $this->assertArrayHasKey('completionminattemptserror', $plan->get_errors());
        $plan = $this->plan($course, $quiz, [
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionminattempts' => 3, 'attempts' => 0,
        ]);
        $this->assertSame([], $plan->get_errors());

        $plan = $this->plan($course, $quiz, ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]);
        $this->assertSame([], $plan->get_errors());
        $this->assertTrue($plan->can_apply());
    }

    /**
     * Lowering attempts below the existing minimum attempts is caught too.
     */
    public function test_attempts_change_checks_minattempts(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, [
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionminattempts' => 3, 'attempts' => 0,
        ]);
        $plan = $this->plan($course, $quiz, ['attempts' => 2]);
        $this->assertArrayHasKey('completionminattemptserror', $plan->get_errors());
    }

    /**
     * Completion changes are refused when completion is off for the course.
     */
    public function test_completion_disabled(): void {
        $course = $this->course(false);
        $quiz = $this->quiz($course);
        $plan = $this->plan($course, $quiz, ['completion' => COMPLETION_TRACKING_MANUAL, 'attempts' => 3]);
        $this->assertArrayHasKey('completiondisabled', $plan->get_errors());
        $this->assertFalse($plan->is_changing('completion'));
        $this->assertFalse($plan->can_apply());
    }

    /**
     * Changing completion with existing completion data warns and needs confirmation;
     * manual tracking kept manual does not reset.
     */
    public function test_completion_reset_warning(): void {
        global $DB;
        $course = $this->course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $quiz = $this->quiz($course, ['completion' => COMPLETION_TRACKING_MANUAL]);

        $plan = $this->plan($course, $quiz, ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]);
        $this->assertTrue($plan->needs_completion_reset());
        $this->assertArrayNotHasKey('warncompletionreset', $plan->get_warnings());

        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $quiz->cmid, 'userid' => $student->id,
            'completionstate' => COMPLETION_COMPLETE, 'timemodified' => time(),
        ]);
        $plan = $this->plan($course, $quiz, ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]);
        $this->assertArrayHasKey('warncompletionreset', $plan->get_warnings());
        $this->assertTrue($plan->needs_confirmation());

        // Manual stays manual: no reset, no warning.
        $plan = $this->plan($course, $quiz, ['completionview' => 1]);
        $this->assertTrue($plan->has_changes());
        $this->assertFalse($plan->needs_completion_reset());
        $this->assertSame([], $plan->get_warnings());
    }

    /**
     * A quiz already holding the requested values is "no change", with no diff rows.
     */
    public function test_no_change(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, ['attempts' => 3, 'grade' => 10, 'navmethod' => 'free']);
        $plan = $this->plan($course, $quiz, ['attempts' => 3, 'maxgrade' => 10.0, 'navmethod' => 'free']);
        $this->assertFalse($plan->has_changes());
        $this->assertFalse($plan->can_apply());
        $this->assertSame([], $plan->get_diff_rows());
        $this->assertSame([], $plan->get_quiz_column_changes());
    }

    /**
     * Diff rows list only differing values, as display text.
     */
    public function test_diff_rows(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, ['attempts' => 3, 'grade' => 10, 'navmethod' => 'free']);
        $plan = $this->plan($course, $quiz, ['attempts' => 0, 'maxgrade' => 10, 'navmethod' => 'sequential']);
        $rows = $plan->get_diff_rows();
        $this->assertSame(['attempts', 'navmethod'], array_column($rows, 'key'));
        $this->assertSame('3', $rows[0]['old']);
        $this->assertSame(get_string('unlimited'), $rows[0]['new']);
        $this->assertSame(get_string('setting_attempts', 'tool_quizbulkedit'), $rows[0]['label']);
    }

    /**
     * A locked grade item blocks grade-affecting changes only.
     *
     * Fails if the lock check is removed (core would then print a "regrade anyway" page mid-request).
     */
    public function test_locked_gradeitem(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, ['grade' => 10]);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id, 'itemnumber' => 0]);
        $item->set_locked(true);

        $plan = $this->plan($course, $quiz, ['maxgrade' => 20]);
        $this->assertArrayHasKey('gradeitemlocked', $plan->get_errors());
        $plan = $this->plan($course, $quiz, ['grademethod' => QUIZ_ATTEMPTLAST]);
        $this->assertArrayHasKey('gradeitemlocked', $plan->get_errors());
        $plan = $this->plan($course, $quiz, ['shuffleanswers' => 0]);
        $this->assertSame([], $plan->get_errors());
        $this->assertTrue($plan->is_gradeitem_locked());
    }

    /**
     * The fingerprint changes with the request, the selection and the quizzes' state.
     *
     * Fails if the fingerprint ignores quiz state (a stale preview could then be applied).
     */
    public function test_fingerprint(): void {
        global $DB;
        $course = $this->course();
        $a = $this->quiz($course, ['attempts' => 1]);
        $b = $this->quiz($course, ['attempts' => 1]);
        $planner = new planner();
        $request = change_request::from_array(['attempts' => 2]);

        $base = $planner->fingerprint($course->id, [$a->cmid, $b->cmid], $request);
        $this->assertSame($base, $planner->fingerprint($course->id, [$b->cmid, $a->cmid], $request));
        $this->assertNotSame($base, $planner->fingerprint($course->id, [$a->cmid], $request));
        $this->assertNotSame($base, $planner->fingerprint(
            $course->id,
            [$a->cmid, $b->cmid],
            change_request::from_array(['attempts' => 3])
        ));

        $DB->set_field('quiz', 'shuffleanswers', 0, ['id' => $b->id]);
        $this->assertNotSame($base, $planner->fingerprint($course->id, [$a->cmid, $b->cmid], $request));
    }
}
