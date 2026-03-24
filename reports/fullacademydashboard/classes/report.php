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

use ArrayIterator;
use coding_exception;
use context_course;
use context_system;
use core\exception\moodle_exception;
use core\dataformat;
use core_text;
use dml_exception;
use Exception;
use flexible_table;
use html_writer;
use local_gimidashboard\page\selection_resolver;
use local_gimidashboard\report\report_interface;
use moodle_url;
use stdClass;

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
     * @return string
     * @throws coding_exception
     * @throws moodle_exception
     * @throws dml_exception
     */
    public static function get_header(array $courses, $extra = ""): string {
        global $OUTPUT;

        $reportdata = self::prepare_report_data($courses);
        if (empty($reportdata->courseids)) {
            return "";
        }

        $title = get_string("pluginname", "gimidashboardreports_fullacademydashboard");
        $academyname = core_text::strtoupper(
            strip_tags(
                $reportdata->selection->label !== "" ? $reportdata->selection->label : $title
            )
        );

        $subtitleparts = [];
        if ($reportdata->pathwaycount === 1) {
            $subtitleparts[] = get_string("allpathwayssingle", "gimidashboardreports_fullacademydashboard");
        } else {
            $subtitleparts[] = get_string("allpathways", "gimidashboardreports_fullacademydashboard", $reportdata->pathwaycount);
        }
        $subtitleparts[] = get_string("learnerscount", "gimidashboardreports_fullacademydashboard", count($reportdata->rows));
        $subtitleparts[] = get_string("snapshotlabel", "gimidashboardreports_fullacademydashboard", userdate(time(), "%Y-%m-%d"));
        $subtitleparts[] = get_string("poweredby", "gimidashboardreports_fullacademydashboard");

        return $OUTPUT->render_from_template("gimidashboardreports_fullacademydashboard/content_title", [
            "academyname" => $academyname,
            "pluginname" => core_text::strtoupper(get_string("pluginname", "gimidashboardreports_fullacademydashboard")),
            "subtitle" => implode(" • ", $subtitleparts),
            "exporturl" => self::build_export_url(
                $reportdata->selection->target,
                $reportdata->learnerid,
                $reportdata->cohortid
            ),
            "extra_html" => $extra,
        ]);
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
     * @throws coding_exception
     * @throws moodle_exception
     * @throws dml_exception
     * @throws Exception
     */
    public static function render(array $courses): string {
        global $OUTPUT;

        $reportdata = self::prepare_report_data($courses);
        if (empty($reportdata->courseids)) {
            return "";
        }

        return $OUTPUT->render_from_template("gimidashboardreports_fullacademydashboard/content", [
            "pluginname" => core_text::strtoupper(get_string("pluginname", "gimidashboardreports_fullacademydashboard")),
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
        ]);
    }

    /**
     * Downloads the current summary data using the selected dataformat.
     *
     * @param array $courses Accessible course records.
     * @param string $dataformat Data format name.
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function export(array $courses, string $dataformat = "excel"): void {
        $reportdata = self::prepare_report_data($courses);
        if (empty($reportdata->courseids)) {
            throw new moodle_exception("invaliddata");
        }

        $columns = self::get_export_columns();
        $iterator = new ArrayIterator(array_values($reportdata->rows));

        dataformat::download_data(
            self::build_export_filename($reportdata->selection),
            $dataformat,
            $columns,
            $iterator,
            static function(object $row, bool $supportshtml): array {
                return self::format_export_record($row, $supportshtml);
            }
        );
    }

    /**
     * Prepares the report data for rendering and export.
     *
     * @param array $courses Accessible course records.
     * @return object
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    protected static function prepare_report_data(array $courses): object {
        global $USER;

        $courseids = self::extract_course_ids($courses);
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
            $usercourses = self::get_user_courses($courseids, $userids);
            $moduletotals = self::get_trackable_module_totals($courseids);
            $completedmodules = self::get_completed_module_totals($courseids, $userids);
            $gradepercentages = self::get_course_grade_percentages($courseids, $userids);
            $completions = self::get_course_completions($courseids, $userids);
            $examcounts = self::get_exam_counts($courseids, $userids);
            $lastaccessbycourse = self::get_last_access_by_course($courseids, $userids);
            $pathways = self::get_user_pathways($courseids, $userids);

            foreach ($users as $userid => $user) {
                $rows[$userid] = self::build_user_row(
                    $user,
                    $usercourses[$userid] ?? [],
                    $moduletotals,
                    $completedmodules[$userid] ?? [],
                    $gradepercentages[$userid] ?? [],
                    $completions[$userid] ?? [],
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
                    $completedmodules[$learnerid] ?? [],
                    $gradepercentages[$learnerid] ?? [],
                    $completions[$learnerid] ?? [],
                    $examcounts[$learnerid] ?? [],
                    $lastaccessbycourse[$learnerid] ?? [],
                    $pathways[$learnerid] ?? [],
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

        return (object) [
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
    }

    /**
     * Extracts course ids from the selection.
     *
     * @param array $courses Course records.
     * @return array
     */
    protected static function extract_course_ids(array $courses): array {
        return array_values(array_map(static function($course): int {
            return (int) $course->id;
        }, $courses));
    }

    /**
     * Returns the learners enrolled in the selected courses.
     *
     * @param array $courseids Course ids.
     * @param int $learnerid Learner filter.
     * @param int $cohortid Cohort filter.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
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

        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.suspended, u.deleted
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
     * Returns the enrolled courses for every selected learner.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    protected static function get_user_courses(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT DISTINCT CONCAT(ue.userid, e.courseid) as unik, ue.userid, e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e
                    ON e.id = ue.enrolid
                 WHERE e.courseid {$coursesql}
                   AND ue.userid {$usersql}
                   AND e.status = 0
                   AND ue.status = 0";

        $records = $DB->get_records_sql($sql, $courseparams + $userparams);
        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->userid][(int) $record->courseid] = (int) $record->courseid;
        }

        return $result;
    }

    /**
     * Returns the number of trackable modules for each course.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    protected static function get_trackable_module_totals(array $courseids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $sql = "SELECT cm.course, COUNT(cm.id) AS total
                  FROM {course_modules} cm
                 WHERE cm.course {$insql}
                   AND cm.visible = 1
                   AND cm.deletioninprogress = 0
                   AND cm.completion > 0
              GROUP BY cm.course";
        $records = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->course] = (int) $record->total;
        }

        return $result;
    }

    /**
     * Returns completed module counts by user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    protected static function get_completed_module_totals(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT CONCAT(cmc.userid, cm.course) AS unik, cmc.userid, cm.course, COUNT(DISTINCT cmc.coursemoduleid) AS total
                  FROM {course_modules_completion} cmc
                  JOIN {course_modules} cm
                    ON cm.id = cmc.coursemoduleid
                 WHERE cm.course {$coursesql}
                   AND cmc.userid {$usersql}
                   AND cm.visible = 1
                   AND cm.deletioninprogress = 0
                   AND cm.completion > 0
                   AND cmc.completionstate > 0
              GROUP BY cmc.userid, cm.course";

        $records = $DB->get_records_sql($sql, $courseparams + $userparams);
        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->userid][(int) $record->course] = (int) $record->total;
        }

        return $result;
    }

    /**
     * Returns course grade percentages by user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    protected static function get_course_grade_percentages(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT gg.userid,
                       gi.courseid,
                       AVG(CASE
                               WHEN gg.finalgrade IS NULL THEN NULL
                               WHEN gi.grademax <= gi.grademin THEN NULL
                               ELSE ((gg.finalgrade - gi.grademin) / (gi.grademax - gi.grademin)) * 100
                           END) AS gradepercent
                  FROM {grade_items} gi
                  JOIN {grade_grades} gg
                    ON gg.itemid = gi.id
                 WHERE gi.courseid {$coursesql}
                   AND gg.userid {$usersql}
                   AND gi.itemtype = 'course'
              GROUP BY gg.userid, gi.courseid";

        $records = $DB->get_records_sql($sql, $courseparams + $userparams);
        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->userid][(int) $record->courseid] = is_null($record->gradepercent)
                ? null
                : round((float) $record->gradepercent, 1);
        }

        return $result;
    }

    /**
     * Returns course completions by user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    protected static function get_course_completions(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT userid, course, timecompleted
                  FROM {course_completions}
                 WHERE course {$coursesql}
                   AND userid {$usersql}
                   AND timecompleted > 0";
        $records = $DB->get_records_sql($sql, $courseparams + $userparams);

        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->userid][(int) $record->course] = (int) $record->timecompleted;
        }

        return $result;
    }

    /**
     * Returns exam counts by user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    protected static function get_exam_counts(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT CONCAT(qa.userid, q.course) as unik, qa.userid, q.course, COUNT(DISTINCT qa.quiz) AS total
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q
                    ON q.id = qa.quiz
                 WHERE q.course {$coursesql}
                   AND qa.userid {$usersql}
                   AND qa.state = 'finished'
              GROUP BY qa.userid, q.course";
        $records = $DB->get_records_sql($sql, $courseparams + $userparams);

        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->userid][(int) $record->course] = (int) $record->total;
        }

        return $result;
    }

    /**
     * Returns the last access for each user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    protected static function get_last_access_by_course(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT CONCAT(userid, courseid) userid, courseid, MAX(timeaccess) AS timeaccess
                  FROM {user_lastaccess}
                 WHERE courseid {$coursesql}
                   AND userid {$usersql}
              GROUP BY userid, courseid";
        $records = $DB->get_records_sql($sql, $courseparams + $userparams);

        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->userid][(int) $record->courseid] = (int) $record->timeaccess;
        }

        return $result;
    }

    /**
     * Returns user pathways using cohort memberships.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    protected static function get_user_pathways(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        $cohortids = self::get_linked_cohort_ids($courseids);
        if (empty($cohortids)) {
            [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");
            $fallbacksql = "SELECT DISTINCT cm.cohortid
                              FROM {cohort_members} cm
                             WHERE cm.userid {$usersql}";
            $fallback = $DB->get_records_sql($fallbacksql, $userparams);
            foreach ($fallback as $record) {
                $cohortids[(int) $record->cohortid] = (int) $record->cohortid;
            }
        }

        if (empty($cohortids)) {
            return [];
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");
        [$cohortsql, $cohortparams] = $DB->get_in_or_equal(array_values($cohortids), SQL_PARAMS_NAMED, "cohort");

        $sql = "SELECT cm.userid, c.id, c.name
                  FROM {cohort_members} cm
                  JOIN {cohort} c
                    ON c.id = cm.cohortid
                 WHERE cm.userid {$usersql}
                   AND c.id {$cohortsql}
              ORDER BY c.name ASC";
        $records = $DB->get_records_sql($sql, $userparams + $cohortparams);

        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->userid][(int) $record->id] =
                format_string($record->name, true, ["context" => context_system::instance()]);
        }

        return $result;
    }

    /**
     * Returns linked cohort ids for the selected courses.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    protected static function get_linked_cohort_ids(array $courseids): array {
        global $DB;

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params["enroltype"] = "cohort";

        $sql = "SELECT DISTINCT e.customint1 AS cohortid
                  FROM {enrol} e
                 WHERE e.courseid {$coursesql}
                   AND e.enrol = :enroltype
                   AND e.customint1 > 0";
        $records = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->cohortid] = (int) $record->cohortid;
        }

        return $result;
    }

    /**
     * Builds a single learner summary row.
     *
     * @param stdClass $user User record.
     * @param array $usercourseids Course ids.
     * @param array $moduletotals Module totals by course.
     * @param array $completedmodules Completed modules by course.
     * @param array $gradepercentages Grade percentages by course.
     * @param array $completions Completion timestamps by course.
     * @param array $examcounts Exam counts by course.
     * @param array $lastaccessbycourse Last access by course.
     * @param array $pathways Pathways.
     * @param object $selection Selection payload.
     * @return object
     * @throws coding_exception
     */
    protected static function build_user_row(
        stdClass $user,
        array $usercourseids,
        array $moduletotals,
        array $completedmodules,
        array $gradepercentages,
        array $completions,
        array $examcounts,
        array $lastaccessbycourse,
        array $pathways,
        object $selection
    ): object {
        $courseprogresses = [];
        $gradevalues = [];
        $completedcount = 0;
        $examtotal = 0;
        $lastaccess = 0;

        foreach ($usercourseids as $courseid) {
            $trackable = $moduletotals[$courseid] ?? 0;
            $completedmodulescount = $completedmodules[$courseid] ?? 0;
            $coursecompleted = !empty($completions[$courseid]);

            if ($trackable > 0) {
                $courseprogresses[] = min(100, round(($completedmodulescount / $trackable) * 100, 1));
            } else if ($coursecompleted) {
                $courseprogresses[] = 100.0;
            } else {
                $courseprogresses[] = 0.0;
            }

            if (isset($gradepercentages[$courseid]) && $gradepercentages[$courseid] !== null) {
                $gradevalues[] = (float) $gradepercentages[$courseid];
            }

            if ($coursecompleted) {
                $completedcount++;
            }

            $examtotal += (int) ($examcounts[$courseid] ?? 0);
            $lastaccess = max($lastaccess, (int) ($lastaccessbycourse[$courseid] ?? 0));
        }

        $avgprogress = !empty($courseprogresses) ? round(array_sum($courseprogresses) / count($courseprogresses), 1) : 0.0;
        $avggrade = !empty($gradevalues) ? round(array_sum($gradevalues) / count($gradevalues), 1) : null;
        $status = ((int) $user->suspended === 1 || (int) $user->deleted === 1)
            ? get_string("suspended", "gimidashboardreports_fullacademydashboard")
            : get_string("active", "gimidashboardreports_fullacademydashboard");
        $daysinactive = $lastaccess > 0 ? (int) floor((time() - $lastaccess) / DAYSECS) : null;

        return (object) [
            "userid" => (int) $user->id,
            "firstname" => s($user->firstname),
            "lastname" => s($user->lastname),
            "email" => s($user->email),
            "coursecount" => count($usercourseids),
            "avgprogress" => $avgprogress,
            "avggrade" => $avggrade,
            "delta" => 0.0,
            "completed" => $completedcount,
            "certs" => $completedcount,
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
     * @throws coding_exception
     */
    protected static function build_detail_rows(
        stdClass $user,
        object $summaryrow,
        array $courses,
        array $usercourseids,
        array $moduletotals,
        array $completedmodules,
        array $gradepercentages,
        array $completions,
        array $examcounts,
        array $lastaccessbycourse,
        array $pathways,
        object $selection
    ): array {
        $rows = [];

        foreach ($usercourseids as $courseid) {
            if (empty($courses[$courseid])) {
                continue;
            }

            $trackable = $moduletotals[$courseid] ?? 0;
            $completedmodulescount = $completedmodules[$courseid] ?? 0;
            $coursecompleted = !empty($completions[$courseid]);
            if ($trackable > 0) {
                $progress = min(100, round(($completedmodulescount / $trackable) * 100, 1));
            } else if ($coursecompleted) {
                $progress = 100.0;
            } else {
                $progress = 0.0;
            }

            $lastaccess = (int) ($lastaccessbycourse[$courseid] ?? 0);
            $rows[] = [
                "course" => format_string($courses[$courseid]->fullname, true, ["context" => context_course::instance($courseid)]),
                "pathway" => self::render_pathway_links($pathways, $selection),
                "progress" => self::format_percent($progress),
                "grade" => self::format_grade($gradepercentages[$courseid] ?? null),
                "completed" => $coursecompleted ? 1 : 0,
                "exams" => (int) ($examcounts[$courseid] ?? 0),
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
            if ((int) $row->lastaccess === 0) {
                $neveraccessed++;
            }
            $certsearned += (int) $row->certs;
            $coursescomplete += (int) $row->completed;
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
        global $OUTPUT;

        $cohortid = optional_param("cohortid", 0, PARAM_INT);

        $templatecontext = [
            "headers" => [
                ["label" => get_string("firstname", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("lastname", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("email", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("pathway", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("courses", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("avgscoreprogress", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("avgscoregrade", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("deltavsday1", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("completed", "gimidashboardreports_fullacademydashboard")],
                ["label" => get_string("certs", "gimidashboardreports_fullacademydashboard")],
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
                "firstname" => s($row->firstname),
                "lastname" => s($row->lastname),
                "email" => s($row->email),
                "learnerurl" => $learnerurl,
                "pathwayshtml" => self::render_pathway_links($row->pathways, $selection),
                "courses" => $row->coursecount,
                "avgprogress" => self::format_percent($row->avgprogress),
                "avggrade" => self::format_grade($row->avggrade),
                "delta" => self::format_percent($row->delta),
                "completed" => $row->completed,
                "certs" => $row->certs,
                "exams" => $row->exams,
                "lastaccess" => self::format_date($row->lastaccess),
                "daysinactive" => self::format_days_inactive($row->daysinactive),
                "status" => s($row->status),
            ];
        }

        return $OUTPUT->render_from_template(
            "gimidashboardreports_fullacademydashboard/summary_table",
            $templatecontext
        );
    }

    /**
     * Renders the detail table.
     *
     * @param array $rows Detail rows.
     * @param object $selection Selection.
     * @return string
     * @throws coding_exception
     * @throws Exception
     */
    protected static function render_detail_table(array $rows, object $selection): string {
        $table = new flexible_table("gimi-full-dashboard-detail-" . md5($selection->target . ":detail"));
        $table->define_columns(["course", "pathway", "progress", "grade", "completed", "exams", "lastaccess", "status"]);
        $table->define_headers([
            get_string("coursename", "gimidashboardreports_fullacademydashboard"),
            get_string("pathway", "gimidashboardreports_fullacademydashboard"),
            get_string("avgscoreprogress", "gimidashboardreports_fullacademydashboard"),
            get_string("avgscoregrade", "gimidashboardreports_fullacademydashboard"),
            get_string("completed", "gimidashboardreports_fullacademydashboard"),
            get_string("exams", "gimidashboardreports_fullacademydashboard"),
            get_string("lastaccess", "gimidashboardreports_fullacademydashboard"),
            get_string("status", "gimidashboardreports_fullacademydashboard"),
        ]);
        $table->define_baseurl(
            self::build_url($selection->target, optional_param("learnerid", 0, PARAM_INT), optional_param("cohortid", 0, PARAM_INT))
        );
        $table->set_attribute("class", "generaltable table-sm");
        $table->setup();

        foreach ($rows as $row) {
            $table->add_data([
                $row["course"],
                $row["pathway"],
                $row["progress"],
                $row["grade"],
                $row["completed"],
                $row["exams"],
                $row["lastaccess"],
                $row["status"],
            ]);
        }

        ob_start();
        $table->finish_output();
        return ob_get_clean();
    }

    /**
     * Renders pathway links.
     *
     * @param array $pathways Pathways.
     * @param object $selection Selection.
     * @return string
     * @throws coding_exception
     * @throws Exception
     */
    protected static function render_pathway_links(array $pathways, object $selection): string {
        if (empty($pathways)) {
            return get_string("dash", "gimidashboardreports_fullacademydashboard");
        }

        $links = [];
        foreach ($pathways as $cohortid => $cohortname) {
            $url = self::build_url($selection->target, 0, (int) $cohortid);
            $links[] = html_writer::link($url, s($cohortname));
        }

        return html_writer::div(implode("", $links), "gimi-pathway-links");
    }

    /**
     * Returns the export column definitions.
     *
     * @return array
     * @throws coding_exception
     */
    protected static function get_export_columns(): array {
        return [
            "firstname" => get_string("firstname", "gimidashboardreports_fullacademydashboard"),
            "lastname" => get_string("lastname", "gimidashboardreports_fullacademydashboard"),
            "email" => get_string("email", "gimidashboardreports_fullacademydashboard"),
            "pathway" => get_string("pathway", "gimidashboardreports_fullacademydashboard"),
            "courses" => get_string("courses", "gimidashboardreports_fullacademydashboard"),
            "avgprogress" => get_string("avgscoreprogress", "gimidashboardreports_fullacademydashboard"),
            "avggrade" => get_string("avgscoregrade", "gimidashboardreports_fullacademydashboard"),
            "delta" => get_string("deltavsday1", "gimidashboardreports_fullacademydashboard"),
            "completed" => get_string("completed", "gimidashboardreports_fullacademydashboard"),
            "certs" => get_string("certs", "gimidashboardreports_fullacademydashboard"),
            "exams" => get_string("exams", "gimidashboardreports_fullacademydashboard"),
            "lastaccess" => get_string("lastaccess", "gimidashboardreports_fullacademydashboard"),
            "daysinactive" => get_string("daysinactive", "gimidashboardreports_fullacademydashboard"),
            "status" => get_string("status", "gimidashboardreports_fullacademydashboard"),
        ];
    }

    /**
     * Formats a summary row for export.
     *
     * @param object $row Learner row.
     * @param bool $supportshtml Whether the selected format supports HTML.
     * @return array
     * @throws coding_exception
     */
    protected static function format_export_record(object $row, bool $supportshtml): array {
        return [
            "firstname" => $row->firstname,
            "lastname" => $row->lastname,
            "email" => $row->email,
            "pathway" => self::format_pathways_for_export($row->pathways),
            "courses" => $row->coursecount,
            "avgprogress" => self::format_percent($row->avgprogress),
            "avggrade" => self::format_grade($row->avggrade),
            "delta" => self::format_percent($row->delta),
            "completed" => $row->completed,
            "certs" => $row->certs,
            "exams" => $row->exams,
            "lastaccess" => self::format_date($row->lastaccess),
            "daysinactive" => self::format_days_inactive($row->daysinactive),
            "status" => $row->status,
        ];
    }

    /**
     * Returns a plain text list of pathways for export.
     *
     * @param array $pathways Pathways.
     * @return string
     * @throws coding_exception
     */
    protected static function format_pathways_for_export(array $pathways): string {
        if (empty($pathways)) {
            return get_string("dash", "gimidashboardreports_fullacademydashboard");
        }

        return implode(" | ", array_values($pathways));
    }

    /**
     * Formats a percentage value.
     *
     * @param float|null $value Value.
     * @param bool $appendpercent Append percent sign.
     * @return string
     * @throws coding_exception
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
     * @throws coding_exception
     */
    protected static function format_grade(?float $value): string {
        if ($value === null) {
            return get_string("dash", "gimidashboardreports_fullacademydashboard");
        }

        return number_format($value, 1);
    }

    /**
     * Formats a date value.
     *
     * @param int $timestamp Timestamp.
     * @return string
     * @throws coding_exception
     */
    protected static function format_date(int $timestamp): string {
        if ($timestamp <= 0) {
            return get_string("never", "gimidashboardreports_fullacademydashboard");
        }

        return userdate($timestamp, "%Y-%m-%d");
    }

    /**
     * Formats inactive days.
     *
     * @param int|null $days Days.
     * @return string
     * @throws coding_exception
     */
    protected static function format_days_inactive(?int $days): string {
        if ($days === null) {
            return get_string("never", "gimidashboardreports_fullacademydashboard");
        }

        return (string) $days;
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
        $params = ["target" => $target];
        if ($learnerid > 0) {
            $params["learnerid"] = $learnerid;
        }
        if ($cohortid > 0) {
            $params["cohortid"] = $cohortid;
        }

        return new moodle_url("/local/gimidashboard/view.php", $params);
    }

    /**
     * Builds the export URL preserving active filters.
     *
     * @param string $target Target.
     * @param int $learnerid Learner id.
     * @param int $cohortid Cohort id.
     * @return moodle_url
     */
    protected static function build_export_url(string $target, int $learnerid = 0, int $cohortid = 0): moodle_url {
        $params = [
            "target" => $target,
            "dataformat" => "excel",
        ];
        if ($learnerid > 0) {
            $params["learnerid"] = $learnerid;
        }
        if ($cohortid > 0) {
            $params["cohortid"] = $cohortid;
        }

        return new moodle_url("/local/gimidashboard/reports/fullacademydashboard/export.php", $params);
    }

    /**
     * Builds the export filename.
     *
     * @param object $selection Selection payload.
     * @return string
     */
    protected static function build_export_filename(object $selection): string {
        $title = get_string("pluginname", "gimidashboardreports_fullacademydashboard");
        $label = $selection->label !== "" ? $selection->label : $title;
        return clean_filename("full-academy-dashboard-" . $label . "-" . userdate(time(), "%Y%m%d-%H%M"));
    }

    /**
     * Returns the cohort name.
     *
     * @param int $cohortid Cohort id.
     * @return string
     * @throws dml_exception
     */
    protected static function get_cohort_name(int $cohortid): string {
        global $DB;

        $name = $DB->get_field("cohort", "name", ["id" => $cohortid]);
        if ($name === false) {
            return "";
        }

        return format_string($name, true, ["context" => context_system::instance()]);
    }
}
