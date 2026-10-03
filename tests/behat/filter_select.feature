@tool @tool_quizbulkedit @javascript
Feature: Filter the quiz table by name and select the shown quizzes
  In order to pick the quizzes to change in a long course
  As a teacher
  I need a name filter, and a select-all that acts on the quizzes the filter shows

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
      | activity | name          | course | idnumber |
      | quiz     | Algebra quiz  | C1     | q1       |
      | quiz     | Algebra test  | C1     | q2       |
      | quiz     | Geometry quiz | C1     | q3       |
    And I log in as "teacher1"
    And I am on the "C1" "tool_quizbulkedit > Bulk edit" page

  Scenario: Select all acts on the shown rows only and hidden rows keep their ticks
    When I set the field "Filter by name" to "ALG"
    Then "Algebra quiz" "table_row" should be visible
    And "Algebra test" "table_row" should be visible
    And "Geometry quiz" "table_row" should not be visible
    And I should not see "No quizzes match the filter."
    When I click on "Select all shown quizzes" "checkbox"
    Then the field "Select Algebra quiz" matches value "1"
    And the field "Select Algebra test" matches value "1"
    And the field "Select Geometry quiz" matches value "0"
    # With every row shown, the select-all reflects all of them.
    When I set the field "Filter by name" to ""
    Then "Geometry quiz" "table_row" should be visible
    And the field "Select all shown quizzes" matches value "0"
    # Unticking all shown rows leaves the hidden ones ticked.
    When I set the field "Filter by name" to "test"
    Then the field "Select all shown quizzes" matches value "1"
    And I click on "Select all shown quizzes" "checkbox"
    And I set the field "Filter by name" to ""
    Then the field "Select Algebra quiz" matches value "1"
    And the field "Select Algebra test" matches value "0"
    And the field "Select Geometry quiz" matches value "0"
    When I set the field "Filter by name" to "zzz"
    Then I should see "No quizzes match the filter."
    And the field "Select all shown quizzes" matches value "0"

  Scenario: Ticked quizzes stay ticked when the page is shown again
    When I set the field "Select Algebra test" to "1"
    And I press "Preview"
    Then I should see "next to at least one setting."
    And the field "Select Algebra test" matches value "1"
    And the field "Select Algebra quiz" matches value "0"
    And the field "Select Geometry quiz" matches value "0"
