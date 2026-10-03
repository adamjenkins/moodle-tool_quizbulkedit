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
 * Tests for the settings form.
 *
 * @package    tool_quizbulkedit
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_quizbulkedit\form;

use PHPUnit\Framework\Attributes\CoversClass;
use tool_quizbulkedit\local\change_request;
use tool_quizbulkedit\local\settings_catalogue;

/**
 * Tests for the settings form: every setting is individually settable through the form.
 */
#[CoversClass(settings_form::class)]
final class settings_form_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Build the form.
     *
     * @param bool $showcompletion
     * @param array $extra more custom data
     * @return settings_form
     */
    private function form(bool $showcompletion = true, array $extra = []): settings_form {
        return new settings_form(null, ['courseid' => SITEID, 'showcompletion' => $showcompletion] + $extra);
    }

    /**
     * The QuickForm object of a form.
     *
     * @param settings_form $form
     * @return \MoodleQuickForm
     */
    private function quickform(settings_form $form): \MoodleQuickForm {
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        return $property->getValue($form);
    }

    /**
     * Form data that ticks nothing but fills every value element with a valid value different
     * from the site defaults, so that any value read without its Change box would show.
     *
     * @return array
     */
    private function all_values_unticked(): array {
        $data = ['courseid' => SITEID];
        foreach (settings_catalogue::get_keys() as $key) {
            $data['change_' . $key] = 0;
            $type = settings_catalogue::get_type($key);
            if ($type === settings_catalogue::TYPE_REVIEW) {
                foreach (array_keys(settings_catalogue::REVIEW_TIMES) as $time) {
                    $data[$key . '_' . $time] = 1;
                }
            } else if ($type === settings_catalogue::TYPE_GRADEPASS) {
                $data['gradepasstype'] = settings_catalogue::GRADEPASS_ABSOLUTE;
                $data['gradepass'] = '3';
            } else if ($type === settings_catalogue::TYPE_DURATION) {
                $data[$key] = ['number' => 5, 'timeunit' => 60];
            } else if ($type === settings_catalogue::TYPE_FLOAT || $type === settings_catalogue::TYPE_INT) {
                $data[$key] = '7';
            } else {
                $choices = array_keys(settings_catalogue::get_choices($key));
                $data[$key] = end($choices);
            }
        }
        return $data;
    }

    /**
     * Every catalogue key has its own Change checkbox, and every value element is disabled
     * while it is unticked.
     *
     * Fails if a setting is added to the catalogue without its own Change checkbox, or if a
     * value element is not tied to its own setting's checkbox.
     */
    public function test_every_setting_has_its_own_change_checkbox(): void {
        $mform = $this->quickform($this->form());
        $dependencies = (new \ReflectionProperty(\MoodleQuickForm::class, '_dependencies'))->getValue($mform);

        foreach (settings_catalogue::get_keys() as $key) {
            $group = $mform->getElement($key . '_grp');
            $names = array_map(fn($element) => $element->getName(), $group->getElements());
            $this->assertSame('change_' . $key, $names[0], $key);
            $this->assertCount(1, array_filter($names, fn($name) => str_starts_with($name, 'change_')), $key);

            $type = settings_catalogue::get_type($key);
            if ($type === settings_catalogue::TYPE_REVIEW) {
                $values = array_map(fn($time) => $key . '_' . $time, array_keys(settings_catalogue::REVIEW_TIMES));
            } else if ($type === settings_catalogue::TYPE_GRADEPASS) {
                $values = ['gradepasstype', 'gradepass'];
            } else if ($type === settings_catalogue::TYPE_DURATION) {
                $values = [$key . '[number]', $key . '[timeunit]'];
            } else {
                $values = [$key];
            }
            // Stored as dependent-on name => condition => value => element names.
            $controlled = $dependencies['change_' . $key]['notchecked']['1'] ?? [];
            foreach ($values as $value) {
                $this->assertContains($value, $controlled, "{$value} must be disabled while change_{$key} is unticked");
            }
            // The Change checkbox controls only its own setting's elements.
            $this->assertCount(count($values), $controlled, $key);
        }
    }

    /**
     * The Completion section is only there when completion is enabled.
     */
    public function test_completion_section_only_when_enabled(): void {
        $this->assertTrue($this->quickform($this->form(true))->elementExists('section_completion'));
        $this->assertTrue($this->quickform($this->form(true))->elementExists('completion_grp'));
        $this->assertFalse($this->quickform($this->form(false))->elementExists('section_completion'));
        $this->assertFalse($this->quickform($this->form(false))->elementExists('completion_grp'));
    }

    /**
     * Apply changes, the confirmation and the fingerprint are only there with a preview.
     */
    public function test_apply_only_with_preview(): void {
        $mform = $this->quickform($this->form());
        $this->assertTrue($mform->elementExists('buttonar'));
        $names = array_map(fn($element) => $element->getName(), $mform->getElement('buttonar')->getElements());
        $this->assertSame(['preview'], $names);
        $this->assertFalse($mform->elementExists('confirmreset'));
        $this->assertFalse($mform->elementExists('fingerprint'));

        $mform = $this->quickform($this->form(true, [
            'previewhtml' => '<p>preview</p>',
            'canapply' => true,
            'needsconfirm' => true,
            'fingerprint' => 'abc123',
        ]));
        $names = array_map(fn($element) => $element->getName(), $mform->getElement('buttonar')->getElements());
        $this->assertSame(['preview', 'apply'], $names);
        $this->assertTrue($mform->elementExists('confirmreset'));
        $this->assertSame('abc123', $mform->getElement('fingerprint')->getValue());
    }

    /**
     * Nothing ticked: the request is empty, whatever the value elements hold.
     *
     * Fails if the form or change_request reads a value whose Change box is unticked.
     */
    public function test_nothing_ticked_reads_nothing(): void {
        settings_form::mock_submit($this->all_values_unticked());
        $data = $this->form()->get_data();
        $this->assertNotNull($data);
        $this->assertTrue(change_request::from_form_data($data)->is_empty());
    }

    /**
     * Ticking exactly one setting requests exactly that setting, for every setting in turn.
     *
     * Fails if any setting is not individually settable through the real form (e.g. only the
     * review options without the maximum grade).
     */
    public function test_each_setting_alone(): void {
        foreach (settings_catalogue::get_keys() as $key) {
            $post = $this->all_values_unticked();
            $post['change_' . $key] = 1;
            settings_form::mock_submit($post);
            $data = $this->form()->get_data();
            $this->assertNotNull($data, $key);
            $request = change_request::from_form_data($data);
            $this->assertSame([$key], $request->get_changed_keys(), $key);
        }
    }

    /**
     * Only one review row ticked: that row's four times are read, as a bitmask.
     */
    public function test_one_review_row(): void {
        $post = $this->all_values_unticked();
        $post['change_review_marks'] = 1;
        $post['review_marks_during'] = 0;
        $post['review_marks_immediately'] = 1;
        $post['review_marks_open'] = 0;
        $post['review_marks_closed'] = 1;
        settings_form::mock_submit($post);
        $request = change_request::from_form_data($this->form()->get_data());
        $this->assertSame(['review_marks'], $request->get_changed_keys());
        $this->assertSame(
            settings_catalogue::REVIEW_TIMES['immediately'] | settings_catalogue::REVIEW_TIMES['closed'],
            $request->get('review_marks')
        );
    }

    /**
     * A ticked setting with an invalid value fails validation, and the error is shown on its row.
     */
    public function test_invalid_ticked_value_errors_on_its_row(): void {
        $post = $this->all_values_unticked();
        $post['change_maxgrade'] = 1;
        $post['maxgrade'] = 'abc';
        $post['change_gradepass'] = 1;
        $post['gradepasstype'] = settings_catalogue::GRADEPASS_PERCENT;
        $post['gradepass'] = '120';
        settings_form::mock_submit($post);
        $form = $this->form();
        $this->assertNull($form->get_data());
        $mform = $this->quickform($form);
        $this->assertSame(get_string('error_maxgrade', 'tool_quizbulkedit'), $mform->getElementError('maxgrade_grp'));
        $this->assertSame(get_string('error_gradepasspercent', 'tool_quizbulkedit'), $mform->getElementError('gradepass_grp'));

        // The same invalid values with the boxes unticked are ignored.
        $post['change_maxgrade'] = 0;
        $post['change_gradepass'] = 0;
        settings_form::mock_submit($post);
        $this->assertNotNull($this->form()->get_data());
    }
}
