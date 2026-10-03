@tool @tool_quizbulkedit
Feature: Preview and apply a grade to pass as a percentage of each quiz's maximum grade
  In order to set the same pass mark on quizzes with different maximum grades
  As a teacher
  I need to preview the grade to pass each quiz would get, and then apply it

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
      | activity | name   | course | idnumber | grade |
      | quiz     | Quiz 1 | C1     | q1       | 10    |
      | quiz     | Quiz 2 | C1     | q2       | 20    |
      | quiz     | Quiz 3 | C1     | q3       | 10    |
    And I log in as "teacher1"
    And I am on the "C1" "tool_quizbulkedit > Bulk edit" page
    And I set the field "Select Quiz 1" to "1"
    And I set the field "Select Quiz 2" to "1"
    And I set the field "change_gradepass" to "1"
    And I set the field "gradepasstype" to "Percentage of each quiz's maximum grade"
    And I set the field "gradepass" to "50"
    And I press "Preview"

  Scenario: Preview writes nothing, Apply changes writes each quiz's own grade to pass
    Then I should see "Quizzes that will change: 2."
    And I should see "5.00" in the "//div[@data-region='tool_quizbulkedit-previewquiz'][.//a[normalize-space(.)='Quiz 1']]//tr[@data-key='gradepass']" "xpath_element"
    And I should see "10.00" in the "//div[@data-region='tool_quizbulkedit-previewquiz'][.//a[normalize-space(.)='Quiz 2']]//tr[@data-key='gradepass']" "xpath_element"
    And "//div[@data-region='tool_quizbulkedit-previewquiz'][.//a[normalize-space(.)='Quiz 3']]" "xpath_element" should not exist
    And "//div[@data-region='tool_quizbulkedit-previewquiz']//tr[@data-key='maxgrade']" "xpath_element" should not exist
    And the "gradepass" of quiz "q1" should be "0"
    And the "gradepass" of quiz "q2" should be "0"
    When I press "Apply changes"
    Then I should see "Quizzes changed: 2."
    And the "gradepass" of quiz "q1" should be "5"
    And the "gradepass" of quiz "q2" should be "10"
    And the "gradepass" of quiz "q3" should be "0"
    And the "grade" of quiz "q1" should be "10"
    And the "grade" of quiz "q2" should be "20"
    # Post-redirect-get: the page is shown fresh, with nothing ticked and no preview.
    And the field "Select Quiz 1" matches value "0"
    And "Apply changes" "button" should not exist

  Scenario: Apply changes refuses settings changed after the preview
    When I set the field "gradepass" to "60"
    And I press "Apply changes"
    Then I should see "changed after the preview. Nothing was saved."
    And the "gradepass" of quiz "q1" should be "0"
    And I should see "6.00" in the "//div[@data-region='tool_quizbulkedit-previewquiz'][.//a[normalize-space(.)='Quiz 1']]//tr[@data-key='gradepass']" "xpath_element"
    When I press "Apply changes"
    Then I should see "Quizzes changed: 2."
    And the "gradepass" of quiz "q1" should be "6"
    And the "gradepass" of quiz "q2" should be "12"

  @javascript @accessibility
  Scenario: The page with a preview meets accessibility standards
    Then the page should meet accessibility standards

  @javascript
  Scenario: Changing a setting after the preview marks it stale and disables Apply changes
    Given I should not see "changed after this preview"
    And the "Apply changes" "button" should be enabled
    When I set the field "gradepass" to "60"
    Then I should see "changed after this preview"
    And the "Apply changes" "button" should be disabled
    # Going back to the previewed value makes the preview current again.
    When I set the field "gradepass" to "50"
    Then I should not see "changed after this preview"
    And the "Apply changes" "button" should be enabled
    # Changing the selection makes it stale too.
    When I set the field "Select Quiz 3" to "1"
    Then the "Apply changes" "button" should be disabled
