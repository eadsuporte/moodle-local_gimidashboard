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
 * Header helper.
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard;

use context_system;
use local_gimidashboard\header_color_manager;
use stdClass;

/**
 * Builds standardized headers for dashboard reports.
 *
 * @package   local_gimidashboard
 */
class header_helper {
    /**
     * Resolves scope data for the current selection.
     *
     * @param object $selection Current selection.
     * @param array $courseids Course ids.
     * @return stdClass
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function get_scope_data(object $selection, array $courseids = []): stdClass {
        global $DB;

        $site = get_site();
        $defaultsitename = format_string($site->fullname, true, ["context" => context_system::instance()]);
        $scope = (object) [
            "level" => "academy",
            "academyname" => $defaultsitename,
            "pathwayname" => "",
            "coursename" => "",
        ];

        $target = (string) ($selection->target ?? "");
        [$type, $id] = array_pad(explode("-", $target, 2), 2, "0");
        $id = (int) $id;

        if (($selection->type ?? "") === "course") {
            $scope->level = "course";
            $scope->coursename = self::clean_text($selection->label ?? "");

            $courseid = $id > 0 ? $id : (int) reset($courseids);
            if ($courseid > 0) {
                $categoryid = (int) $DB->get_field("course", "category", ["id" => $courseid]);
                $pathnames = self::get_category_path_names($categoryid);
                if (!empty($pathnames)) {
                    $scope->academyname = $pathnames[0];
                    $scope->pathwayname = end($pathnames) ?: "";
                }
            }

            if ($scope->pathwayname === "" && !empty($selection->label)) {
                $pathparts = self::extract_path_parts($selection->label);
                if (!empty($pathparts)) {
                    $scope->academyname = $pathparts[0];
                    $scope->pathwayname = end($pathparts) ?: "";
                }
            }

            return $scope;
        }

        if (($selection->type ?? "") === "category") {
            $pathnames = self::get_category_path_names($id);
            if (empty($pathnames) && !empty($selection->label)) {
                $pathnames = self::extract_path_parts($selection->label);
            }

            if (!empty($pathnames)) {
                $scope->academyname = $pathnames[0];
            } else if (!empty($selection->label)) {
                $scope->academyname = self::clean_text($selection->label);
            }

            if (count($pathnames) > 1) {
                $scope->level = "pathway";
                $scope->pathwayname = end($pathnames) ?: "";
            }
        }

        return $scope;
    }

    /**
     * Returns the standardized dashboard title.
     *
     * @param object $selection Current selection.
     * @param array $courseids Course ids.
     * @return string
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function get_dashboard_title(object $selection, array $courseids = []): string {
        $scope = self::get_scope_data($selection, $courseids);

        if ($scope->level === "course") {
            if ($scope->pathwayname !== "") {
                return get_string("headertitledashboardcourse", "local_gimidashboard", (object) [
                    "course" => $scope->coursename,
                    "pathway" => $scope->pathwayname,
                ]);
            }

            return get_string("headertitledashboardcoursenopathway", "local_gimidashboard", $scope->coursename);
        }

        if ($scope->level === "pathway") {
            return get_string("headertitledashboardpathway", "local_gimidashboard", $scope->pathwayname);
        }

        // return get_string("headertitledashboardacademy", "local_gimidashboard");
        return "";
    }

    /**
     * Returns the standardized leaderboard title.
     *
     * @param object $selection Current selection.
     * @param array $courseids Course ids.
     * @return string
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function get_leaderboard_title(object $selection, array $courseids = []): string {
        $scope = self::get_scope_data($selection, $courseids);

        if ($scope->level === "course") {
            if ($scope->pathwayname !== "") {
                return get_string("headertitleleaderboardcourse", "local_gimidashboard", (object) [
                    "course" => $scope->coursename,
                    "pathway" => $scope->pathwayname,
                ]);
            }

            return get_string("headertitleleaderboardcoursenopathway", "local_gimidashboard", $scope->coursename);
        }

        if ($scope->level === "pathway") {
            return get_string("headertitleleaderboardpathway", "local_gimidashboard", $scope->pathwayname);
        }

        return get_string("headertitleleaderboardacademy", "local_gimidashboard");
    }

    /**
     * Returns a compact scope label for subtitles.
     *
     * @param object $selection Current selection.
     * @param array $courseids Course ids.
     * @return string
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function get_scope_context_label(object $selection, array $courseids = []): string {
        $scope = self::get_scope_data($selection, $courseids);

        if ($scope->level === "course") {
            if ($scope->pathwayname !== "") {
                return $scope->coursename . " | " . $scope->pathwayname . " Pathway";
            }

            return $scope->coursename;
        }

        if ($scope->level === "pathway") {
            return $scope->pathwayname . " Pathway";
        }

        return $scope->academyname;
    }

    /**
     * Renders the standardized content title.
     *
     * @param string $title Header title.
     * @param object $selection Current selection.
     * @param array $courseids Course ids.
     * @param array $subtitleparts Subtitle fragments.
     * @param string $extrahtml Extra HTML.
     * @param string $component
     * @return string
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function render_standard_header(
        string $title,
        object $selection,
        array $courseids = [],
        array $subtitleparts = [],
        string $extrahtml = "",
        string $component = ""
    ): string {
        global $OUTPUT;

        $scope = self::get_scope_data($selection, $courseids);
        $subtitleparts = self::normalize_subtitle_parts($subtitleparts, [$title]);

        return $OUTPUT->render_from_template("local_gimidashboard/content_title", [
            "title" => $title,
            "subtitle" => implode(" • ", $subtitleparts),
            "scope_class" => $scope->level,
            "header_style" => header_color_manager::get_header_style($component),
            "extra_html" => $extrahtml,
        ]);
    }

    /**
     * Returns the category path names.
     *
     * @param int $categoryid Category id.
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     */
    protected static function get_category_path_names(int $categoryid): array {
        global $DB;

        if ($categoryid <= 0) {
            return [];
        }

        $path = (string) $DB->get_field("course_categories", "path", ["id" => $categoryid]);
        if ($path === "") {
            return [];
        }

        $categoryids = array_filter(array_map("intval", explode("/", trim($path, "/"))));
        if (empty($categoryids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select_menu("course_categories", "id {$insql}", $params, "", "id, name");

        $names = [];
        foreach ($categoryids as $pathid) {
            if (!empty($records[$pathid])) {
                $names[] = format_string($records[$pathid], true, ["context" => context_system::instance()]);
            }
        }

        return $names;
    }

    /**
     * Extracts path parts from a formatted label.
     *
     * @param string $label Label text.
     * @return array
     */
    protected static function extract_path_parts(string $label): array {
        $parts = preg_split('/\s*\/\s*/', self::clean_text($label));
        return array_values(array_filter($parts, static function(string $value): bool {
            return $value !== "";
        }));
    }

    /**
     * Normalizes subtitle parts.
     *
     * @param array $parts Candidate subtitle parts.
     * @param array $blocked Blocked values.
     * @return array
     */
    protected static function normalize_subtitle_parts(array $parts, array $blocked = []): array {
        $normalized = [];
        $blocked = array_map([self::class, "clean_text"], $blocked);

        foreach ($parts as $part) {
            $clean = self::clean_text((string) $part);
            if ($clean === "") {
                continue;
            }

            if (in_array($clean, $blocked)) {
                continue;
            }

            if (in_array($clean, $normalized)) {
                continue;
            }

            $normalized[] = $clean;
        }

        return $normalized;
    }

    /**
     * Cleans text rendered inside the header.
     *
     * @param string $text Raw text.
     * @return string
     */
    protected static function clean_text(string $text): string {
        $text = trim(strip_tags($text));
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }
}
