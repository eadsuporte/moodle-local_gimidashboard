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
 * category_path_formatter.php
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\access;


use context_system;

/**
 * Formats category names including their full path.
 *
 * @package   local_gimidashboard
 */
class category_path_formatter {
    /**
     * Returns full labels indexed by category id.
     *
     * @param array $categoryids Category ids.
     * @return array
     */
    public static function get_labels(array $categoryids): array {
        global $DB;

        $categoryids = array_values(array_unique(array_map("intval", $categoryids)));
        if (empty($categoryids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
        $selected = $DB->get_records_select("course_categories", "id {$insql}", $params, "", "id, name, path");

        $allids = [];
        foreach ($selected as $category) {
            foreach (explode("/", trim( $category->path, "/")) as $pathid) {
                if ($pathid !== "") {
                    $allids[ $pathid] =  $pathid;
                }
            }
        }

        if (empty($allids)) {
            return [];
        }

        [$allinsql, $allparams] = $DB->get_in_or_equal(array_values($allids), SQL_PARAMS_NAMED);
        $allcategories = $DB->get_records_select("course_categories", "id {$allinsql}", $allparams, "", "id, name");

        $labels = [];
        foreach ($selected as $category) {
            $parts = [];
            foreach (explode("/", trim( $category->path, "/")) as $pathid) {
                $pathid =  $pathid;
                if (!empty($allcategories[$pathid])) {
                    $parts[] = format_string($allcategories[$pathid]->name, true, ["context" => context_system::instance()]);
                }
            }
            $labels[ $category->id] = implode(" / ", $parts);
        }

        return $labels;
    }
}
