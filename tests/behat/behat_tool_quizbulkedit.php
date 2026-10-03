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
 * Behat steps for tool_quizbulkedit.
 *
 * @package    tool_quizbulkedit
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat steps for tool_quizbulkedit.
 */
class behat_tool_quizbulkedit extends behat_base {
    /** @var string[] settings read from course_modules rather than from the quiz table. */
    private const CM_COLUMNS = ['completion', 'completionview', 'completionpassgrade', 'completiongradeitemnumber'];

    /**
     * Convert page names to URLs for 'I am on the "[identifier]" "tool_quizbulkedit > [page]" page'.
     *
     * | page      | identifier       | description            |
     * | Bulk edit | Course shortname | The bulk edit page     |
     *
     * @param string $page The page type: 'Bulk edit'.
     * @param string $identifier The course shortname.
     * @return moodle_url
     * @throws Exception If the page type is unknown.
     */
    protected function resolve_page_instance_url(string $page, string $identifier): moodle_url {
        if (strtolower($page) !== 'bulk edit') {
            throw new Exception("Unrecognised tool_quizbulkedit page type '{$page}'");
        }
        return new moodle_url('/admin/tool/quizbulkedit/index.php', ['courseid' => $this->get_course_id($identifier)]);
    }

    /**
     * Open the bulk edit page of a course directly and check that the current user is refused.
     *
     * Every step is followed by a check for exceptions on the page, so this step checks the
     * missing-capability error itself and then leaves the error page.
     *
     * @Then /^I should be refused access to the bulk edit page of "(?P<identifier>[^"]*)"$/
     *
     * @param string $identifier The course shortname.
     * @throws ExpectationException If the page opened, or failed for another reason.
     */
    public function i_should_be_refused_access_to_the_bulk_edit_page(string $identifier): void {
        $url = $this->resolve_page_instance_url('Bulk edit', $identifier);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));

        $error = $this->getSession()->getPage()->find('xpath', "//div[@data-rel='fatalerror']");
        if (!$error) {
            throw new ExpectationException('The bulk edit page opened; access should have been refused', $this->getSession());
        }
        $expected = get_string('nopermissions', 'error', get_capability_string('tool/quizbulkedit:manage'));
        if (!str_contains($error->getText(), $expected)) {
            throw new ExpectationException(
                "Expected '{$expected}' but the page showed: " . $error->getText(),
                $this->getSession()
            );
        }
        $this->getSession()->visit($this->locate_path('/'));
    }

    /**
     * Check a stored setting of a quiz, read from the database.
     *
     * "gradepass" is read from the quiz's grade item; completion, completionview,
     * completionpassgrade and completiongradeitemnumber from its course module; anything else
     * from the quiz table. Numbers are compared as numbers; "null" matches a null value.
     *
     * @Then /^the "(?P<setting>[^"]*)" of quiz "(?P<idnumber>[^"]*)" should be "(?P<value>[^"]*)"$/
     *
     * @param string $setting The column name.
     * @param string $idnumber The quiz's idnumber.
     * @param string $value The expected value.
     * @throws ExpectationException If the value differs.
     */
    public function the_setting_of_quiz_should_be(string $setting, string $idnumber, string $value): void {
        global $DB;
        $cm = $this->get_cm_by_idnumber($idnumber);
        if ($setting === 'gradepass') {
            $actual = $DB->get_field('grade_items', 'gradepass', [
                'courseid' => $cm->course,
                'itemtype' => 'mod',
                'itemmodule' => 'quiz',
                'iteminstance' => $cm->instance,
                'itemnumber' => 0,
            ], MUST_EXIST);
        } else if (in_array($setting, self::CM_COLUMNS, true)) {
            $actual = $DB->get_field('course_modules', $setting, ['id' => $cm->id], MUST_EXIST);
        } else {
            if (!isset($DB->get_columns('quiz')[$setting])) {
                throw new ExpectationException("The quiz table has no '{$setting}' column", $this->getSession());
            }
            $actual = $DB->get_field('quiz', $setting, ['id' => $cm->instance], MUST_EXIST);
        }

        if ($value === 'null') {
            $same = $actual === null;
        } else if (is_numeric($value) && is_numeric($actual)) {
            $same = abs((float) $actual - (float) $value) < 0.000001;
        } else {
            $same = (string) $actual === $value;
        }
        if (!$same) {
            throw new ExpectationException(
                "The '{$setting}' of quiz '{$idnumber}' is '" . var_export($actual, true) . "', not '{$value}'",
                $this->getSession()
            );
        }
    }

    /**
     * The course module of a quiz, by its idnumber.
     *
     * @param string $idnumber
     * @return stdClass the course_modules record
     */
    private function get_cm_by_idnumber(string $idnumber): stdClass {
        global $DB;
        return $DB->get_record_sql(
            "SELECT cm.*
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE m.name = 'quiz' AND cm.idnumber = :idnumber",
            ['idnumber' => $idnumber],
            MUST_EXIST
        );
    }

    /**
     * Give a user a completion record for a quiz, as if they had marked it complete.
     *
     * @Given /^"(?P<username>[^"]*)" has completion data for quiz "(?P<idnumber>[^"]*)"$/
     *
     * @param string $username
     * @param string $idnumber The quiz's idnumber.
     */
    public function user_has_completion_data_for_quiz(string $username, string $idnumber): void {
        global $DB;
        $cm = $this->get_cm_by_idnumber($idnumber);
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $cm->id,
            'userid' => $this->get_user_id_by_identifier($username),
            'completionstate' => 1,
            'overrideby' => null,
            'timemodified' => time(),
        ]);
    }
}
