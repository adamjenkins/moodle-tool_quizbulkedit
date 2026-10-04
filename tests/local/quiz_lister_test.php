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
 * Tests for the eligible quiz lister.
 *
 * @package    tool_quizbulkedit
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_quizbulkedit\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the eligible quiz lister (and the planner's rejection of ineligible ids).
 */
#[CoversClass(quiz_lister::class)]
#[CoversClass(planner::class)]
final class quiz_lister_test extends \advanced_testcase {
    /**
     * Only quizzes where the user holds both manageactivities and mod/quiz:manage are listed.
     *
     * Fails if either capability check in quiz_lister::get_eligible() is removed.
     */
    public function test_capability_filtering(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $q1 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'QBE one']);
        $q2 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'QBE two']);
        $q3 = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'QBE three']);
        $generator->create_module('page', ['course' => $course->id]);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/quiz:manage', CAP_PROHIBIT, $roleid, \context_module::instance($q2->cmid)->id, true);
        assign_capability(
            'moodle/course:manageactivities',
            CAP_PROHIBIT,
            $roleid,
            \context_module::instance($q3->cmid)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($teacher);
        $this->assertFalse(has_capability('mod/quiz:manage', \context_module::instance($q2->cmid)));
        $eligible = quiz_lister::get_eligible($course->id);
        $this->assertSame([(int) $q1->cmid], array_keys($eligible));
        $entry = $eligible[$q1->cmid];
        $this->assertInstanceOf(\cm_info::class, $entry->cm);
        $this->assertEquals($q1->id, $entry->quiz->id);
        $this->assertEquals($q1->cmid, $entry->cmrecord->id);
        $this->assertFalse($entry->gradeitemlocked);

        // The planner drops posted ids of ineligible quizzes.
        $planner = new planner();
        $plans = $planner->plan($course->id, [$q1->cmid, $q2->cmid, $q3->cmid], change_request::from_array(['attempts' => 2]));
        $this->assertSame([(int) $q1->cmid], array_keys($plans));
        $this->assertEqualsCanonicalizing([(int) $q2->cmid, (int) $q3->cmid], $planner->get_rejected_cmids());

        // An admin sees all three.
        $this->setAdminUser();
        $this->assertCount(3, quiz_lister::get_eligible($course->id));
    }

    /**
     * A quiz of another course is never planned, even when its id is posted.
     *
     * Fails if planner::plan() trusts posted cmids instead of the course's eligible list.
     */
    public function test_foreign_course_cmid_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        // The applier refuses to run inside the harness's per-test transaction (PostgreSQL); see applier_test.
        $this->preventResetByRollback();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $other = $generator->create_course();
        $mine = $generator->create_module('quiz', ['course' => $course->id, 'attempts' => 0]);
        $foreign = $generator->create_module('quiz', ['course' => $other->id, 'attempts' => 0]);

        $planner = new planner();
        $plans = $planner->plan($course->id, [$mine->cmid, $foreign->cmid, 999999], change_request::from_array(['attempts' => 4]));
        $this->assertSame([(int) $mine->cmid], array_keys($plans));
        $this->assertEqualsCanonicalizing([(int) $foreign->cmid, 999999], $planner->get_rejected_cmids());

        (new applier())->apply($course->id, $plans);
        $this->assertEquals(4, $DB->get_field('quiz', 'attempts', ['id' => $mine->id]));
        $this->assertEquals(0, $DB->get_field('quiz', 'attempts', ['id' => $foreign->id]));
    }

    /**
     * A quiz being deleted is not listed.
     */
    public function test_deletion_in_progress_excluded(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $keep = $generator->create_module('quiz', ['course' => $course->id]);
        $going = $generator->create_module('quiz', ['course' => $course->id]);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $going->cmid]);
        rebuild_course_cache($course->id, true);

        $this->assertSame([(int) $keep->cmid], array_keys(quiz_lister::get_eligible($course->id)));
    }

    /**
     * filter_cmids() keeps eligible ids only, in course order, once each.
     */
    public function test_filter_cmids(): void {
        $eligible = [5 => (object) [], 9 => (object) [], 7 => (object) []];
        $this->assertSame([5, 7], quiz_lister::filter_cmids(['7', 5, 5, 8, -1], $eligible));
        $this->assertSame([], quiz_lister::filter_cmids([], $eligible));
    }

    /**
     * Quizzes are listed in course-page order, with a subsection's quizzes inline.
     *
     * Fails if quiz_lister::get_eligible() iterates get_cms() without sort_cm_array():
     * the subsection's quiz then comes last.
     */
    public function test_course_page_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $first = $generator->create_module('quiz', ['course' => $course->id, 'section' => 1, 'name' => 'QBE first']);
        $subsection = $generator->create_module('subsection', ['course' => $course->id, 'section' => 1]);
        $after = $generator->create_module('quiz', ['course' => $course->id, 'section' => 1, 'name' => 'QBE after']);
        $later = $generator->create_module('quiz', ['course' => $course->id, 'section' => 2, 'name' => 'QBE later']);
        $delegated = get_fast_modinfo($course)->get_sections_delegated_by_cm()[$subsection->cmid];
        $inside = $generator->create_module('quiz', [
            'course' => $course->id,
            'section' => $delegated->section,
            'name' => 'QBE inside',
        ]);
        // The subsection module sits before 'QBE after' in section 1, so its content does too.
        $this->assertSame(
            [(int) $first->cmid, (int) $inside->cmid, (int) $after->cmid, (int) $later->cmid],
            array_keys(quiz_lister::get_eligible($course->id))
        );
    }
}
