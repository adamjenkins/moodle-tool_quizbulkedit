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
 * Tests for the applier.
 *
 * @package    tool_quizbulkedit
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_quizbulkedit\local;

use mod_quiz\question\display_options;
use mod_quiz\quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the applier.
 */
#[CoversClass(applier::class)]
#[CoversClass(apply_result::class)]
final class applier_test extends \advanced_testcase {
    /** @var int an old timestamp written into every compared record, so that any write shows up. */
    private const OLDTIME = 1000;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        // On PostgreSQL the test harness wraps each test in a transaction, which the applier refuses to run inside
        // (it isolates each quiz in its own transaction). Reset by a full rollback-free reset instead.
        $this->preventResetByRollback();
        $this->setAdminUser();
        $CFG->enablecompletion = 1;
    }

    /**
     * A course with completion enabled.
     *
     * @return \stdClass
     */
    private function course(): \stdClass {
        return $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
    }

    /**
     * A quiz in a course.
     *
     * @param \stdClass $course
     * @param array $settings
     * @return \stdClass quiz record with cmid
     */
    private function quiz(\stdClass $course, array $settings = []): \stdClass {
        return $this->getDataGenerator()->create_module('quiz', ['course' => $course->id] + $settings);
    }

    /**
     * Add one short-answer question (correct answer "frog") worth 1 mark to a quiz.
     *
     * @param \stdClass $quiz
     */
    private function add_question(\stdClass $quiz): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz, 0, 1);
        quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
    }

    /**
     * Plan and apply a request for some quizzes.
     *
     * @param \stdClass $course
     * @param \stdClass[] $quizzes
     * @param array $changes for change_request::from_array()
     * @param applier|null $applier
     * @return apply_result[]
     */
    private function apply(\stdClass $course, array $quizzes, array $changes, ?applier $applier = null): array {
        $cmids = array_map(fn($quiz) => $quiz->cmid, $quizzes);
        $plans = (new planner())->plan($course->id, $cmids, change_request::from_array($changes));
        return ($applier ?? new applier())->apply($course->id, $plans);
    }

    /**
     * Set an old timemodified on the quiz and its grade item.
     *
     * @param \stdClass $quiz
     */
    private function age(\stdClass $quiz): void {
        global $DB;
        // The course_modules table has no timemodified column; any write to it shows in the column values.
        $DB->set_field('quiz', 'timemodified', self::OLDTIME, ['id' => $quiz->id]);
        $DB->set_field(
            'grade_items',
            'timemodified',
            self::OLDTIME,
            ['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id, 'itemnumber' => 0]
        );
    }

    /**
     * The full stored state of a quiz's settings.
     *
     * @param \stdClass $quiz
     * @return array table => record array (feedback: rows)
     */
    private function snapshot(\stdClass $quiz): array {
        global $DB;
        return [
            'quiz' => (array) $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST),
            'cm' => (array) $DB->get_record('course_modules', ['id' => $quiz->cmid], '*', MUST_EXIST),
            'gradeitem' => (array) $DB->get_record(
                'grade_items',
                ['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id, 'itemnumber' => 0],
                '*',
                MUST_EXIST
            ),
            'feedback' => array_values(array_map(
                fn($r) => (array) $r,
                $DB->get_records('quiz_feedback', ['quizid' => $quiz->id], 'id')
            )),
        ];
    }

    /**
     * Assert that exactly the expected columns differ between two snapshots.
     *
     * @param array $before
     * @param array $after
     * @param array $expected table => columns that must differ (all others byte-identical)
     */
    private function assert_only_changed(array $before, array $after, array $expected): void {
        foreach (['quiz', 'cm', 'gradeitem'] as $table) {
            $changed = [];
            foreach ($before[$table] as $column => $value) {
                if ($after[$table][$column] !== $value) {
                    $changed[] = $column;
                }
            }
            $this->assertEqualsCanonicalizing($expected[$table] ?? [], $changed, "Changed columns of $table");
        }
        $this->assertSame($before['feedback'], $after['feedback'], 'quiz_feedback rows');
    }

    /**
     * Changed quiz columns are written; the result says applied.
     */
    public function test_writes_quiz_columns(): void {
        global $DB;
        $course = $this->course();
        $quiz = $this->quiz($course, ['preferredbehaviour' => 'interactive']);
        $results = $this->apply($course, [$quiz], [
            'attempts' => 3,
            'navmethod' => 'sequential',
            'timelimit' => 1200,
            'delay1' => 60,
            'browsersecurity' => 'securewindow',
            'showuserpicture' => QUIZ_SHOWIMAGE_LARGE,
            'questiondecimalpoints' => 3,
            'review_rightanswer' => display_options::DURING | display_options::AFTER_CLOSE,
        ]);
        $this->assertSame(apply_result::APPLIED, $results[$quiz->cmid]->status);
        $record = $DB->get_record('quiz', ['id' => $quiz->id]);
        $this->assertEquals(3, $record->attempts);
        $this->assertEquals('sequential', $record->navmethod);
        $this->assertEquals(1200, $record->timelimit);
        $this->assertEquals(60, $record->delay1);
        $this->assertEquals('securewindow', $record->browsersecurity);
        $this->assertEquals(QUIZ_SHOWIMAGE_LARGE, $record->showuserpicture);
        $this->assertEquals(3, $record->questiondecimalpoints);
        $this->assertEquals(display_options::DURING | display_options::AFTER_CLOSE, $record->reviewrightanswer);
    }

    /**
     * Individual settability: changing ONE review row writes that column only.
     *
     * Fails if the applier or planner writes any other quiz column, course module column or the grade item.
     */
    public function test_only_one_review_row_changes(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, [
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1, 'gradepass' => 50,
            'overallfeedbacks' => [['mingrade' => 50, 'feedbacktext' => 'Well done'], ['feedbacktext' => 'Try again']],
        ]);
        $this->age($quiz);
        $before = $this->snapshot($quiz);
        $results = $this->apply($course, [$quiz], ['review_specificfeedback' => display_options::IMMEDIATELY_AFTER]);
        $this->assertSame(apply_result::APPLIED, $results[$quiz->cmid]->status);
        $after = $this->snapshot($quiz);
        $this->assert_only_changed($before, $after, ['quiz' => ['reviewspecificfeedback', 'timemodified']]);
        $this->assertEquals(display_options::IMMEDIATELY_AFTER, $after['quiz']['reviewspecificfeedback']);
    }

    /**
     * Individual settability: changing only the maximum grade leaves everything else alone.
     *
     * Fails if review options, grade to pass, completion or other quiz columns are written.
     */
    public function test_only_maxgrade_changes(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, [
            'grade' => 10, 'gradepass' => 5,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1,
        ]);
        $this->age($quiz);
        $before = $this->snapshot($quiz);
        $this->apply($course, [$quiz], ['maxgrade' => 20]);
        $after = $this->snapshot($quiz);
        $this->assert_only_changed($before, $after, [
            'quiz' => ['grade', 'timemodified'],
            'gradeitem' => ['grademax', 'timemodified'],
        ]);
        $this->assertEquals(20, $after['quiz']['grade']);
        $this->assertEquals(20, $after['gradeitem']['grademax']);
        $this->assertEquals(5, $after['gradeitem']['gradepass']);
    }

    /**
     * Individual settability: changing only the grade to pass writes the grade item's gradepass only.
     *
     * Fails if the quiz record (even timemodified) or the course module is written.
     */
    public function test_only_gradepass_changes(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, ['grade' => 10, 'gradepass' => 5]);
        $this->age($quiz);
        $before = $this->snapshot($quiz);
        $this->apply($course, [$quiz], ['gradepass' => ['type' => 'percent', 'value' => 70]]);
        $after = $this->snapshot($quiz);
        $this->assert_only_changed($before, $after, ['gradeitem' => ['gradepass', 'timemodified']]);
        $this->assertEquals(7, $after['gradeitem']['gradepass']);
    }

    /**
     * Individual settability: changing one completion condition writes that course module column only.
     *
     * Fails if other completion columns, the quiz record or the grade item are written.
     */
    public function test_only_one_completion_condition_changes(): void {
        $course = $this->course();
        $quiz = $this->quiz($course, [
            'grade' => 10, 'gradepass' => 5,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1, 'completionpassgrade' => 1,
            'completionexpected' => time() + WEEKSECS,
        ]);
        $this->age($quiz);
        $before = $this->snapshot($quiz);
        $this->assertEquals(0, $before['cm']['completionview']);
        $this->apply($course, [$quiz], ['completionview' => 1]);
        $after = $this->snapshot($quiz);
        $this->assert_only_changed($before, $after, ['cm' => ['completionview']]);
        $this->assertEquals(1, $after['cm']['completionview']);
        $this->assertEquals($before['cm']['completionexpected'], $after['cm']['completionexpected']);

        // And a quiz-table completion condition alone.
        $this->age($quiz);
        $before = $this->snapshot($quiz);
        $this->apply($course, [$quiz], ['completionminattempts' => 2]);
        $after = $this->snapshot($quiz);
        $this->assert_only_changed($before, $after, ['quiz' => ['completionminattempts', 'timemodified']]);
    }

    /**
     * The use-grade condition maps to completiongradeitemnumber 0/null.
     */
    public function test_completionusegrade_maps_to_gradeitemnumber(): void {
        global $DB;
        $course = $this->course();
        $quiz = $this->quiz($course, ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]);
        $this->assertNull($DB->get_field('course_modules', 'completiongradeitemnumber', ['id' => $quiz->cmid]));
        $this->apply($course, [$quiz], ['completionusegrade' => 1]);
        $this->assertSame('0', (string) $DB->get_field('course_modules', 'completiongradeitemnumber', ['id' => $quiz->cmid]));
        $this->apply($course, [$quiz], ['completionusegrade' => 0]);
        $this->assertNull($DB->get_field('course_modules', 'completiongradeitemnumber', ['id' => $quiz->cmid]));
    }

    /**
     * Changing the maximum grade rescales an existing attempt's final grade and the gradebook.
     *
     * Fails if quiz.grade is written directly instead of through update_quiz_maximum_grade().
     */
    public function test_maxgrade_rescales_attempt_grades(): void {
        global $DB;
        $course = $this->course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $quiz = $this->quiz($course, ['grade' => 10, 'preferredbehaviour' => 'deferredfeedback']);
        $this->add_question($quiz);
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);
        $quizgenerator->submit_responses($attempt->id, [1 => 'frog'], false, true);
        $this->setAdminUser();
        $this->assertEquals(10, $DB->get_field('quiz_grades', 'grade', ['quiz' => $quiz->id, 'userid' => $student->id]));

        $plans = (new planner())->plan($course->id, [$quiz->cmid], change_request::from_array(['maxgrade' => 20]));
        $this->assertArrayHasKey('warnattemptsrescale', $plans[$quiz->cmid]->get_warnings());
        (new applier())->apply($course->id, $plans);

        $this->assertEquals(20, $DB->get_field('quiz', 'grade', ['id' => $quiz->id]));
        $this->assertEquals(20, $DB->get_field('quiz_grades', 'grade', ['quiz' => $quiz->id, 'userid' => $student->id]));
        $grades = grade_get_grades($course->id, 'mod', 'quiz', $quiz->id, $student->id);
        $this->assertEquals(20, $grades->items[0]->grademax);
        $this->assertEquals(20, $grades->items[0]->grades[$student->id]->grade);
    }

    /**
     * Overall feedback rows survive any change; a maximum grade change rescales their boundaries.
     *
     * Fails if the applier replays quiz_update_instance()/quiz_after_add_or_update(), which deletes them.
     */
    public function test_quiz_feedback_rows_survive(): void {
        global $DB;
        $course = $this->course();
        $quiz = $this->quiz($course, [
            'grade' => 10,
            'overallfeedbacks' => [
                ['mingrade' => 5, 'maxgrade' => 11, 'feedbacktext' => 'QBE pass'],
                ['mingrade' => 0, 'maxgrade' => 5, 'feedbacktext' => 'QBE fail'],
            ],
        ]);
        $before = $DB->get_records('quiz_feedback', ['quizid' => $quiz->id], 'id');
        $this->assertCount(2, $before);

        $this->apply($course, [$quiz], [
            'shuffleanswers' => 0,
            'review_overallfeedback' => display_options::AFTER_CLOSE,
            'gradepass' => ['type' => 'absolute', 'value' => 4],
            'grademethod' => QUIZ_ATTEMPTLAST,
        ]);
        $this->assertEquals($before, $DB->get_records('quiz_feedback', ['quizid' => $quiz->id], 'id'));

        $this->apply($course, [$quiz], ['maxgrade' => 20]);
        $after = $DB->get_records('quiz_feedback', ['quizid' => $quiz->id], 'id');
        $this->assertSame(array_keys($before), array_keys($after));
        foreach ($before as $id => $row) {
            $this->assertSame($row->feedbacktext, $after[$id]->feedbacktext);
            $this->assertEquals($row->mingrade * 2, $after[$id]->mingrade);
            $this->assertEquals($row->maxgrade * 2, $after[$id]->maxgrade);
        }
    }

    /**
     * A time limit change updates open attempts' state-check time.
     *
     * Fails if quiz_update_open_attempts() is not called after the column write.
     */
    public function test_open_attempts_updated_on_timelimit_change(): void {
        global $DB;
        $course = $this->course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $quiz = $this->quiz($course, ['timelimit' => 0]);
        $this->add_question($quiz);
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);
        $this->setAdminUser();
        $this->assertNull($DB->get_field('quiz_attempts', 'timecheckstate', ['id' => $attempt->id]));

        $this->apply($course, [$quiz], ['timelimit' => 600]);
        $record = $DB->get_record('quiz_attempts', ['id' => $attempt->id]);
        $this->assertEquals($record->timestart + 600, $record->timecheckstate);
    }

    /**
     * Changing completion resets and recalculates completion state; manual kept manual does not.
     *
     * Fails if reset_all_state() is not called for changed quizzes (stale "complete" would remain).
     */
    public function test_completion_reset_clears_state(): void {
        global $DB;
        $course = $this->course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $quiz = $this->quiz($course, ['completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = get_fast_modinfo($course)->get_cm($quiz->cmid);
        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $iscomplete = fn() => $DB->record_exists(
            'course_modules_completion',
            ['coursemoduleid' => $quiz->cmid, 'userid' => $student->id, 'completionstate' => COMPLETION_COMPLETE]
        );
        $this->assertTrue($iscomplete());

        // Manual stays manual: state kept.
        $this->apply($course, [$quiz], ['completionview' => 1]);
        $this->assertTrue($iscomplete());

        // Manual to automatic (view required, not viewed): the manual tick is gone.
        $results = $this->apply($course, [$quiz], ['completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $this->assertSame(apply_result::APPLIED, $results[$quiz->cmid]->status);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $DB->get_field('course_modules', 'completion', ['id' => $quiz->cmid]));
        $this->assertFalse($iscomplete());
    }

    /**
     * A course_module_updated event is fired for each changed quiz, and none for unchanged or skipped ones.
     */
    public function test_event_fired(): void {
        $course = $this->course();
        $changed = $this->quiz($course, ['attempts' => 0]);
        $same = $this->quiz($course, ['attempts' => 2]);
        $bad = $this->quiz($course, ['grade' => 10, 'gradepass' => 8]);
        $sink = $this->redirectEvents();
        $results = $this->apply($course, [$changed, $same], ['attempts' => 2]);
        $results += $this->apply($course, [$bad], ['maxgrade' => 5]);
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \core\event\course_module_updated);
        $sink->close();

        $this->assertSame([(int) $changed->cmid], array_values(array_map(fn($e) => (int) $e->objectid, $events)));
        $this->assertSame(apply_result::APPLIED, $results[$changed->cmid]->status);
        $this->assertSame(apply_result::NOCHANGE, $results[$same->cmid]->status);
        $this->assertSame(apply_result::SKIPPED, $results[$bad->cmid]->status);
        $this->assertNotEmpty($results[$bad->cmid]->messages);
    }

    /**
     * A quiz that throws is rolled back completely and the others are still applied.
     *
     * Fails if the per-quiz transaction is removed (the failing quiz's first writes would stay).
     */
    public function test_rollback_isolates_failing_quiz(): void {
        global $DB;
        $course = $this->course();
        $first = $this->quiz($course, ['attempts' => 0, 'grade' => 10]);
        $failing = $this->quiz($course, ['attempts' => 0, 'grade' => 10]);
        $last = $this->quiz($course, ['attempts' => 0, 'grade' => 10]);

        $applier = new class ((int) $failing->cmid) extends applier {
            /**
             * Constructor.
             *
             * @param int $failcmid the course module whose plan throws after it was written
             */
            public function __construct(
                /** @var int the course module whose plan throws */
                private int $failcmid
            ) {
            }

            #[\Override]
            protected function apply_plan(quiz_plan $plan): void {
                parent::apply_plan($plan);
                if ($plan->get_cmid() === $this->failcmid) {
                    throw new \moodle_exception('error_invalidvalue', 'tool_quizbulkedit');
                }
            }
        };
        $sink = $this->redirectEvents();
        $results = $this->apply($course, [$first, $failing, $last], ['attempts' => 5, 'maxgrade' => 20], $applier);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame(apply_result::APPLIED, $results[$first->cmid]->status);
        $this->assertSame(apply_result::FAILED, $results[$failing->cmid]->status);
        $this->assertSame(apply_result::APPLIED, $results[$last->cmid]->status);
        $this->assertEquals(5, $DB->get_field('quiz', 'attempts', ['id' => $first->id]));
        $this->assertEquals(5, $DB->get_field('quiz', 'attempts', ['id' => $last->id]));
        $this->assertEquals(0, $DB->get_field('quiz', 'attempts', ['id' => $failing->id]));
        $this->assertEquals(10, $DB->get_field('quiz', 'grade', ['id' => $failing->id]));
        $this->assertEquals(10, $DB->get_field(
            'grade_items',
            'grademax',
            ['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $failing->id]
        ));
        $updated = array_map(
            fn($e) => (int) $e->objectid,
            array_filter($events, fn($e) => $e instanceof \core\event\course_module_updated)
        );
        $this->assertNotContains((int) $failing->cmid, $updated);
    }

    /**
     * A locked grade item does not make core print its "regrade anyway" page.
     *
     * Fails if quiz_grade_item_update() runs for a locked grade item (it echoes HTML mid-request).
     */
    public function test_locked_gradeitem_applies_without_output(): void {
        global $DB;
        $course = $this->course();
        $quiz = $this->quiz($course, ['shuffleanswers' => 1]);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id, 'itemnumber' => 0]);
        $item->set_locked(true);
        $this->expectOutputString('');
        $results = $this->apply($course, [$quiz], ['shuffleanswers' => 0]);
        $this->assertSame(apply_result::APPLIED, $results[$quiz->cmid]->status);
        $this->assertEquals(0, $DB->get_field('quiz', 'shuffleanswers', ['id' => $quiz->id]));
    }

    /**
     * The applier refuses to run inside an outer transaction (it would break rollback isolation).
     */
    public function test_refuses_outer_transaction(): void {
        global $DB;
        $course = $this->course();
        $transaction = $DB->start_delegated_transaction();
        try {
            (new applier())->apply($course->id, []);
            $this->fail('Expected a coding exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('transaction', $e->getMessage());
        }
        $transaction->allow_commit();
    }

    /**
     * Plans for another course are refused.
     */
    public function test_refuses_plans_of_other_course(): void {
        $course = $this->course();
        $other = $this->course();
        $quiz = $this->quiz($course);
        $plans = (new planner())->plan($course->id, [$quiz->cmid], change_request::from_array(['attempts' => 2]));
        $this->expectException(\coding_exception::class);
        (new applier())->apply($other->id, $plans);
    }
}
