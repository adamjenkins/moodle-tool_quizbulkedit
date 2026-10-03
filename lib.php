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
 * Library of interface functions for tool_quizbulkedit.
 *
 * @package    tool_quizbulkedit
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add a "Bulk edit quizzes" link to the course administration navigation for
 * users who can bulk edit quiz settings in this course.
 *
 * @param navigation_node $navigation the navigation node to extend
 * @param stdClass $course the course to extend navigation for
 * @param context_course $context the context of the course
 * @return void
 */
function tool_quizbulkedit_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
) {
    if (!has_capability('tool/quizbulkedit:manage', $context)) {
        return;
    }
    $url = new moodle_url('/admin/tool/quizbulkedit/index.php', ['courseid' => $course->id]);
    $navigation->add(
        get_string('pluginname', 'tool_quizbulkedit'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'tool_quizbulkedit',
        new pix_icon('i/settings', '')
    );
}
