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
 * Tests for the change request (the form-field contract).
 *
 * @package    tool_quizbulkedit
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_quizbulkedit\local;

use mod_quiz\question\display_options;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the change request.
 */
#[CoversClass(change_request::class)]
final class change_request_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        parent::setUpBeforeClass();
    }

    /**
     * Unticked settings are not in the request even when their value fields are posted.
     *
     * Fails if from_form_data() reads a value without its change_<key> box.
     */
    public function test_unticked_settings_are_ignored(): void {
        $request = change_request::from_form_data((object) [
            'change_attempts' => 1,
            'attempts' => 3,
            'change_maxgrade' => 0,
            'maxgrade' => 50,
            'change_review_marks' => 0,
            'review_marks_during' => 1,
            'gradepasstype' => 'percent',
            'gradepass' => 50,
            'completion' => 2,
        ]);
        $this->assertSame(['attempts'], $request->get_changed_keys());
        $this->assertSame(3, $request->get('attempts'));
        $this->assertFalse($request->is_changed('maxgrade'));
        $this->assertFalse($request->is_changed('gradepass'));
        $this->assertFalse($request->is_changed('review_marks'));
        $this->assertFalse($request->is_changed('completion'));
        $this->assertFalse($request->has_review_changes());
        $this->assertFalse($request->has_completion_changes());
    }

    /**
     * Every field of the documented contract is read, with the right types.
     */
    public function test_from_form_data_reads_every_field(): void {
        $data = [
            'change_maxgrade' => 1, 'maxgrade' => 20.5,
            'change_gradepass' => 1, 'gradepasstype' => 'percent', 'gradepass' => '40',
            'change_attempts' => 1, 'attempts' => '2',
            'change_grademethod' => 1, 'grademethod' => QUIZ_GRADEAVERAGE,
            'change_decimalpoints' => 1, 'decimalpoints' => '1',
            'change_questiondecimalpoints' => 1, 'questiondecimalpoints' => '-1',
            'change_preferredbehaviour' => 1, 'preferredbehaviour' => 'interactive',
            'change_canredoquestions' => 1, 'canredoquestions' => '1',
            'change_shuffleanswers' => 1, 'shuffleanswers' => '0',
            'change_navmethod' => 1, 'navmethod' => 'sequential',
            'change_timelimit' => 1, 'timelimit' => 1800,
            'change_overduehandling' => 1, 'overduehandling' => 'autoabandon',
            'change_graceperiod' => 1, 'graceperiod' => 600,
            'change_showuserpicture' => 1, 'showuserpicture' => '2',
            'change_showblocks' => 1, 'showblocks' => '1',
            'change_delay1' => 1, 'delay1' => 60,
            'change_delay2' => 1, 'delay2' => 120,
            'change_browsersecurity' => 1, 'browsersecurity' => '-',
            'change_completion' => 1, 'completion' => '2',
            'change_completionview' => 1, 'completionview' => '1',
            'change_completionusegrade' => 1, 'completionusegrade' => '1',
            'change_completionpassgrade' => 1, 'completionpassgrade' => '1',
            'change_completionattemptsexhausted' => 1, 'completionattemptsexhausted' => '1',
            'change_completionminattempts' => 1, 'completionminattempts' => '2',
        ];
        foreach (settings_catalogue::REVIEW_FIELDS as $field) {
            $data['change_review_' . $field] = 1;
            $data['review_' . $field . '_during'] = 0;
            $data['review_' . $field . '_immediately'] = 1;
            $data['review_' . $field . '_open'] = 0;
            $data['review_' . $field . '_closed'] = 1;
        }
        $request = change_request::from_form_data((object) $data);

        $this->assertSame(settings_catalogue::get_keys(), $request->get_changed_keys());
        $this->assertSame(20.5, $request->get('maxgrade'));
        $this->assertSame(40.0, $request->get('gradepass'));
        $this->assertSame('percent', $request->get_gradepass_type());
        $this->assertSame(2, $request->get('attempts'));
        $this->assertSame(-1, $request->get('questiondecimalpoints'));
        $this->assertSame('interactive', $request->get('preferredbehaviour'));
        $this->assertSame('sequential', $request->get('navmethod'));
        $this->assertSame(1800, $request->get('timelimit'));
        $this->assertSame('-', $request->get('browsersecurity'));
        $this->assertSame(2, $request->get('completion'));
        $this->assertSame(2, $request->get('completionminattempts'));
        $expected = display_options::IMMEDIATELY_AFTER | display_options::AFTER_CLOSE;
        foreach (settings_catalogue::REVIEW_FIELDS as $field) {
            $this->assertSame($expected, $request->get('review_' . $field), $field);
        }
        $this->assertTrue($request->has_review_changes());
        $this->assertTrue($request->has_completion_changes());
    }

    /**
     * from_array() accepts review rows as time arrays or bitmasks, and gradepass as [type, value].
     */
    public function test_from_array(): void {
        $request = change_request::from_array([
            'review_marks' => ['immediately' => 1, 'closed' => 1],
            'review_attempt' => display_options::DURING | display_options::LATER_WHILE_OPEN,
            'gradepass' => ['type' => 'absolute', 'value' => 7.5],
        ]);
        $this->assertSame(['gradepass', 'review_attempt', 'review_marks'], $request->get_changed_keys());
        $this->assertSame(display_options::IMMEDIATELY_AFTER | display_options::AFTER_CLOSE, $request->get('review_marks'));
        $this->assertSame(display_options::DURING | display_options::LATER_WHILE_OPEN, $request->get('review_attempt'));
        $this->assertSame('absolute', $request->get_gradepass_type());
        $this->assertSame(7.5, $request->get('gradepass'));
    }

    /**
     * from_array() rejects keys outside the catalogue.
     */
    public function test_from_array_rejects_unknown_key(): void {
        $this->expectException(\invalid_parameter_exception::class);
        change_request::from_array(['sumgrades' => 5]);
    }

    /**
     * Data provider of invalid ticked values.
     *
     * @return array
     */
    public static function invalid_values_provider(): array {
        return [
            'max grade zero' => [['change_maxgrade' => 1, 'maxgrade' => 0], 'maxgrade'],
            'max grade negative' => [['change_maxgrade' => 1, 'maxgrade' => -3], 'maxgrade'],
            'max grade too big' => [['change_maxgrade' => 1, 'maxgrade' => 100000], 'maxgrade'],
            'max grade missing' => [['change_maxgrade' => 1], 'maxgrade'],
            'percent over 100' => [['change_gradepass' => 1, 'gradepasstype' => 'percent', 'gradepass' => 101], 'gradepass'],
            'percent negative' => [['change_gradepass' => 1, 'gradepasstype' => 'percent', 'gradepass' => -1], 'gradepass'],
            'absolute negative' => [['change_gradepass' => 1, 'gradepasstype' => 'absolute', 'gradepass' => -0.5], 'gradepass'],
            'gradepass bad type' => [['change_gradepass' => 1, 'gradepasstype' => 'ratio', 'gradepass' => 5], 'gradepasstype'],
            'attempts out of list' => [['change_attempts' => 1, 'attempts' => 11], 'attempts'],
            'navmethod unknown' => [['change_navmethod' => 1, 'navmethod' => 'random'], 'navmethod'],
            'behaviour unknown' => [['change_preferredbehaviour' => 1, 'preferredbehaviour' => 'nosuch'], 'preferredbehaviour'],
            'yes/no is 2' => [['change_shuffleanswers' => 1, 'shuffleanswers' => 2], 'shuffleanswers'],
            'completion 3' => [['change_completion' => 1, 'completion' => 3], 'completion'],
            'negative duration' => [['change_timelimit' => 1, 'timelimit' => -60], 'timelimit'],
            'fractional int' => [['change_completionminattempts' => 1, 'completionminattempts' => '1.5'], 'completionminattempts'],
            'review bit 2' => [['change_review_marks' => 1, 'review_marks_open' => 2], 'review_marks_open'],
        ];
    }

    /**
     * Ticked settings with invalid values are reported per form field and refused by from_form_data().
     *
     * @param array $data
     * @param string $field the form field the error must be on
     */
    #[DataProvider('invalid_values_provider')]
    public function test_invalid_values_are_rejected(array $data, string $field): void {
        $errors = change_request::validate_form_data($data);
        $this->assertArrayHasKey($field, $errors);
        $this->expectException(\invalid_parameter_exception::class);
        change_request::from_form_data((object) $data);
    }

    /**
     * Invalid values in unticked settings are not an error (they are never read).
     */
    public function test_invalid_unticked_value_is_ignored(): void {
        $this->assertSame([], change_request::validate_form_data(['change_maxgrade' => 0, 'maxgrade' => -1]));
    }

    /**
     * to_array() is stable and includes the grade to pass type.
     */
    public function test_to_array(): void {
        $a = change_request::from_array(['attempts' => 2, 'gradepass' => ['type' => 'percent', 'value' => 50]]);
        $b = change_request::from_array(['gradepass' => ['type' => 'percent', 'value' => 50], 'attempts' => '2']);
        $this->assertSame($a->to_array(), $b->to_array());
        $c = change_request::from_array(['attempts' => 2, 'gradepass' => ['type' => 'absolute', 'value' => 50]]);
        $this->assertNotSame($a->to_array(), $c->to_array());
        $this->assertTrue(change_request::from_array([])->is_empty());
    }
}
