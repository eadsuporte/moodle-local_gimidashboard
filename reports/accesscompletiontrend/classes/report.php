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
 * report.php
 *
 * @package   gimidashboardreports_accesscompletiontrend
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gimidashboardreports_accesscompletiontrend;

use context_course;
use core_text;
use Exception;
use local_gimidashboard\page\selection_resolver;
use local_gimidashboard\report\report_interface;
use moodle_url;

/**
 * Course access and completion trend report.
 *
 * @package   gimidashboardreports_accesscompletiontrend
 */
class report implements report_interface {
    /**
     * Returns the report header.
     *
     * @param array $courses Accessible course records.
     * @param string $extra Extra HTML rendered on the right side of the header.
     * @return string
     * @throws Exception
     */
    public static function get_header(array $courses, $extra = ""): string {
        global $OUTPUT, $USER;

        $selection = selection_resolver::resolve(optional_param("target", "", PARAM_TEXT), $USER->id);
        $date = userdate(time(), get_string("strftimedatefullshort", "langconfig"));
        $subtitle = implode(" • ", [
            get_string("range12months", "gimidashboardreports_accesscompletiontrend"),
            get_string("selectedscope", "gimidashboardreports_accesscompletiontrend", strip_tags($selection->label ?? "")),
            get_string("snapshotlabel", "gimidashboardreports_accesscompletiontrend", $date),
        ]);

        return $OUTPUT->render_from_template("local_gimidashboard/content_title", [
            "academyname" => get_string("pluginname", "gimidashboardreports_accesscompletiontrend"),
            "subtitle" => $subtitle,
            "extra_html" => $extra,
        ]);
    }

    /**
     * Returns true because the report supports single course selections.
     *
     * @return bool
     */
    public static function supports_course(): bool {
        return true;
    }

    /**
     * Returns true because the report supports category selections.
     *
     * @return bool
     */
    public static function supports_category(): bool {
        return true;
    }

    /**
     * Renders the report HTML.
     *
     * @param array $courses Accessible course records.
     * @return string
     * @throws Exception
     */
    public static function render(array $courses): string {
        global $OUTPUT;

        $data = self::prepare_data($courses);
        if (empty($data->courseids)) {
            return "";
        }

        return $OUTPUT->render_from_template("gimidashboardreports_accesscompletiontrend/content", [
            "summarycards" => [
                [
                    "label" => get_string("totalaccesses", "gimidashboardreports_accesscompletiontrend"),
                    "value" => format_float($data->summary->totalaccesses, 0),
                ],
                [
                    "label" => get_string("totalcompletions", "gimidashboardreports_accesscompletiontrend"),
                    "value" => format_float($data->summary->totalcompletions, 0),
                ],
                [
                    "label" => get_string("courseswithactivity", "gimidashboardreports_accesscompletiontrend"),
                    "value" => format_float($data->summary->courseswithactivity, 0),
                ],
                [
                    "label" => get_string("avgrate", "gimidashboardreports_accesscompletiontrend"),
                    "value" => self::format_percent($data->summary->avgcompletionrate),
                ],
            ],
            "chartsvg" => self::render_chart_svg($data->chart),
            "chartlegend" => [
                [
                    "color" => "#2563eb",
                    "label" => get_string("courseaccesses_monthly", "gimidashboardreports_accesscompletiontrend"),
                ],
                [
                    "color" => "#16a34a",
                    "label" => get_string("coursecompletions", "gimidashboardreports_accesscompletiontrend"),
                ],
            ],
            "tablehtml" => self::render_table($data->rows),
            "hasrows" => !empty($data->rows),
            "emptylabel" => get_string("notabledata", "gimidashboardreports_accesscompletiontrend"),
            "charthelp" => get_string("charthelp", "gimidashboardreports_accesscompletiontrend"),
        ]);
    }

    /**
     * Prepares the data needed by the report.
     *
     * @param array $courses Accessible course records.
     * @return object
     * @throws Exception
     */
    protected static function prepare_data(array $courses): object {
        $courseids = array_values(array_map(static function($course): int {
            return $course->id;
        }, $courses));

        if (empty($courseids)) {
            return (object) [
                "courseids" => [],
                "chart" => (object) [
                    "labels" => [],
                    "series" => [],
                ],
                "rows" => [],
                "summary" => (object) [
                    "totalaccesses" => 0,
                    "totalcompletions" => 0,
                    "courseswithactivity" => 0,
                    "avgcompletionrate" => 0,
                ],
            ];
        }

        $months = self::build_months(12);
        $starttimestamp = reset($months)->starttimestamp;

        $accessseries = self::get_access_series($courseids, $months, $starttimestamp);
        $completionseries = self::get_completion_series($courseids, $months, $starttimestamp);
        $rows = self::get_table_rows($courses, $courseids, $starttimestamp);

        $totalaccesses = array_sum($accessseries["values"]);
        $totalcompletions = array_sum($completionseries["values"]);
        $courseswithactivity = count(array_filter($rows, static function(array $row): bool {
            return $row["accessesraw"] > 0 || $row["completionsraw"] > 0;
        }));
        $rates = array_values(array_filter(array_map(static function(array $row): ?float {
            return $row["completionrateraw"];
        }, $rows), static function($value): bool {
            return $value !== null;
        }));

        return (object) [
            "courseids" => $courseids,
            "chart" => (object) [
                "labels" => array_map(static function(object $month): string {
                    return $month->label;
                }, $months),
                "series" => [
                    [
                        "name" => get_string("courseaccesses_monthly", "gimidashboardreports_accesscompletiontrend"),
                        "color" => "#2563eb",
                        "values" => $accessseries["values"],
                    ],
                    [
                        "name" => get_string("coursecompletions", "gimidashboardreports_accesscompletiontrend"),
                        "color" => "#16a34a",
                        "values" => $completionseries["values"],
                    ],
                ],
            ],
            "rows" => $rows,
            "summary" => (object) [
                "totalaccesses" => $totalaccesses,
                "totalcompletions" => $totalcompletions,
                "courseswithactivity" => $courseswithactivity,
                "avgcompletionrate" => !empty($rates) ? array_sum($rates) / count($rates) : 0,
            ],
        ];
    }

    /**
     * Builds the month buckets used in the chart.
     *
     * @param int $count Number of months.
     * @return array
     */
    protected static function build_months(int $count): array {
        $months = [];

        $currentstart = usergetmidnight(time());
        $currentstart = mktime(0, 0, 0, date("n", $currentstart), 1, date("Y", $currentstart));

        for ($index = $count - 1; $index >= 0; $index--) {
            $timestamp = strtotime("-{$index} months", $currentstart);
            $key = date("Y-m", $timestamp);
            $months[] = (object) [
                "key" => $key,
                "label" => userdate($timestamp, "%b %Y"),
                "starttimestamp" => $timestamp,
            ];
        }

        return $months;
    }

    /**
     * Returns the monthly course access series.
     *
     * @param array $courseids Course ids.
     * @param array $months Month buckets.
     * @param int $starttimestamp Start timestamp.
     * @return array
     * @throws Exception
     */
    protected static function get_access_series(array $courseids, array $months, int $starttimestamp): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params["action"] = "viewed";
        $params["target"] = "course";
        $params["starttime"] = $starttimestamp;

        $monthsql = self::get_year_month_sql("l.timecreated");
        $sql = "SELECT {$monthsql} AS monthkey, COUNT(1) AS total
                  FROM {logstore_standard_log} l
                 WHERE l.courseid {$insql}
                   AND l.userid > 0
                   AND l.action = :action
                   AND l.target = :target
                   AND l.timecreated >= :starttime
              GROUP BY {$monthsql}
              ORDER BY {$monthsql}";

        $records = $DB->get_records_sql($sql, $params);
        $series = array_fill_keys(array_map(static function(object $month): string {
            return $month->key;
        }, $months), 0);

        foreach ($records as $record) {
            if (isset($series[$record->monthkey])) {
                $series[$record->monthkey] = $record->total;
            }
        }

        return ["values" => array_values($series)];
    }

    /**
     * Returns the monthly course completion series.
     *
     * @param array $courseids Course ids.
     * @param array $months Month buckets.
     * @param int $starttimestamp Start timestamp.
     * @return array
     * @throws Exception
     */
    protected static function get_completion_series(array $courseids, array $months, int $starttimestamp): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params["starttime"] = $starttimestamp;

        $monthsql = self::get_year_month_sql("cc.timecompleted");
        $sql = "SELECT {$monthsql} AS monthkey, COUNT(1) AS total
                  FROM {course_completions} cc
                 WHERE cc.course {$insql}
                   AND cc.timecompleted > 0
                   AND cc.timecompleted >= :starttime
              GROUP BY {$monthsql}
              ORDER BY {$monthsql}";

        $records = $DB->get_records_sql($sql, $params);
        $series = array_fill_keys(array_map(static function(object $month): string {
            return $month->key;
        }, $months), 0);

        foreach ($records as $record) {
            if (isset($series[$record->monthkey])) {
                $series[$record->monthkey] = $record->total;
            }
        }

        return ["values" => array_values($series)];
    }

    /**
     * Returns the table rows per course.
     *
     * @param array $courses Course records indexed by course id.
     * @param array $courseids Course ids.
     * @param int $starttimestamp Start timestamp.
     * @return array
     * @throws Exception
     */
    protected static function get_table_rows(array $courses, array $courseids, int $starttimestamp): array {
        $enrolled = self::get_enrolled_totals($courseids);
        $accesses = self::get_access_totals($courseids, $starttimestamp);
        $completions = self::get_completion_totals($courseids, $starttimestamp);

        $rows = [];
        foreach ($courseids as $courseid) {
            if (empty($courses[$courseid])) {
                continue;
            }

            $course = $courses[$courseid];
            $context = context_course::instance($courseid);
            $enrolledcount = $enrolled[$courseid] ?? 0;
            $accesscount = $accesses[$courseid]->accesscount ?? 0;
            $userswithaccess = $accesses[$courseid]->usercount ?? 0;
            $completioncount = $completions[$courseid] ?? 0;
            $completionrate = $enrolledcount > 0 ? ($completioncount / $enrolledcount) * 100 : null;

            $rows[] = [
                "course" => format_string($course->fullname, true, ["context" => $context]),
                "courseurl" => (new moodle_url("/course/", ["id" => $courseid]))->out(false),
                "enrolled" => format_float($enrolledcount, 0),
                "accesses" => format_float($accesscount, 0),
                "userswithaccess" => format_float($userswithaccess, 0),
                "completions" => format_float($completioncount, 0),
                "completionrate" => self::format_percent($completionrate),
                "lastaccess" => self::format_date($accesses[$courseid]->lastaccess ?? 0),
                "accessesraw" => $accesscount,
                "completionsraw" => $completioncount,
                "completionrateraw" => $completionrate,
            ];
        }

        usort($rows, static function(array $left, array $right): int {
            if ($left["accessesraw"] !== $right["accessesraw"]) {
                return $right["accessesraw"] <=> $left["accessesraw"];
            }

            if ($left["completionsraw"] !== $right["completionsraw"]) {
                return $right["completionsraw"] <=> $left["completionsraw"];
            }

            return strtolower($left["course"]) <=> strtolower($right["course"]);
        });

        return $rows;
    }

    /**
     * Returns active enrolled learners totals per course.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws Exception
     */
    protected static function get_enrolled_totals(array $courseids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params["confirmed"] = 1;

        $sql = "SELECT e.courseid, COUNT(DISTINCT ue.userid) AS total
                  FROM {user_enrolments} ue
                  JOIN {enrol} e
                    ON e.id = ue.enrolid
                  JOIN {user} u
                    ON u.id = ue.userid
                 WHERE e.courseid {$insql}
                   AND e.status = 0
                   AND ue.status = 0
                   AND u.deleted = 0
                   AND u.confirmed = :confirmed
              GROUP BY e.courseid";

        $records = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($records as $record) {
            $result[$record->courseid] = $record->total;
        }

        return $result;
    }

    /**
     * Returns course access totals from the log store.
     *
     * @param array $courseids Course ids.
     * @param int $starttimestamp Start timestamp.
     * @return array
     * @throws Exception
     */
    protected static function get_access_totals(array $courseids, int $starttimestamp): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params["action"] = "viewed";
        $params["target"] = "course";
        $params["starttime"] = $starttimestamp;

        $sql = "SELECT l.courseid,
                       COUNT(1) AS accesscount,
                       COUNT(DISTINCT l.userid) AS usercount,
                       MAX(l.timecreated) AS lastaccess
                  FROM {logstore_standard_log} l
                 WHERE l.courseid {$insql}
                   AND l.userid > 0
                   AND l.action = :action
                   AND l.target = :target
                   AND l.timecreated >= :starttime
              GROUP BY l.courseid";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Returns course completion totals.
     *
     * @param array $courseids Course ids.
     * @param int $starttimestamp Start timestamp.
     * @return array
     * @throws Exception
     */
    protected static function get_completion_totals(array $courseids, int $starttimestamp): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params["starttime"] = $starttimestamp;

        $sql = "SELECT cc.course, COUNT(1) AS total
                  FROM {course_completions} cc
                 WHERE cc.course {$insql}
                   AND cc.timecompleted > 0
                   AND cc.timecompleted >= :starttime
              GROUP BY cc.course";

        $records = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($records as $record) {
            $result[$record->course] = $record->total;
        }

        return $result;
    }

    /**
     * Returns a DB family aware SQL expression for year-month grouping.
     *
     * @param string $field Unix timestamp field.
     * @return string
     */
    protected static function get_year_month_sql(string $field): string {
        global $DB;

        switch ($DB->get_dbfamily()) {
            case "postgres":
                return "TO_CHAR(TO_TIMESTAMP({$field}), 'YYYY-MM')";
            case "oracle":
                return "TO_CHAR(DATE '1970-01-01' + ({$field} / 86400), 'YYYY-MM')";
            case "mssql":
                return "FORMAT(DATEADD(SECOND, {$field}, '1970-01-01'), 'yyyy-MM')";
            case "mysql":
            default:
                return "DATE_FORMAT(FROM_UNIXTIME({$field}), '%Y-%m')";
        }
    }

    /**
     * Renders the SVG line chart.
     *
     * @param object $chart Chart data.
     * @return string
     * @throws Exception
     */
    protected static function render_chart_svg(object $chart): string {
        $labels = $chart->labels ?? [];
        $series = $chart->series ?? [];
        if (empty($labels) || empty($series)) {
            return "";
        }

        $width = 980;
        $height = 320;
        $paddingleft = 58;
        $paddingright = 18;
        $paddingtop = 18;
        $paddingbottom = 48;
        $plotwidth = $width - $paddingleft - $paddingright;
        $plotheight = $height - $paddingtop - $paddingbottom;

        $maxvalue = 0;
        foreach ($series as $item) {
            foreach ($item["values"] as $value) {
                $maxvalue = max($maxvalue, $value);
            }
        }

        if ($maxvalue < 1) {
            $maxvalue = 1;
        }

        $parts = [];
        $parts[] = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' .
            s(get_string("chartarialabel", "gimidashboardreports_accesscompletiontrend")) . '">';
        $parts[] = '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" rx="18" fill="#ffffff"></rect>';

        for ($step = 0; $step <= 4; $step++) {
            $value = ($maxvalue / 4) * (4 - $step);
            $y = $paddingtop + ($plotheight / 4) * $step;
            $parts[] = '<line x1="' . $paddingleft . '" y1="' . $y . '" x2="' . ($width - $paddingright) .
                '" y2="' . $y . '" stroke="#e2e8f0" stroke-width="1"></line>';
            $parts[] =
                '<text x="' . ($paddingleft - 8) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="11" fill="#64748b">' .
                format_float($value, 0) . '</text>';
        }

        $count = max(count($labels), 2);
        foreach ($labels as $index => $label) {
            $x = $paddingleft + ($plotwidth * ($count == 1 ? 0 : $index / ($count - 1)));
            $parts[] = '<text x="' . $x . '" y="' . ($height - 18) . '" text-anchor="middle" font-size="11" fill="#64748b">' .
                s($label) . '</text>';
        }

        foreach ($series as $item) {
            $points = [];
            foreach ($item["values"] as $index => $value) {
                $x = $paddingleft + ($plotwidth * ($count == 1 ? 0 : $index / ($count - 1)));
                $y = $paddingtop + $plotheight - (($value / $maxvalue) * $plotheight);
                $points[] = [$x, $y, $value];
            }

            $path = [];
            foreach ($points as $index => $point) {
                $path[] = ($index == 0 ? "M" : "L") . round($point[0], 2) . " " . round($point[1], 2);
            }

            $parts[] = '<path d="' . implode(" ", $path) . '" fill="none" stroke="' . $item["color"] .
                '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></path>';

            foreach ($points as $point) {
                $parts[] = '<g>';
                $parts[] = '<title>' . s($item["name"] . ': ' . format_float($point[2], 0)) . '</title>';
                $parts[] =
                    '<circle cx="' . round($point[0], 2) . '" cy="' . round($point[1], 2) . '" r="4" fill="#ffffff" stroke="' .
                    $item["color"] . '" stroke-width="2"></circle>';
                $parts[] = '</g>';
            }
        }

        $parts[] = '</svg>';
        return implode("", $parts);
    }

    /**
     * Renders the DataTable HTML.
     *
     * @param array $rows Rows.
     * @return string
     * @throws Exception
     */
    protected static function render_table(array $rows): string {
        global $OUTPUT, $PAGE;

        $pageLength = optional_param("plugin", false, PARAM_COMPONENT) ? 50 : 5;
        $PAGE->requires->js_call_amd("local_gimidashboard/dashboard", "datatable", ["#accesscompletiontrend-table", $pageLength]);

        return $OUTPUT->render_from_template("gimidashboardreports_accesscompletiontrend/table", [
            "headers" => [
                ["label" => get_string("course", "gimidashboardreports_accesscompletiontrend")],
                ["label" => get_string("enrolledlearners", "gimidashboardreports_accesscompletiontrend")],
                ["label" => get_string("courseaccesses", "gimidashboardreports_accesscompletiontrend")],
                ["label" => get_string("learnerswithaccess", "gimidashboardreports_accesscompletiontrend")],
                ["label" => get_string("coursecompletions", "gimidashboardreports_accesscompletiontrend")],
                ["label" => get_string("completionrate", "gimidashboardreports_accesscompletiontrend")],
                ["label" => get_string("lastaccess", "gimidashboardreports_accesscompletiontrend")],
            ],
            "rows" => $rows,
            "hasrows" => !empty($rows),
            "emptymessage" => get_string("notabledata", "gimidashboardreports_accesscompletiontrend"),
        ]);
    }

    /**
     * Formats a percent value.
     *
     * @param float|null $value Value.
     * @return string
     * @throws Exception
     */
    protected static function format_percent(?float $value): string {
        if ($value == null) {
            return get_string("notavailable", "gimidashboardreports_accesscompletiontrend");
        }

        return format_float($value, 1) . "%";
    }

    /**
     * Formats a timestamp.
     *
     * @param int|null $timestamp Timestamp.
     * @return string
     * @throws Exception
     */
    protected static function format_date(?int $timestamp): string {
        if (empty($timestamp)) {
            return get_string("never", "gimidashboardreports_accesscompletiontrend");
        }

        return userdate($timestamp, get_string("strftimedatefullshort", "langconfig"));
    }
}
