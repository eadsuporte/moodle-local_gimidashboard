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
 * Gradebook overview report.
 *
 * @package   gimidashboardreports_gradebookoverview
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gimidashboardreports_gradebookoverview;

use context_course;
use Exception;
use local_gimidashboard\local\header_helper;
use local_gimidashboard\page\selection_resolver;
use local_gimidashboard\report\report_interface;
use moodle_url;

/**
 * Gradebook overview report.
 *
 * @package   gimidashboardreports_gradebookoverview
 */
class report implements report_interface {
    /**
     * Returns the report header.
     *
     * @param array $courses Accessible course records.
     * @param string $extra Extra header HTML.
     * @return string
     * @throws Exception
     */
    public static function get_header(array $courses, $extra = ""): string {
        global $OUTPUT;

        $data = self::prepare_data($courses);
        if (empty($data->courseids)) {
            return "";
        }

        return header_helper::render_standard_header(
            get_string("pluginname", "gimidashboardreports_gradebookoverview"),
            $data->selection,
            $data->courseids,
            [
                header_helper::get_scope_context_label($data->selection, $data->courseids),
                get_string("courseslabel", "gimidashboardreports_gradebookoverview", count($data->courseids)),
                get_string("activitieslabel", "gimidashboardreports_gradebookoverview", count($data->activityrows)),
                get_string("learnerslabel", "gimidashboardreports_gradebookoverview", count($data->learnerrows)),
            ],
            $extra
        );
    }

    /**
     * Returns true because the report supports course selections.
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
     * Renders the report.
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

        return $OUTPUT->render_from_template("gimidashboardreports_gradebookoverview/content", [
            "kpis" => [
                [
                    "label" => get_string("trackedactivities", "gimidashboardreports_gradebookoverview"),
                    "value" => format_float($data->summary->trackedactivities, 0),
                ],
                [
                    "label" => get_string("gradedlearners", "gimidashboardreports_gradebookoverview"),
                    "value" => format_float($data->summary->gradedlearners, 0),
                ],
                [
                    "label" => get_string("overallaverage", "gimidashboardreports_gradebookoverview"),
                    "value" => self::format_percent($data->summary->overallaverage),
                ],
                [
                    "label" => get_string("averagecompletion", "gimidashboardreports_gradebookoverview"),
                    "value" => self::format_percent($data->summary->averagecompletion),
                ],
                [
                    "label" => get_string("lowestaverageactivity", "gimidashboardreports_gradebookoverview"),
                    "value" => $data->summary->lowestactivitylabel,
                ],
            ],
            "hasfilters" => !empty($data->filters),
            "filters" => $data->filters,
            "reseturl" => self::build_url($data->selection),
            "lowestactivities" => $data->lowestactivities,
            "haslowestactivities" => !empty($data->lowestactivities),
            "coveragecards" => [
                [
                    "label" => get_string("graderecords", "gimidashboardreports_gradebookoverview"),
                    "value" => format_float($data->summary->graderecords, 0),
                ],
                [
                    "label" => get_string("activitieswithgrades", "gimidashboardreports_gradebookoverview"),
                    "value" => format_float($data->summary->activitieswithgrades, 0),
                ],
                [
                    "label" => get_string("completioncrosscount", "gimidashboardreports_gradebookoverview"),
                    "value" => format_float($data->summary->completioncrosscount, 0),
                ],
            ],
            "activitytablehtml" => self::render_activity_table($data->activityrows, $data->selection),
            "learnertablehtml" => self::render_learner_table($data->learnerrows, $data->selection),
            "hasdetailtable" => !empty($data->detailrows),
            "detailtitle" => $data->detailtitle,
            "detailtablehtml" => !empty($data->detailrows)
                ? self::render_detail_table($data->detailrows, $data->detailmode)
                : "",
            "emptylabel" => get_string("nodata", "gimidashboardreports_gradebookoverview"),
            "helptext" => get_string("drilldownhelp", "gimidashboardreports_gradebookoverview"),
        ]);
    }

    /**
     * Prepares the report data.
     *
     * @param array $courses Accessible course records.
     * @return object
     * @throws Exception
     */
    protected static function prepare_data(array $courses): object {
        global $USER;

        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $courseids = self::extract_course_ids($courses);
        $selection = selection_resolver::resolve(optional_param("target", "", PARAM_TEXT), $USER->id);
        $activityid = optional_param("activityid", 0, PARAM_INT);
        $learnerid = optional_param("learnerid", 0, PARAM_INT);

        if (empty($courseids)) {
            $cache = (object) [
                "courseids" => [],
                "selection" => $selection,
                "activityrows" => [],
                "learnerrows" => [],
                "detailrows" => [],
                "detailmode" => "",
                "detailtitle" => "",
                "filters" => [],
                "lowestactivities" => [],
                "summary" => self::empty_summary(),
            ];
            return $cache;
        }

        $teacherbycourse = self::get_course_teachers($courseids);
        $activityrows = self::get_activity_summary_rows($courseids, $teacherbycourse, $activityid, $learnerid);
        $learnerrows = self::get_learner_summary_rows($courseids, $activityid, $learnerid);
        $detailrows = [];
        $detailmode = "";
        $detailtitle = "";

        if ($activityid > 0 || $learnerid > 0) {
            $detailrows = self::get_detail_rows($courseids, $teacherbycourse, $activityid, $learnerid);
            $detailmode = $activityid > 0 ? "activity" : "learner";
            $detailtitle = self::build_detail_title($detailrows, $activityrows, $learnerrows, $activityid, $learnerid);
        }

        $filters = self::build_filters($selection, $activityrows, $learnerrows, $activityid, $learnerid);
        $summary = self::build_summary($activityrows, $learnerrows, $detailrows);

        $cache = (object) [
            "courseids" => $courseids,
            "selection" => $selection,
            "activityid" => $activityid,
            "learnerid" => $learnerid,
            "activityrows" => $activityrows,
            "learnerrows" => $learnerrows,
            "detailrows" => $detailrows,
            "detailmode" => $detailmode,
            "detailtitle" => $detailtitle,
            "filters" => $filters,
            "lowestactivities" => array_slice(array_values(array_filter($activityrows, static function(array $row): bool {
                return $row["avggraderaw"] !== null;
            })), 0, 5),
            "summary" => $summary,
        ];

        return $cache;
    }

    /**
     * Returns an empty summary object.
     *
     * @return object
     */
    protected static function empty_summary(): object {
        return (object) [
            "trackedactivities" => 0,
            "gradedlearners" => 0,
            "overallaverage" => null,
            "averagecompletion" => 0,
            "lowestactivitylabel" => get_string("dash", "gimidashboardreports_gradebookoverview"),
            "graderecords" => 0,
            "activitieswithgrades" => 0,
            "completioncrosscount" => 0,
        ];
    }

    /**
     * Returns the selected course ids.
     *
     * @param array $courses Accessible course records.
     * @return array
     */
    protected static function extract_course_ids(array $courses): array {
        $courseids = [];
        foreach ($courses as $course) {
            $courseids[(int) $course->id] = (int) $course->id;
        }

        return array_values($courseids);
    }

    /**
     * Returns teacher names for each course.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws Exception
     */
    protected static function get_course_teachers(array $courseids): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params += [
            "contextlevel" => CONTEXT_COURSE,
            "teacherarchetype1" => "editingteacher",
            "teacherarchetype2" => "teacher",
            "teachershortname1" => "editingteacher",
            "teachershortname2" => "teacher",
        ];

        $sql = "SELECT CONCAT(ctx.instanceid, '-', u.id) AS unik,
                       ctx.instanceid AS courseid,
                       u.firstname,
                       u.lastname
                  FROM {context} ctx
                  JOIN {role_assignments} ra
                    ON ra.contextid = ctx.id
                  JOIN {role} r
                    ON r.id = ra.roleid
                  JOIN {user} u
                    ON u.id = ra.userid
                 WHERE ctx.contextlevel = :contextlevel
                   AND ctx.instanceid {$coursesql}
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND (r.archetype IN (:teacherarchetype1, :teacherarchetype2)
                        OR r.shortname IN (:teachershortname1, :teachershortname2))
              ORDER BY ctx.instanceid ASC, u.firstname ASC, u.lastname ASC";
        $records = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($records as $record) {
            $name = trim($record->firstname . " " . $record->lastname);
            if ($name === "") {
                continue;
            }

            $result[$record->courseid][$name] = $name;
        }

        foreach ($result as $courseid => $names) {
            $result[$courseid] = implode(", ", array_values($names));
        }

        return $result;
    }

    /**
     * Returns summarized activity rows.
     *
     * @param array $courseids Course ids.
     * @param array $teacherbycourse Teacher names by course.
     * @param int $activityid Optional activity filter.
     * @param int $learnerid Optional learner filter.
     * @return array
     * @throws Exception
     */
    protected static function get_activity_summary_rows(
        array $courseids,
        array $teacherbycourse,
        int $activityid = 0,
        int $learnerid = 0
    ): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $selection = selection_resolver::resolve(optional_param("target", "", PARAM_TEXT));

        $gradeexpr = self::get_grade_percent_sql("g");
        $filterwhere = "";
        if ($activityid > 0) {
            $filterwhere .= " AND gi.id = :activityid";
            $params["activityid"] = $activityid;
        }
        if ($learnerid > 0) {
            $filterwhere .= " AND eu.userid = :learnerid";
            $params["learnerid"] = $learnerid;
        }

        $sql = "SELECT gi.id AS itemid,
                       gi.courseid,
                       c.fullname AS coursename,
                       gi.itemname,
                       gi.itemmodule,
                       gi.iteminstance,
                       cm.id AS cmid,
                       COUNT(1) AS enrolledcount,
                       SUM(CASE WHEN g.finalgrade IS NOT NULL THEN 1 ELSE 0 END) AS gradedcount,
                       AVG(CASE WHEN g.finalgrade IS NOT NULL THEN {$gradeexpr} ELSE NULL END) AS avggrade,
                       MIN(CASE WHEN g.finalgrade IS NOT NULL THEN {$gradeexpr} ELSE NULL END) AS lowestgrade,
                       MAX(CASE WHEN g.timemodified > 0 THEN g.timemodified ELSE 0 END) AS lastgraded,
                       SUM(CASE WHEN cmc.completionstate IN (1, 2, 3) THEN 1 ELSE 0 END) AS completedcount
                  FROM (
                        SELECT DISTINCT ue.userid, e.courseid
                          FROM {user_enrolments} ue
                          JOIN {enrol} e
                            ON e.id = ue.enrolid
                          JOIN {user} u
                            ON u.id = ue.userid
                         WHERE e.courseid {$coursesql}
                           AND e.status = 0
                           AND ue.status = 0
                           AND u.deleted = 0
                           AND u.suspended = 0
                           AND u.username <> 'guest'
                       ) eu
                  JOIN {course} c
                    ON c.id = eu.courseid
                  JOIN {grade_items} gi
                    ON gi.courseid = eu.courseid
                   AND gi.itemtype = 'mod'
                   AND (gi.hidden = 0 OR gi.hidden IS NULL)
                  LEFT JOIN {modules} m
                    ON m.name = gi.itemmodule
                  LEFT JOIN {course_modules} cm
                    ON cm.course = gi.courseid
                   AND cm.module = m.id
                   AND cm.instance = gi.iteminstance
                   AND cm.deletioninprogress = 0
                  LEFT JOIN {grade_grades} g
                    ON g.itemid = gi.id
                   AND g.userid = eu.userid
                  LEFT JOIN {course_modules_completion} cmc
                    ON cmc.coursemoduleid = cm.id
                   AND cmc.userid = eu.userid
                 WHERE c.visible = 1
                   {$filterwhere}
              GROUP BY gi.id, gi.courseid, c.fullname, gi.itemname, gi.itemmodule, gi.iteminstance, cm.id
              ORDER BY avggrade ASC, c.fullname ASC, gi.itemname ASC";

        $records = $DB->get_records_sql($sql, $params);
        $rows = [];
        foreach ($records as $record) {
            $enrolledcount = (int) $record->enrolledcount;
            $gradedcount = (int) $record->gradedcount;
            $completedcount = (int) $record->completedcount;
            $avggrade = $record->avggrade !== null ? round((float) $record->avggrade, 1) : null;
            $lowestgrade = $record->lowestgrade !== null ? round((float) $record->lowestgrade, 1) : null;
            $completionrate = $enrolledcount > 0 ? round(($completedcount / $enrolledcount) * 100, 1) : 0.0;
            $gradingrate = $enrolledcount > 0 ? round(($gradedcount / $enrolledcount) * 100, 1) : 0.0;
            $coursename = format_string($record->coursename, true, ["context" => context_course::instance($record->courseid)]);
            $activityname = trim((string) $record->itemname) !== ""
                ? format_string($record->itemname, true, ["context" => context_course::instance($record->courseid)])
                : get_string("unnamedactivity", "gimidashboardreports_gradebookoverview");
            $activityurl = null;
            if (!empty($record->cmid) && !empty($record->itemmodule)) {
                $activityurl = new moodle_url("/mod/{$record->itemmodule}/view.php", ["id" => $record->cmid]);
            }

            $rows[] = [
                "itemid" => (int) $record->itemid,
                "courseid" => (int) $record->courseid,
                "coursename" => $coursename,
                "activityname" => $activityname,
                "activitylabel" => $activityname,
                "activitymodule" => s($record->itemmodule ?? ""),
                "activityurl" => $activityurl,
                "teacher" => s($teacherbycourse[$record->courseid] ??
                    get_string("notavailable", "gimidashboardreports_gradebookoverview")),
                "enrolledcount" => $enrolledcount,
                "gradedcount" => $gradedcount,
                "gradedcountdisplay" => format_float($gradedcount, 0),
                "avggraderaw" => $avggrade,
                "avggrade" => self::format_percent($avggrade),
                "lowestgraderaw" => $lowestgrade,
                "lowestgrade" => self::format_percent($lowestgrade),
                "lastgradedraw" => (int) $record->lastgraded,
                "lastgraded" => self::format_date((int) $record->lastgraded),
                "completedcount" => $completedcount,
                "completionrateraw" => $completionrate,
                "completionrate" => self::format_percent($completionrate),
                "gradingrateraw" => $gradingrate,
                "gradingrate" => self::format_percent($gradingrate),
                "filterurl" => self::build_url(
                    $selection,
                    (int) $record->itemid,
                    $learnerid
                ),
            ];
        }

        return $rows;
    }

    /**
     * Returns summarized learner rows.
     *
     * @param array $courseids Course ids.
     * @param int $activityid Optional activity filter.
     * @param int $learnerid Optional learner filter.
     * @return array
     * @throws Exception
     */
    protected static function get_learner_summary_rows(array $courseids, int $activityid = 0, int $learnerid = 0): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $selection = selection_resolver::resolve(optional_param("target", "", PARAM_TEXT));

        $gradeexpr = self::get_grade_percent_sql("g");
        $filterwhere = "";
        if ($activityid > 0) {
            $filterwhere .= " AND gi.id = :activityid";
            $params["activityid"] = $activityid;
        }
        if ($learnerid > 0) {
            $filterwhere .= " AND eu.userid = :learnerid";
            $params["learnerid"] = $learnerid;
        }

        $sql = "SELECT eu.userid,
                       u.firstname,
                       u.lastname,
                       u.email,
                       COUNT(DISTINCT eu.courseid) AS coursecount,
                       COUNT(gi.id) AS activitycount,
                       SUM(CASE WHEN g.finalgrade IS NOT NULL THEN 1 ELSE 0 END) AS gradedcount,
                       AVG(CASE WHEN g.finalgrade IS NOT NULL THEN {$gradeexpr} ELSE NULL END) AS avggrade,
                       MIN(CASE WHEN g.finalgrade IS NOT NULL THEN {$gradeexpr} ELSE NULL END) AS lowestgrade,
                       MAX(CASE WHEN g.timemodified > 0 THEN g.timemodified ELSE 0 END) AS lastgraded,
                       SUM(CASE WHEN cmc.completionstate IN (1, 2, 3) THEN 1 ELSE 0 END) AS completedcount
                  FROM (
                        SELECT DISTINCT ue.userid, e.courseid
                          FROM {user_enrolments} ue
                          JOIN {enrol} e
                            ON e.id = ue.enrolid
                          JOIN {user} u2
                            ON u2.id = ue.userid
                         WHERE e.courseid {$coursesql}
                           AND e.status = 0
                           AND ue.status = 0
                           AND u2.deleted = 0
                           AND u2.suspended = 0
                           AND u2.username <> 'guest'
                       ) eu
                  JOIN {user} u
                    ON u.id = eu.userid
                  JOIN {grade_items} gi
                    ON gi.courseid = eu.courseid
                   AND gi.itemtype = 'mod'
                   AND (gi.hidden = 0 OR gi.hidden IS NULL)
                  LEFT JOIN {modules} m
                    ON m.name = gi.itemmodule
                  LEFT JOIN {course_modules} cm
                    ON cm.course = gi.courseid
                   AND cm.module = m.id
                   AND cm.instance = gi.iteminstance
                   AND cm.deletioninprogress = 0
                  LEFT JOIN {grade_grades} g
                    ON g.itemid = gi.id
                   AND g.userid = eu.userid
                  LEFT JOIN {course_modules_completion} cmc
                    ON cmc.coursemoduleid = cm.id
                   AND cmc.userid = eu.userid
                 WHERE 1 = 1
                   {$filterwhere}
              GROUP BY eu.userid, u.firstname, u.lastname, u.email
              ORDER BY avggrade ASC, u.firstname ASC, u.lastname ASC";

        $records = $DB->get_records_sql($sql, $params);
        $rows = [];
        foreach ($records as $record) {
            $activitycount = (int) $record->activitycount;
            $gradedcount = (int) $record->gradedcount;
            $completionrate = $activitycount > 0 ? round((((int) $record->completedcount) / $activitycount) * 100, 1) : 0.0;
            $avggrade = $record->avggrade !== null ? round((float) $record->avggrade, 1) : null;
            $lowestgrade = $record->lowestgrade !== null ? round((float) $record->lowestgrade, 1) : null;
            $fullnamedisplay = trim($record->firstname . " " . $record->lastname);

            $rows[] = [
                "userid" => (int) $record->userid,
                "fullname" => s($fullnamedisplay !== "" ? $fullnamedisplay : $record->email),
                "email" => s($record->email),
                "coursecount" => (int) $record->coursecount,
                "activitycount" => $activitycount,
                "gradedcount" => $gradedcount,
                "avggraderaw" => $avggrade,
                "avggrade" => self::format_percent($avggrade),
                "lowestgraderaw" => $lowestgrade,
                "lowestgrade" => self::format_percent($lowestgrade),
                "lastgradedraw" => (int) $record->lastgraded,
                "lastgraded" => self::format_date((int) $record->lastgraded),
                "completionrateraw" => $completionrate,
                "completionrate" => self::format_percent($completionrate),
                "gradingrateraw" => $activitycount > 0 ? round(($gradedcount / $activitycount) * 100, 1) : 0.0,
                "gradingrate" => $activitycount > 0 ?
                    self::format_percent(round(($gradedcount / $activitycount) * 100, 1)) : self::format_percent(0),
                "filterurl" => self::build_url(
                    $selection,
                    $activityid,
                    (int) $record->userid
                ),
            ];
        }

        return $rows;
    }

    /**
     * Returns the detail rows for the active drill-down.
     *
     * @param array $courseids Course ids.
     * @param array $teacherbycourse Teacher names by course.
     * @param int $activityid Activity filter.
     * @param int $learnerid Learner filter.
     * @return array
     * @throws Exception
     */
    protected static function get_detail_rows(array $courseids, array $teacherbycourse, int $activityid, int $learnerid): array {
        global $DB;

        if (empty($courseids) || ($activityid <= 0 && $learnerid <= 0)) {
            return [];
        }

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params += [
            "activityid" => $activityid,
            "learnerid" => $learnerid,
        ];

        $gradeexpr = self::get_grade_percent_sql("g");
        $filterwhere = [];
        if ($activityid > 0) {
            $filterwhere[] = "gi.id = :activityid";
        }
        if ($learnerid > 0) {
            $filterwhere[] = "eu.userid = :learnerid";
        }

        $where = !empty($filterwhere) ? implode(" AND ", $filterwhere) : "1 = 1";

        $sql = "SELECT gi.id AS itemid,
                       gi.courseid,
                       c.fullname AS coursename,
                       gi.itemname,
                       gi.itemmodule,
                       cm.id AS cmid,
                       eu.userid,
                       u.firstname,
                       u.lastname,
                       u.email,
                       {$gradeexpr} AS gradepercent,
                       g.timemodified AS gradedtime,
                       g.usermodified,
                       grader.firstname AS graderfirstname,
                       grader.lastname AS graderlastname,
                       cmc.completionstate,
                       cmc.timemodified AS completiontime,
                       ul.timeaccess AS lastaccess
                  FROM (
                        SELECT DISTINCT ue.userid, e.courseid
                          FROM {user_enrolments} ue
                          JOIN {enrol} e
                            ON e.id = ue.enrolid
                          JOIN {user} u2
                            ON u2.id = ue.userid
                         WHERE e.courseid {$coursesql}
                           AND e.status = 0
                           AND ue.status = 0
                           AND u2.deleted = 0
                           AND u2.suspended = 0
                           AND u2.username <> 'guest'
                       ) eu
                  JOIN {user} u
                    ON u.id = eu.userid
                  JOIN {course} c
                    ON c.id = eu.courseid
                  JOIN {grade_items} gi
                    ON gi.courseid = eu.courseid
                   AND gi.itemtype = 'mod'
                   AND (gi.hidden = 0 OR gi.hidden IS NULL)
                  LEFT JOIN {modules} m
                    ON m.name = gi.itemmodule
                  LEFT JOIN {course_modules} cm
                    ON cm.course = gi.courseid
                   AND cm.module = m.id
                   AND cm.instance = gi.iteminstance
                   AND cm.deletioninprogress = 0
                  LEFT JOIN {grade_grades} g
                    ON g.itemid = gi.id
                   AND g.userid = eu.userid
                  LEFT JOIN {user} grader
                    ON grader.id = g.usermodified
                  LEFT JOIN {course_modules_completion} cmc
                    ON cmc.coursemoduleid = cm.id
                   AND cmc.userid = eu.userid
                  LEFT JOIN {user_lastaccess} ul
                    ON ul.courseid = gi.courseid
                   AND ul.userid = eu.userid
                 WHERE {$where}
              ORDER BY c.fullname ASC, gi.itemname ASC, u.firstname ASC, u.lastname ASC";

        $records = $DB->get_records_sql($sql, $params);
        $rows = [];
        foreach ($records as $record) {
            $coursename = format_string($record->coursename, true, ["context" => context_course::instance($record->courseid)]);
            $activityname = trim((string) $record->itemname) !== ""
                ? format_string($record->itemname, true, ["context" => context_course::instance($record->courseid)])
                : get_string("unnamedactivity", "gimidashboardreports_gradebookoverview");
            $learnername = trim($record->firstname . " " . $record->lastname);
            $gradedby = trim(($record->graderfirstname ?? "") . " " . ($record->graderlastname ?? ""));
            if ($gradedby === "") {
                $gradedby = $teacherbycourse[$record->courseid] ??
                    get_string("notavailable", "gimidashboardreports_gradebookoverview");
            }
            $activityurl = null;
            if (!empty($record->cmid) && !empty($record->itemmodule)) {
                $activityurl = new moodle_url("/mod/{$record->itemmodule}/view.php", ["id" => $record->cmid]);
            }

            $rows[] = [
                "course" => $coursename,
                "activity" => $activityname,
                "activityurl" => $activityurl,
                "learner" => s($learnername !== "" ? $learnername : $record->email),
                "email" => s($record->email),
                "grade" => self::format_percent($record->gradepercent !== null ? round((float) $record->gradepercent, 1) : null),
                "grader" => s($gradedby),
                "gradedtime" => self::format_date((int) ($record->gradedtime ?? 0)),
                "completion" => self::format_completion_state((int) ($record->completionstate ?? 0)),
                "completiontime" => self::format_date((int) ($record->completiontime ?? 0)),
                "lastaccess" => self::format_date((int) ($record->lastaccess ?? 0)),
            ];
        }

        return $rows;
    }

    /**
     * Builds the filter pills.
     *
     * @param object $selection Selection payload.
     * @param array $activityrows Activity rows.
     * @param array $learnerrows Learner rows.
     * @param int $activityid Selected activity id.
     * @param int $learnerid Selected learner id.
     * @return array
     */
    protected static function build_filters(
        object $selection,
        array $activityrows,
        array $learnerrows,
        int $activityid,
        int $learnerid
    ): array {
        $filters = [];

        if ($activityid > 0) {
            foreach ($activityrows as $row) {
                if ($row["itemid"] !== $activityid) {
                    continue;
                }

                $filters[] = [
                    "label" => get_string("activity", "gimidashboardreports_gradebookoverview"),
                    "value" => $row["activitylabel"],
                    "clearurl" => self::build_url($selection, 0, $learnerid),
                ];
                break;
            }
        }

        if ($learnerid > 0) {
            foreach ($learnerrows as $row) {
                if ($row["userid"] !== $learnerid) {
                    continue;
                }

                $filters[] = [
                    "label" => get_string("learner", "gimidashboardreports_gradebookoverview"),
                    "value" => $row["fullname"],
                    "clearurl" => self::build_url($selection, $activityid, 0),
                ];
                break;
            }
        }

        return $filters;
    }

    /**
     * Builds the summary object.
     *
     * @param array $activityrows Activity summary rows.
     * @param array $learnerrows Learner summary rows.
     * @param array $detailrows Detail rows.
     * @return object
     */
    protected static function build_summary(array $activityrows, array $learnerrows, array $detailrows): object {
        if (empty($activityrows)) {
            return self::empty_summary();
        }

        $averagevalues = [];
        $completionvalues = [];
        $lowestactivity = null;
        $gradedlearners = 0;
        $graderecords = 0;
        $activitieswithgrades = 0;

        foreach ($activityrows as $row) {
            if ($row["avggraderaw"] !== null) {
                $averagevalues[] = $row["avggraderaw"];
                $activitieswithgrades++;
                if ($lowestactivity === null || $row["avggraderaw"] < $lowestactivity["avggraderaw"]) {
                    $lowestactivity = $row;
                }
            }
            $completionvalues[] = $row["completionrateraw"];
            $graderecords += $row["gradedcount"];
        }

        foreach ($learnerrows as $row) {
            if ($row["gradedcount"] > 0) {
                $gradedlearners++;
            }
        }

        $lowestactivitylabel = get_string("dash", "gimidashboardreports_gradebookoverview");
        if ($lowestactivity !== null) {
            $lowestactivitylabel = $lowestactivity["activitylabel"] . " • " . self::format_percent($lowestactivity["avggraderaw"]);
        }

        $completioncrosscount = 0;
        foreach ($activityrows as $row) {
            $completioncrosscount += (int) $row["completedcount"];
        }

        return (object) [
            "trackedactivities" => count($activityrows),
            "gradedlearners" => $gradedlearners,
            "overallaverage" => !empty($averagevalues) ?
                round(array_sum($averagevalues) / count($averagevalues), 1) : null,
            "averagecompletion" => !empty($completionvalues) ?
                round(array_sum($completionvalues) / count($completionvalues), 1) : 0,
            "lowestactivitylabel" => $lowestactivitylabel,
            "graderecords" => $graderecords,
            "activitieswithgrades" => $activitieswithgrades,
            "completioncrosscount" => $completioncrosscount,
        ];
    }

    /**
     * Builds the detail title.
     *
     * @param array $detailrows Detail rows.
     * @param array $activityrows Activity rows.
     * @param array $learnerrows Learner rows.
     * @param int $activityid Selected activity id.
     * @param int $learnerid Selected learner id.
     * @return string
     */
    protected static function build_detail_title(
        array $detailrows,
        array $activityrows,
        array $learnerrows,
        int $activityid,
        int $learnerid
    ): string {
        if ($activityid > 0) {
            foreach ($activityrows as $row) {
                if ($row["itemid"] == $activityid) {
                    return get_string("activitydrilldown", "gimidashboardreports_gradebookoverview", $row["activitylabel"]);
                }
            }
        }

        if ($learnerid > 0) {
            foreach ($learnerrows as $row) {
                if ($row["userid"] == $learnerid) {
                    return get_string("learnerdrilldown", "gimidashboardreports_gradebookoverview", $row["fullname"]);
                }
            }
        }

        return !empty($detailrows)
            ? get_string("detailtable", "gimidashboardreports_gradebookoverview")
            : "";
    }

    /**
     * Renders the activity table.
     *
     * @param array $rows Activity rows.
     * @param object $selection Selection.
     * @return string
     * @throws Exception
     */
    protected static function render_activity_table(array $rows, object $selection): string {
        global $OUTPUT, $PAGE;

        $templatecontext = [
            "headers" => [
                ["label" => get_string("course")],
                ["label" => get_string("activity", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("teacher", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("averagegrade", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("lowestgrade", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("gradedlearners", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("submissionrate", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("completioncross", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("evaluationdate", "gimidashboardreports_gradebookoverview")],
            ],
            "rows" => [],
            "hasrows" => !empty($rows),
            "emptymessage" => get_string("nodata", "gimidashboardreports_gradebookoverview"),
        ];

        $selectedlearnerid = optional_param("learnerid", 0, PARAM_INT);
        foreach ($rows as $row) {
            $templatecontext["rows"][] = [
                "course" => $row["coursename"],
                "activity" => $row["activitylabel"],
                "activityfilterurl" => self::build_url($selection, $row["itemid"], $selectedlearnerid),
                "activityurl" => $row["activityurl"],
                "teacher" => $row["teacher"],
                "avggrade" => $row["avggrade"],
                "lowestgrade" => $row["lowestgrade"],
                "gradedcount" => $row["gradedcountdisplay"],
                "gradingrate" => $row["gradingrate"],
                "completionrate" => $row["completionrate"],
                "lastgraded" => $row["lastgraded"],
            ];
        }

        $pagelength = optional_param("plugin", false, PARAM_COMPONENT) ? 50 : 5;
        $PAGE->requires->js_call_amd(
            "local_gimidashboard/dashboard", "datatable", ["#gradebookoverview-activity-table", $pagelength]
        );
        return $OUTPUT->render_from_template("gimidashboardreports_gradebookoverview/activity_table", $templatecontext);
    }

    /**
     * Renders the learner table.
     *
     * @param array $rows Learner rows.
     * @param object $selection Selection.
     * @return string
     * @throws Exception
     */
    protected static function render_learner_table(array $rows, object $selection): string {
        global $OUTPUT, $PAGE;

        $templatecontext = [
            "headers" => [
                ["label" => get_string("learner", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("email")],
                ["label" => get_string("courses", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("activities", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("averagegrade", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("lowestgrade", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("submissionrate", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("completioncross", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("evaluationdate", "gimidashboardreports_gradebookoverview")],
            ],
            "rows" => [],
            "hasrows" => !empty($rows),
            "emptymessage" => get_string("nodata", "gimidashboardreports_gradebookoverview"),
        ];

        $selectedactivityid = optional_param("activityid", 0, PARAM_INT);
        foreach ($rows as $row) {
            $templatecontext["rows"][] = [
                "learner" => $row["fullname"],
                "learnerfilterurl" => self::build_url($selection, $selectedactivityid, $row["userid"]),
                "email" => $row["email"],
                "coursecount" => format_float($row["coursecount"], 0),
                "activitycount" => format_float($row["activitycount"], 0),
                "avggrade" => $row["avggrade"],
                "lowestgrade" => $row["lowestgrade"],
                "gradingrate" => $row["gradingrate"],
                "completionrate" => $row["completionrate"],
                "lastgraded" => $row["lastgraded"],
            ];
        }

        $pagelength = optional_param("plugin", false, PARAM_COMPONENT) ? 50 : 5;
        $PAGE->requires->js_call_amd(
            "local_gimidashboard/dashboard", "datatable", ["#gradebookoverview-learner-table", $pagelength]
        );
        return $OUTPUT->render_from_template("gimidashboardreports_gradebookoverview/learner_table", $templatecontext);
    }

    /**
     * Renders the detail table.
     *
     * @param array $rows Detail rows.
     * @param string $mode Detail mode.
     * @return string
     * @throws Exception
     */
    protected static function render_detail_table(array $rows, string $mode): string {
        global $OUTPUT, $PAGE;

        if ($mode === "activity") {
            $headers = [
                ["label" => get_string("learner", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("email")],
                ["label" => get_string("grade", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("gradedby", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("evaluationdate", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("completionstatus", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("completiondate", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("lastaccess", "gimidashboardreports_gradebookoverview")],
            ];
        } else {
            $headers = [
                ["label" => get_string("course")],
                ["label" => get_string("activity", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("grade", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("gradedby", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("evaluationdate", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("completionstatus", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("completiondate", "gimidashboardreports_gradebookoverview")],
                ["label" => get_string("lastaccess", "gimidashboardreports_gradebookoverview")],
            ];
        }

        $templatecontext = [
            "activitymode" => $mode === "activity",
            "headers" => $headers,
            "rows" => $rows,
            "hasrows" => !empty($rows),
            "emptymessage" => get_string("nodata", "gimidashboardreports_gradebookoverview"),
        ];

        $pagelength = optional_param("plugin", false, PARAM_COMPONENT) ? 50 : 5;
        $PAGE->requires->js_call_amd(
            "local_gimidashboard/dashboard", "datatable", ["#gradebookoverview-detail-table", $pagelength]
        );
        return $OUTPUT->render_from_template("gimidashboardreports_gradebookoverview/detail_table", $templatecontext);
    }

    /**
     * Returns the grade percentage SQL fragment.
     *
     * @param string $alias Grade table alias.
     * @return string
     */
    protected static function get_grade_percent_sql(string $alias): string {
        return "(CASE
                    WHEN {$alias}.finalgrade IS NULL THEN NULL
                    WHEN ({$alias}.rawgrademax - {$alias}.rawgrademin) > 0
                        THEN (({$alias}.finalgrade - {$alias}.rawgrademin) / ({$alias}.rawgrademax - {$alias}.rawgrademin)) * 100
                    WHEN {$alias}.rawgrademax > 0
                        THEN ({$alias}.finalgrade / {$alias}.rawgrademax) * 100
                    ELSE {$alias}.finalgrade
                END)";
    }

    /**
     * Builds an in-report URL.
     *
     * @param object $selection Selection payload.
     * @param int $activityid Activity id.
     * @param int $learnerid Learner id.
     * @return moodle_url
     */
    protected static function build_url(object $selection, int $activityid = 0, int $learnerid = 0): moodle_url {
        $params = [
            "target" => $selection->target,
        ];

        $plugin = optional_param("plugin", "", PARAM_COMPONENT);
        if ($plugin !== "") {
            $params["plugin"] = $plugin;
        }

        if ($activityid > 0) {
            $params["activityid"] = $activityid;
        }
        if ($learnerid > 0) {
            $params["learnerid"] = $learnerid;
        }

        return new moodle_url("/local/gimidashboard/", $params);
    }

    /**
     * Formats a percentage or returns a placeholder.
     *
     * @param float|null $value Percentage value.
     * @return string
     */
    protected static function format_percent(?float $value): string {
        if ($value === null) {
            return get_string("dash", "gimidashboardreports_gradebookoverview");
        }

        return format_float($value, 1) . "%";
    }

    /**
     * Formats a date or returns a placeholder.
     *
     * @param int $timestamp Timestamp.
     * @return string
     */
    protected static function format_date(int $timestamp): string {
        if ($timestamp <= 0) {
            return get_string("dash", "gimidashboardreports_gradebookoverview");
        }

        return userdate($timestamp, get_string("strftimedatetime", "langconfig"));
    }

    /**
     * Formats the completion state.
     *
     * @param int $state Completion state.
     * @return string
     */
    protected static function format_completion_state(int $state): string {
        if ($state === 2) {
            return get_string("completionpass", "gimidashboardreports_gradebookoverview");
        }
        if ($state === 3) {
            return get_string("completionfail", "gimidashboardreports_gradebookoverview");
        }
        if ($state === 1) {
            return get_string("completioncomplete", "gimidashboardreports_gradebookoverview");
        }

        return get_string("completionincomplete", "gimidashboardreports_gradebookoverview");
    }
}
