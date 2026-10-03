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
 * The settings a teacher asked to change: only the keys whose "Change" box was ticked.
 *
 * Every key of {@see settings_catalogue::get_keys()} is individually settable. A key
 * that is not in the request is never planned or written.
 *
 * Form-field contract (what {@see from_form_data()} and {@see validate_form_data()} read;
 * the settings form must use exactly these element names):
 *
 * - change_<key>: advcheckbox, 1 = change this setting, 0/absent = leave it alone. One per
 *   key: change_maxgrade, change_gradepass, change_attempts, change_grademethod,
 *   change_review_attempt ... change_review_overallfeedback (one per review row),
 *   change_shuffleanswers, change_preferredbehaviour, change_canredoquestions,
 *   change_navmethod, change_timelimit, change_overduehandling, change_graceperiod,
 *   change_decimalpoints, change_questiondecimalpoints, change_showuserpicture,
 *   change_showblocks, change_delay1, change_delay2, change_browsersecurity,
 *   change_completion, change_completionview, change_completionusegrade,
 *   change_completionpassgrade, change_completionattemptsexhausted,
 *   change_completionminattempts.
 * - Value fields, read only when the matching change_<key> is ticked:
 *   - maxgrade: float (> 0, at most settings_catalogue::GRADE_LIMIT).
 *   - gradepasstype: 'absolute' or 'percent'; gradepass: float (percent: 0-100; absolute: 0 or more).
 *   - review_<field>_during, review_<field>_immediately, review_<field>_open,
 *     review_<field>_closed: advcheckboxes 0/1, for each field of
 *     settings_catalogue::REVIEW_FIELDS (attempt, correctness, maxmarks, marks,
 *     specificfeedback, generalfeedback, rightanswer, overallfeedback).
 *   - <key> for every other key, holding a value of settings_catalogue::get_choices(<key>)
 *     for select and yes/no keys (attempts, grademethod, decimalpoints,
 *     questiondecimalpoints, preferredbehaviour, canredoquestions, shuffleanswers,
 *     navmethod, overduehandling, showuserpicture, showblocks, browsersecurity,
 *     completion, completionview, completionusegrade, completionpassgrade,
 *     completionattemptsexhausted), a number of seconds 0 or more for durations
 *     (timelimit, graceperiod, delay1, delay2: the value a 'duration' element returns),
 *     and an integer 0 or more for completionminattempts (0 = off).
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class change_request {
    /** @var array key => normalised requested value, for the changed keys only (review keys: bitmask). */
    protected array $values = [];

    /** @var string|null grade to pass type (settings_catalogue::GRADEPASS_*), when gradepass is changed. */
    protected ?string $gradepasstype = null;

    /**
     * Use {@see from_form_data()} or {@see from_array()}.
     *
     * @param array $values key => normalised value
     * @param string|null $gradepasstype
     */
    protected function __construct(array $values, ?string $gradepasstype) {
        $this->values = $values;
        $this->gradepasstype = $gradepasstype;
    }

    /**
     * Build a request from the settings form data (see the class docblock for the field names).
     *
     * @param \stdClass $data form data, as returned by moodleform::get_data()
     * @return self
     * @throws \invalid_parameter_exception if a ticked setting has a missing or invalid value
     */
    public static function from_form_data(\stdClass $data): self {
        $data = (array) $data;
        $errors = self::validate_form_data($data);
        if ($errors) {
            throw new \invalid_parameter_exception('Invalid quiz settings: ' . implode(', ', array_keys($errors)));
        }
        $values = [];
        $gradepasstype = null;
        foreach (settings_catalogue::get_keys() as $key) {
            if (empty($data['change_' . $key])) {
                continue;
            }
            $type = settings_catalogue::get_type($key);
            if ($type === settings_catalogue::TYPE_REVIEW) {
                $bitmask = 0;
                foreach (settings_catalogue::REVIEW_TIMES as $time => $bit) {
                    if (!empty($data[$key . '_' . $time])) {
                        $bitmask |= $bit;
                    }
                }
                $values[$key] = $bitmask;
            } else if ($type === settings_catalogue::TYPE_GRADEPASS) {
                $gradepasstype = (string) $data['gradepasstype'];
                $values[$key] = self::to_float($data['gradepass']);
            } else if ($type === settings_catalogue::TYPE_FLOAT) {
                $values[$key] = self::to_float($data[$key]);
            } else {
                $values[$key] = settings_catalogue::normalise($key, $data[$key]);
            }
        }
        return new self($values, $gradepasstype);
    }

    /**
     * Build a request from a plain array (for tests and scripts).
     *
     * Keys are catalogue keys. Review keys take either an int bitmask or an array
     * of time name => 0/1 (missing times = 0). gradepass takes
     * ['type' => 'absolute'|'percent', 'value' => float].
     *
     * Example: ['maxgrade' => 20, 'gradepass' => ['type' => 'percent', 'value' => 50],
     * 'review_marks' => ['immediately' => 1, 'closed' => 1], 'completion' => 2].
     *
     * @param array $changes key => value
     * @return self
     * @throws \invalid_parameter_exception on an unknown key or invalid value
     */
    public static function from_array(array $changes): self {
        $data = [];
        foreach ($changes as $key => $value) {
            if (!settings_catalogue::is_key($key)) {
                throw new \invalid_parameter_exception('Unknown quiz setting key: ' . $key);
            }
            $data['change_' . $key] = 1;
            $type = settings_catalogue::get_type($key);
            if ($type === settings_catalogue::TYPE_REVIEW) {
                foreach (settings_catalogue::REVIEW_TIMES as $time => $bit) {
                    $data[$key . '_' . $time] = is_array($value) ? (int) !empty($value[$time]) : (int) (bool) ($value & $bit);
                }
            } else if ($type === settings_catalogue::TYPE_GRADEPASS) {
                if (!is_array($value) || !array_key_exists('type', $value) || !array_key_exists('value', $value)) {
                    throw new \invalid_parameter_exception('gradepass needs [type, value]');
                }
                $data['gradepasstype'] = $value['type'];
                $data['gradepass'] = $value['value'];
            } else {
                $data[$key] = $value;
            }
        }
        return self::from_form_data((object) $data);
    }

    /**
     * Validate settings form data: for moodleform::validation() and {@see from_form_data()}.
     *
     * @param array $data submitted form data (see the class docblock for the field names)
     * @return array form element name => error message; empty when valid
     */
    public static function validate_form_data(array $data): array {
        $errors = [];
        foreach (settings_catalogue::get_keys() as $key) {
            if (empty($data['change_' . $key])) {
                continue;
            }
            $type = settings_catalogue::get_type($key);
            switch ($type) {
                case settings_catalogue::TYPE_REVIEW:
                    foreach (settings_catalogue::REVIEW_TIMES as $time => $notused) {
                        $field = $key . '_' . $time;
                        if (isset($data[$field]) && !in_array((string) $data[$field], ['0', '1', ''], true)) {
                            $errors[$field] = get_string('error_invalidvalue', 'tool_quizbulkedit');
                        }
                    }
                    break;
                case settings_catalogue::TYPE_FLOAT:
                    $value = self::to_float($data[$key] ?? null);
                    if ($value === null || $value <= 0 || $value > settings_catalogue::GRADE_LIMIT) {
                        $errors[$key] = get_string('error_maxgrade', 'tool_quizbulkedit');
                    }
                    break;
                case settings_catalogue::TYPE_GRADEPASS:
                    $gradepasstype = $data['gradepasstype'] ?? null;
                    $value = self::to_float($data['gradepass'] ?? null);
                    if ($gradepasstype === settings_catalogue::GRADEPASS_PERCENT) {
                        if ($value === null || $value < 0 || $value > 100) {
                            $errors['gradepass'] = get_string('error_gradepasspercent', 'tool_quizbulkedit');
                        }
                    } else if ($gradepasstype === settings_catalogue::GRADEPASS_ABSOLUTE) {
                        if ($value === null || $value < 0 || $value > settings_catalogue::GRADE_LIMIT) {
                            $errors['gradepass'] = get_string('error_gradepassabsolute', 'tool_quizbulkedit');
                        }
                    } else {
                        $errors['gradepasstype'] = get_string('error_invalidvalue', 'tool_quizbulkedit');
                    }
                    break;
                case settings_catalogue::TYPE_DURATION:
                case settings_catalogue::TYPE_INT:
                    if (!self::is_non_negative_int($data[$key] ?? null)) {
                        $errors[$key] = get_string('error_notnegativeint', 'tool_quizbulkedit');
                    }
                    break;
                case settings_catalogue::TYPE_SELECT:
                case settings_catalogue::TYPE_YESNO:
                    $value = $data[$key] ?? null;
                    if (
                        $value === null || !is_scalar($value)
                        || !array_key_exists((string) $value, self::string_keys(settings_catalogue::get_choices($key)))
                    ) {
                        $errors[$key] = get_string('error_invalidvalue', 'tool_quizbulkedit');
                    }
                    break;
            }
        }
        return $errors;
    }

    /**
     * Re-key a choice list by string keys, so lookups do not depend on PHP's key casting.
     *
     * @param array $choices
     * @return array
     */
    protected static function string_keys(array $choices): array {
        $result = [];
        foreach ($choices as $value => $label) {
            $result[(string) $value] = $label;
        }
        return $result;
    }

    /**
     * Whether a form value is a whole number of 0 or more (int, integral float or digit string).
     *
     * @param mixed $value
     * @return bool
     */
    protected static function is_non_negative_int($value): bool {
        if (is_int($value)) {
            return $value >= 0;
        }
        if (is_float($value)) {
            return is_finite($value) && $value >= 0 && floor($value) == $value && $value <= PHP_INT_MAX;
        }
        return is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1;
    }

    /**
     * Read a float from form data (already a float from a 'float' element, or a localised string).
     *
     * @param mixed $value
     * @return float|null null when not a finite number
     */
    protected static function to_float($value): ?float {
        if ($value === null || $value === '' || is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }
        if (!is_float($value) && !is_int($value)) {
            $value = unformat_float((string) $value, true);
            if ($value === false || $value === null) {
                return null;
            }
        }
        $value = (float) $value;
        return is_finite($value) ? $value : null;
    }

    /**
     * Whether a setting was asked to change.
     *
     * @param string $key a catalogue key
     * @return bool
     */
    public function is_changed(string $key): bool {
        return array_key_exists($key, $this->values);
    }

    /**
     * The requested value of a changed setting.
     *
     * @param string $key a catalogue key
     * @return mixed normalised value (review keys: the requested bitmask before planner rules)
     */
    public function get(string $key) {
        if (!$this->is_changed($key)) {
            throw new \coding_exception('Quiz setting not in this request: ' . $key);
        }
        return $this->values[$key];
    }

    /**
     * The keys asked to change, in catalogue order.
     *
     * @return string[]
     */
    public function get_changed_keys(): array {
        return array_values(array_filter(settings_catalogue::get_keys(), fn($key) => $this->is_changed($key)));
    }

    /**
     * Whether nothing was asked to change.
     *
     * @return bool
     */
    public function is_empty(): bool {
        return !$this->values;
    }

    /**
     * The grade to pass type, when gradepass is changed.
     *
     * @return string|null settings_catalogue::GRADEPASS_ABSOLUTE or GRADEPASS_PERCENT
     */
    public function get_gradepass_type(): ?string {
        return $this->gradepasstype;
    }

    /**
     * Whether any review options row is changed.
     *
     * @return bool
     */
    public function has_review_changes(): bool {
        foreach (array_keys($this->values) as $key) {
            if (settings_catalogue::is_review_key($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any completion setting is changed.
     *
     * @return bool
     */
    public function has_completion_changes(): bool {
        foreach (array_keys($this->values) as $key) {
            if (settings_catalogue::is_completion_key($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A canonical array form of the request (stable order), for fingerprints.
     *
     * @return array
     */
    public function to_array(): array {
        $result = [];
        foreach ($this->get_changed_keys() as $key) {
            $result[$key] = $this->values[$key];
        }
        if ($this->gradepasstype !== null) {
            $result['gradepasstype'] = $this->gradepasstype;
        }
        return $result;
    }
}
