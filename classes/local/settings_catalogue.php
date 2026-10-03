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

use mod_quiz\access_manager;
use mod_quiz\question\display_options;

/**
 * The catalogue of quiz settings this tool can change (design spec section 5).
 *
 * Every key is individually settable: the form carries one "Change" checkbox
 * per key, and only ticked keys are planned and written.
 *
 * Keys and where they are stored:
 * - maxgrade: quiz.grade (changed through grade_calculator::update_quiz_maximum_grade()).
 * - gradepass: grade item gradepass (absolute, or percentage of each quiz's maximum grade).
 * - the plain quiz columns in QUIZ_COLUMN_KEYS: the quiz column of the same name.
 * - review_<field> for each field in REVIEW_FIELDS: quiz.review<field> bitmask.
 * - completion, completionview, completionpassgrade: course_modules column of the same name.
 * - completionusegrade: course_modules.completiongradeitemnumber (1 = 0, 0 = null).
 * - completionattemptsexhausted, completionminattempts: quiz column of the same name.
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_catalogue {
    /** @var string a positive float (maximum grade). */
    public const TYPE_FLOAT = 'float';
    /** @var string grade to pass: a type (absolute|percent) plus a float. */
    public const TYPE_GRADEPASS = 'gradepass';
    /** @var string one value out of {@see get_choices()}. */
    public const TYPE_SELECT = 'select';
    /** @var string 0 or 1. */
    public const TYPE_YESNO = 'yesno';
    /** @var string a number of seconds, 0 or more. */
    public const TYPE_DURATION = 'duration';
    /** @var string an integer, 0 or more. */
    public const TYPE_INT = 'int';
    /** @var string a review options bitmask made of the four times in REVIEW_TIMES. */
    public const TYPE_REVIEW = 'review';

    /** @var string grade to pass entered as an absolute grade. */
    public const GRADEPASS_ABSOLUTE = 'absolute';
    /** @var string grade to pass entered as a percentage of each quiz's maximum grade. */
    public const GRADEPASS_PERCENT = 'percent';

    /** @var float largest grade the quiz and grade item columns (NUMBER(10,5)) can hold. */
    public const GRADE_LIMIT = 99999.99999;

    /** @var string[] the review fields, in the order of core's quiz form. */
    public const REVIEW_FIELDS = [
        'attempt', 'correctness', 'maxmarks', 'marks',
        'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback',
    ];

    /** @var int[] review time name => display_options bit, in the order of core's quiz form. */
    public const REVIEW_TIMES = [
        'during' => display_options::DURING,
        'immediately' => display_options::IMMEDIATELY_AFTER,
        'open' => display_options::LATER_WHILE_OPEN,
        'closed' => display_options::AFTER_CLOSE,
    ];

    /** @var string[] keys stored in the quiz column of the same name (no follow-up beyond the applier's). */
    public const QUIZ_COLUMN_KEYS = [
        'attempts', 'grademethod', 'decimalpoints', 'questiondecimalpoints',
        'preferredbehaviour', 'canredoquestions', 'shuffleanswers',
        'navmethod', 'timelimit', 'overduehandling', 'graceperiod',
        'showuserpicture', 'showblocks', 'delay1', 'delay2', 'browsersecurity',
    ];

    /** @var string[] completion keys stored in course_modules. */
    public const COMPLETION_CM_KEYS = ['completion', 'completionview', 'completionusegrade', 'completionpassgrade'];

    /** @var string[] completion keys stored in the quiz table. */
    public const COMPLETION_QUIZ_KEYS = ['completionattemptsexhausted', 'completionminattempts'];

    /** @var array[] form section => keys, in display order. */
    protected const GROUPS = [
        'grade' => ['maxgrade', 'gradepass', 'attempts', 'grademethod'],
        'review' => [
            'review_attempt', 'review_correctness', 'review_maxmarks', 'review_marks',
            'review_specificfeedback', 'review_generalfeedback', 'review_rightanswer', 'review_overallfeedback',
        ],
        'behaviour' => ['shuffleanswers', 'preferredbehaviour', 'canredoquestions'],
        'layouttiming' => ['navmethod', 'timelimit', 'overduehandling', 'graceperiod'],
        'display' => [
            'decimalpoints', 'questiondecimalpoints', 'showuserpicture', 'showblocks',
            'delay1', 'delay2', 'browsersecurity',
        ],
        'completion' => [
            'completion', 'completionview', 'completionusegrade', 'completionpassgrade',
            'completionattemptsexhausted', 'completionminattempts',
        ],
    ];

    /** @var string[] key => type, for every key that is not a review key. */
    protected const TYPES = [
        'maxgrade' => self::TYPE_FLOAT,
        'gradepass' => self::TYPE_GRADEPASS,
        'attempts' => self::TYPE_SELECT,
        'grademethod' => self::TYPE_SELECT,
        'decimalpoints' => self::TYPE_SELECT,
        'questiondecimalpoints' => self::TYPE_SELECT,
        'preferredbehaviour' => self::TYPE_SELECT,
        'canredoquestions' => self::TYPE_YESNO,
        'shuffleanswers' => self::TYPE_YESNO,
        'navmethod' => self::TYPE_SELECT,
        'timelimit' => self::TYPE_DURATION,
        'overduehandling' => self::TYPE_SELECT,
        'graceperiod' => self::TYPE_DURATION,
        'showuserpicture' => self::TYPE_SELECT,
        'showblocks' => self::TYPE_YESNO,
        'delay1' => self::TYPE_DURATION,
        'delay2' => self::TYPE_DURATION,
        'browsersecurity' => self::TYPE_SELECT,
        'completion' => self::TYPE_SELECT,
        'completionview' => self::TYPE_YESNO,
        'completionusegrade' => self::TYPE_YESNO,
        'completionpassgrade' => self::TYPE_YESNO,
        'completionattemptsexhausted' => self::TYPE_YESNO,
        'completionminattempts' => self::TYPE_INT,
    ];

    /**
     * Load the core libraries the choice lists and formatting need.
     */
    protected static function require_libs(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
    }

    /**
     * All keys, in display order.
     *
     * @return string[]
     */
    public static function get_keys(): array {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * Form sections and their keys, in display order.
     *
     * @return array section name => string[] keys
     */
    public static function get_groups(): array {
        return self::GROUPS;
    }

    /**
     * Whether a key is in the catalogue.
     *
     * @param string $key
     * @return bool
     */
    public static function is_key(string $key): bool {
        return in_array($key, self::get_keys(), true);
    }

    /**
     * Whether a key is a review options row.
     *
     * @param string $key
     * @return bool
     */
    public static function is_review_key(string $key): bool {
        return str_starts_with($key, 'review_') && in_array(substr($key, 7), self::REVIEW_FIELDS, true);
    }

    /**
     * Whether a key is a completion setting.
     *
     * @param string $key
     * @return bool
     */
    public static function is_completion_key(string $key): bool {
        return in_array($key, self::COMPLETION_CM_KEYS, true) || in_array($key, self::COMPLETION_QUIZ_KEYS, true);
    }

    /**
     * The quiz table column a key is stored in, or null for keys stored elsewhere.
     *
     * @param string $key
     * @return string|null
     */
    public static function get_quiz_column(string $key): ?string {
        if (self::is_review_key($key)) {
            return 'review' . substr($key, 7);
        }
        if (in_array($key, self::QUIZ_COLUMN_KEYS, true) || in_array($key, self::COMPLETION_QUIZ_KEYS, true)) {
            return $key;
        }
        if ($key === 'maxgrade') {
            return 'grade';
        }
        return null;
    }

    /**
     * The input type of a key (one of the TYPE_ constants).
     *
     * @param string $key
     * @return string
     */
    public static function get_type(string $key): string {
        if (self::is_review_key($key)) {
            return self::TYPE_REVIEW;
        }
        if (!isset(self::TYPES[$key])) {
            throw new \coding_exception('Unknown quiz setting key: ' . $key);
        }
        return self::TYPES[$key];
    }

    /**
     * The label of a key.
     *
     * @param string $key
     * @return string
     */
    public static function get_label(string $key): string {
        if (!self::is_key($key)) {
            throw new \coding_exception('Unknown quiz setting key: ' . $key);
        }
        return get_string('setting_' . $key, 'tool_quizbulkedit');
    }

    /**
     * The choices offered for a select or yes/no key: the same as core's quiz form.
     *
     * @param string $key
     * @param string $currentbehaviour for preferredbehaviour: a behaviour to keep in the list
     *      even if it is disabled site-wide (as core's form does for a quiz's current behaviour).
     * @return array value => label
     */
    public static function get_choices(string $key, string $currentbehaviour = ''): array {
        self::require_libs();
        $type = self::get_type($key);
        if ($type === self::TYPE_YESNO) {
            return [0 => get_string('no'), 1 => get_string('yes')];
        }
        switch ($key) {
            case 'attempts':
                $options = [0 => get_string('unlimited')];
                for ($i = 1; $i <= QUIZ_MAX_ATTEMPT_OPTION; $i++) {
                    $options[$i] = (string) $i;
                }
                return $options;
            case 'grademethod':
                return quiz_get_grading_options();
            case 'decimalpoints':
                $options = [];
                for ($i = 0; $i <= QUIZ_MAX_DECIMAL_OPTION; $i++) {
                    $options[$i] = (string) $i;
                }
                return $options;
            case 'questiondecimalpoints':
                $options = [-1 => get_string('sameasoverall', 'quiz')];
                for ($i = 0; $i <= QUIZ_MAX_Q_DECIMAL_OPTION; $i++) {
                    $options[$i] = (string) $i;
                }
                return $options;
            case 'preferredbehaviour':
                return \question_engine::get_behaviour_options($currentbehaviour);
            case 'navmethod':
                return quiz_get_navigation_options();
            case 'overduehandling':
                return quiz_get_overdue_handling_options();
            case 'showuserpicture':
                return quiz_get_user_image_options();
            case 'browsersecurity':
                return access_manager::get_browser_security_choices();
            case 'completion':
                return [
                    COMPLETION_TRACKING_NONE => get_string('completiontracking_none', 'tool_quizbulkedit'),
                    COMPLETION_TRACKING_MANUAL => get_string('completiontracking_manual', 'tool_quizbulkedit'),
                    COMPLETION_TRACKING_AUTOMATIC => get_string('completiontracking_automatic', 'tool_quizbulkedit'),
                ];
        }
        throw new \coding_exception('Quiz setting key has no choice list: ' . $key);
    }

    /**
     * Normalise a stored or requested value of a key to its canonical PHP type, so
     * that values from the database (strings) and from forms compare reliably.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed float for maxgrade/gradepass, string for string-valued selects, int otherwise
     */
    public static function normalise(string $key, $value) {
        $type = self::get_type($key);
        if ($type === self::TYPE_FLOAT || $type === self::TYPE_GRADEPASS) {
            return (float) $value;
        }
        if (in_array($key, ['preferredbehaviour', 'navmethod', 'overduehandling', 'browsersecurity'], true)) {
            return (string) $value;
        }
        return (int) $value;
    }

    /**
     * Whether two normalised values of a key are the same.
     *
     * Grades are compared with the tolerance core's grade calculator uses.
     *
     * @param string $key
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    public static function values_equal(string $key, $a, $b): bool {
        $type = self::get_type($key);
        if ($type === self::TYPE_FLOAT || $type === self::TYPE_GRADEPASS) {
            return abs((float) $a - (float) $b) < \mod_quiz\grade_calculator::ALMOST_ZERO;
        }
        return self::normalise($key, $a) === self::normalise($key, $b);
    }

    /**
     * A review bitmask as text: the review times it contains.
     *
     * @param int $bitmask
     * @return string
     */
    public static function format_review(int $bitmask): string {
        $parts = [];
        foreach (self::REVIEW_TIMES as $name => $bit) {
            if ($bitmask & $bit) {
                $parts[] = get_string('reviewtime_' . $name, 'tool_quizbulkedit');
            }
        }
        if (!$parts) {
            return get_string('reviewnever', 'tool_quizbulkedit');
        }
        return implode(', ', $parts);
    }

    /**
     * A value of a key as display text (plain text, not escaped).
     *
     * @param string $key
     * @param mixed $value
     * @param int $decimals decimal places for grades
     * @return string
     */
    public static function format_value(string $key, $value, int $decimals = 2): string {
        $type = self::get_type($key);
        switch ($type) {
            case self::TYPE_FLOAT:
            case self::TYPE_GRADEPASS:
                if ($value === null) {
                    return get_string('notset', 'tool_quizbulkedit');
                }
                return format_float((float) $value, $decimals);
            case self::TYPE_REVIEW:
                return self::format_review((int) $value);
            case self::TYPE_DURATION:
                return ((int) $value === 0) ? get_string('durationnone', 'tool_quizbulkedit') : format_time((int) $value);
            case self::TYPE_INT:
                if ($key === 'completionminattempts' && (int) $value === 0) {
                    return get_string('completionminattemptsoff', 'tool_quizbulkedit');
                }
                return (string) (int) $value;
            case self::TYPE_YESNO:
            case self::TYPE_SELECT:
                $choices = self::get_choices($key, $key === 'preferredbehaviour' ? (string) $value : '');
                $normalised = self::normalise($key, $value);
                foreach ($choices as $choice => $label) {
                    if (self::normalise($key, $choice) === $normalised) {
                        return (string) $label;
                    }
                }
                return (string) $value;
        }
        return (string) $value;
    }
}
