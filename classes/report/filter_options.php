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
 * Builds the filter <select> with optgroups (top categories),
 *  listing only categories/courses the current user has privilege for.
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\report;

use context_course;
use context_coursecat;
use Exception;

/**
 * Builds the filter <select> with optgroups (top categories),
 * listing only categories/courses the current user has privilege for.
 */
class filter_options {
    /**
     * Build template context for the filter.
     *
     * @param string $selectedraw Selected value from request (course param)
     * @return array
     * @throws Exception
     */
    public static function get_template_context(string $selectedraw): array {
        global $DB, $USER;

        // Fetch categories.
        $categories = $DB->get_records("course_categories", null, "sortorder ASC", "id,name,parent,depth,path");

        // Fetch courses (exclude site course id=1).
        $courses = $DB->get_records_select(
            "course",
            "id <> 1 AND visible = 1",
            [],
            "sortorder ASC",
            "id,fullname,category,visible"
        );

        // Index categories by id.
        $catbyid = [];
        foreach ($categories as $c) {
            $catbyid[$c->id] = $c;
        }

        // Helper: get top/root category id from path "/1/2/3".
        $getrootid = static function(string $path): int {
            $path = trim($path, "/");
            if ($path === "") {
                return 0;
            }

            $parts = explode("/", $path);
            return (int)($parts[0] ?? 0);
        };

        // Determine which categories are selectable.
        $catselectable = [];
        foreach ($categories as $cat) {
            $ctx = context_coursecat::instance($cat->id, IGNORE_MISSING);
            if ($ctx && has_capability("moodle/category:manage", $ctx, $USER)) {
                $catselectable[$cat->id] = true;
            }
        }

        // Determine which courses are selectable.
        $courseselectable = [];
        foreach ($courses as $course) {
            $ctx = context_course::instance($course->id, IGNORE_MISSING);
            if ($ctx && (
                    has_capability("moodle/course:viewparticipants", $ctx, $USER) ||
                    has_capability("moodle/course:update", $ctx, $USER)
                )) {
                $courseselectable[$course->id] = true;
            }
        }

        // Build items grouped by root category.
        $groups = [];

        // Pre-group categories by root.
        $catsbyroot = [];
        foreach ($categories as $cat) {
            $rootid = $getrootid($cat->path);
            if (!$rootid) {
                continue;
            }
            $catsbyroot[$rootid][] = $cat->id;
        }

        // Pre-group courses by category.
        $coursesbycat = [];
        foreach ($courses as $course) {
            $coursesbycat[$course->category][] = $course;
        }

        // Build each optgroup using each root category present.
        foreach ($catsbyroot as $rootid => $catids) {
            if (empty($catbyid[$rootid])) {
                continue;
            }

            $optgroupitems = [];

            foreach ($catids as $catid) {
                $cat = $catbyid[$catid] ?? null;
                if (!$cat) {
                    continue;
                }

                // Add category option if selectable.
                if (!empty($catselectable[$catid])) {
                    $indent = str_repeat("&nbsp;&nbsp;&nbsp;&nbsp;", max(0, ($cat->depth - $catbyid[$rootid]->depth)));
                    $optgroupitems[] = [
                        "value" => "cat-" . $catid,
                        "text" => $indent . "Category: " . htmlspecialchars($cat->name),
                        "selected" => ($selectedraw === "cat-" . $catid),
                    ];
                }

                // Add courses for this category if selectable.
                if (!empty($coursesbycat[$catid])) {
                    foreach ($coursesbycat[$catid] as $course) {
                        $cid = (int)$course->id;

                        if (empty($courseselectable[$cid]) && empty($catselectable[$catid])) {
                            continue;
                        }

                        $indent = str_repeat(
                            "&nbsp;&nbsp;&nbsp;&nbsp;",
                            max(0, ($cat->depth - $catbyid[$rootid]->depth + 1))
                        );

                        $optgroupitems[] = [
                            "value" => (string)$cid,
                            "text" => $indent . htmlspecialchars($course->fullname),
                            "selected" => ($selectedraw === (string)$cid),
                        ];
                    }
                }
            }

            if (!$optgroupitems) {
                continue;
            }

            $groups[] = [
                "label" => $catbyid[$rootid]->name,
                "options" => $optgroupitems,
            ];
        }

        return [
            "selectedraw" => $selectedraw,
            "groups" => $groups,
        ];
    }
}
