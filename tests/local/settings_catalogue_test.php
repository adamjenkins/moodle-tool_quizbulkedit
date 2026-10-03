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
 * Tests for the settings catalogue.
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
 * Tests for the settings catalogue.
 */
#[CoversClass(settings_catalogue::class)]
final class settings_catalogue_test extends \advanced_testcase {
    /**
     * The catalogue has every setting of the spec, each individually, with a label string.
     *
     * Fails if a key is dropped, merged with another, or has no lang string.
     */
    public function test_keys_and_labels(): void {
        $keys = settings_catalogue::get_keys();
        $this->assertCount(32, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
        foreach (settings_catalogue::REVIEW_FIELDS as $field) {
            $this->assertContains('review_' . $field, $keys);
        }
        foreach (
            ['maxgrade', 'gradepass', 'completion', 'completionview', 'completionusegrade',
                'completionpassgrade', 'completionattemptsexhausted', 'completionminattempts'] as $key
        ) {
            $this->assertContains($key, $keys);
        }
        $stringmanager = get_string_manager();
        foreach ($keys as $key) {
            $this->assertTrue($stringmanager->string_exists('setting_' . $key, 'tool_quizbulkedit'), $key);
            $this->assertStringNotContainsString('[[', settings_catalogue::get_label($key));
            settings_catalogue::get_type($key);
        }
    }

    /**
     * Columns map to the quiz table, or to nowhere for settings stored elsewhere.
     */
    public function test_quiz_columns(): void {
        global $DB;
        $columns = $DB->get_columns('quiz');
        foreach (settings_catalogue::get_keys() as $key) {
            $column = settings_catalogue::get_quiz_column($key);
            if ($column !== null) {
                $this->assertArrayHasKey($column, $columns, $key);
            }
        }
        $this->assertSame('grade', settings_catalogue::get_quiz_column('maxgrade'));
        $this->assertSame('reviewmarks', settings_catalogue::get_quiz_column('review_marks'));
        $this->assertNull(settings_catalogue::get_quiz_column('gradepass'));
        $this->assertNull(settings_catalogue::get_quiz_column('completionview'));
        $cmcolumns = $DB->get_columns('course_modules');
        foreach (['completion', 'completionview', 'completionpassgrade', 'completiongradeitemnumber'] as $column) {
            $this->assertArrayHasKey($column, $cmcolumns);
        }
    }

    /**
     * Choice lists match core's quiz form.
     */
    public function test_choices(): void {
        $this->assertCount(11, settings_catalogue::get_choices('attempts'));
        $this->assertSame([0, 1, 2, 3, 4, 5], array_keys(settings_catalogue::get_choices('decimalpoints')));
        $this->assertSame([-1, 0, 1, 2, 3, 4, 5, 6, 7], array_keys(settings_catalogue::get_choices('questiondecimalpoints')));
        $this->assertSame(['free', 'sequential'], array_keys(settings_catalogue::get_choices('navmethod')));
        $this->assertSame(
            ['autosubmit', 'graceperiod', 'autoabandon'],
            array_keys(settings_catalogue::get_choices('overduehandling'))
        );
        $this->assertArrayHasKey('deferredfeedback', settings_catalogue::get_choices('preferredbehaviour'));
        $this->assertArrayHasKey('-', settings_catalogue::get_choices('browsersecurity'));
        $this->assertSame([0, 1, 2], array_keys(settings_catalogue::get_choices('completion')));
        $this->assertSame([0, 1], array_keys(settings_catalogue::get_choices('showblocks')));
    }

    /**
     * Values display as text.
     */
    public function test_format_value(): void {
        $this->assertSame(get_string('reviewnever', 'tool_quizbulkedit'), settings_catalogue::format_value('review_marks', 0));
        $text = settings_catalogue::format_value('review_marks', display_options::DURING | display_options::AFTER_CLOSE);
        $this->assertStringContainsString(get_string('reviewtime_during', 'tool_quizbulkedit'), $text);
        $this->assertStringContainsString(get_string('reviewtime_closed', 'tool_quizbulkedit'), $text);
        $this->assertStringNotContainsString(get_string('reviewtime_open', 'tool_quizbulkedit'), $text);
        $this->assertSame('7.50', settings_catalogue::format_value('maxgrade', 7.5, 2));
        $this->assertSame(get_string('unlimited'), settings_catalogue::format_value('attempts', '0'));
        $this->assertSame(get_string('durationnone', 'tool_quizbulkedit'), settings_catalogue::format_value('timelimit', 0));
        $this->assertSame(format_time(3600), settings_catalogue::format_value('timelimit', 3600));
        $this->assertSame(get_string('yes'), settings_catalogue::format_value('shuffleanswers', '1'));
    }

    /**
     * Grades compare with core's tolerance; other values by normalised value.
     */
    public function test_values_equal(): void {
        $this->assertTrue(settings_catalogue::values_equal('maxgrade', '10.00000', 10));
        $this->assertFalse(settings_catalogue::values_equal('maxgrade', 10, 10.001));
        $this->assertTrue(settings_catalogue::values_equal('attempts', '3', 3));
        $this->assertFalse(settings_catalogue::values_equal('navmethod', 'free', 'sequential'));
    }
}
