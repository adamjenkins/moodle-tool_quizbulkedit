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
 * Named configurations of the settings form: per course, or site-wide admin presets.
 *
 * A configuration is a JSON snapshot of the settings form's fields (every Change box and
 * value, so loading restores the form as it was) and, for a course configuration, the
 * ticked quizzes. Admin presets (courseid 0) hold no quizzes. Saving and loading write
 * nothing to any quiz: a loaded configuration is only put into the form, and still goes
 * through Preview and Apply changes, with the form's validation, like typed values.
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class saved_configs {
    /** @var string the table. */
    const TABLE = 'tool_quizbulkedit_config';

    /** @var int the courseid of admin presets. */
    const PRESETS = 0;

    /** @var int the snapshot format. */
    const VERSION = 1;

    /** @var int the longest field value kept. */
    const MAXVALUE = 64;

    /**
     * The configurations of a course (or the admin presets), by name.
     *
     * @param int $courseid the course id, or PRESETS
     * @return \stdClass[] id, courseid, name, timemodified, keyed by id
     */
    public static function list(int $courseid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['courseid' => $courseid], 'name ASC, id ASC', 'id, courseid, name, timemodified');
    }

    /**
     * One configuration of a course (or one admin preset).
     *
     * @param int $courseid the course id, or PRESETS
     * @param int $id the configuration id, which must belong to that course (or be a preset)
     * @return \stdClass
     * @throws \dml_missing_record_exception when there is no such configuration there
     */
    public static function get(int $courseid, int $id): \stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id, 'courseid' => $courseid], '*', MUST_EXIST);
    }

    /**
     * Clean a configuration name: plain text, trimmed, at most 255 characters.
     *
     * @param string $name the name as typed
     * @return string the name, '' when nothing is left
     */
    public static function clean_name(string $name): string {
        return trim(\core_text::substr(trim(clean_param($name, PARAM_TEXT)), 0, 255));
    }

    /**
     * Save a snapshot under a name, replacing the configuration of that name there.
     *
     * @param int $courseid the course id, or PRESETS
     * @param string $name the name, already cleaned (see clean_name())
     * @param array $snapshot from snapshot()
     * @param int $id when > 0, the configuration to overwrite (renaming it); it must belong there
     * @return bool true when an existing configuration was replaced
     */
    public static function save(int $courseid, string $name, array $snapshot, int $id = 0): bool {
        global $DB;
        $now = time();
        $data = json_encode($snapshot);
        $existing = $id ? self::get($courseid, $id) : $DB->get_record(self::TABLE, ['courseid' => $courseid, 'name' => $name]);
        if ($existing) {
            // Renaming onto another configuration's name replaces that one.
            $clash = $DB->get_record(self::TABLE, ['courseid' => $courseid, 'name' => $name]);
            if ($clash && (int) $clash->id !== (int) $existing->id) {
                $DB->delete_records(self::TABLE, ['id' => $clash->id]);
            }
            $DB->update_record(self::TABLE, (object) [
                'id' => $existing->id,
                'name' => $name,
                'data' => $data,
                'timemodified' => $now,
            ]);
            return true;
        }
        $DB->insert_record(self::TABLE, (object) [
            'courseid' => $courseid,
            'name' => $name,
            'data' => $data,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return false;
    }

    /**
     * Delete one configuration of a course (or one admin preset).
     *
     * @param int $courseid the course id, or PRESETS
     * @param int $id the configuration id, which must belong there
     * @return \stdClass the deleted record
     */
    public static function delete(int $courseid, int $id): \stdClass {
        global $DB;
        $record = self::get($courseid, $id);
        $DB->delete_records(self::TABLE, ['id' => $record->id]);
        return $record;
    }

    /**
     * The settings form's field names a snapshot keeps: every Change box and value field.
     *
     * Follows the form-field contract of {@see change_request}.
     *
     * @return string[]
     */
    public static function field_names(): array {
        $names = [];
        foreach (settings_catalogue::get_keys() as $key) {
            $names[] = 'change_' . $key;
            $type = settings_catalogue::get_type($key);
            if ($type === settings_catalogue::TYPE_REVIEW) {
                foreach (array_keys(settings_catalogue::REVIEW_TIMES) as $time) {
                    $names[] = $key . '_' . $time;
                }
            } else if ($type === settings_catalogue::TYPE_GRADEPASS) {
                $names[] = 'gradepasstype';
                $names[] = 'gradepass';
            } else {
                $names[] = $key;
            }
        }
        return $names;
    }

    /**
     * The snapshot of the settings form (and the ticked quizzes).
     *
     * @param \stdClass $formdata the settings form's data (submitted or validated)
     * @param int[] $cmids the ticked quizzes (already filtered to the eligible ones); [] for a preset
     * @return array
     */
    public static function snapshot(\stdClass $formdata, array $cmids = []): array {
        $fields = [];
        foreach (self::field_names() as $name) {
            $value = $formdata->$name ?? null;
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $fields[$name] = $value;
            } else if (is_string($value) && \core_text::strlen($value) <= self::MAXVALUE) {
                $fields[$name] = $value;
            }
        }
        return [
            'version' => self::VERSION,
            'fields' => $fields,
            'cmids' => array_values(array_unique(array_map('intval', $cmids))),
        ];
    }

    /**
     * Decode a configuration's snapshot, keeping only known scalar fields.
     *
     * @param \stdClass $record a configuration record
     * @return array fields (name => scalar) and cmids (int[])
     */
    public static function decode(\stdClass $record): array {
        $data = json_decode((string) $record->data, true);
        $data = is_array($data) ? $data : [];
        $stored = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $fields = [];
        foreach (self::field_names() as $name) {
            if (array_key_exists($name, $stored) && is_scalar($stored[$name])) {
                $value = $stored[$name];
                $fields[$name] = is_string($value) ? \core_text::substr($value, 0, self::MAXVALUE) : $value;
            }
        }
        $cmids = [];
        foreach (is_array($data['cmids'] ?? null) ? $data['cmids'] : [] as $cmid) {
            if ((is_int($cmid) && $cmid > 0) || (is_string($cmid) && ctype_digit($cmid) && (int) $cmid > 0)) {
                $cmids[] = (int) $cmid;
            }
        }
        return ['fields' => $fields, 'cmids' => $cmids];
    }
}
