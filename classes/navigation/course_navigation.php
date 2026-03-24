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
 * course_navigation.php
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\navigation;


use coding_exception;
use context_course;
use core\exception\moodle_exception;
use local_gimidashboard\access\access_manager;
use moodle_url;
use navigation_node;
use stdClass;

/**
 * Adds the dashboard link to course navigation.
 *
 * @package   local_gimidashboard
 */
class course_navigation {
    /**
     * Adds the course navigation node when the user has access.
     *
     * @param navigation_node $navigation Course navigation node.
     * @param stdClass $course Course record.
     * @param context_course $context Course context.
     * @return void
     * @throws coding_exception
     * @throws moodle_exception
     */
    public static function extend(navigation_node $navigation, \stdClass $course, context_course $context): void {
        if (!access_manager::user_has_course_access($course->id)) {
            return;
        }

        $navigation->add(
            get_string("pluginname", "local_gimidashboard"),
            new moodle_url("/local/gimidashboard/view.php", ["target" => "course:" . $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            "local_gimidashboard"
        );
    }
}
