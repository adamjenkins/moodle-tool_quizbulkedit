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
 * English language strings for tool_quizbulkedit.
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addpreset'] = 'Add preset';
$string['adminpresets'] = 'Quiz bulk edit presets';
$string['adminpresets_desc'] = 'Named presets of quiz settings. Every course\'s Bulk edit quizzes page lists them under Admin presets; loading one fills in the settings form, and nothing changes until the teacher previews and applies it.';
$string['apply'] = 'Apply changes';
$string['change'] = 'Change';
$string['completionminattemptsoff'] = 'Not required';
$string['completiontracking_automatic'] = 'Show activity as complete when conditions are met';
$string['completiontracking_manual'] = 'Students can manually mark the activity as completed';
$string['completiontracking_none'] = 'Do not indicate activity completion';
$string['configdeleted'] = 'Configuration "{$a}" deleted.';
$string['configloaded'] = 'Configuration "{$a}" loaded. Preview and apply it to change the quizzes.';
$string['configname'] = 'Configuration name';
$string['configreplaced'] = 'Configuration "{$a}" replaced.';
$string['configsaved'] = 'Configuration "{$a}" saved.';
$string['configsavedon'] = 'Saved';
$string['confirmneeded'] = 'Applying these changes deletes and recalculates the completion data of the quizzes marked above. Tick the confirmation below to apply them.';
$string['confirmreset'] = 'Delete and recalculate the completion data of the quizzes marked above';
$string['confirmresetlabel'] = 'Completion data';
$string['deleteconfigconfirm'] = 'Delete the saved configuration "{$a}"? The quizzes are not changed.';
$string['deletepresetconfirm'] = 'Delete the admin preset "{$a}"? Quizzes it was applied to are not changed.';
$string['durationnone'] = 'None';
$string['editpreset'] = 'Edit preset';
$string['error_completiondisabled'] = 'Completion tracking is not enabled for this course, so completion settings cannot be changed.';
$string['error_configname'] = 'Enter a name.';
$string['error_confirmreset'] = 'These changes delete completion data. Tick the confirmation next to "Completion data" to apply them.';
$string['error_exhaustedneedspassgrade'] = '"Passing grade or all available attempts completed" needs "Require passing grade".';
$string['error_gradeitemlocked'] = 'The grade item of this quiz is locked in the gradebook. Unlock it before changing the maximum grade, grading method or the review options for marks.';
$string['error_gradepassabovemax'] = 'The grade to pass ({$a->gradepass}) would be higher than the maximum grade ({$a->max}). Set the grade to pass as well.';
$string['error_gradepassabsolute'] = 'Enter a grade of 0 or more.';
$string['error_gradepasspercent'] = 'Enter a percentage from 0 to 100.';
$string['error_invalidvalue'] = 'Invalid value.';
$string['error_maxgrade'] = 'Enter a maximum grade greater than 0 and less than 100000.';
$string['error_nogradeitem'] = 'This quiz has no grade item, so its grade to pass cannot be set.';
$string['error_noquizzes'] = 'Select at least one quiz in the table.';
$string['error_nosettings'] = 'Tick "Change" next to at least one setting.';
$string['error_notnegativeint'] = 'Enter a whole number of 0 or more.';
$string['error_passgradeneedsusegrade'] = '"Require passing grade" needs "Require grade".';
$string['filterbyname'] = 'Filter by name';
$string['gradepasstype'] = 'Grade to pass entered as';
$string['gradepasstype_absolute'] = 'Points';
$string['gradepasstype_percent'] = 'Percentage of each quiz\'s maximum grade';
$string['intro'] = 'Tick the quizzes to change, then tick "Change" next to each setting to set on all of them. Settings without a tick keep each quiz\'s own value. Preview shows what would change; nothing is saved until you apply the changes.';
$string['load'] = 'Load';
$string['nofiltermatch'] = 'No quizzes match the filter.';
$string['nopresets'] = 'There are no presets yet.';
$string['noquizzes'] = 'There are no quizzes in this course that you can edit.';
$string['nosavedconfigs'] = 'This course has no saved configurations yet. Use Save configuration below the settings to save one.';
$string['noteafterclose'] = 'This quiz has no close date, so the "After the quiz is closed" review options take effect only once a close date is set.';
$string['notereviewadjusted'] = 'Some requested review options will not be saved, because the quiz form does not allow them with the other review options or the question behaviour: {$a}.';
$string['nothingtoapply'] = 'None of the selected quizzes can be changed with these settings.';
$string['notset'] = 'Not set';
$string['pluginname'] = 'Bulk edit quizzes';
$string['presetdeleted'] = 'Preset "{$a}" deleted.';
$string['presetloaded'] = 'Admin preset "{$a}" loaded. Tick the quizzes, then preview and apply it.';
$string['presetname'] = 'Preset name';
$string['presetsaved'] = 'Preset "{$a}" saved.';
$string['preview'] = 'Preview';
$string['previewchanged'] = 'The settings, the selected quizzes or the quizzes themselves changed after the preview. Nothing was saved. Check the new preview and apply again.';
$string['previewheading'] = 'Preview';
$string['previewnew'] = 'New value';
$string['previewnochange'] = 'These settings already have the requested values.';
$string['previewold'] = 'Current value';
$string['previewsetting'] = 'Setting';
$string['previewstale'] = 'The settings or the selected quizzes changed after this preview. Press Preview again before applying.';
$string['previewsummary'] = 'Quizzes that will change: {$a->change}. Already as requested: {$a->nochange}. Cannot be changed: {$a->error}.';
$string['privacy:metadata'] = 'The Bulk edit quizzes tool only changes quiz settings, grade items and activity completion settings. It does not store any personal data.';
$string['quizbulkedit:manage'] = 'Bulk edit the settings of quizzes in a course';
$string['rejectedcmids'] = '{$a} of the selected quizzes cannot be edited by you and were ignored.';
$string['resultappliedwithmessages'] = '{$a->name} was changed, with a problem: {$a->messages}';
$string['resultfailed'] = 'Changing {$a->name} failed, so nothing was changed in it: {$a->messages}';
$string['resultskipped'] = '{$a->name} was not changed: {$a->messages}';
$string['resultsummary'] = 'Quizzes changed: {$a->applied}. Already as requested: {$a->nochange}. Skipped: {$a->skipped}. Failed: {$a->failed}.';
$string['reviewintro'] = 'Each row has its own "Change" box. A ticked row is set to exactly the ticked times on every selected quiz; rows without a tick keep each quiz\'s own review options.';
$string['reviewnever'] = 'Never';
$string['reviewtime_closed'] = 'After the quiz is closed';
$string['reviewtime_during'] = 'During the attempt';
$string['reviewtime_immediately'] = 'Immediately after the attempt';
$string['reviewtime_open'] = 'Later, while the quiz is still open';
$string['saveconfig'] = 'Save configuration';
$string['saveconfigheading'] = 'Save configuration';
$string['savedconfigs'] = 'Saved configurations';
$string['savepreset'] = 'Save preset';
$string['section_behaviour'] = 'Question behaviour';
$string['section_completion'] = 'Completion';
$string['section_display'] = 'Display and restrictions';
$string['section_grade'] = 'Grade';
$string['section_layouttiming'] = 'Layout and timing';
$string['section_review'] = 'Review options';
$string['selectallshown'] = 'Select all shown quizzes';
$string['selectquiz'] = 'Select {$a}';
$string['selectquizzes'] = 'Quizzes';
$string['setting_attempts'] = 'Attempts allowed';
$string['setting_browsersecurity'] = 'Browser security';
$string['setting_canredoquestions'] = 'Allow redo within an attempt';
$string['setting_completion'] = 'Completion tracking';
$string['setting_completionattemptsexhausted'] = 'Passing grade or all available attempts completed';
$string['setting_completionminattempts'] = 'Minimum attempts';
$string['setting_completionpassgrade'] = 'Require passing grade';
$string['setting_completionusegrade'] = 'Require grade';
$string['setting_completionview'] = 'Require view';
$string['setting_decimalpoints'] = 'Decimal places in grades';
$string['setting_delay1'] = 'Enforced delay between 1st and 2nd attempts';
$string['setting_delay2'] = 'Enforced delay between later attempts';
$string['setting_graceperiod'] = 'Submission grace period';
$string['setting_grademethod'] = 'Grading method';
$string['setting_gradepass'] = 'Grade to pass';
$string['setting_maxgrade'] = 'Maximum grade';
$string['setting_navmethod'] = 'Navigation method';
$string['setting_overduehandling'] = 'When time expires';
$string['setting_preferredbehaviour'] = 'How questions behave';
$string['setting_questiondecimalpoints'] = 'Decimal places in marks for questions';
$string['setting_review_attempt'] = 'Review: The attempt';
$string['setting_review_correctness'] = 'Review: Whether correct';
$string['setting_review_generalfeedback'] = 'Review: General feedback';
$string['setting_review_marks'] = 'Review: Marks';
$string['setting_review_maxmarks'] = 'Review: Maximum marks';
$string['setting_review_overallfeedback'] = 'Review: Overall feedback';
$string['setting_review_rightanswer'] = 'Review: Right answer';
$string['setting_review_specificfeedback'] = 'Review: Specific feedback';
$string['setting_showblocks'] = 'Show blocks during quiz attempts';
$string['setting_showuserpicture'] = 'Show the user\'s picture';
$string['setting_shuffleanswers'] = 'Shuffle within questions';
$string['setting_timelimit'] = 'Time limit';
$string['settingsintro'] = 'Only the settings with a ticked "Change" box are changed.';
$string['status_change'] = 'Will change';
$string['status_error'] = 'Cannot be changed';
$string['status_nochange'] = 'Already as requested';
$string['warnattemptsrescale'] = 'This quiz has attempts. Changing the maximum grade rescales all existing grades.';
$string['warncompletionreset'] = 'Students have completion data for this quiz. Changing its completion settings deletes that data and recalculates it.';
