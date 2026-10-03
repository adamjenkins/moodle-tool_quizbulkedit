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
 * Tests for the quiz table and preview output.
 *
 * @package    tool_quizbulkedit
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_quizbulkedit\output;

use PHPUnit\Framework\Attributes\CoversClass;
use tool_quizbulkedit\local\change_request;
use tool_quizbulkedit\local\planner;
use tool_quizbulkedit\local\quiz_lister;

/**
 * Tests for the quiz table and preview output.
 */
#[CoversClass(quiztable::class)]
#[CoversClass(preview::class)]
final class output_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A quiz name with markup is escaped in the rendered table, the filter attribute and the label.
     *
     * Fails if the name reaches the HTML unescaped (an XSS sink).
     */
    public function test_quiztable_escapes_names(): void {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Q <script>alert(1)</script> & "x"',
            'grade' => 10,
        ]);
        $eligible = quiz_lister::get_eligible((int) $course->id);
        $output = $PAGE->get_renderer('core');
        $table = new quiztable($eligible, [(int) $quiz->cmid], false, 'formid');
        $html = $output->render_from_template('tool_quizbulkedit/quiztable', $table->export_for_template($output));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('data-name="Q alert(1) &amp; &quot;x&quot;"', $html);
        $this->assertStringContainsString('>Q alert(1) &amp; "x"</a>', $html);
        $this->assertStringContainsString('name="cmids[]" value="' . $quiz->cmid . '" form="formid"', $html);
        $this->assertMatchesRegularExpression('/id="tool_quizbulkedit_cm_' . $quiz->cmid . '"[^>]*checked>/', $html);
    }

    /**
     * The preview lists only the changing settings, with escaped names, and reports
     * whether Apply changes and the completion confirmation are needed.
     */
    public function test_preview(): void {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $quiz1 = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'name' => 'One <b>bold</b>', 'grade' => 10,
        ]);
        $quiz2 = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'name' => 'Two', 'grade' => 10, 'attempts' => 3,
        ]);
        $request = change_request::from_array(['attempts' => 3]);
        $plans = (new planner())->plan((int) $course->id, [$quiz1->cmid, $quiz2->cmid], $request);
        $preview = new preview($plans);
        $this->assertTrue($preview->can_apply());
        $this->assertFalse($preview->needs_confirmation());

        $output = $PAGE->get_renderer('core');
        $data = $preview->export_for_template($output);
        $this->assertSame(['change', 'nochange'], array_column($data['quizzes'], 'status'));
        $this->assertSame(['attempts'], array_column($data['quizzes'][0]['rows'], 'key'));
        $this->assertSame([], $data['quizzes'][1]['rows']);

        $html = $output->render_from_template('tool_quizbulkedit/preview', $data);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('>One bold</a>', $html);
        $this->assertStringContainsString('<tr data-key="attempts">', $html);

        // Nothing to change at all: no Apply changes.
        $plans = (new planner())->plan((int) $course->id, [$quiz2->cmid], $request);
        $this->assertFalse((new preview($plans))->can_apply());
    }
}
