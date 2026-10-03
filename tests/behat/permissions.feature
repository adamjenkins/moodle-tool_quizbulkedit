@tool @tool_quizbulkedit
Feature: Only users with the bulk edit capability get the page
  In order to keep bulk changes to quiz grades and completion with the right people
  As an administrator
  I need the course link and the page to need tool/quizbulkedit:manage

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
      | activity | name   | course | idnumber |
      | quiz     | Quiz 1 | C1     | q1       |

  Scenario: An editing teacher has the link and the page
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then "Bulk edit quizzes" "link" should exist
    And I navigate to "Bulk edit quizzes" in current page administration
    And I should see "Quiz 1" in the "tool_quizbulkedit_table" "table"

  Scenario: A teacher without the capability has no link and is refused the page
    Given the following "permission overrides" exist:
      | capability               | permission | role           | contextlevel | reference |
      | tool/quizbulkedit:manage | Prohibit   | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then "Bulk edit quizzes" "link" should not exist
    And I should be refused access to the bulk edit page of "C1"
