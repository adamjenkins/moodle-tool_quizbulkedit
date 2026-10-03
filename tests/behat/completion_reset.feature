@tool @tool_quizbulkedit
Feature: Changing completion settings that reset completion data needs a confirmation
  In order not to delete students' completion data by accident
  As a teacher
  I need to confirm before completion changes that reset completion data are applied

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format | enablecompletion |
      | Course 1 | C1        | topics | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name   | course | idnumber | completion |
      | quiz     | Quiz 1 | C1     | q1       | 1          |
    And "student1" has completion data for quiz "q1"

  Scenario: Apply changes is refused until the reset is confirmed
    Given I log in as "teacher1"
    And I am on the "C1" "tool_quizbulkedit > Bulk edit" page
    And I set the field "Select Quiz 1" to "1"
    And I set the field "change_completion" to "1"
    And I set the field "completion" to "Show activity as complete when conditions are met"
    And I set the field "change_completionview" to "1"
    And I set the field "completionview" to "Yes"
    When I press "Preview"
    Then I should see "Students have completion data for this quiz."
    And I should see "Tick the confirmation below to apply them."
    When I press "Apply changes"
    Then I should see "These changes delete completion data."
    And the "completion" of quiz "q1" should be "1"
    And the "completionview" of quiz "q1" should be "0"
    When I set the field "confirmreset" to "1"
    And I press "Apply changes"
    Then I should see "Quizzes changed: 1."
    And the "completion" of quiz "q1" should be "2"
    And the "completionview" of quiz "q1" should be "1"
