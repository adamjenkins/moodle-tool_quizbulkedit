@tool @tool_quizbulkedit
Feature: Change only one review option row and leave every other setting alone
  In order to change one thing without disturbing the rest
  As a teacher
  I need settings without a ticked Change box to keep each quiz's own value

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name   | course | idnumber | grade | gradepass | attempts |
      | quiz     | Quiz 1 | C1     | q1       | 10    | 4         | 3        |
      | quiz     | Quiz 2 | C1     | q2       | 25    | 12        | 0        |

  # JavaScript: the browser-less driver posts nothing for an unticked advcheckbox (it drops the
  # hidden 0 that shares the checkbox's name), so moodleform would read the box's default.
  @javascript
  Scenario: Only the marks review row changes; maximum grades and grades to pass are untouched
    Given I log in as "teacher1"
    And I am on the "C1" "tool_quizbulkedit > Bulk edit" page
    And I set the field "Select Quiz 1" to "1"
    And I set the field "Select Quiz 2" to "1"
    # A setting without a ticked Change box cannot be edited.
    And the "maxgrade" "field" should be disabled
    And the "attempts" "field" should be disabled
    And I expand all fieldsets
    And the "review_marks_open" "field" should be disabled
    And I set the field "change_review_marks" to "1"
    And the "review_marks_open" "field" should be enabled
    And the "review_attempt_open" "field" should be disabled
    And I set the field "review_marks_during" to "0"
    And I set the field "review_marks_immediately" to "0"
    And I set the field "review_marks_open" to "1"
    And I set the field "review_marks_closed" to "1"
    And the field "review_marks_immediately" matches value "0"
    When I press "Preview"
    Then I should see "Quizzes that will change: 2."
    And "//div[@data-region='tool_quizbulkedit-previewquiz'][.//a[normalize-space(.)='Quiz 1']]//tr[@data-key='review_marks']/td[2][normalize-space(.)='Later, while the quiz is still open, After the quiz is closed']" "xpath_element" should exist
    And "//div[@data-region='tool_quizbulkedit-previewquiz']//tr[@data-key='review_marks']" "xpath_element" should exist
    And "//div[@data-region='tool_quizbulkedit-previewquiz']//tr[@data-key!='review_marks']" "xpath_element" should not exist
    When I press "Apply changes"
    Then I should see "Quizzes changed: 2."
    # LATER_WHILE_OPEN (0x100) + AFTER_CLOSE (0x10) = 272.
    And the "reviewmarks" of quiz "q1" should be "272"
    And the "reviewmarks" of quiz "q2" should be "272"
    # The generator's default for every other review row: all four times (0x11110 = 69904).
    And the "reviewmaxmarks" of quiz "q1" should be "69904"
    And the "reviewattempt" of quiz "q1" should be "69904"
    And the "grade" of quiz "q1" should be "10"
    And the "grade" of quiz "q2" should be "25"
    And the "gradepass" of quiz "q1" should be "4"
    And the "gradepass" of quiz "q2" should be "12"
    And the "attempts" of quiz "q1" should be "3"
    And the "attempts" of quiz "q2" should be "0"
