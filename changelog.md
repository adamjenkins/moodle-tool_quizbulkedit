# Changelog

All notable changes to `tool_quizbulkedit` are documented in this file.

## [0.1.2] - 2026-10-04

### Changed

- Maturity is now `MATURITY_BETA` (was `MATURITY_ALPHA`).
- CI tests `MOODLE_503_STABLE` (blocking rows: PHP 8.3-8.4, PostgreSQL 17,
  MariaDB 11.4) instead of the experimental moodle.git `main` rows, now that
  Moodle 5.3 is released.
- `composer.json`: `moodle/moodle` constraint is now `^5.2` (was `>=5.2 <5.4`),
  so later 5.x releases are not excluded.

## [0.1.1] - 2026-10-04

### Added

- Saved configurations per course and site-wide admin presets, in the new table
  `tool_quizbulkedit_config` (`courseid` 0 = preset): `local\saved_configs`,
  the presets page `presets.php` (`moodle/site:config`, Admin tools), Save
  configuration / Load / Delete on the course page, a `course_deleted`
  observer. `$plugin->version` 2026100301 (schema change; release unchanged).
- `composer.json` (`adamjenkins/moodle-tool_quizbulkedit`, type `moodle-tool`,
  `moodle/moodle` `>=5.2 <5.4`) so Composer can install the plugin.
- Tagged releases are published to the camp plugin registry.

### Fixed

- The quiz list is in course-page order (`course_modinfo::sort_cm_array()`),
  so a subsection's quizzes are listed inline instead of last.

## [0.1.0] - 2026-10-03

### Added

- Initial release: version metadata, the `tool/quizbulkedit:manage` capability
  (course context, `RISK_DATALOSS`, editing teachers and managers, cloned from
  `moodle/course:manageactivities`), a "Bulk edit quizzes" course navigation
  link, a GDPR null privacy provider and language strings.
- Course page `admin/tool/quizbulkedit/index.php`: a table of the quizzes the
  user may edit (needs `moodle/course:manageactivities` and `mod/quiz:manage`
  on each quiz) with a Filter by name box and a select-all that acts on the
  shown rows only, and a settings form with one Change checkbox per setting
  (each review options row and each completion condition separately).
- Planner: final values (requested where ticked, current otherwise) are checked
  with the quiz form's rules: grade to pass not above the maximum grade (except
  with certainty-based marking), review options rules on the requested rows,
  the grace period minimum, the completion rule dependencies, a locked grade
  item, and minimum attempts against attempts allowed. A percentage grade to
  pass is rounded to each quiz's decimal places.
- Preview with a per-quiz diff, errors, warnings and notes; Apply changes
  re-plans and refuses if the request, the selection or the quizzes changed
  since the preview (fingerprint), and needs a confirmation when completion
  data would be reset.
- Applier: per quiz, in its own transaction, writes only the changed `quiz`
  columns, then runs the follow-ups core runs: final grades for a new grading
  method, open attempts for a new time limit or grace period,
  `grade_calculator::update_quiz_maximum_grade()` for a new maximum grade
  (rescaling grades and overall feedback boundaries), the grade item, the grade
  to pass, completion settings with a completion reset, and preview deletion.
  The quiz edit form is never replayed, so overall feedback and access rule
  settings are untouched.
- PHPUnit coverage of the catalogue, change request, lister, planner, applier,
  form and output, and Behat coverage of the filter and select-all, preview and
  apply, individual settability, the completion reset confirmation and
  permissions.
