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
 * @package   gimidashboardreports_fullacademydashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gimidashboardreports_fullacademydashboard;

use context_course;
use context_system;
use Exception;
use html_writer;
use local_gimidashboard\header_helper;
use local_gimidashboard\page\selection_resolver;
use local_gimidashboard\report\base_report;
use local_gimidashboard\report\report_interface;
use moodle_url;
use stdClass;
use xmldb_field;
use xmldb_table;

/**
 * Full academy dashboard report.
 *
 * @package   gimidashboardreports_fullacademydashboard
 */
class report implements report_interface {
    /**
     * Returns the report title.
     *
     * @param array $courses
     * @param string $extra
     * @return string
     * @throws Exception
     */
    public static function get_header(array $courses, $extra = ""): string {
        global $OUTPUT;

        $reportdata = self::prepare_report_data($courses);
        if (empty($reportdata->courseids)) {
            return "";
        }

        $scope = header_helper::get_scope_data($reportdata->selection, $reportdata->courseids);

        return header_helper::render_standard_header(
            header_helper::get_dashboard_title($reportdata->selection, $reportdata->courseids),
            $reportdata->selection,
            $reportdata->courseids,
            [$scope->academyname],
            $extra,
            "gimidashboardreports_fullacademydashboard"
        );
    }

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
     * Renders the report HTML.
     *
     * @param array $courses Accessible course records.
     * @return string
     * @throws Exception
     */
    public static function render(array $courses): string {
        global $OUTPUT;

        $reportdata = self::prepare_report_data($courses);
        if (empty($reportdata->courseids)) {
            return "";
        }

        $mustachedata = [
            "pluginname" => strtoupper(get_string("pluginname", "gimidashboardreports_fullacademydashboard")),
            "kpis" => [
                [
                    "label" => get_string("totallearners", "gimidashboardreports_fullacademydashboard"),
                    "value" => $reportdata->summary->totallearners,
                ],
                [
                    "label" => get_string("avgprogress", "gimidashboardreports_fullacademydashboard"),
                    "value" => self::format_percent($reportdata->summary->avgprogress),
                ],
                [
                    "label" => get_string("avggrade", "gimidashboardreports_fullacademydashboard"),
                    "value" => self::format_grade($reportdata->summary->avggrade),
                ],
                [
                    "label" => get_string("neveraccessed", "gimidashboardreports_fullacademydashboard"),
                    "value" => $reportdata->summary->neveraccessed,
                ],
                [
                    "label" => get_string("certsearned", "gimidashboardreports_fullacademydashboard"),
                    "value" => $reportdata->summary->certsearned,
                ],
                [
                    "label" => get_string("coursescomplete", "gimidashboardreports_fullacademydashboard"),
                    "value" => $reportdata->summary->coursescomplete,
                ],
            ],
            "summarytablehtml" => self::render_summary_table($reportdata->rows, $reportdata->selection),
            "detailtablehtml" => !empty($reportdata->detailrows)
                ? self::render_detail_table($reportdata->detailrows, $reportdata->selection)
                : "",
            "hasdetailtable" => !empty($reportdata->detailrows),
            "hasrows" => !empty($reportdata->rows),
            "hasfilters" => !empty($reportdata->filters),
            "filters" => $reportdata->filters,
            "reseturl" => self::build_url($reportdata->selection->target),
        ];
        return $OUTPUT->render_from_template("gimidashboardreports_fullacademydashboard/content", $mustachedata);
    }

    /**
     * Prepares the report data for rendering.
     *
     * @param array $courses Accessible course records.
     * @return object
     * @throws Exception
     */
    protected static function prepare_report_data(array $courses): object {
        global $USER;

        static $returndata = null;
        if ($returndata) {
            return $returndata;
        }

        $courseids = base_report::extract_course_ids($courses);
        if (empty($courseids)) {
            return (object) [
                "courseids" => [],
                "selection" => (object) ["target" => "", "label" => ""],
                "learnerid" => 0,
                "cohortid" => 0,
                "rows" => [],
                "detailrows" => [],
                "filters" => [],
                "summary" => self::build_summary([]),
                "pathwaycount" => 0,
            ];
        }

        $selection = selection_resolver::resolve(optional_param("target", "", PARAM_TEXT), $USER->id);
        $learnerid = optional_param("learnerid", 0, PARAM_INT);
        $cohortid = optional_param("cohortid", 0, PARAM_INT);

        $users = self::get_learners($courseids, $learnerid, $cohortid);
        $rows = [];
        $detailrows = [];
        $filters = [];

        if (!empty($users)) {
            $userids = array_keys($users);
            $usercourses = base_report::get_user_courses($courseids, $userids);
            $moduletotals = base_report::get_trackable_module_totals($courseids);
            $completedmodules = base_report::get_completed_module_totals($courseids, $userids);
            $day1completedmodules = self::get_day1_completed_module_totals($courseids, $userids);
            $firstaccesstimes = base_report::get_first_course_access_times($courseids, $userids);
            $examgrademetrics = self::get_exam_grade_metrics($courseids, $userids);
            $completions = base_report::get_course_completions($courseids, $userids);
            $certificatecounts = self::get_certificate_counts($courseids, $userids);
            $examcounts = self::get_exam_counts($courseids, $userids);
            $lastaccessbycourse = base_report::get_last_access_by_course($courseids, $userids);
            $pathways = base_report::get_user_pathways($courseids, $userids);
            $usercoursepathways = self::get_user_course_pathways($courseids, $userids);

            foreach ($users as $userid => $user) {
                $rows[$userid] = self::build_user_row(
                    $user,
                    $usercourses[$userid] ?? [],
                    $moduletotals,
                    $firstaccesstimes[$userid] ?? [],
                    $completedmodules[$userid] ?? [],
                    $day1completedmodules[$userid] ?? [],
                    $examgrademetrics[$userid] ?? [],
                    $completions[$userid] ?? [],
                    $certificatecounts[$userid] ?? [],
                    $examcounts[$userid] ?? [],
                    $lastaccessbycourse[$userid] ?? [],
                    $pathways[$userid] ?? [],
                    $selection
                );
            }

            if ($learnerid > 0 && !empty($rows[$learnerid])) {
                $detailrows = self::build_detail_rows(
                    $users[$learnerid],
                    $rows[$learnerid],
                    $courses,
                    $usercourses[$learnerid] ?? [],
                    $moduletotals,
                    $firstaccesstimes[$learnerid] ?? [],
                    $completedmodules[$learnerid] ?? [],
                    $day1completedmodules[$learnerid] ?? [],
                    $examgrademetrics[$learnerid] ?? [],
                    $completions[$learnerid] ?? [],
                    $certificatecounts[$learnerid] ?? [],
                    $examcounts[$learnerid] ?? [],
                    $lastaccessbycourse[$learnerid] ?? [],
                    $pathways[$learnerid] ?? [],
                    $usercoursepathways[$learnerid] ?? [],
                    $selection
                );
                $filters[] = [
                    "label" => get_string("filteredlearner", "gimidashboardreports_fullacademydashboard"),
                    "value" => fullname($users[$learnerid]),
                    "clearurl" => self::build_url($selection->target, 0, $cohortid),
                ];
            }

            if ($cohortid > 0) {
                $cohortname = self::get_cohort_name($cohortid);
                if ($cohortname !== "") {
                    $filters[] = [
                        "label" => get_string("filteredpathway", "gimidashboardreports_fullacademydashboard"),
                        "value" => $cohortname,
                        "clearurl" => self::build_url($selection->target, $learnerid),
                    ];
                }
            }
        }

        $returndata = (object) [
            "courseids" => $courseids,
            "selection" => $selection,
            "learnerid" => $learnerid,
            "cohortid" => $cohortid,
            "rows" => $rows,
            "detailrows" => $detailrows,
            "filters" => $filters,
            "summary" => self::build_summary($rows),
            "pathwaycount" => self::count_pathways($rows),
        ];
        return $returndata;
    }

    /**
     * Returns the learners enrolled in the selected courses.
     *
     * @param array $courseids Course ids.
     * @param int $learnerid Learner filter.
     * @param int $cohortid Cohort filter.
     * @return array
     * @throws Exception
     */
    protected static function get_learners(array $courseids, int $learnerid = 0, int $cohortid = 0): array {
        global $DB;

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params["uconfirmed"] = 1;
        $joins = "";
        $wheres = [
            "e.courseid {$coursesql}",
            "e.status = 0",
            "ue.status = 0",
            "u.deleted = 0",
            "u.confirmed = :uconfirmed",
        ];

        if ($learnerid > 0) {
            $params["learnerid"] = $learnerid;
            $wheres[] = "u.id = :learnerid";
        }

        if ($cohortid > 0) {
            $params["cohortid"] = $cohortid;
            $joins .= " JOIN {cohort_members} cm ON cm.userid = u.id AND cm.cohortid = :cohortid";
        }

        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.suspended, u.deleted,
                                u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                  FROM {user} u
                  JOIN {user_enrolments} ue
                    ON ue.userid = u.id
                  JOIN {enrol} e
                    ON e.id = ue.enrolid
                {$joins}
                 WHERE " . implode(" AND ", $wheres) . "
              ORDER BY u.firstname ASC, u.lastname ASC, u.email ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Returns completed module counts within the learner first day in the course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_day1_completed_module_totals(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesqla, $courseparamsa] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "coursea");
        [$coursesqlb, $courseparamsb] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "courseb");
        [$coursesqlc, $courseparamsc] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "coursec");
        [$usersqla, $userparamsa] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "usera");
        [$usersqlb, $userparamsb] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "userb");
        [$usersqlc, $userparamsc] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "userc");

        $dbman = $DB->get_manager();
        $haslogtable = $dbman->table_exists(new xmldb_table("logstore_standard_log")) &&
            $dbman->field_exists(new xmldb_table("logstore_standard_log"), new xmldb_field("userid")) &&
            $dbman->field_exists(new xmldb_table("logstore_standard_log"), new xmldb_field("courseid")) &&
            $dbman->field_exists(new xmldb_table("logstore_standard_log"), new xmldb_field("timecreated")) &&
            $dbman->field_exists(new xmldb_table("logstore_standard_log"), new xmldb_field("eventname"));

        $params = $courseparamsa + $courseparamsb + $courseparamsc + $userparamsa + $userparamsb + $userparamsc + [
                "oneday" => DAYSECS,
                "courseevent" => "\\core\\event\\course_viewed",
            ];

        $baselinejoin = "JOIN (
                SELECT enrol.userid,
                       enrol.courseid,
                       COALESCE(firstaccess.firstaccess, enrol.enroltime) AS basetime
                  FROM (
                        SELECT ue.userid,
                               e.courseid,
                               MIN(CASE
                                       WHEN ue.timecreated > 0 THEN ue.timecreated
                                       WHEN ue.timestart > 0 THEN ue.timestart
                                       ELSE NULL
                                   END) AS enroltime
                          FROM {user_enrolments} ue
                          JOIN {enrol} e
                            ON e.id = ue.enrolid
                         WHERE e.courseid {$coursesqla}
                           AND ue.userid {$usersqla}
                           AND e.status = 0
                           AND ue.status = 0
                      GROUP BY ue.userid, e.courseid
                  ) enrol";

        if ($haslogtable) {
            $baselinejoin .= "
             LEFT JOIN (
                        SELECT userid,
                               courseid,
                               MIN(timecreated) AS firstaccess
                          FROM {logstore_standard_log}
                         WHERE courseid {$coursesqlc}
                           AND userid {$usersqlc}
                           AND eventname = :courseevent
                           AND timecreated > 0
                      GROUP BY userid, courseid
                    ) firstaccess
                    ON firstaccess.userid = enrol.userid
                   AND firstaccess.courseid = enrol.courseid";
        } else {
            $baselinejoin .= "
             LEFT JOIN (
                        SELECT 0 AS userid, 0 AS courseid, NULL AS firstaccess
                    ) firstaccess
                    ON 1 = 0";
        }

        $baselinejoin .= "
            ) baseline
              ON baseline.userid = cmc.userid
             AND baseline.courseid = cm.course";

        $sql = "SELECT CONCAT(cmc.userid, '-', cm.course) AS unik,
                       cmc.userid,
                       cm.course,
                       COUNT(DISTINCT cmc.coursemoduleid) AS total
                  FROM {course_modules_completion} cmc
                  JOIN {course_modules} cm
                    ON cm.id = cmc.coursemoduleid
                  {$baselinejoin}
                 WHERE cm.course {$coursesqlb}
                   AND cmc.userid {$usersqlb}
                   AND cm.visible = 1
                   AND cm.deletioninprogress = 0
                   AND cm.completion > 0
                   AND cmc.completionstate > 0
                   AND baseline.basetime IS NOT NULL
                   AND cmc.timemodified > 0
                   AND cmc.timemodified <= baseline.basetime + :oneday
              GROUP BY cmc.userid, cm.course";
        $records = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($records as $record) {
            $result[$record->userid][$record->course] = $record->total;
        }

        return $result;
    }

    /**
     * Returns issued certificate counts by user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_certificate_counts(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        $dbman = $DB->get_manager();
        $sources = [];

        if ($dbman->table_exists(new xmldb_table("customcert")) && $dbman->table_exists(new xmldb_table("customcert_issues"))) {
            $sources[] = [
                "activitytable" => "customcert",
                "issuestable" => "customcert_issues",
                "issuefield" => "customcertid",
                "coursefield" => "course",
            ];
        }

        if ($dbman->table_exists(new xmldb_table("certificate")) && $dbman->table_exists(new xmldb_table("certificate_issues"))) {
            $sources[] = [
                "activitytable" => "certificate",
                "issuestable" => "certificate_issues",
                "issuefield" => "certificateid",
                "coursefield" => "course",
            ];
        }

        if ($dbman->table_exists(new xmldb_table("simplecertificate")) &&
            $dbman->table_exists(new xmldb_table("simplecertificate_issues"))) {
            $sources[] = [
                "activitytable" => "simplecertificate",
                "issuestable" => "simplecertificate_issues",
                "issuefield" => "certificateid",
                "coursefield" => "course",
            ];
        }

        if ($dbman->table_exists(new xmldb_table("tool_certificate_templates")) &&
            $dbman->table_exists(new xmldb_table("tool_certificate_issues")) &&
            $dbman->field_exists(new xmldb_table("tool_certificate_templates"), new xmldb_field("courseid"))) {
            $sources[] = [
                "activitytable" => "tool_certificate_templates",
                "issuestable" => "tool_certificate_issues",
                "issuefield" => "templateid",
                "coursefield" => "courseid",
            ];
        }

        if (empty($sources)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $result = [];
        foreach ($sources as $source) {
            $sql = "SELECT issues.userid,
                           activity." . $source["coursefield"] . " AS courseid,
                           COUNT(DISTINCT issues.id) AS total
                      FROM {" . $source["issuestable"] . "} issues
                      JOIN {" . $source["activitytable"] . "} activity
                        ON activity.id = issues." . $source["issuefield"] . "
                     WHERE activity." . $source["coursefield"] . " {$coursesql}
                       AND issues.userid {$usersql}
                  GROUP BY issues.userid, activity." . $source["coursefield"];

            $records = $DB->get_records_sql($sql, $courseparams + $userparams);
            foreach ($records as $record) {
                if (!isset($result[$record->userid][$record->courseid])) {
                    $result[$record->userid][$record->courseid] = 0;
                }
                $result[$record->userid][$record->courseid] += (int) $record->total;
            }
        }

        return $result;
    }

    /**
     * Returns exam counts by user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_exam_counts(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");
        $params = $courseparams + $userparams + self::get_exam_name_like_params(1);

        $sql = "SELECT CONCAT(qa.userid, '-', q.course) AS unik,
                       qa.userid,
                       q.course AS courseid,
                       COUNT(DISTINCT qa.id) AS total
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q
                    ON q.id = qa.quiz
                 WHERE q.course {$coursesql}
                   AND qa.userid {$usersql}
                   AND qa.state IN ('finished', 'abandoned', 'overdue')
                   AND " . self::get_exam_name_sql("COALESCE(q.name, '')") . "
              GROUP BY qa.userid, q.course";
        $records = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($records as $record) {
            $result[$record->userid][$record->courseid] = $record->total;
        }

        return $result;
    }

    /**
     * Returns exam grade metrics by user and course.
     *
     * The average is based only on attempted exams that have a calculable score,
     * so unattempted exams are excluded from the denominator.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_exam_grade_metrics(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "courseg");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "userg");
        $params = $courseparams + $userparams + self::get_exam_name_like_params(1);

        $sql = "SELECT CONCAT(qa.userid, '-', q.course) AS unik,
                       qa.userid,
                       q.course AS courseid,
                       SUM(CASE
                               WHEN q.sumgrades > 0 AND qa.sumgrades IS NOT NULL THEN (qa.sumgrades / q.sumgrades) * 100
                               ELSE 0
                           END) AS scoretotal,
                       SUM(CASE
                               WHEN q.sumgrades > 0 AND qa.sumgrades IS NOT NULL THEN 1
                               ELSE 0
                           END) AS scorecount
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q
                    ON q.id = qa.quiz
                 WHERE q.course {$coursesql}
                   AND qa.userid {$usersql}
                   AND qa.preview = 0
                   AND qa.state IN ('finished', 'abandoned', 'overdue')
                   AND " . self::get_exam_name_sql("COALESCE(q.name, '')") . "
              GROUP BY qa.userid, q.course";
        $records = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->userid][(int) $record->courseid] = (object) [
                "scoretotal" => (float) $record->scoretotal,
                "scorecount" => (int) $record->scorecount,
            ];
        }

        return $result;
    }

    /**
     * Returns pathway memberships keyed by user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_user_course_pathways(array $courseids, array $userids): array {
        global $DB;

        if (empty($courseids) || empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");
        $params = $courseparams + $userparams + [
                "enroltype" => "cohort",
            ];

        $sql = "SELECT CONCAT(cm.userid, '-', e.courseid, '-', c.id) AS unik,
                       cm.userid,
                       e.courseid,
                       c.id AS cohortid,
                       c.name
                  FROM {cohort_members} cm
                  JOIN {enrol} e
                    ON e.customint1 = cm.cohortid
                   AND e.enrol = :enroltype
                   AND e.status = 0
                  JOIN {cohort} c
                    ON c.id = cm.cohortid
                 WHERE e.courseid {$coursesql}
                   AND cm.userid {$usersql}
              ORDER BY c.name ASC";
        $records = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($records as $record) {
            $result[$record->userid][$record->courseid][$record->cohortid] =
                format_string($record->name, true, ["context" => context_system::instance()]);
        }

        return $result;
    }

    /**
     * Builds a single learner summary row.
     *
     * @param stdClass $user User record.
     * @param array $usercourseids Course ids.
     * @param array $moduletotals Module totals by course.
     * @param array $firstaccesstimes
     * @param array $completedmodules Completed modules by course.
     * @param array $day1completedmodules
     * @param array $examgrademetrics Exam grade metrics by course.
     * @param array $completions Completion timestamps by course.
     * @param array $certificatecounts
     * @param array $examcounts Exam counts by course.
     * @param array $lastaccessbycourse Last access by course.
     * @param array $pathways Pathways.
     * @param object $selection Selection payload.
     * @return object
     * @throws \coding_exception
     */
    protected static function build_user_row(
        stdClass $user,
        array $usercourseids,
        array $moduletotals,
        array $firstaccesstimes,
        array $completedmodules,
        array $day1completedmodules,
        array $examgrademetrics,
        array $completions,
        array $certificatecounts,
        array $examcounts,
        array $lastaccessbycourse,
        array $pathways,
        object $selection
    ): object {
        $courseprogresses = [];
        $day1courseprogresses = [];
        $examscoretotal = 0.0;
        $examscorecount = 0;
        $completedcount = 0;
        $certtotal = 0;
        $examtotal = 0;
        $lastaccess = 0;

        foreach ($usercourseids as $courseid) {
            $trackable = $moduletotals[$courseid] ?? 0;
            $completedmodulescount = $completedmodules[$courseid] ?? 0;
            $day1completedmodulescount = $day1completedmodules[$courseid] ?? 0;
            $firstaccess = $firstaccesstimes[$courseid] ?? 0;
            $hascoursecompletion = !empty($completions[$courseid]);
            $coursecompleted = $hascoursecompletion || ($trackable > 0 && $completedmodulescount >= $trackable);
            $completedonday1 = $hascoursecompletion && $firstaccess > 0 && $completions[$courseid] <= ($firstaccess + DAYSECS);

            $courseprogresses[] = base_report::calculate_course_progress($trackable, $completedmodulescount, $coursecompleted);
            $day1courseprogresses[] =
                base_report::calculate_course_progress($trackable, $day1completedmodulescount, $completedonday1);

            $courseexammetrics = $examgrademetrics[$courseid] ?? null;
            if ($courseexammetrics !== null && (int) $courseexammetrics->scorecount > 0) {
                $examscoretotal += (float) $courseexammetrics->scoretotal;
                $examscorecount += (int) $courseexammetrics->scorecount;
            }

            if (!empty($certificatecounts[$courseid])) {
                $completedcount++;
            }

            $certtotal += ($certificatecounts[$courseid] ?? 0);
            $examtotal += ($examcounts[$courseid] ?? 0);
            $lastaccess = max($lastaccess, ($lastaccessbycourse[$courseid] ?? 0));
        }

        $avgprogress = !empty($courseprogresses) ? round(array_sum($courseprogresses) / count($courseprogresses), 1) : 0.0;
        $avgday1progress =
            !empty($day1courseprogresses) ? round(array_sum($day1courseprogresses) / count($day1courseprogresses), 1) : 0.0;
        $avggrade = $examscorecount > 0 ? round($examscoretotal / $examscorecount, 1) : null;
        $status = ($user->suspended == 1 || $user->deleted == 1)
            ? get_string("suspended", "gimidashboardreports_fullacademydashboard")
            : get_string("active", "gimidashboardreports_fullacademydashboard");
        $daysinactive = $lastaccess > 0 ? floor((time() - $lastaccess) / DAYSECS) : null;

        return (object) [
            "userid" => $user->id,
            "firstname" => s($user->firstname),
            "lastname" => s($user->lastname),
            "email" => s($user->email),
            "coursecount" => count($usercourseids),
            "avgprogress" => $avgprogress,
            "avggrade" => $avggrade,
            "delta" => round($avgprogress - $avgday1progress, 1),
            "completed" => $completedcount,
            "certs" => $certtotal,
            "exams" => $examtotal,
            "lastaccess" => $lastaccess,
            "daysinactive" => $daysinactive,
            "status" => $status,
            "pathways" => $pathways,
            "selection" => $selection,
        ];
    }

    /**
     * Builds the detail rows for one learner.
     *
     * @param stdClass $user User record.
     * @param object $summaryrow Summary row.
     * @param array $courses Course records.
     * @param array $usercourseids User course ids.
     * @param array $moduletotals Module totals.
     * @param array $completedmodules Completed modules.
     * @param array $gradepercentages Grade percentages.
     * @param array $completions Completions.
     * @param array $examcounts Exam counts.
     * @param array $lastaccessbycourse Last access by course.
     * @param array $pathways Pathways.
     * @param object $selection Selection payload.
     * @return array
     * @throws Exception
     */
    protected static function build_detail_rows(
        stdClass $user,
        object $summaryrow,
        array $courses,
        array $usercourseids,
        array $moduletotals,
        array $firstaccesstimes,
        array $completedmodules,
        array $day1completedmodules,
        array $examgrademetrics,
        array $completions,
        array $certificatecounts,
        array $examcounts,
        array $lastaccessbycourse,
        array $pathways,
        array $coursepathways,
        object $selection
    ): array {
        $rows = [];

        foreach ($usercourseids as $courseid) {
            if (empty($courses[$courseid])) {
                continue;
            }

            $trackable = $moduletotals[$courseid] ?? 0;
            $completedmodulescount = $completedmodules[$courseid] ?? 0;
            $firstaccess = $firstaccesstimes[$courseid] ?? 0;
            $hascoursecompletion = !empty($completions[$courseid]);
            $coursecompleted = $hascoursecompletion || ($trackable > 0 && $completedmodulescount >= $trackable);
            $completedonday1 = $hascoursecompletion && $firstaccess > 0 && $completions[$courseid] <= ($firstaccess + DAYSECS);

            $progress = base_report::calculate_course_progress($trackable, $completedmodulescount, $coursecompleted);
            $day1progress = base_report::calculate_course_progress(
                $trackable,
                ($day1completedmodules[$courseid] ?? 0),
                $completedonday1
            );
            $courseexammetrics = $examgrademetrics[$courseid] ?? null;
            $grade = ($courseexammetrics !== null && (int) $courseexammetrics->scorecount > 0)
                ? round(((float) $courseexammetrics->scoretotal) / (int) $courseexammetrics->scorecount, 1)
                : null;

            $lastaccess = ($lastaccessbycourse[$courseid] ?? 0);
            $rows[] = [
                "course" => format_string($courses[$courseid]->fullname, true, ["context" => context_course::instance($courseid)]),
                "pathway" => self::render_pathway_links($coursepathways[$courseid] ?? $pathways, $selection),
                "progress" => self::format_percent($progress),
                "grade" => self::format_grade($grade),
                "delta" => self::format_percent(round($progress - $day1progress, 1)),
                "completed" => !empty($certificatecounts[$courseid]) ? 1 : 0,
                "certs" => ($certificatecounts[$courseid] ?? 0),
                "exams" => ($examcounts[$courseid] ?? 0),
                "lastaccess" => self::format_date($lastaccess),
                "status" => $summaryrow->status,
            ];
        }

        usort($rows, static function(array $a, array $b): int {
            return strcasecmp($a["course"], $b["course"]);
        });

        return $rows;
    }

    /**
     * Builds the report summary metrics.
     *
     * @param array $rows Learner rows.
     * @return object
     */
    protected static function build_summary(array $rows): object {
        $totallearners = count($rows);
        $avgprogressvalues = [];
        $avggradevalues = [];
        $neveraccessed = 0;
        $certsearned = 0;
        $coursescomplete = 0;

        foreach ($rows as $row) {
            $avgprogressvalues[] = (float) $row->avgprogress;
            if ($row->avggrade !== null) {
                $avggradevalues[] = (float) $row->avggrade;
            }
            if ($row->lastaccess == 0) {
                $neveraccessed++;
            }
            $certsearned += $row->certs;
            $coursescomplete += $row->completed;
        }

        return (object) [
            "totallearners" => $totallearners,
            "avgprogress" => $totallearners > 0 ? round(array_sum($avgprogressvalues) / $totallearners, 1) : 0.0,
            "avggrade" => !empty($avggradevalues) ? round(array_sum($avggradevalues) / count($avggradevalues), 1) : null,
            "neveraccessed" => $neveraccessed,
            "certsearned" => $certsearned,
            "coursescomplete" => $coursescomplete,
        ];
    }

    /**
     * Counts distinct pathways in the result rows.
     *
     * @param array $rows Learner rows.
     * @return int
     */
    protected static function count_pathways(array $rows): int {
        $pathways = [];
        foreach ($rows as $row) {
            foreach ($row->pathways as $cohortid => $cohortname) {
                $pathways[$cohortid] = $cohortid;
            }
        }

        return count($pathways);
    }

    /**
     * Renders the summary table.
     *
     * @param array $rows Learner rows.
     * @param object $selection Selection.
     * @return string
     * @throws Exception
     */
    protected static function render_summary_table(array $rows, object $selection): string {
        global $OUTPUT, $PAGE;

        $cohortid = optional_param("cohortid", 0, PARAM_INT);

        $templatecontext = [
            "headers" => [
                ["label" => get_string("firstname")],
                ["label" => get_string("lastname")],
                ["label" => get_string("email")],
                ["label" => get_string("pathway", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("courses", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("avgscoreprogress", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("avgscoregrade", "gimidashboardreports_fullacademydashboard")],
                //["label" => get_string("deltavsday1", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("completed", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("exams", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("lastaccess", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("daysinactive", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("status", "gimidashboardreports_fullacademydashboard")],
            ],
            "rows" => [],
            "hasrows" => !empty($rows),
            "emptymessage" => get_string("nodata", "gimidashboardreports_fullacademydashboard"),
        ];

        foreach ($rows as $row) {
            $learnerurl = self::build_url($selection->target, $row->userid, $cohortid);

            $templatecontext["rows"][] = [
                "firstname" => $row->firstname,
                "lastname" => $row->lastname,
                "email" => $row->email,
                "learnerurl" => $learnerurl,
                "pathwayshtml" => self::render_pathway_links($row->pathways, $selection),
                "courses" => $row->coursecount,
                "avgprogress" => self::format_percent($row->avgprogress),
                "avggrade" => self::format_grade($row->avggrade),
                //"delta" => self::format_percent($row->delta),
                "completed" => $row->completed,
                "exams" => $row->exams,
                "lastaccess" => self::format_date($row->lastaccess),
                "daysinactive" => self::format_days_inactive($row->daysinactive),
                "status" => $row->status,
            ];
        }

        $pagelength = optional_param("plugin", false, PARAM_COMPONENT) ? 50 : 5;
        $PAGE->requires->js_call_amd(
            "local_gimidashboard/dashboard", "datatable", ["#fullacademydashboard-summary_table", $pagelength]
        );
        return $OUTPUT->render_from_template("gimidashboardreports_fullacademydashboard/summary_table", $templatecontext);
    }

    /**
     * Renders the detail table.
     *
     * @param array $rows Detail rows.
     * @param object $selection Selection.
     * @return string
     * @throws Exception
     */
    protected static function render_detail_table(array $rows, object $selection): string {
        global $OUTPUT, $PAGE;

        $templatecontext = [
            "headers" => [
                ["label" => get_string("coursename", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("pathway", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("avgscoreprogress", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("avgscoregrade", "gimidashboardreports_fullacademydashboard")],
                //["label" => get_string("deltavsday1", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("completed", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("exams", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("lastaccess", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("status", "gimidashboardreports_fullacademydashboard")],
            ],
            "rows" => [],
            "hasrows" => !empty($rows),
            "emptymessage" => get_string("nodata", "gimidashboardreports_fullacademydashboard"),
        ];

        foreach ($rows as $row) {
            $templatecontext["rows"][] = [
                "coursehtml" => $row["course"],
                "pathwayhtml" => $row["pathway"],
                "progress" => $row["progress"],
                "grade" => $row["grade"],
                //"delta" => $row["delta"],
                "completed" => $row["completed"],
                "exams" => $row["exams"],
                "lastaccess" => $row["lastaccess"],
                "status" => s($row["status"]),
            ];
        }

        $pagelength = optional_param("plugin", false, PARAM_COMPONENT) ? 50 : 5;
        $PAGE->requires->js_call_amd(
            "local_gimidashboard/dashboard", "datatable", ["#fullacademydashboard-detail_table", $pagelength]
        );
        return $OUTPUT->render_from_template("gimidashboardreports_fullacademydashboard/detail_table", $templatecontext);
    }

    /**
     * Renders pathway links.
     *
     * @param array $pathways Pathways.
     * @param object $selection Selection.
     * @return string
     * @throws Exception
     */
    protected static function render_pathway_links(array $pathways, object $selection): string {
        if (empty($pathways)) {
            return get_string("dash", "gimidashboardreports_fullacademydashboard");
        }

        $links = [];
        foreach ($pathways as $cohortid => $cohortname) {
            $url = self::build_url($selection->target, 0, $cohortid);
            $links[] = html_writer::link($url, s($cohortname));
        }

        return html_writer::div(implode("", $links), "gimi-pathway-links");
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
    protected static function get_exam_name_like_params(int $num): array {
        $params = [];
        for ($key = 0; $key < $num; $key++) {
            $params += [
                "examterm_a{$key}_1" => "%exam%",
                "examterm_a{$key}_2" => "%final assessment%",
                "examterm_a{$key}_3" => "%final test%",
            ];
        }
        return $params;
    }

    /**
     * Formats a percentage value.
     *
     * @param float|null $value Value.
     * @param bool $appendpercent Append percent sign.
     * @return string
     * @throws Exception
     */
    protected static function format_percent(?float $value, bool $appendpercent = true): string {
        if ($value === null) {
            return get_string("dash", "gimidashboardreports_fullacademydashboard");
        }

        $formatted = number_format($value, 1);
        return $appendpercent ? $formatted . "%" : $formatted;
    }

    /**
     * Formats a grade value.
     *
     * @param float|null $value Value.
     * @return string
     * @throws Exception
     */
    protected static function format_grade(?float $value): string {
        if ($value === null) {
            return get_string("dash", "gimidashboardreports_fullacademydashboard");
        }

        return number_format($value, 1) . "%";
    }

    /**
     * Formats a date value.
     *
     * @param int $timestamp Timestamp.
     * @return string
     * @throws Exception
     */
    protected static function format_date(int $timestamp): string {
        if ($timestamp <= 0) {
            return get_string("never", "gimidashboardreports_fullacademydashboard");
        }

        return userdate($timestamp, get_string("strftimedatefullshort", "langconfig"));
    }

    /**
     * Formats inactive days.
     *
     * @param int|null $days Days.
     * @return string
     * @throws Exception
     */
    protected static function format_days_inactive(?int $days): string {
        if ($days === null) {
            return get_string("never", "gimidashboardreports_fullacademydashboard");
        }

        return $days;
    }

    /**
     * Builds a dashboard URL.
     *
     * @param string $target Target.
     * @param int $learnerid Learner id.
     * @param int $cohortid Cohort id.
     * @return moodle_url
     * @throws Exception
     */
    protected static function build_url(string $target, int $learnerid = 0, int $cohortid = 0): moodle_url {
        $params = [];
        if ($learnerid > 0) {
            $params["learnerid"] = $learnerid;
        }
        if ($cohortid > 0) {
            $params["cohortid"] = $cohortid;
        }
        $params["target"] = $target;
        $params["plugin"] = "fullacademydashboard";

        return new moodle_url("/local/gimidashboard/", $params);
    }

    /**
     * Returns the cohort name.
     *
     * @param int $cohortid Cohort id.
     * @return string
     * @throws Exception
     */
    protected static function get_cohort_name(int $cohortid): string {
        global $DB;

        $name = $DB->get_field("cohort", "name", ["id" => $cohortid]);
        if ($name == false) {
            return "";
        }

        return format_string($name, true, ["context" => context_system::instance()]);
    }
}
