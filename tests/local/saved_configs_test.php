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
 * Tests for saved configurations and admin presets.
 *
 * @package    tool_quizbulkedit
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_quizbulkedit\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for saved configurations and admin presets.
 */
#[CoversClass(saved_configs::class)]
#[CoversClass(\tool_quizbulkedit\observer::class)]
final class saved_configs_test extends \advanced_testcase {
    /**
     * A snapshot keeps the known form fields and the quizzes, and round-trips through save/get/decode
     * into data a change_request accepts.
     */
    public function test_snapshot_round_trip(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $formdata = (object) [
            'change_gradepass' => 1, 'gradepasstype' => 'percent', 'gradepass' => '60',
            'change_review_marks' => 1, 'review_marks_during' => 0, 'review_marks_immediately' => 1,
            'review_marks_open' => 0, 'review_marks_closed' => 1,
            'change_timelimit' => 1, 'timelimit' => 1800,
            'change_maxgrade' => 0, 'maxgrade' => '10',
            'notafield' => 'dropped', 'sesskey' => 'dropped',
        ];
        $this->assertFalse(saved_configs::save($course->id, 'Mid-term', saved_configs::snapshot($formdata, [5, 5, 7])));
        $record = saved_configs::get($course->id, (int) array_key_first(saved_configs::list($course->id)));
        $snapshot = saved_configs::decode($record);

        $this->assertSame([5, 7], $snapshot['cmids']);
        $this->assertArrayNotHasKey('notafield', $snapshot['fields']);
        $this->assertArrayNotHasKey('sesskey', $snapshot['fields']);
        $this->assertSame('60', $snapshot['fields']['gradepass']);
        $this->assertSame(1800, $snapshot['fields']['timelimit']);

        $request = change_request::from_form_data((object) $snapshot['fields']);
        $this->assertSame(['gradepass', 'review_marks', 'timelimit'], $request->get_changed_keys());
        $this->assertSame('percent', $request->get_gradepass_type());
    }

    /**
     * Saving under an existing name replaces it; presets (courseid 0) and courses are separate.
     */
    public function test_replace_and_scope(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        saved_configs::save($course->id, 'Same', saved_configs::snapshot((object) ['change_attempts' => 1, 'attempts' => 1]));
        $this->assertTrue(saved_configs::save($course->id, 'Same', saved_configs::snapshot((object) [
            'change_attempts' => 1, 'attempts' => 3,
        ])));
        saved_configs::save(saved_configs::PRESETS, 'Same', saved_configs::snapshot((object) []));

        $this->assertCount(1, saved_configs::list($course->id));
        $this->assertCount(1, saved_configs::list(saved_configs::PRESETS));
        $this->assertCount(0, saved_configs::list($other->id));
        $id = (int) array_key_first(saved_configs::list($course->id));
        $this->assertSame(3, saved_configs::decode(saved_configs::get($course->id, $id))['fields']['attempts']);

        // A course's configuration cannot be reached through another course or as a preset.
        $this->expectException(\dml_missing_record_exception::class);
        saved_configs::get($other->id, $id);
    }

    /**
     * Editing a preset by id renames it, replacing any other preset of the new name.
     */
    public function test_rename_by_id(): void {
        $this->resetAfterTest();
        saved_configs::save(saved_configs::PRESETS, 'A', saved_configs::snapshot((object) []));
        saved_configs::save(saved_configs::PRESETS, 'B', saved_configs::snapshot((object) []));
        $ids = array_keys(saved_configs::list(saved_configs::PRESETS));
        saved_configs::save(saved_configs::PRESETS, 'B', saved_configs::snapshot((object) []), (int) $ids[0]);
        $presets = saved_configs::list(saved_configs::PRESETS);
        $this->assertSame(['B'], array_values(array_map(fn($p) => $p->name, $presets)));
        $this->assertSame((int) $ids[0], (int) array_key_first($presets));
    }

    /**
     * Deleting a course deletes its configurations, not other courses' or the presets.
     */
    public function test_course_deleted(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        saved_configs::save($course->id, 'X', saved_configs::snapshot((object) []));
        saved_configs::save($other->id, 'X', saved_configs::snapshot((object) []));
        saved_configs::save(saved_configs::PRESETS, 'X', saved_configs::snapshot((object) []));
        delete_course($course, false);
        $this->assertCount(0, saved_configs::list($course->id));
        $this->assertCount(1, saved_configs::list($other->id));
        $this->assertCount(1, saved_configs::list(saved_configs::PRESETS));
    }

    /**
     * A tampered snapshot keeps only known scalar fields and integer cmids.
     */
    public function test_decode_tampered(): void {
        $record = (object) ['data' => json_encode([
            'fields' => ['attempts' => ['x'], 'change_attempts' => 1, 'evil' => '<script>', 'gradepass' => str_repeat('9', 500)],
            'cmids' => [3, '4', 'x', ['y'], -2],
        ])];
        $snapshot = saved_configs::decode($record);
        $this->assertEquals(['change_attempts' => 1, 'gradepass' => str_repeat('9', saved_configs::MAXVALUE)], $snapshot['fields']);
        $this->assertSame([3, 4], $snapshot['cmids']);
        $this->assertSame(['fields' => [], 'cmids' => []], saved_configs::decode((object) ['data' => 'not json']));
    }
}
