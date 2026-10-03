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

use mod_quiz\question\display_options;

/**
 * Works out, per selected quiz, what a change request would change (design spec section 6).
 *
 * Rules are checked on the FINAL values: the requested value where a setting is
 * changed, the quiz's current value otherwise. Only the settings in the request
 * get a final value, so a setting the teacher did not tick is never written —
 * in particular, the review-option rules only ever adjust the review rows that
 * are themselves in the request (a row that is not in the request keeps its
 * stored bitmask, even if the rules would have cleared bits of it; at run time
 * core already ignores those bits, see display_options::make_from_quiz()).
 * A validation rule runs only when one of the settings it involves is changed,
 * so a quiz's existing state never blocks an unrelated change.
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class planner {
    /** @var string[] behaviours with which the grade to pass may exceed the maximum grade (mod_form.php:621). */
    protected const CBM_BEHAVIOURS = ['deferredcbm', 'immediatecbm'];

    /** @var string[] review rows hidden at a later time when the attempt row is off then (mod_form.php:427-432). */
    protected const ATTEMPT_DEPENDENT_FIELDS = ['correctness', 'specificfeedback', 'generalfeedback', 'rightanswer'];

    /** @var string[] keys whose change touches the grade item, so a locked grade item blocks them. */
    protected const GRADEITEM_KEYS = ['maxgrade', 'grademethod', 'review_marks', 'review_maxmarks'];

    /** @var int[] posted course module ids that were not eligible in the last plan() call. */
    protected array $rejected = [];

    /**
     * Plan a change request for the selected quizzes of a course.
     *
     * Course module ids are re-derived from the eligible list ({@see quiz_lister}); any
     * id that is not eligible is dropped and reported by {@see get_rejected_cmids()}.
     *
     * @param int $courseid
     * @param int[] $cmids selected course module ids (client-supplied, untrusted)
     * @param change_request $request
     * @return quiz_plan[] cmid => plan, in course order
     */
    public function plan(int $courseid, array $cmids, change_request $request): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $eligible = quiz_lister::get_eligible($courseid);
        $selected = quiz_lister::filter_cmids($cmids, $eligible);
        $posted = array_unique(array_map('intval', $cmids));
        $this->rejected = array_values(array_diff($posted, $selected));
        if (!$selected) {
            return [];
        }

        // Batch per-course facts: one query each, not one per quiz.
        $quizids = array_map(fn($cmid) => (int) $eligible[$cmid]->quiz->id, $selected);
        [$insql, $params] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'qid');
        $withattempts = $DB->get_fieldset_sql(
            "SELECT DISTINCT quiz FROM {quiz_attempts} WHERE preview = 0 AND quiz $insql",
            $params
        );
        $withattempts = array_flip(array_map('intval', $withattempts));
        [$insql, $params] = $DB->get_in_or_equal($selected, SQL_PARAMS_NAMED, 'cmid');
        $withcompletion = $DB->get_fieldset_sql(
            "SELECT DISTINCT coursemoduleid FROM {course_modules_completion} WHERE coursemoduleid $insql",
            $params
        );
        $withcompletion = array_flip(array_map('intval', $withcompletion));

        $course = get_course($courseid);
        $completionenabled = (bool) (new \completion_info($course))->is_enabled();

        $plans = [];
        foreach ($selected as $cmid) {
            $entry = $eligible[$cmid];
            $plan = new quiz_plan(
                $entry,
                isset($withattempts[(int) $entry->quiz->id]),
                isset($withcompletion[$cmid])
            );
            $this->plan_quiz($plan, $request, $completionenabled);
            $plans[$cmid] = $plan;
        }
        return $plans;
    }

    /**
     * Posted course module ids the last {@see plan()} call dropped as not eligible.
     *
     * @return int[]
     */
    public function get_rejected_cmids(): array {
        return $this->rejected;
    }

    /**
     * Fingerprint of a request and the current state of the selected quizzes.
     *
     * The preview carries it; applying recomputes it and refuses when it differs
     * (the request, the selection or a quiz changed since the preview).
     *
     * @param int $courseid
     * @param int[] $cmids
     * @param change_request $request
     * @return string sha1
     */
    public function fingerprint(int $courseid, array $cmids, change_request $request): string {
        return self::fingerprint_plans($request, $this->plan($courseid, $cmids, $request));
    }

    /**
     * Fingerprint of a request and already computed plans (see {@see fingerprint()}).
     *
     * @param change_request $request
     * @param quiz_plan[] $plans
     * @return string sha1
     */
    public static function fingerprint_plans(change_request $request, array $plans): string {
        $quizzes = [];
        foreach ($plans as $plan) {
            $quizzes[$plan->get_cmid()] = $plan->get_snapshot();
        }
        ksort($quizzes);
        return sha1(json_encode(['request' => $request->to_array(), 'quizzes' => $quizzes]));
    }

    /**
     * Plan one quiz.
     *
     * @param quiz_plan $plan
     * @param change_request $request
     * @param bool $completionenabled whether completion is enabled for the site and course
     */
    protected function plan_quiz(quiz_plan $plan, change_request $request, bool $completionenabled): void {
        // Plain settings: final = requested.
        foreach ($request->get_changed_keys() as $key) {
            if ($key === 'gradepass' || settings_catalogue::is_review_key($key)) {
                continue;
            }
            if (settings_catalogue::is_completion_key($key) && !$completionenabled) {
                continue;
            }
            $plan->set_final($key, $request->get($key));
        }

        $this->plan_grades($plan, $request);
        if ($request->has_review_changes()) {
            $this->plan_review($plan, $request);
        }
        $this->check_graceperiod($plan, $request);
        $this->check_completion($plan, $request, $completionenabled);

        if ($plan->is_gradeitem_locked()) {
            foreach (self::GRADEITEM_KEYS as $key) {
                if ($plan->is_changing($key)) {
                    $plan->add_error('gradeitemlocked', get_string('error_gradeitemlocked', 'tool_quizbulkedit'));
                    break;
                }
            }
        }
    }

    /**
     * Maximum grade and grade to pass.
     *
     * @param quiz_plan $plan
     * @param change_request $request
     */
    protected function plan_grades(quiz_plan $plan, change_request $request): void {
        $newmax = (float) $plan->get_final('maxgrade');
        $decimals = (int) $plan->get_final('decimalpoints');

        if ($request->is_changed('gradepass')) {
            if ($plan->get_current('gradepass') === null) {
                $plan->add_error('nogradeitem', get_string('error_nogradeitem', 'tool_quizbulkedit'));
            } else {
                $value = (float) $request->get('gradepass');
                if ($request->get_gradepass_type() === settings_catalogue::GRADEPASS_PERCENT) {
                    $value = round($newmax * $value / 100, $decimals);
                }
                $plan->set_final('gradepass', $value);
            }
        }

        $finalgradepass = $plan->get_final('gradepass');
        if (
            $finalgradepass !== null
            && ($request->is_changed('maxgrade') || $request->is_changed('gradepass')
                || $request->is_changed('preferredbehaviour'))
            && !in_array($plan->get_final('preferredbehaviour'), self::CBM_BEHAVIOURS, true)
            && $finalgradepass - $newmax > \mod_quiz\grade_calculator::ALMOST_ZERO
        ) {
            $plan->add_error('gradepassabovemax', get_string('error_gradepassabovemax', 'tool_quizbulkedit', (object) [
                'gradepass' => format_float($finalgradepass, $decimals),
                'max' => format_float($newmax, $decimals),
            ]));
        }

        if ($plan->is_changing('maxgrade') && $plan->has_attempts()) {
            $plan->add_warning('warnattemptsrescale', get_string('warnattemptsrescale', 'tool_quizbulkedit'));
        }
    }

    /**
     * Review options: apply core's form rules to the requested rows, on final values.
     *
     * Mirrors quiz_process_options() (attempt always shown during, overall feedback never
     * during) and the disabledIf rules of mod_form.php (lines 240-252 and
     * add_review_options_group()): a bit core's form would disable is saved as 0.
     *
     * @param quiz_plan $plan
     * @param change_request $request
     */
    protected function plan_review(quiz_plan $plan, change_request $request): void {
        $unused = \question_engine::get_behaviour_unused_display_options((string) $plan->get_final('preferredbehaviour'));
        $quiz = $plan->get_quiz();
        $adjusted = [];
        $settingafterclose = false;

        // REVIEW_FIELDS lists attempt and maxmarks before the rows that depend on them.
        foreach (settings_catalogue::REVIEW_FIELDS as $field) {
            $key = 'review_' . $field;
            if (!$request->is_changed($key)) {
                continue;
            }
            $requested = (int) $request->get($key);
            $bits = $requested;

            if ($field === 'attempt') {
                $bits |= display_options::DURING;
            }
            if ($field === 'overallfeedback') {
                $bits &= ~display_options::DURING;
            }
            if (in_array($field, $unused, true)) {
                $bits &= ~display_options::DURING;
            }
            if ($field === 'marks') {
                $maxmarks = (int) $plan->get_final('review_maxmarks');
                foreach (settings_catalogue::REVIEW_TIMES as $bit) {
                    if (!($maxmarks & $bit)) {
                        $bits &= ~$bit;
                    }
                }
            }
            if (in_array($field, self::ATTEMPT_DEPENDENT_FIELDS, true)) {
                $attempt = (int) $plan->get_final('review_attempt');
                foreach (settings_catalogue::REVIEW_TIMES as $time => $bit) {
                    if ($time !== 'during' && !($attempt & $bit)) {
                        $bits &= ~$bit;
                    }
                }
            }

            $plan->set_final($key, $bits);
            if ($requested & ~$bits) {
                $adjusted[] = settings_catalogue::get_label($key);
            }
            if (($bits & display_options::AFTER_CLOSE) && !((int) $plan->get_current($key) & display_options::AFTER_CLOSE)) {
                $settingafterclose = true;
            }
        }

        if ($adjusted) {
            $plan->add_note('notereviewadjusted', get_string('notereviewadjusted', 'tool_quizbulkedit', implode(', ', $adjusted)));
        }
        if ($settingafterclose && empty($quiz->timeclose)) {
            $plan->add_note('noteafterclose', get_string('noteafterclose', 'tool_quizbulkedit'));
        }
    }

    /**
     * Grace period must exceed the site minimum when overdue attempts get one (mod_form.php:554-558).
     *
     * @param quiz_plan $plan
     * @param change_request $request
     */
    protected function check_graceperiod(quiz_plan $plan, change_request $request): void {
        if (!$request->is_changed('overduehandling') && !$request->is_changed('graceperiod')) {
            return;
        }
        if ($plan->get_final('overduehandling') !== 'graceperiod') {
            return;
        }
        $graceperiodmin = (int) get_config('quiz', 'graceperiodmin');
        if ((int) $plan->get_final('graceperiod') <= $graceperiodmin) {
            $plan->add_error('graceperiodtoosmall', get_string('graceperiodtoosmall', 'quiz', format_time($graceperiodmin)));
        }
    }

    /**
     * Completion rules (core's quiz form and moodleform_mod validation).
     *
     * @param quiz_plan $plan
     * @param change_request $request
     * @param bool $completionenabled
     */
    protected function check_completion(quiz_plan $plan, change_request $request, bool $completionenabled): void {
        $completionchanged = $request->has_completion_changes();
        if ($completionchanged && !$completionenabled) {
            $plan->add_error('completiondisabled', get_string('error_completiondisabled', 'tool_quizbulkedit'));
            return;
        }

        $tracking = (int) $plan->get_final('completion');
        $usegrade = (bool) $plan->get_final('completionusegrade');
        $passgrade = (bool) $plan->get_final('completionpassgrade');
        $exhausted = (bool) $plan->get_final('completionattemptsexhausted');
        $minattempts = (int) $plan->get_final('completionminattempts');
        $view = (bool) $plan->get_final('completionview');

        if ($completionchanged) {
            if ($passgrade && !$usegrade) {
                $plan->add_error('passgradeneedsusegrade', get_string('error_passgradeneedsusegrade', 'tool_quizbulkedit'));
            }
            if ($exhausted && !$passgrade) {
                $plan->add_error('exhaustedneedspassgrade', get_string('error_exhaustedneedspassgrade', 'tool_quizbulkedit'));
            }
            if (
                $tracking === COMPLETION_TRACKING_AUTOMATIC
                && !$view && !$usegrade && !$passgrade && !$exhausted && $minattempts <= 0
            ) {
                $plan->add_error('badautocompletion', get_string('badautocompletion', 'completion'));
            }
        }

        if (
            ($completionchanged || $request->is_changed('gradepass'))
            && $tracking === COMPLETION_TRACKING_AUTOMATIC && $passgrade
        ) {
            $gradepass = $plan->get_final('gradepass');
            if ($gradepass === null || $gradepass <= 0) {
                $plan->add_error('activitygradetopassnotset', get_string('activitygradetopassnotset', 'completion'));
            }
        }

        if ($request->is_changed('completionminattempts') || $request->is_changed('attempts')) {
            $attempts = (int) $plan->get_final('attempts');
            if ($minattempts > 0 && $attempts > 0 && $minattempts > $attempts) {
                $plan->add_error('completionminattemptserror', get_string('completionminattemptserror', 'quiz'));
            }
        }

        if ($plan->needs_completion_reset() && $plan->has_completion_data()) {
            $plan->add_warning('warncompletionreset', get_string('warncompletionreset', 'tool_quizbulkedit'));
        }
    }
}
