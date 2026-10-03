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

namespace tool_quizbulkedit\local;

/**
 * What would change for one quiz: current and final values, diff, errors, warnings, notes.
 *
 * Built by {@see planner}; consumed by the preview and by {@see applier}. Only keys the
 * planner set a final value for can change; every other setting keeps its stored value
 * and is never written.
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_plan {
    /** @var \cm_info the quiz's course module. */
    protected \cm_info $cm;

    /** @var \stdClass the quiz record when planned. */
    protected \stdClass $quiz;

    /** @var array key => current normalised value, for every catalogue key. */
    protected array $current = [];

    /** @var array key => final normalised value, for the keys the planner set. */
    protected array $final = [];

    /** @var bool whether the quiz's grade item is locked. */
    protected bool $gradeitemlocked;

    /** @var bool whether the quiz has non-preview attempts. */
    protected bool $hasattempts;

    /** @var bool whether course_modules_completion has rows for the quiz. */
    protected bool $hascompletiondata;

    /** @var string[] error code => message; any error stops the quiz from being applied. */
    protected array $errors = [];

    /** @var string[] warning code => message. */
    protected array $warnings = [];

    /** @var string[] note code => message. */
    protected array $notes = [];

    /**
     * Constructor.
     *
     * @param \stdClass $entry an entry of {@see quiz_lister::get_eligible()}
     * @param bool $hasattempts whether the quiz has non-preview attempts
     * @param bool $hascompletiondata whether any user has completion data for the quiz
     */
    public function __construct(\stdClass $entry, bool $hasattempts, bool $hascompletiondata) {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $this->cm = $entry->cm;
        $this->quiz = $entry->quiz;
        $this->gradeitemlocked = (bool) $entry->gradeitemlocked;
        $this->hasattempts = $hasattempts;
        $this->hascompletiondata = $hascompletiondata;

        foreach (settings_catalogue::get_keys() as $key) {
            if ($key === 'gradepass') {
                $this->current[$key] = $entry->gradepass === null ? null : (float) $entry->gradepass;
            } else if ($key === 'completionusegrade') {
                $this->current[$key] = $entry->cmrecord->completiongradeitemnumber === null ? 0 : 1;
            } else if (in_array($key, settings_catalogue::COMPLETION_CM_KEYS, true)) {
                $this->current[$key] = (int) $entry->cmrecord->$key;
            } else {
                $column = settings_catalogue::get_quiz_column($key);
                $this->current[$key] = settings_catalogue::normalise($key, $this->quiz->$column ?? 0);
            }
        }
    }

    /**
     * The course module id.
     *
     * @return int
     */
    public function get_cmid(): int {
        return (int) $this->cm->id;
    }

    /**
     * The course module.
     *
     * @return \cm_info
     */
    public function get_cm(): \cm_info {
        return $this->cm;
    }

    /**
     * The quiz record as it was when planned.
     *
     * @return \stdClass
     */
    public function get_quiz(): \stdClass {
        return $this->quiz;
    }

    /**
     * Whether the quiz's grade item is locked in the gradebook.
     *
     * @return bool
     */
    public function is_gradeitem_locked(): bool {
        return $this->gradeitemlocked;
    }

    /**
     * Whether the quiz has non-preview attempts.
     *
     * @return bool
     */
    public function has_attempts(): bool {
        return $this->hasattempts;
    }

    /**
     * Whether any user has completion data for the quiz.
     *
     * @return bool
     */
    public function has_completion_data(): bool {
        return $this->hascompletiondata;
    }

    /**
     * The current value of a setting.
     *
     * @param string $key a catalogue key
     * @return mixed normalised value (gradepass: null when the quiz has no grade item)
     */
    public function get_current(string $key) {
        if (!array_key_exists($key, $this->current)) {
            throw new \coding_exception('Unknown quiz setting key: ' . $key);
        }
        return $this->current[$key];
    }

    /**
     * Set the final value of a setting (planner only).
     *
     * @param string $key a catalogue key
     * @param mixed $value
     */
    public function set_final(string $key, $value): void {
        if (!array_key_exists($key, $this->current)) {
            throw new \coding_exception('Unknown quiz setting key: ' . $key);
        }
        $this->final[$key] = settings_catalogue::normalise($key, $value);
    }

    /**
     * The final value of a setting: the planned value if one was set, else the current value.
     *
     * @param string $key a catalogue key
     * @return mixed
     */
    public function get_final(string $key) {
        return array_key_exists($key, $this->final) ? $this->final[$key] : $this->get_current($key);
    }

    /**
     * Whether a setting's final value differs from its current value.
     *
     * @param string $key a catalogue key
     * @return bool
     */
    public function is_changing(string $key): bool {
        if (!array_key_exists($key, $this->final)) {
            return false;
        }
        $current = $this->get_current($key);
        if ($current === null) {
            return true;
        }
        return !settings_catalogue::values_equal($key, $current, $this->final[$key]);
    }

    /**
     * The settings that change, with their new values, in catalogue order.
     *
     * @return array key => new normalised value
     */
    public function get_changes(): array {
        $changes = [];
        foreach (settings_catalogue::get_keys() as $key) {
            if ($this->is_changing($key)) {
                $changes[$key] = $this->final[$key];
            }
        }
        return $changes;
    }

    /**
     * The quiz table columns to write (excluding grade, which core's grade calculator writes).
     *
     * @return array column => value
     */
    public function get_quiz_column_changes(): array {
        $columns = [];
        foreach ($this->get_changes() as $key => $value) {
            $column = settings_catalogue::get_quiz_column($key);
            if ($column !== null && $key !== 'maxgrade') {
                $columns[$column] = $value;
            }
        }
        return $columns;
    }

    /**
     * The course_modules completion columns to write.
     *
     * @return array column => value
     */
    public function get_cm_completion_changes(): array {
        $columns = [];
        foreach (settings_catalogue::COMPLETION_CM_KEYS as $key) {
            if (!$this->is_changing($key)) {
                continue;
            }
            if ($key === 'completionusegrade') {
                $columns['completiongradeitemnumber'] = $this->final[$key] ? 0 : null;
            } else {
                $columns[$key] = $this->final[$key];
            }
        }
        return $columns;
    }

    /**
     * Whether any completion setting changes.
     *
     * @return bool
     */
    public function is_completion_changing(): bool {
        foreach (array_merge(settings_catalogue::COMPLETION_CM_KEYS, settings_catalogue::COMPLETION_QUIZ_KEYS) as $key) {
            if ($this->is_changing($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether applying the plan must reset and recalculate the quiz's completion state:
     * completion changes, unless tracking is manual (or none) both before and after.
     *
     * @return bool
     */
    public function needs_completion_reset(): bool {
        if (!$this->is_completion_changing()) {
            return false;
        }
        $old = (int) $this->get_current('completion');
        $new = (int) $this->get_final('completion');
        return !($old === $new && in_array($old, [COMPLETION_TRACKING_NONE, COMPLETION_TRACKING_MANUAL], true));
    }

    /**
     * Rows for the preview: one per setting whose value actually changes.
     *
     * @return array[] each ['key' => string, 'label' => string, 'old' => string, 'new' => string] (plain text)
     */
    public function get_diff_rows(): array {
        $rows = [];
        $olddecimals = (int) $this->get_current('decimalpoints');
        $newdecimals = (int) $this->get_final('decimalpoints');
        foreach ($this->get_changes() as $key => $value) {
            $rows[] = [
                'key' => $key,
                'label' => settings_catalogue::get_label($key),
                'old' => settings_catalogue::format_value($key, $this->get_current($key), $olddecimals),
                'new' => settings_catalogue::format_value($key, $value, $newdecimals),
            ];
        }
        return $rows;
    }

    /**
     * Add an error: the quiz will not be changed.
     *
     * @param string $code
     * @param string $message
     */
    public function add_error(string $code, string $message): void {
        $this->errors[$code] = $message;
    }

    /**
     * Add a warning: the quiz can be changed, with a consequence the teacher should know.
     *
     * @param string $code
     * @param string $message
     */
    public function add_warning(string $code, string $message): void {
        $this->warnings[$code] = $message;
    }

    /**
     * Add an informational note.
     *
     * @param string $code
     * @param string $message
     */
    public function add_note(string $code, string $message): void {
        $this->notes[$code] = $message;
    }

    /**
     * Errors.
     *
     * @return string[] code => message
     */
    public function get_errors(): array {
        return $this->errors;
    }

    /**
     * Warnings.
     *
     * @return string[] code => message
     */
    public function get_warnings(): array {
        return $this->warnings;
    }

    /**
     * Notes.
     *
     * @return string[] code => message
     */
    public function get_notes(): array {
        return $this->notes;
    }

    /**
     * Whether any setting actually changes.
     *
     * @return bool
     */
    public function has_changes(): bool {
        return (bool) $this->get_changes();
    }

    /**
     * Whether the plan can be applied: something changes and there are no errors.
     *
     * @return bool
     */
    public function can_apply(): bool {
        return !$this->errors && $this->has_changes();
    }

    /**
     * Whether applying needs the teacher's explicit confirmation (completion data will be reset).
     *
     * @return bool
     */
    public function needs_confirmation(): bool {
        return isset($this->warnings['warncompletionreset']);
    }

    /**
     * Everything the plan was computed from, for the preview fingerprint.
     *
     * @return array
     */
    public function get_snapshot(): array {
        return [
            'current' => $this->current,
            'timeclose' => (int) $this->quiz->timeclose,
            'gradeitemlocked' => $this->gradeitemlocked,
            'hasattempts' => $this->hasattempts,
            'hascompletiondata' => $this->hascompletiondata,
        ];
    }
}
