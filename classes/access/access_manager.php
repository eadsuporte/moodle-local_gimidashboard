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
 * access_manager.php
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\access;

use coding_exception;
use context_course;
use context_system;
use dml_exception;
use stdClass;

/**
 * Resolves the courses and categories visible to the current user.
 *
 * @package   local_gimidashboard
 */
class access_manager {
    /**
     * Returns true when the user can access the plugin for a specific course.
     *
     * @param int $courseid Course id.
     * @param int|null $userid User id.
     * @return bool
     * @throws coding_exception
     */
    public static function user_has_course_access(int $courseid, ?int $userid = null): bool {
        global $USER;

        $userid = $userid ?: $USER->id;
        $context = context_course::instance($courseid);

        if (!has_capability("local/gimidashboard:view", $context, $userid)) {
            return false;
        }

        $roleids = array_map("intval", config::get_report_capabilities());
        if (empty($roleids)) {
            return false;
        }

        if (is_siteadmin($userid)) {
            return true;
        }

        $userroles = get_user_roles($context, $userid, true, "ra.roleid");
        foreach ($userroles as $userrole) {
            if (in_array($userrole->roleid, $roleids, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the list of courses the user can access based on the configured role IDs.
     *
     * The configuration stores role IDs, not capabilities. A user can access a course when:
     * - They are a site admin
     * - They have one of the configured roles in system context
     * - They have one of the configured roles in a course category context
     * - They have one of the configured roles directly in the course context
     *
     * @param int|null $userid The user ID. When null, the current user is used.
     * @return array The accessible course records indexed by course ID.
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function get_accessible_courses(?int $userid = null): array {
        global $DB, $USER;

        $userid = $userid ?: $USER->id;
        $roleids = array_map("intval", config::get_report_capabilities());

        if (!$roleids) {
            return [];
        }

        if (is_siteadmin($userid)) {
            return $DB->get_records_sql(
                "
            SELECT c.*
              FROM {course} c
             WHERE c.id <> :siteid
          ORDER BY c.sortorder ASC, c.fullname ASC
        ", [
                    "siteid" => SITEID,
                ]
            );
        }

        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, "role");

        $params = [
                "userid" => $userid,
            ] + $roleparams;

        $assignments = $DB->get_records_sql(
            "
        SELECT ra.contextid, ctx.contextlevel, ctx.instanceid
          FROM {role_assignments} ra
          JOIN {context} ctx
            ON ctx.id = ra.contextid
         WHERE ra.userid = :userid
           AND ra.roleid {$rolesql}
    ", $params
        );

        if (!$assignments) {
            return [];
        }

        $hasystemrole = false;
        $courseids = [];
        $categorycontextids = [];

        foreach ($assignments as $assignment) {
            if ($assignment->contextlevel == CONTEXT_SYSTEM) {
                $hasystemrole = true;
                break;
            }

            if ($assignment->contextlevel == CONTEXT_COURSE) {
                $courseids[$assignment->instanceid] = $assignment->instanceid;
                continue;
            }

            if ($assignment->contextlevel == CONTEXT_COURSECAT) {
                $categorycontextids[$assignment->contextid] = $assignment->contextid;
            }
        }

        if ($hasystemrole) {
            return $DB->get_records_sql(
                "
            SELECT c.*
              FROM {course} c
             WHERE c.id <> :siteid
          ORDER BY c.sortorder ASC, c.fullname ASC
        ", [
                    "siteid" => SITEID,
                ]
            );
        }

        if ($categorycontextids) {
            $likeparts = [];
            $likeparams = [
                "courselevel" => CONTEXT_COURSE,
                "siteid" => SITEID,
            ];

            $index = 0;
            foreach ($categorycontextids as $contextid) {
                $paramname = "path{$index}";
                $likeparts[] = $DB->sql_like("ctxcourse.path", ":{$paramname}");
                $likeparams[$paramname] = "%/" . $contextid . "/%";
                $index++;
            }

            $sql = "
            SELECT DISTINCT c.id
              FROM {course} c
              JOIN {context} ctxcourse
                ON ctxcourse.contextlevel = :courselevel
               AND ctxcourse.instanceid = c.id
             WHERE c.id <> :siteid
               AND (" . implode(" OR ", $likeparts) . ")
        ";

            $categorycourses = $DB->get_records_sql($sql, $likeparams);

            foreach ($categorycourses as $course) {
                $courseids[$course->id] = $course->id;
            }
        }

        if (!$courseids) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal(array_values($courseids), SQL_PARAMS_NAMED, "course");

        $sql = "
            SELECT c.*
              FROM {course} c
             WHERE c.id {$coursesql}
               AND c.id <> :siteid
          ORDER BY c.sortorder ASC, c.fullname ASC";
        $courseparams += ["siteid" => SITEID];
        return $DB->get_records_sql($sql, $courseparams);
    }

    /**
     * Returns the accessible courses inside a selected category tree.
     *
     * @param int $categoryid Category id.
     * @param int|null $userid User id.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function get_accessible_courses_for_category(int $categoryid, ?int $userid = null): array {
        global $DB;

        $courses = self::get_accessible_courses($userid);
        if (empty($courses)) {
            return [];
        }

        $categoryids = array_values(array_unique(array_map(static function($course): int {
            return $course->category;
        }, $courses)));

        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
        $categories = $DB->get_records_select("course_categories", "id {$insql}", $params, "", "id, path");

        $filtered = [];
        foreach ($courses as $course) {
            $coursecategoryid = $course->category;
            if (empty($categories[$coursecategoryid])) {
                continue;
            }

            $path = "/" . trim($categories[$coursecategoryid]->path, "/") . "/";
            if (strpos($path, "/{$categoryid}/") === false) {
                continue;
            }

            $filtered[$course->id] = $course;
        }

        return $filtered;
    }

    /**
     * Returns select options for categories and courses preserving the category tree.
     *
     * @param int|null $userid User id.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function get_selector_options(?int $userid = null): array {
        global $DB;

        $courses = self::get_accessible_courses($userid);
        if (empty($courses)) {
            return [];
        }

        $categoryids = [];
        foreach ($courses as $course) {
            $categoryids[$course->category] = $course->category;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_values($categoryids), SQL_PARAMS_NAMED);
        $selectedcategories = $DB->get_records_select(
            "course_categories",
            "id {$insql}",
            $params,
            "sortorder ASC, id ASC",
            "id, parent, name, path, depth, sortorder"
        );

        $allcategoryids = [];
        foreach ($selectedcategories as $category) {
            foreach (explode("/", trim($category->path, "/")) as $pathid) {
                if ($pathid !== "") {
                    $allcategoryids[(int) $pathid] = (int) $pathid;
                }
            }
        }

        if (empty($allcategoryids)) {
            return [];
        }

        [$allinsql, $allparams] = $DB->get_in_or_equal(array_values($allcategoryids), SQL_PARAMS_NAMED);
        $allcategories = $DB->get_records_select(
            "course_categories",
            "id {$allinsql}",
            $allparams,
            "sortorder ASC, id ASC",
            "id, parent, name, path, depth, sortorder"
        );

        $visiblecategories = [];
        foreach ($selectedcategories as $category) {
            foreach (explode("/", trim($category->path, "/")) as $pathid) {
                $pathid = (int) $pathid;
                if (!empty($allcategories[$pathid])) {
                    $visiblecategories[$pathid] = $allcategories[$pathid];
                }
            }
        }

        $children = [];
        foreach ($visiblecategories as $category) {
            $parentid = (int) $category->parent;
            if (empty($visiblecategories[$parentid])) {
                $parentid = 0;
            }
            if (!isset($children[$parentid])) {
                $children[$parentid] = [];
            }
            $children[$parentid][] = $category;
        }

        foreach ($children as $parentid => $items) {
            usort($items, static function(stdClass $a, stdClass $b): int {
                $sortordercomparison = $a->sortorder <=> $b->sortorder;
                if ($sortordercomparison !== 0) {
                    return $sortordercomparison;
                }

                return strcmp(
                    strtolower($a->name),
                    strtolower($b->name)
                );
            });
            $children[$parentid] = $items;
        }

        $coursesbycategory = [];
        foreach ($courses as $course) {
            if (!isset($coursesbycategory[$course->category])) {
                $coursesbycategory[$course->category] = [];
            }
            $coursesbycategory[$course->category][] = $course;
        }

        foreach ($coursesbycategory as $categoryid => $categorycourses) {
            usort($categorycourses, static function(stdClass $a, stdClass $b): int {
                $sortordercomparison = $a->sortorder <=> $b->sortorder;
                if ($sortordercomparison !== 0) {
                    return $sortordercomparison;
                }

                return strcmp(
                    strtolower($a->fullname),
                    strtolower($b->fullname)
                );
            });
            $coursesbycategory[$categoryid] = $categorycourses;
        }

        $options = [];
        $appendcategory =
            static function(stdClass $category, int $level) use (&$appendcategory, &$options, $children, $coursesbycategory): void {
                $prefix = str_repeat("&nbsp;&nbsp;&nbsp;&nbsp;", max(0, $level));
                $prefixtitle = get_string("selectioncategory", "local_gimidashboard");
                $name = format_string($category->name, true, ["context" => context_system::instance()]);
                $options[] = [
                    "value" => "category-{$category->id}",
                    "name" => "{$prefix}{$prefixtitle}: {$name}",
                    "selected" => false,
                ];

                if (!empty($coursesbycategory[$category->id])) {
                    foreach ($coursesbycategory[$category->id] as $course) {
                        $name = format_string($course->fullname, true, ["context" => context_course::instance($course->id)]);
                        $options[] = [
                            "value" => "course-{$course->id}",
                            "name" => "{$prefix}&nbsp;&nbsp;&nbsp;&nbsp;{$name}",
                            "selected" => false,
                        ];
                    }
                }

                if (!empty($children[$category->id])) {
                    foreach ($children[$category->id] as $childcategory) {
                        $appendcategory($childcategory, $level + 1);
                    }
                }
            };

        foreach ($children[0] ?? [] as $rootcategory) {
            $appendcategory($rootcategory, 0);
        }

        return $options;
    }
}
