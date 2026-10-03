# Bulk edit quizzes (`tool_quizbulkedit`)

A Moodle admin tool that changes the settings of some or all quizzes in a course
in one go: review options, grade to pass (in points or as a percentage of each
quiz's maximum grade), maximum grade, completion conditions, and the grade,
question behaviour, layout and timing, and display settings.

## Requirements

- Moodle 5.2 or later

## Installation

1. Copy the plugin directory into `<moodleroot>/public/admin/tool/quizbulkedit/`
2. Visit Site administration → Notifications to run the upgrade

## How it works

From a course's administration menu (More → **Bulk edit quizzes**), a teacher
ticks the quizzes to change in a table that shows each quiz's maximum grade,
grade to pass, attempts allowed and completion tracking. A **Filter by name**
box shows only the quizzes whose name contains the typed text; the select-all
box then ticks or unticks the shown quizzes only, and hidden quizzes keep their
ticks.

Below the table, every setting has its own **Change** box. Only settings with a
ticked Change box are changed; every other setting keeps each quiz's own value
and is not written at all. So it is possible to change, say, only one review
options row and leave the maximum grades alone.

**Preview** shows for each selected quiz the settings that would change, with
the current and the new value, and any reason a quiz cannot be changed (for
example a grade to pass above the maximum grade, or a locked grade item).
Nothing is saved until **Apply changes** is pressed. Changing a setting or the
selection after the preview disables Apply changes until the next preview, and
the server refuses an apply when the settings, the selection or the quizzes
changed since the preview. Each quiz is changed in its own database
transaction: a quiz that fails is rolled back and reported, and the others are
still changed.

## Settings

| Section | Settings |
|---|---|
| Grade | Maximum grade, Grade to pass (points or % of each quiz's maximum grade), Attempts allowed, Grading method |
| Review options | Each of the 8 rows (The attempt, Whether correct, Maximum marks, Marks, Specific feedback, General feedback, Right answer, Overall feedback), each with its 4 times |
| Question behaviour | Shuffle within questions, How questions behave, Allow redo within an attempt |
| Layout and timing | Navigation method, Time limit, When time expires, Submission grace period |
| Display and restrictions | Decimal places in grades, Decimal places in marks for questions, Show the user's picture, Show blocks during quiz attempts, Enforced delays between attempts, Browser security |
| Completion | Completion tracking, Require view, Require grade, Require passing grade, Passing grade or all attempts completed, Minimum attempts (shown only when completion is enabled for the site and the course) |

The values offered are the same as in the quiz settings form, and the same
rules apply. Open, close and due dates, the password, the network address
restriction and overall feedback are not offered.

What happens when a setting changes, as in core:

- **Maximum grade**: existing grades and overall feedback boundaries are
  rescaled (the preview warns when a quiz has attempts).
- **Grading method**: final grades are recalculated.
- **Time limit / grace period**: open attempts get the new deadline.
- **Completion**: changing completion settings of a quiz that students have
  completion data for deletes and recalculates that data; Apply changes then
  needs a confirmation.

## Saved configurations and admin presets

- **Save configuration** (below the settings) stores the form's settings and the
  ticked quizzes under a name, for the course. **Load** puts them back into the
  form; **Delete** removes one after a confirmation. Saving and loading change no
  quiz: a loaded configuration still goes through Preview and Apply changes.
- **Admin presets**: in *Site administration > Plugins > Admin tools > Quiz bulk
  edit presets*, an administrator creates named presets of settings with the same
  form. Every course's page lists them under *Admin presets* with a **Load**
  button (presets hold settings only; the teacher ticks the quizzes).
- A course's configurations are deleted with the course.

## Permissions

The page needs `tool/quizbulkedit:manage` in the course (editing teachers and
managers by default; `RISK_DATALOSS`, because it rescales grades and resets
completion). Only quizzes on which the user also has
`moodle/course:manageactivities` and `mod/quiz:manage` are listed and changed.

## Privacy

The plugin stores no personal data (saved configurations hold quiz settings and
quiz ids, with no user reference) and implements Moodle's privacy `null_provider`.

## License

GNU GPL v3 or later — https://www.gnu.org/licenses/gpl-3.0.html
