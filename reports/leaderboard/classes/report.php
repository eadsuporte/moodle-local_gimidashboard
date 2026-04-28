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
 * @package   gimidashboardreports_leaderboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gimidashboardreports_leaderboard;

use coding_exception;
use context_system;
use Exception;
use local_gimidashboard\header_helper;
use local_gimidashboard\page\selection_resolver;
use local_gimidashboard\report\base_report;
use local_gimidashboard\report\grade;
use local_gimidashboard\report\report_interface;
use moodle_url;
use stdClass;
use xmldb_field;
use xmldb_table;

/**
 * Leaderboards report.
 *
 * This first version assumes pathway = cohort linked to the selected course(s).
 * Category selections render the pathway leaderboard and course selections render
 * the course leaderboard.
 *
 * @package   gimidashboardreports_leaderboard
 */
class report implements report_interface {
    /**
     * Returns true for course selections.
     *
     * @return bool
     */
    public static function supports_course(): bool {
        return true;
    }

    /**
     * Returns true for category selections.
     *
     * @return bool
     */
    public static function supports_category(): bool {
        return true;
    }

    /**
     * Returns the report header.
     *
     * @param array $courses Accessible course records.
     * @param string $extra Optional header action HTML.
     * @return string
     * @throws Exception
     */
    public static function get_header(array $courses, $extra = ""): string {
        $reportdata = self::prepare_report_data($courses);
        $scope = header_helper::get_scope_data($reportdata->selection, $reportdata->courseids);

        return header_helper::render_standard_header(
            header_helper::get_leaderboard_title($reportdata->selection, $reportdata->courseids),
            $reportdata->selection,
            $reportdata->courseids,
            [
                $scope->academyname,
                $reportdata->learnercount > 0
                    ? get_string("learners", "gimidashboardreports_leaderboard") . ": " . $reportdata->learnercount
                    : "",
            ],
            $extra,
            "gimidashboardreports_leaderboard"
        );
    }

    /**
     * Renders the report HTML.
     *
     * @param array $courses Accessible course records.
     * @return string
     * @throws Exception
     */
    public static function render(array $courses): string {
        global $OUTPUT, $PAGE;

        $reportdata = self::prepare_report_data($courses);

        $messageiswarning = false;
        if ($reportdata->state === "emptyselection") {
            $message = get_string("emptyselection", "gimidashboardreports_leaderboard");
            $messageiswarning = true;
        } else if ($reportdata->state === "nopathways") {
            $message = get_string("nopathways", "gimidashboardreports_leaderboard");
            $messageiswarning = true;
        } else if ($reportdata->state === "choosepathway") {
            $message = get_string("choosepathway", "gimidashboardreports_leaderboard");
            $messageiswarning = true;
        } else if ($reportdata->state === "nolearners") {
            $message = get_string("nolearners", "gimidashboardreports_leaderboard");
            $messageiswarning = true;
        } else if ($reportdata->autopathway) {
            $message = get_string("autopathway", "gimidashboardreports_leaderboard");
        } else {
            $message = get_string("scopenotice", "gimidashboardreports_leaderboard");
        }

        $pagelength = optional_param("plugin", false, PARAM_COMPONENT) ? 50 : 5;
        $PAGE->requires->js_call_amd("local_gimidashboard/dashboard", "datatable", ["#gimi-leaderboard-table", $pagelength]);
        return $OUTPUT->render_from_template("gimidashboardreports_leaderboard/content", [
            "hasmessage" => $message !== "",
            "message" => $message,
            "messageiswarning" => $messageiswarning,
            "showpathwayoptions" => !empty($reportdata->pathwayoptions),
            "pathwayoptions" => $reportdata->pathwayoptions,
            "hasclearpathway" => $reportdata->cohortid > 0 && count($reportdata->pathwayoptions) > 1,
            "clearpathwayurl" => self::build_url($reportdata->selection->target, 0, false),
            "haskpis" => !empty($reportdata->kpis),
            "kpis" => $reportdata->kpis,
            "hasboards" => !empty($reportdata->boards),
            "boards" => $reportdata->boards,
        ]);
    }

    /**
     * Prepares the report payload.
     *
     * @param array $courses Accessible courses.
     * @return object
     * @throws Exception
     */
    protected static function prepare_report_data(array $courses = []): object {
        global $USER;

        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $selection = selection_resolver::resolve(optional_param("target", "", PARAM_TEXT), $USER->id);
        $courseids = base_report::extract_course_ids($courses);

        $base = (object) [
            "state" => "ready",
            "selection" => $selection,
            "courseids" => $courseids,
            "cohortid" => 0,
            "pathwayname" => "",
            "pathwayoptions" => [],
            "autopathway" => false,
            "learnercount" => 0,
            "kpis" => [],
            "boards" => [],
        ];

        if (empty($courseids)) {
            $base->state = "emptyselection";
            $cache = $base;
            return $cache;
        }

        $linkedpathways = self::get_linked_pathways($courseids);
        $requestedcohortid = optional_param("cohortid", 0, PARAM_INT);
        $cohortid = $requestedcohortid;
        $autopathway = false;
        if ($cohortid === 0 && count($linkedpathways) === 1) {
            $cohortid = array_key_first($linkedpathways);
            $autopathway = true;
        }

        $base->pathwayoptions = self::build_pathway_options($selection->target, $linkedpathways, $cohortid);

        if (empty($linkedpathways)) {
            $base->state = "nopathways";
            $cache = $base;
            return $cache;
        }

        if ($cohortid <= 0 || !isset($linkedpathways[$cohortid])) {
            $base->state = "choosepathway";
            $cache = $base;
            return $cache;
        }

        $base->cohortid = $cohortid;
        $base->pathwayname = $linkedpathways[$cohortid];
        $base->autopathway = $autopathway;

        $users = self::get_learners($courseids, $cohortid);
        if (empty($users)) {
            $base->state = "nolearners";
            $base->kpis = self::build_kpis($selection, $base->pathwayname, $courseids, 0);
            $cache = $base;
            return $cache;
        }

        $userids = array_keys($users);
        $usercourses = base_report::get_user_courses($courseids, $userids);
        $moduletotals = base_report::get_trackable_module_totals($courseids);
        $completedmodules = base_report::get_completed_module_totals($courseids, $userids);
        $grades = grade::get_course_grade_percentages($courseids, $userids);
        $completions = base_report::get_course_completions($courseids, $userids);
        $firstaccesstimes = base_report::get_first_course_access_times($courseids, $userids);
        $certificatetimes = self::get_certificate_issue_times($courseids, $userids);

        if ($selection->type === "course") {
            $courseid = reset($courseids);
            $base->boards = [
                self::build_course_best_grade_board($users, $grades, $courseid),
                self::build_course_progress_board($users, $moduletotals, $completedmodules, $completions, $courseid),
                self::build_course_fastest_board($users, $firstaccesstimes, $certificatetimes, $courseid),
            ];
        } else {
            $coursenames = self::get_course_names($courseids);
            $base->boards = [
                self::build_pathway_best_grade_board($users, $usercourses, $grades),
                self::build_pathway_progress_board($users, $usercourses, $moduletotals, $completedmodules, $completions),
                self::build_pathway_fastest_board($users, $usercourses, $firstaccesstimes, $certificatetimes, $coursenames),
            ];
        }

        $base->learnercount = count($users);
        $base->kpis = self::build_kpis($selection, $base->pathwayname, $courseids, $base->learnercount);
        $cache = $base;
        return $cache;
    }

    /**
     * Returns the linked pathways for the selected courses.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws Exception
     */
    protected static function get_linked_pathways(array $courseids): array {
        global $DB;

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params["enroltype"] = "cohort";

        $sql = "SELECT DISTINCT c.id, c.name
                  FROM {enrol} e
                  JOIN {cohort} c
                    ON c.id = e.customint1
                 WHERE e.courseid {$coursesql}
                   AND e.enrol = :enroltype
                   AND e.customint1 > 0
              ORDER BY c.name ASC";

        $records = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($records as $record) {
            $result[$record->id] = format_string($record->name, true, ["context" => context_system::instance()]);
        }

        return $result;
    }

    /**
     * Builds the pathway option links.
     *
     * @param string $target Current target.
     * @param array $pathways Pathways.
     * @param int $selectedcohortid Selected cohort id.
     * @return array
     * @throws Exception
     */
    protected static function build_pathway_options(string $target, array $pathways, int $selectedcohortid): array {
        $options = [];
        foreach ($pathways as $cohortid => $name) {
            $options[] = [
                "name" => $name,
                "url" => self::build_url($target, $cohortid),
                "selected" => $selectedcohortid === $cohortid,
            ];
        }

        return $options;
    }

    /**
     * Returns learners scoped to the selected courses and pathway.
     *
     * @param array $courseids Course ids.
     * @param int $cohortid Cohort id.
     * @return array
     * @throws Exception
     */
    protected static function get_learners(array $courseids, int $cohortid): array {
        global $DB;

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params["cohortid"] = $cohortid;
        $params["uconfirmed"] = 1;

        $sql = "SELECT DISTINCT u.id,
                                u.firstname,
                                u.lastname,
                                u.email,
                                u.suspended,
                                u.deleted,
                                u.firstnamephonetic,
                                u.lastnamephonetic,
                                u.middlename,
                                u.alternatename
                  FROM {user} u
                  JOIN {cohort_members} cm
                    ON cm.userid = u.id
                   AND cm.cohortid = :cohortid
                  JOIN {user_enrolments} ue
                    ON ue.userid = u.id
                  JOIN {enrol} e
                    ON e.id = ue.enrolid
                 WHERE e.courseid {$coursesql}
                   AND e.status = 0
                   AND ue.status = 0
                   AND u.deleted = 0
                   AND u.confirmed = :uconfirmed
              ORDER BY u.firstname ASC, u.lastname ASC, u.email ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Returns course names keyed by id.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws Exception
     */
    protected static function get_course_names(array $courseids): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $records = $DB->get_records_select("course", "id {$insql}", $params, "", "id, fullname, shortname");

        $result = [];
        foreach ($records as $record) {
            $result[$record->id] = format_string($record->fullname ?: $record->shortname);
        }

        return $result;
    }

    /**
     * Returns enrolment start timestamps by user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_enrolment_times(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT CONCAT(ue.userid, '-', e.courseid) AS unik,
                       ue.userid,
                       e.courseid,
                       MIN(ue.timecreated) AS timecreated
                  FROM {user_enrolments} ue
                  JOIN {enrol} e
                    ON e.id = ue.enrolid
                 WHERE e.courseid {$coursesql}
                   AND ue.userid {$usersql}
                   AND e.status = 0
                   AND ue.status = 0
              GROUP BY ue.userid, e.courseid";

        $records = $DB->get_records_sql($sql, $courseparams + $userparams);
        $result = [];
        foreach ($records as $record) {
            $result[$record->userid][$record->courseid] = $record->timecreated;
        }

        return $result;
    }

    /**
     * Returns certificate issue timestamps by user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_certificate_issue_times(array $courseids, array $userids): array {
        $result = [];

        foreach (self::get_customcert_issue_times($courseids, $userids) as $userid => $courses) {
            foreach ($courses as $courseid => $timestamp) {
                if (!isset($result[$userid][$courseid]) || $timestamp < $result[$userid][$courseid]) {
                    $result[$userid][$courseid] = $timestamp;
                }
            }
        }

        foreach (self::get_tool_certificate_issue_times($courseids, $userids) as $userid => $courses) {
            foreach ($courses as $courseid => $timestamp) {
                if (!isset($result[$userid][$courseid]) || $timestamp < $result[$userid][$courseid]) {
                    $result[$userid][$courseid] = $timestamp;
                }
            }
        }

        return $result;
    }

    /**
     * Returns custom certificate issue timestamps.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_customcert_issue_times(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(new xmldb_table("customcert")) ||
            !$dbman->table_exists(new xmldb_table("customcert_issues")) ||
            !$dbman->field_exists(new xmldb_table("customcert"), new xmldb_field("course")) ||
            !$dbman->field_exists(new xmldb_table("customcert_issues"), new xmldb_field("customcertid")) ||
            !$dbman->field_exists(new xmldb_table("customcert_issues"), new xmldb_field("userid")) ||
            !$dbman->field_exists(new xmldb_table("customcert_issues"), new xmldb_field("timecreated"))) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT CONCAT(ci.userid, '-', cc.course) AS unik,
                       ci.userid,
                       cc.course,
                       MIN(ci.timecreated) AS timeissued
                  FROM {customcert_issues} ci
                  JOIN {customcert} cc
                    ON cc.id = ci.customcertid
                 WHERE cc.course {$coursesql}
                   AND ci.userid {$usersql}
                   AND ci.timecreated > 0
              GROUP BY ci.userid, cc.course";

        $records = $DB->get_records_sql($sql, $courseparams + $userparams);
        $result = [];
        foreach ($records as $record) {
            $result[$record->userid][$record->course] = $record->timeissued;
        }

        return $result;
    }

    /**
     * Returns tool certificate issue timestamps when the schema exposes courseid.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_tool_certificate_issue_times(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(new xmldb_table("tool_certificate_templates")) ||
            !$dbman->table_exists(new xmldb_table("tool_certificate_issues")) ||
            !$dbman->field_exists(new xmldb_table("tool_certificate_templates"), new xmldb_field("courseid")) ||
            !$dbman->field_exists(new xmldb_table("tool_certificate_issues"), new xmldb_field("templateid")) ||
            !$dbman->field_exists(new xmldb_table("tool_certificate_issues"), new xmldb_field("userid")) ||
            !$dbman->field_exists(new xmldb_table("tool_certificate_issues"), new xmldb_field("timecreated"))) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT CONCAT(ti.userid, '-', tt.courseid) AS unik,
                       ti.userid,
                       tt.courseid,
                       MIN(ti.timecreated) AS timeissued
                  FROM {tool_certificate_issues} ti
                  JOIN {tool_certificate_templates} tt
                    ON tt.id = ti.templateid
                 WHERE tt.courseid {$coursesql}
                   AND ti.userid {$usersql}
                   AND ti.timecreated > 0
              GROUP BY ti.userid, tt.courseid";

        $records = $DB->get_records_sql($sql, $courseparams + $userparams);
        $result = [];
        foreach ($records as $record) {
            $result[$record->userid][$record->courseid] = $record->timeissued;
        }

        return $result;
    }

    /**
     * Builds the pathway Best Grade board.
     *
     * @param array $users Users.
     * @param array $usercourses Selected course ids.
     * @param array $grades Grades.
     * @return array
     * @throws coding_exception
     */
    protected static function build_pathway_best_grade_board(array $users, array $usercourses, array $grades): array {
        $rows = [];
        foreach ($users as $userid => $user) {
            $gradevalues = [];
            foreach (array_keys($usercourses[$userid] ?? []) as $courseid) {
                if (isset($grades[$userid][$courseid]) && $grades[$userid][$courseid] !== null && $grades[$userid][$courseid] > 0) {
                    $gradevalues[] = (float) $grades[$userid][$courseid];
                }
            }

            $metric = !empty($gradevalues) ? round(array_sum($gradevalues) / count($gradevalues), 1) : null;
            $rows[] = self::build_row(
                $user,
                $metric,
                //$metric !== null ? self::format_percent($metric) : "—",
                $metric !== null ? $metric : "—",
                $metric !== null
                    ? get_string("gradedcourses", "gimidashboardreports_leaderboard", count($gradevalues))
                    : get_string("notrankedassessment", "gimidashboardreports_leaderboard")
            );
        }

        $board = self::build_board(
            get_string("bestgrade", "gimidashboardreports_leaderboard"),
            get_string("bestgradedescpathway", "gimidashboardreports_leaderboard"),
            get_string("pathwayleaderboard", "gimidashboardreports_leaderboard"),
            $rows,
            false,
            true,
            false,
            [],
            get_string("grade", "gimidashboardreports_leaderboard")
        );

        return $board;
    }

    /**
     * Builds the pathway Most Progress board.
     *
     * @param array $users Users.
     * @param array $usercourses Selected course ids.
     * @param array $moduletotals Trackable totals.
     * @param array $completedmodules Completed modules.
     * @param array $completions Course completions.
     * @return array
     * @throws coding_exception
     * @throws \Exception
     */
    protected static function build_pathway_progress_board(
        array $users,
        array $usercourses,
        array $moduletotals,
        array $completedmodules,
        array $completions
    ): array {
        $rows = [];
        foreach ($users as $userid => $user) {
            $usercourseids = array_keys($usercourses[$userid] ?? []);
            $metric = base_report::calculate_average_course_progress(
                $usercourseids,
                $moduletotals,
                $completedmodules[$userid] ?? [],
                $completions[$userid] ?? []
            );
            $rows[] = self::build_row(
                $user,
                $metric,
                self::format_percent($metric),
                get_string("pathwaycourses", "gimidashboardreports_leaderboard", count($usercourseids))
            );
        }

        return self::build_board(
            get_string("mostprogress", "gimidashboardreports_leaderboard"),
            get_string("mostprogressdescpathway", "gimidashboardreports_leaderboard"),
            get_string("pathwayleaderboard", "gimidashboardreports_leaderboard"),
            $rows,
            true,
            true,
            false,
            [],
            get_string("progress", "gimidashboardreports_leaderboard")
        );
    }

    /**
     * Builds the pathway Fastest to Finish Top 5 board.
     *
     * @param array $users Users.
     * @param array $usercourses Selected course ids.
     * @param array $firstaccesstimes First access timestamps.
     * @param array $certificatetimes Certificate issue timestamps.
     * @param array $coursenames Course names.
     * @return array
     * @throws coding_exception
     */
    protected static function build_pathway_fastest_board(
        array $users,
        array $usercourses,
        array $firstaccesstimes,
        array $certificatetimes,
        array $coursenames
    ): array {
        $rows = [];
        foreach ($users as $userid => $user) {
            $bestmetric = null;
            $bestcourseid = 0;
            $beststarttime = 0;
            $bestcertificatetime = 0;

            foreach (array_keys($usercourses[$userid] ?? []) as $courseid) {
                if (empty($firstaccesstimes[$userid][$courseid]) || empty($certificatetimes[$userid][$courseid])) {
                    continue;
                }

                $seconds = max(
                    0,
                    $certificatetimes[$userid][$courseid] - $firstaccesstimes[$userid][$courseid]
                );
                $metric = (float) $seconds;

                if ($bestmetric === null || $metric < $bestmetric) {
                    $bestmetric = $metric;
                    $bestcourseid = $courseid;
                    $beststarttime = $firstaccesstimes[$userid][$courseid];
                    $bestcertificatetime = $certificatetimes[$userid][$courseid];
                }
            }

            $details = $bestmetric !== null
                ? get_string(
                    "fastestpathwaydetails",
                    "gimidashboardreports_leaderboard",
                    [
                        "course" => $coursenames[$bestcourseid] ?? get_string("course", "gimidashboardreports_leaderboard"),
                        "date" => userdate($bestcertificatetime),
                    ]
                )
                : get_string("notrankedcertificateaccess", "gimidashboardreports_leaderboard");

            $rows[] = self::build_row(
                $user,
                $bestmetric,
                $bestmetric !== null
                    ? self::format_duration_hours((int) round($bestmetric))
                    : "—",
                $details,
                [
                    [
                        "value" => $bestmetric !== null ? self::format_duration_hours((int) round($bestmetric)) : "—",
                        "sortvalue" => $bestmetric !== null ? (int) round($bestmetric) : 999999999,
                    ],
                    [
                        "value" => $beststarttime > 0 ? userdate($beststarttime) : "—",
                        "sortvalue" => $beststarttime,
                    ],
                    [
                        "value" => $bestcertificatetime > 0 ? userdate($bestcertificatetime) : "—",
                        "sortvalue" => $bestcertificatetime,
                    ],
                ]
            );
        }

        return self::build_board(
            get_string("fastesttofinishtop5", "gimidashboardreports_leaderboard"),
            get_string("fastesttofinishtop5desc", "gimidashboardreports_leaderboard"),
            get_string("pathwayleaderboard", "gimidashboardreports_leaderboard"),
            $rows,
            false,
            false,
            true,
            [
                ["label" => get_string("timetocertificate", "gimidashboardreports_leaderboard")],
                ["label" => get_string("startdate", "gimidashboardreports_leaderboard")],
                ["label" => get_string("certificateissued", "gimidashboardreports_leaderboard")],
            ]
        );
    }

    /**
     * Builds the course Best Grade board.
     *
     * @param array $users Users.
     * @param array $grades Grades.
     * @param int $courseid Course id.
     * @return array
     * @throws coding_exception
     */
    protected static function build_course_best_grade_board(array $users, array $grades, int $courseid): array {
        $rows = [];
        foreach ($users as $userid => $user) {
            $metric = isset($grades[$userid][$courseid]) && $grades[$userid][$courseid] !== null && $grades[$userid][$courseid] > 0
                ? (float) $grades[$userid][$courseid]
                : null;

            $rows[] = self::build_row(
                $user,
                $metric,
                $metric !== null ? self::format_percent($metric) : "—",
                $metric !== null
                    ? get_string("bestgradedesccourse", "gimidashboardreports_leaderboard")
                    : get_string("notrankedassessment", "gimidashboardreports_leaderboard")
            );
        }

        return self::build_board(
            get_string("bestgrade", "gimidashboardreports_leaderboard"),
            get_string("bestgradedesccourse", "gimidashboardreports_leaderboard"),
            get_string("courseleaderboard", "gimidashboardreports_leaderboard"),
            $rows,
            false,
            true,
            false,
            [],
            get_string("grade", "gimidashboardreports_leaderboard")
        );
    }

    /**
     * Builds the course Most Progress board.
     *
     * @param array $users Users.
     * @param array $moduletotals Trackable totals.
     * @param array $completedmodules Completed modules.
     * @param array $completions Course completions.
     * @param int $courseid Course id.
     * @return array
     * @throws coding_exception
     */
    protected static function build_course_progress_board(
        array $users,
        array $moduletotals,
        array $completedmodules,
        array $completions,
        int $courseid
    ): array {
        $rows = [];
        foreach ($users as $userid => $user) {
            $metric = base_report::calculate_course_progress(
                $moduletotals[$courseid] ?? 0,
                $completedmodules[$userid][$courseid] ?? 0,
                !empty($completions[$userid][$courseid])
            );

            $rows[] = self::build_row(
                $user,
                $metric,
                self::format_percent($metric),
                get_string("courseprogressdetails", "gimidashboardreports_leaderboard", round($metric, 1))
            );
        }

        return self::build_board(
            get_string("mostprogress", "gimidashboardreports_leaderboard"),
            get_string("mostprogressdesccourse", "gimidashboardreports_leaderboard"),
            get_string("courseleaderboard", "gimidashboardreports_leaderboard"),
            $rows,
            true,
            true,
            false,
            [],
            get_string("progress", "gimidashboardreports_leaderboard")
        );
    }

    /**
     * Builds the course Fastest to Finish board.
     *
     * @param array $users Users.
     * @param array $firstaccesstimes
     * @param array $certificatetimes Certificate times.
     * @param int $courseid Course id.
     * @return array
     * @throws \coding_exception
     */
    protected static function build_course_fastest_board(
        array $users,
        array $firstaccesstimes,
        array $certificatetimes,
        int $courseid
    ): array {
        $rows = [];
        foreach ($users as $userid => $user) {
            $metric = null;
            $starttime = 0;
            $certificatetime = 0;

            if (!empty($firstaccesstimes[$userid][$courseid]) && !empty($certificatetimes[$userid][$courseid])) {
                $starttime = (int) $firstaccesstimes[$userid][$courseid];
                $certificatetime = (int) $certificatetimes[$userid][$courseid];
                $metric = (float) max(0, $certificatetime - $starttime);
            }

            $rows[] = self::build_row(
                $user,
                $metric,
                $metric !== null ? self::format_duration_hours((int) round($metric)) : "—",
                $metric !== null
                    ? get_string("certissuedon", "gimidashboardreports_leaderboard", userdate($certificatetime))
                    : get_string("notrankedcertificateaccess", "gimidashboardreports_leaderboard"),
                [
                    [
                        "value" => $metric !== null ? self::format_duration_hours((int) round($metric)) : "—",
                        "sortvalue" => $metric !== null ? (int) round($metric) : 999999999,
                    ],
                    [
                        "value" => $starttime > 0 ? userdate($starttime) : "—",
                        "sortvalue" => $starttime,
                    ],
                    [
                        "value" => $certificatetime > 0 ? userdate($certificatetime) : "—",
                        "sortvalue" => $certificatetime,
                    ],
                ]
            );
        }

        return self::build_board(
            get_string("fastesttofinish", "gimidashboardreports_leaderboard"),
            get_string("fastesttofinishdesc", "gimidashboardreports_leaderboard"),
            get_string("courseleaderboard", "gimidashboardreports_leaderboard"),
            $rows,
            false,
            false,
            false,
            [
                ["label" => get_string("timetocertificate", "gimidashboardreports_leaderboard")],
                ["label" => get_string("startdate", "gimidashboardreports_leaderboard")],
                ["label" => get_string("certificateissued", "gimidashboardreports_leaderboard")],
            ]
        );
    }

    /**
     * Builds a raw leaderboard row.
     *
     * @param stdClass $user User.
     * @param float|null $metric Metric.
     * @param string $valuedisplay Display value.
     * @param string $details Details.
     * @param array $extracells
     * @return array
     */
    protected static function build_row(
        stdClass $user,
        ?float $metric,
        string $valuedisplay,
        string $details,
        array $extracells = []
    ): array {
        return [
            "userid" => $user->id,
            "fullname" => fullname($user),
            "firstname" => s($user->firstname),
            "lastname" => s($user->lastname),
            "email" => s($user->email),
            "metric" => $metric,
            "valuedisplay" => $valuedisplay,
            "details" => $details,
            "extracells" => $extracells,
        ];
    }

    /**
     * Builds a board context.
     *
     * @param string $title Title.
     * @param string $description Description.
     * @param string $scopebadge Scope badge.
     * @param array $rows Rows.
     * @param bool $rankall Whether everyone is ranked.
     * @param bool $descending Whether higher is better.
     * @param bool $hideunranked Hide unranked rows.
     * @return array
     * @throws \coding_exception
     */
    protected static function build_board(
        string $title,
        string $description,
        string $scopebadge,
        array $rows,
        bool $rankall,
        bool $descending,
        bool $hideunranked = false,
        array $columns = [],
        string $valuetitle = ""
    ): array {
        $rows = self::rank_rows($rows, $rankall, $descending);

        if ($hideunranked) {
            $rows = array_values(array_filter($rows, static function(array $row): bool {
                return $row["rank"] !== null;
            }));
        }

        $hascustomcolumns = !empty($columns);

        return [
            "title" => $title,
            "description" => $description,
            "scopebadge" => $scopebadge,
            "hasrows" => !empty($rows),
            "rows" => array_slice($rows, 0, 5),
            "hascustomcolumns" => $hascustomcolumns,
            "customcolumns" => $columns,
            "value_title" => $valuetitle !== ""
                ? $valuetitle
                : get_string("value", "gimidashboardreports_leaderboard"),
            "emptymessage" => get_string("emptyboard", "gimidashboardreports_leaderboard"),
        ];
    }

    /**
     * Ranks the rows and decorates them for output.
     *
     * @param array $rows Raw rows.
     * @param bool $rankall Whether everyone is ranked.
     * @param bool $descending Whether higher is better.
     * @return array
     */
    protected static function rank_rows(array $rows, bool $rankall, bool $descending): array {
        usort($rows, static function(array $a, array $b) use ($rankall, $descending): int {
            $ametric = $a["metric"];
            $bmetric = $b["metric"];

            if (!$rankall) {
                if ($ametric === null && $bmetric !== null) {
                    return 1;
                }
                if ($ametric !== null && $bmetric === null) {
                    return -1;
                }
                if ($ametric === null && $bmetric === null) {
                    return strcasecmp($a["fullname"], $b["fullname"]);
                }
            }

            if ($ametric === null && $bmetric === null) {
                return strcasecmp($a["fullname"], $b["fullname"]);
            }

            if ($descending) {
                if ($ametric > $bmetric) {
                    return -1;
                }
                if ($ametric < $bmetric) {
                    return 1;
                }
            } else {
                if ($ametric < $bmetric) {
                    return -1;
                }
                if ($ametric > $bmetric) {
                    return 1;
                }
            }

            return strcasecmp($a["fullname"], $b["fullname"]);
        });

        $position = 0;
        $rank = 0;
        $lastmetric = null;
        $haslastmetric = false;

        foreach ($rows as $index => $row) {
            $position++;
            if (!$rankall && $row["metric"] === null) {
                $rows[$index]["rank"] = null;
            } else {
                if (!$haslastmetric || $row["metric"] !== $lastmetric) {
                    $rank = $position;
                    $lastmetric = $row["metric"];
                    $haslastmetric = true;
                }
                $rows[$index]["rank"] = $rank;
            }

            $rows[$index]["rankdisplay"] = $rows[$index]["rank"] !== null ? (string) $rows[$index]["rank"] : "—";
            $rows[$index]["ranksort"] = $rows[$index]["rank"] !== null ? $rows[$index]["rank"] : 999999;
            $rows[$index]["rankclass"] = self::get_rank_class($rows[$index]["rank"]);
            $rows[$index]["rowclass"] = $rows[$index]["rank"] === null ? "is-unranked" : "is-ranked";
        }

        return $rows;
    }

    /**
     * Returns the visual class for a rank.
     *
     * @param int|null $rank Rank number.
     * @return string
     */
    protected static function get_rank_class(?int $rank): string {
        if ($rank === 1) {
            return "is-gold";
        }
        if ($rank === 2) {
            return "is-silver";
        }
        if ($rank === 3) {
            return "is-bronze";
        }
        if ($rank === null) {
            return "is-unranked";
        }

        return "";
    }

    /**
     * Builds the top KPI cards.
     *
     * @param object $selection Selection payload.
     * @param string $pathwayname Pathway name.
     * @param array $courseids Course ids.
     * @param int $learnercount Learner count.
     * @return array
     * @throws coding_exception
     */
    protected static function build_kpis(object $selection, string $pathwayname, array $courseids, int $learnercount): array {
        return [
            [
                "label" => get_string("pathway", "gimidashboardreports_leaderboard"),
                "value" => $pathwayname !== "" ? $pathwayname : "—",
            ],
            [
                "label" => get_string("courses", "gimidashboardreports_leaderboard"),
                "value" => count($courseids),
            ],
            [
                "label" => get_string("learners", "gimidashboardreports_leaderboard"),
                "value" => $learnercount,
            ],
        ];
    }

    /**
     * Returns the SQL clause used to detect exam activities.
     *
     * @param string $primaryexpr Primary SQL expression.
     * @param string|null $secondaryexpr Optional secondary SQL expression.
     * @return string
     */
    protected static function get_exam_name_sql(string $primaryexpr, ?string $secondaryexpr = null): string {
        $expressions = [$primaryexpr];
        if ($secondaryexpr !== null) {
            $expressions[] = $secondaryexpr;
        }

        $parts = [];
        foreach ($expressions as $key => $expression) {
            $parts[] = "LOWER({$expression}) LIKE :examterm_a{$key}_1";
            $parts[] = "LOWER({$expression}) LIKE :examterm_a{$key}_2";
            $parts[] = "LOWER({$expression}) LIKE :examterm_a{$key}_3";
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Returns the LIKE params used to detect exam activities.
     *
     * @return array
     */
    protected static function get_exam_name_like_params(): array {
        return [
            "examterm_a0_1" => "%exam%",
            "examterm_a0_2" => "%final assessment%",
            "examterm_a0_3" => "%final test%",

            "examterm_a1_1" => "%exam%",
            "examterm_a1_2" => "%final assessment%",
            "examterm_a1_3" => "%final test%",
        ];
    }

    /**
     * Formats an elapsed duration using days, hours, minutes and seconds.
     *
     * @param int $seconds Total seconds.
     * @return string
     */
    protected static function format_duration_hours(int $seconds): string {
        $seconds = max(0, $seconds);

        $hours = intdiv($seconds, HOURSECS);
        $seconds -= $hours * HOURSECS;
        $minutes = intdiv($seconds, MINSECS);
        $seconds -= $minutes * MINSECS;

        return $hours . "h " . $minutes . "m " . $seconds . "s";
    }

    /**
     * Formats a percentage value.
     *
     * @param float|null $value Value.
     * @return string
     */
    protected static function format_percent(?float $value): string {
        if ($value === null) {
            return "—";
        }

        return format_float($value) . "%";
    }

    /**
     * Builds a report URL preserving the selected plugin.
     *
     * @param string $target Target.
     * @param int $cohortid Cohort id.
     * @param bool $showplugin
     * @return moodle_url
     * @throws \core\exception\moodle_exception
     */
    protected static function build_url(string $target, int $cohortid = 0, $showplugin = true): moodle_url {
        $params = [];
        if ($cohortid > 0) {
            $params["cohortid"] = $cohortid;
        }
        if ($showplugin) {
            $params["plugin"] = "leaderboard";
        }
        $params["target"] = $target;

        return new moodle_url("/local/gimidashboard/", $params);
    }
}
