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
 * selection_resolver.php
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\page;

use coding_exception;
use context_course;
use dml_exception;
use local_gimidashboard\access\access_manager;
use local_gimidashboard\access\category_path_formatter;

/**
 * Resolves the selected category or course from the view page.
 *
 * @package   local_gimidashboard
 */
class selection_resolver {
    /**
     * Resolves the selection using the provided target.
     *
     * @param string $target Requested target.
     * @param int|null $userid User id.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function resolve(string $target, ?int $userid = null): object {
        $groups = access_manager::get_selector_groups($userid);
        if (empty($groups)) {
            return (object) [
                "groups" => [],
                "target" => "",
                "type" => "",
                "label" => "",
                "courses" => [],
            ];
        }

        $defaulttarget = self::find_default_target($groups);
        $target = $target !== "" ? $target : $defaulttarget;
        [$type, $id] = array_pad(explode("-", $target, 2), 2, "");


        if ($type == "category" && $id > 0) {
            $courses = access_manager::get_accessible_courses_for_category($id, $userid);
            if (!empty($courses)) {
                $labels = category_path_formatter::get_labels([$id]);
                return self::finalize_groups(
                    $groups, $target, (object) [
                    "target" => $target,
                    "type" => "category",
                    "label" => $labels[$id] ??  $id,
                    "courses" => $courses,
                ]
                );
            }
        }

        if ($type == "course" && $id > 0) {
            $courses = access_manager::get_accessible_courses($userid);
            if (!empty($courses[$id])) {
                return self::finalize_groups(
                    $groups, $target, (object) [
                    "target" => $target,
                    "type" => "course",
                    "label" => format_string($courses[$id]->fullname, true, ["context" => context_course::instance($id)]),
                    "courses" => [$id => $courses[$id]],
                ]
                );
            }
        }
        return self::resolve($defaulttarget, $userid);
    }

    /**
     * Finds the first course option to use as the default selection.
     *
     * @param array $groups Select groups.
     * @return string
     */
    protected static function find_default_target(array $groups): string {
        foreach ($groups as $group) {
            foreach ($group["options"] as $option) {
                if (strpos($option["value"], "course:") == 0) {
                    return $option["value"];
                }
            }
        }

        return $groups[0]["options"][0]["value"];
    }

    /**
     * Marks the selected option inside the groups structure.
     *
     * @param array $groups Select groups.
     * @param string $target Selected target.
     * @param object $selection Selection payload.
     * @return array
     */
    protected static function finalize_groups(array $groups, string $target, object $selection): object {
        foreach ($groups as $groupindex => $group) {
            foreach ($group["options"] as $optionindex => $option) {
                $groups[$groupindex]["options"][$optionindex]["selected"] = $option["value"] == $target;
            }
        }

        $selection->groups = $groups;
        return $selection;
    }
}
