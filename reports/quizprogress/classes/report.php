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
 * @package   gimidashboardreports_quizprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gimidashboardreports_quizprogress;

use context_course;
use context_system;
use Exception;
use local_gimidashboard\page\selection_resolver;
use local_gimidashboard\header_color_manager;
use local_gimidashboard\report\base_report;
use local_gimidashboard\report\report_interface;
use moodle_url;

/**
 * Quiz progress report.
 *
 * @package   gimidashboardreports_quizprogress
 */
class report implements report_interface {
    /**
     * Returns the report header.
     *
     * @param array $courses Accessible course records.
     * @param string $extra Optional header action HTML.
     * @return string
     * @throws Exception
     */
    public static function get_header(array $courses, $extra = ""): string {
        global $OUTPUT, $PAGE;

        $reportdata = self::prepare_report_data($courses);
        if (empty($reportdata->courseids)) {
            return "";
        }

        $academyname = strtoupper(
            strip_tags(
                $reportdata->selection->label !== ""
                    ? $reportdata->selection->label
                    : get_string("pluginname", "gimidashboardreports_quizprogress")
            )
        );

        $subtitleparts = [
            get_string("selectionlabel", "gimidashboardreports_quizprogress", strip_tags($reportdata->selection->label)),
            get_string("coursescount", "gimidashboardreports_quizprogress", count($reportdata->courseids)),
            get_string("learnerscount", "gimidashboardreports_quizprogress", $reportdata->summary->totallearners),
        ];

        if ($reportdata->cohortid > 0 && $reportdata->cohortname !== "") {
            $subtitleparts[] = get_string("cohortlabel", "gimidashboardreports_quizprogress", $reportdata->cohortname);
        }

        if ($reportdata->learnerid > 0 && $reportdata->learnername !== "") {
            $subtitleparts[] = get_string("learnerlabel", "gimidashboardreports_quizprogress", $reportdata->learnername);
        }

        $subtitleparts[] = get_string(
            "snapshotlabel",
            "gimidashboardreports_quizprogress",
            userdate(time(), get_string("strftimedatefullshort", "langconfig"))
        );

        $pagelength = optional_param("plugin", false, PARAM_COMPONENT) ? 50 : 5;
        $PAGE->requires->js_call_amd("local_gimidashboard/dashboard", "datatable", [".gimi-quizprogress-table", $pagelength]);

        return $OUTPUT->render_from_template("local_gimidashboard/content_title", [
            "academyname" => $academyname,
            "pluginname" => get_string("pluginname", "gimidashboardreports_quizprogress"),
            "subtitle" => implode(" • ", $subtitleparts),
            "header_style" => header_color_manager::get_header_style("gimidashboardreports_quizprogress"),
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
     * @throws Exception
     */
    public static function render(array $courses): string {
        global $OUTPUT;

        $reportdata = self::prepare_report_data($courses);
        if (empty($reportdata->courseids)) {
            return "";
        }

        return $OUTPUT->render_from_template("gimidashboardreports_quizprogress/content", [
            "hasmessage" => $reportdata->message !== "",
            "message" => $reportdata->message,
            "showcohortoptions" => !empty($reportdata->cohortoptions),
            "cohortoptions" => $reportdata->cohortoptions,
            "hasfilters" => !empty($reportdata->filters),
            "filters" => $reportdata->filters,
            "reseturl" => self::build_url($reportdata->selection->target),
            "kpis" => [
                [
                    "label" => get_string("totalquizzes", "gimidashboardreports_quizprogress"),
                    "value" => $reportdata->summary->totalquizzes,
                ],
                [
                    "label" => get_string("submittedquizzes", "gimidashboardreports_quizprogress"),
                    "value" => $reportdata->summary->submittedquizzes,
                ],
                [
                    "label" => get_string("totalattempts", "gimidashboardreports_quizprogress"),
                    "value" => $reportdata->summary->attempts,
                ],
                [
                    "label" => get_string("submissionrate", "gimidashboardreports_quizprogress"),
                    "value" => self::format_percent($reportdata->summary->submissionrate),
                ],
                [
                    "label" => get_string("learnerswithattempts", "gimidashboardreports_quizprogress"),
                    "value" => $reportdata->summary->learnerswithattempts,
                ],
            ],
            "hascourserows" => !empty($reportdata->courserows),
            "courserows" => $reportdata->courserows,
            "haslearnerrows" => !empty($reportdata->learnerrows),
            "learnerrows" => $reportdata->learnerrows,
            "hasquestionrows" => !empty($reportdata->questionrows),
            "questionrows" => $reportdata->questionrows,
        ]);
    }

    /**
     * Prepares the report payload.
     *
     * @param array $courses Accessible course records.
     * @return object
     * @throws Exception
     */
    protected static function prepare_report_data(array $courses): object {
        global $USER;

        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $selection = selection_resolver::resolve(optional_param("target", "", PARAM_TEXT), $USER->id);
        $courseids = base_report::extract_course_ids($courses);
        $learnerid = optional_param("learnerid", 0, PARAM_INT);
        $requestedcohortid = optional_param("cohortid", 0, PARAM_INT);

        $base = (object) [
            "selection" => $selection,
            "courseids" => $courseids,
            "cohortid" => 0,
            "cohortname" => "",
            "cohortoptions" => [],
            "learnerid" => 0,
            "learnername" => "",
            "filters" => [],
            "summary" => (object) [
                "totalquizzes" => 0,
                "submittedquizzes" => 0,
                "attempts" => 0,
                "submissionrate" => 0,
                "totallearners" => 0,
                "learnerswithattempts" => 0,
            ],
            "courserows" => [],
            "learnerrows" => [],
            "questionrows" => [],
            "message" => "",
        ];

        if (empty($courseids)) {
            $cache = $base;
            return $cache;
        }

        $availablequizzes = self::get_available_quiz_totals($courseids);
        $availablecohorts = self::get_available_cohorts($courseids, $learnerid);

        $cohortid = 0;
        if ($requestedcohortid > 0 && isset($availablecohorts[$requestedcohortid])) {
            $cohortid = $requestedcohortid;
        }

        $base->cohortid = $cohortid;
        $base->cohortname = $cohortid > 0 ? ($availablecohorts[$cohortid] ?? "") : "";
        $base->cohortoptions = !empty($availablecohorts)
            ? self::build_cohort_options($selection->target, $availablecohorts, $cohortid, $learnerid)
            : [];

        $learners = self::get_learners($courseids, $learnerid, $cohortid);
        $base->summary->totallearners = count($learners);

        if ($learnerid > 0 && isset($learners[$learnerid])) {
            $base->learnerid = $learnerid;
            $base->learnername = fullname($learners[$learnerid]);
        }

        if ($cohortid > 0) {
            $base->filters[] = [
                "label" => get_string("cohort", "gimidashboardreports_quizprogress"),
                "value" => $base->cohortname,
                "clearurl" => self::build_url($selection->target, 0, $base->learnerid),
            ];
        }

        if ($base->learnerid > 0 && $base->learnername !== "") {
            $base->filters[] = [
                "label" => get_string("learner", "gimidashboardreports_quizprogress"),
                "value" => $base->learnername,
                "clearurl" => self::build_url($selection->target, $cohortid),
            ];
        }

        if (empty($availablequizzes->bycourse)) {
            $base->message = get_string("noquizzes", "gimidashboardreports_quizprogress");
            $cache = $base;
            return $cache;
        }

        if (empty($learners)) {
            $base->message = get_string("nolearners", "gimidashboardreports_quizprogress");
            $cache = $base;
            return $cache;
        }

        $userids = array_keys($learners);
        $usercourses = base_report::get_user_courses($courseids, $userids);
        $usercohorts = self::get_user_cohorts($userids, array_keys($availablecohorts));
        $lastaccessbycourse = base_report::get_last_access_by_course($courseids, $userids);
        $usermetrics = self::get_attempt_metrics_by_user_course($courseids, $userids);
        $usergrademetrics = base_report::get_quiz_grade_metrics($courseids, $userids);
        $coursemetrics = self::get_course_metrics($courseids, $cohortid, $base->learnerid);
        $coursegrademetrics = self::aggregate_grade_metrics_by_course($usergrademetrics);
        $wrongquestions = self::get_top_incorrect_questions($courseids, $cohortid, $base->learnerid);

        $learnerrows = [];
        foreach ($learners as $userid => $user) {
            $row = self::build_learner_row(
                $user,
                $selection,
                $usercourses[$userid] ?? [],
                $availablequizzes->bycourse,
                $usermetrics[$userid] ?? [],
                $usergrademetrics[$userid] ?? [],
                $lastaccessbycourse[$userid] ?? [],
                $usercohorts[$userid] ?? [],
                $cohortid
            );
            $learnerrows[$userid] = $row;
            if ($row["hasattempts"]) {
                $base->summary->learnerswithattempts++;
            }
        }

        $courserows = [];
        foreach ($courseids as $courseid) {
            $metrics = $coursemetrics[$courseid] ?? (object) [
                "courseid" => $courseid,
                "submittedquizzes" => 0,
                "attempts" => 0,
                "learnerswithattempts" => 0,
                "scoretotal" => 0,
                "scorecount" => 0,
            ];

            $availablecount = $availablequizzes->bycourse[$courseid] ?? 0;
            $course = $selection->courses[$courseid] ?? null;
            if (!$course) {
                continue;
            }

            $submitted = (int) $metrics->submittedquizzes;
            $attempts = (int) $metrics->attempts;
            $submissionrate = $availablecount > 0 ? ($submitted / $availablecount) * 100 : 0;
            $grademetrics = $coursegrademetrics[$courseid] ?? (object) [
                "scoretotal" => 0,
                "scorecount" => 0,
            ];

            $avgscore = (int) $grademetrics->scorecount > 0
                ? ((float) $grademetrics->scoretotal / (int) $grademetrics->scorecount)
                : null;

            $base->summary->submittedquizzes += $submitted;
            $base->summary->attempts += $attempts;

            $courserows[$courseid] = [
                "coursehtml" => self::format_course_link($selection, $course, $cohortid, $base->learnerid),
                "quizzesavailable" => $availablecount,
                "quizzessubmitted" => $submitted,
                "attempts" => $attempts,
                "learnerswithattempts" => (int) $metrics->learnerswithattempts,
                "submissiondisplay" => self::format_percent($submissionrate),
                "scoredisplay" => self::format_percent_or_dash($avgscore),
            ];
        }

        $base->summary->totalquizzes = (int) $availablequizzes->total;
        $base->summary->submissionrate = $base->summary->totalquizzes > 0
            ? ($base->summary->submittedquizzes / $base->summary->totalquizzes) * 100
            : 0;

        $base->courserows = array_values($courserows);
        $base->learnerrows = array_values($learnerrows);
        $base->questionrows = array_values(array_map(static function($row): array {
            $errrate = (int) $row->responsecount > 0 ? ((int) $row->wrongcount / (int) $row->responsecount) * 100 : 0;

            return [
                "questionname" => format_string(
                    trim((string) $row->questionname) !== "" ?
                        $row->questionname : "#" . $row->questionid
                ),
                "quizname" => format_string($row->quizname),
                "coursename" => format_string($row->coursename, true, ["context" => context_course::instance($row->courseid)]),
                "wrongcount" => (int) $row->wrongcount,
                "responsecount" => (int) $row->responsecount,
                "learnercount" => (int) $row->learnercount,
                "errordisplay" => self::format_percent($errrate),
            ];
        }, $wrongquestions));

        if (empty($base->questionrows)) {
            $base->message = get_string("nowronganswers", "gimidashboardreports_quizprogress");
        }

        $cache = $base;
        return $cache;
    }

    /**
     * Returns the available quiz totals by course.
     *
     * @param array $courseids Course ids.
     * @return object
     * @throws Exception
     */
    protected static function get_available_quiz_totals(array $courseids): object {
        global $DB;

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $sql = "SELECT q.course AS courseid,
                       COUNT(DISTINCT q.id) AS total
                  FROM {quiz} q
                 WHERE q.course {$coursesql}
              GROUP BY q.course";

        $records = $DB->get_records_sql($sql, $params);
        $result = [
            "total" => 0,
            "bycourse" => [],
        ];

        foreach ($records as $record) {
            $result["bycourse"][(int) $record->courseid] = (int) $record->total;
            $result["total"] += (int) $record->total;
        }

        return (object) $result;
    }

    /**
     * Returns learners scoped to the selected courses.
     *
     * @param array $courseids Course ids.
     * @param int $learnerid Learner id.
     * @param int $cohortid Cohort id.
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
            "u.suspended = 0",
            $DB->sql_compare_text("u.username") . " <> :guestuser",
        ];
        $params["guestuser"] = "guest";

        if ($learnerid > 0) {
            $params["learnerid"] = $learnerid;
            $wheres[] = "u.id = :learnerid";
        }

        if ($cohortid > 0) {
            $params["cohortid"] = $cohortid;
            $joins .= " JOIN {cohort_members} cm ON cm.userid = u.id AND cm.cohortid = :cohortid";
        }

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
     * Returns attempt metrics by learner and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_attempt_metrics_by_user_course(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT CONCAT(qa.userid, '-', q.course) AS unik,
                       qa.userid                        AS userid,
                       q.course                         AS courseid,
                       COUNT(DISTINCT qa.quiz)          AS submittedquizzes,
                       COUNT(DISTINCT qa.id)            AS attempts
                  FROM {quiz_attempts} qa
                  JOIN {quiz}           q ON q.id = qa.quiz
                 WHERE q.course {$coursesql}
                   AND qa.userid {$usersql}
                   AND qa.preview = 0
              GROUP BY qa.userid, q.course";

        $records = $DB->get_records_sql($sql, $courseparams + $userparams);
        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->userid][(int) $record->courseid] = $record;
        }

        return $result;
    }

    /**
     * Returns aggregated metrics by course.
     *
     * @param array $courseids Course ids.
     * @param int $cohortid Cohort id.
     * @param int $learnerid Learner id.
     * @return array
     * @throws Exception
     */
    protected static function get_course_metrics(array $courseids, int $cohortid = 0, int $learnerid = 0): array {
        global $DB;

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $joins = "";
        $wheres = [
            "q.course {$coursesql}",
            "qa.preview = 0",
        ];

        if ($cohortid > 0) {
            $params["cohortid"] = $cohortid;
            $joins .= " JOIN {cohort_members} cm ON cm.userid = qa.userid AND cm.cohortid = :cohortid";
        }

        if ($learnerid > 0) {
            $params["learnerid"] = $learnerid;
            $wheres[] = "qa.userid = :learnerid";
        }

        $sql = "SELECT q.course                  AS courseid,
                       COUNT(DISTINCT qa.quiz)   AS submittedquizzes,
                       COUNT(DISTINCT qa.id)     AS attempts,
                       COUNT(DISTINCT qa.userid) AS learnerswithattempts
                  FROM {quiz_attempts} qa
                  JOIN {quiz}           q ON q.id = qa.quiz
                {$joins}
                 WHERE " . implode(" AND ", $wheres) . "
              GROUP BY q.course";

        $records = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->courseid] = $record;
        }

        return $result;
    }

    /**
     * Returns the available cohorts for the selected courses.
     *
     * @param array $courseids Course ids.
     * @param int $learnerid Learner id.
     * @return array
     * @throws Exception
     */
    protected static function get_available_cohorts(array $courseids, int $learnerid = 0): array {
        global $DB;

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params["uconfirmed"] = 1;
        $wheres = [
            "e.courseid {$coursesql}",
            "e.status = 0",
            "ue.status = 0",
            "u.deleted = 0",
            "u.confirmed = :uconfirmed",
            "u.suspended = 0",
        ];

        if ($learnerid > 0) {
            $params["learnerid"] = $learnerid;
            $wheres[] = "u.id = :learnerid";
        }

        $sql = "SELECT DISTINCT c.id, c.name
                  FROM {cohort} c
                  JOIN {cohort_members} cm
                    ON cm.cohortid = c.id
                  JOIN {user} u
                    ON u.id = cm.userid
                  JOIN {user_enrolments} ue
                    ON ue.userid = u.id
                  JOIN {enrol} e
                    ON e.id = ue.enrolid
                 WHERE " . implode(" AND ", $wheres) . "
              ORDER BY c.name ASC";

        $records = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->id] = format_string($record->name, true, ["context" => context_system::instance()]);
        }

        return $result;
    }

    /**
     * Returns user cohorts.
     *
     * @param array $userids User ids.
     * @param array $cohortids Cohort ids.
     * @return array
     * @throws Exception
     */
    protected static function get_user_cohorts(array $userids, array $cohortids = []): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");
        $params = $userparams;
        $wheres = ["cm.userid {$usersql}"];

        if (!empty($cohortids)) {
            [$cohortsql, $cohortparams] = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, "cohort");
            $params += $cohortparams;
            $wheres[] = "c.id {$cohortsql}";
        }

        $sql = "SELECT CONCAT(cm.userid, '-', c.id) AS unik,
                       cm.userid,
                       c.id AS cohortid,
                       c.name
                  FROM {cohort_members} cm
                  JOIN {cohort} c
                    ON c.id = cm.cohortid
                 WHERE " . implode(" AND ", $wheres) . "
              ORDER BY c.name ASC";

        $records = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->userid][(int) $record->cohortid] = format_string(
                $record->name,
                true,
                ["context" => context_system::instance()]
            );
        }

        return $result;
    }

    /**
     * Returns the questions with most incorrect answers.
     *
     * @param array $courseids Course ids.
     * @param int $cohortid Cohort id.
     * @param int $learnerid Learner id.
     * @param int $limit Limit.
     * @return array
     * @throws Exception
     */
    protected static function get_top_incorrect_questions(
        array $courseids,
        int $cohortid = 0,
        int $learnerid = 0,
        int $limit = 15
    ): array {
        global $DB;

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $joins = "";
        $wheres = [
            "q.course {$coursesql}",
            "qa.preview = 0",
            "ques.parent = 0",
            "qas.state IN ('gradedright', 'gradedwrong', 'gradedpartial', 'mangrright', 'mangrwrong', 'mangrpartial', 'gaveup')",
        ];

        if ($cohortid > 0) {
            $params["cohortid"] = $cohortid;
            $joins .= " JOIN {cohort_members} cm ON cm.userid = qa.userid AND cm.cohortid = :cohortid";
        }

        if ($learnerid > 0) {
            $params["learnerid"] = $learnerid;
            $wheres[] = "qa.userid = :learnerid";
        }

        $latestsql = "SELECT qasinner.questionattemptid,
                             MAX(qasinner.sequencenumber) AS sequencenumber
                        FROM {question_attempt_steps} qasinner
                    GROUP BY qasinner.questionattemptid";

        $sql = "SELECT CONCAT(q.course, '-', q.id, '-', ques.id) AS unik,
                       q.course AS courseid,
                       c.fullname AS coursename,
                       q.id AS quizid,
                       q.name AS quizname,
                       ques.id AS questionid,
                       ques.name AS questionname,
                       COUNT(DISTINCT CASE
                           WHEN qas.state IN ('gradedwrong', 'mangrwrong', 'gaveup') THEN qatt.id
                           ELSE NULL
                       END) AS wrongcount,
                       COUNT(DISTINCT qatt.id) AS responsecount,
                       COUNT(DISTINCT CASE
                           WHEN qas.state IN ('gradedwrong', 'mangrwrong', 'gaveup') THEN qa.userid
                           ELSE NULL
                       END) AS learnercount
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q
                    ON q.id = qa.quiz
                  JOIN {course} c
                    ON c.id = q.course
                  JOIN {question_usages} qu
                    ON qu.id = qa.uniqueid
                  JOIN {question_attempts} qatt
                    ON qatt.questionusageid = qu.id
                  JOIN ({$latestsql}) latest
                    ON latest.questionattemptid = qatt.id
                  JOIN {question_attempt_steps} qas
                    ON qas.questionattemptid = latest.questionattemptid
                   AND qas.sequencenumber = latest.sequencenumber
                  JOIN {question} ques
                    ON ques.id = qatt.questionid
                {$joins}
                 WHERE " . implode(" AND ", $wheres) . "
              GROUP BY q.course, c.fullname, q.id, q.name, ques.id, ques.name
                HAVING COUNT(DISTINCT CASE
                           WHEN qas.state IN ('gradedwrong', 'mangrwrong', 'gaveup') THEN qatt.id
                           ELSE NULL
                       END) > 0
              ORDER BY wrongcount DESC, responsecount DESC, learnercount DESC, ques.name ASC";

        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Builds learner options row.
     *
     * @param object $user User.
     * @param object $selection Selection.
     * @param array $usercourses User course ids.
     * @param array $availablequizzes Available quizzes by course.
     * @param array $usermetrics Attempt metrics by course.
     * @param array $usergrademetrics
     * @param array $lastaccessbycourse Last access by course.
     * @param array $cohorts Learner cohorts.
     * @param int $cohortid Selected cohort id.
     * @return array
     * @throws \coding_exception
     * @throws \core\exception\moodle_exception
     */
    protected static function build_learner_row(
        object $user,
        object $selection,
        array $usercourses,
        array $availablequizzes,
        array $usermetrics,
        array $usergrademetrics,
        array $lastaccessbycourse,
        array $cohorts,
        int $cohortid = 0
    ): array {
        $availablecount = 0;
        foreach ($usercourses as $courseid) {
            $availablecount += (int) ($availablequizzes[$courseid] ?? 0);
        }

        $submittedquizzes = 0;
        $attempts = 0;
        foreach ($usermetrics as $metric) {
            $submittedquizzes += (int) $metric->submittedquizzes;
            $attempts += (int) $metric->attempts;
        }

        $scoretotal = 0.0;
        $scorecount = 0;
        foreach ($usergrademetrics as $metric) {
            $scoretotal += (float) $metric->scoretotal;
            $scorecount += (int) $metric->scorecount;
        }

        $lastaccess = 0;
        foreach ($lastaccessbycourse as $timeaccess) {
            if ((int) $timeaccess > $lastaccess) {
                $lastaccess = (int) $timeaccess;
            }
        }

        $submissionrate = $availablecount > 0 ? ($submittedquizzes / $availablecount) * 100 : 0;
        $avgscore = $scorecount > 0 ? $scoretotal / $scorecount : null;
        [$statuskey, $statusclass] = self::resolve_status($availablecount, $submittedquizzes, $attempts);

        return [
            "learnername" => fullname($user),
            "email" => s($user->email),
            "cohortsdisplay" => !empty($cohorts) ?
                s(implode(", ", $cohorts)) :
                get_string("notavailable", "gimidashboardreports_quizprogress"),
            "lastaccessdisplay" => $lastaccess > 0
                ? userdate($lastaccess, get_string("strftimedatetime", "langconfig"))
                : get_string("never", "gimidashboardreports_quizprogress"),
            "quizzesdisplay" => $availablecount > 0
                ? get_string(
                    "quizfraction",
                    "gimidashboardreports_quizprogress",
                    (object) ["done" => $submittedquizzes, "total" => $availablecount]
                )
                : get_string("notavailable", "gimidashboardreports_quizprogress"),
            "attemptsdisplay" => $attempts,
            "submissiondisplay" => self::format_percent($submissionrate),
            "scoredisplay" => self::format_percent_or_dash($avgscore),
            "statusdisplay" => get_string($statuskey, "gimidashboardreports_quizprogress"),
            "statusclass" => $statusclass,
            "focusurl" => self::build_url($selection->target, $cohortid, (int) $user->id),
            "hasattempts" => $attempts > 0,
        ];
    }

    /**
     * Resolves the status label and class.
     *
     * @param int $availablecount Available quizzes.
     * @param int $submittedquizzes Submitted quizzes.
     * @param int $attempts Attempts.
     * @return array
     */
    protected static function resolve_status(int $availablecount, int $submittedquizzes, int $attempts): array {
        if ($attempts <= 0 || $submittedquizzes <= 0) {
            return ["statusnotstarted", "is-empty"];
        }

        if ($availablecount > 0 && $submittedquizzes >= $availablecount) {
            return ["statuscomplete", "is-complete"];
        }

        if ($availablecount > 0 && (($submittedquizzes / $availablecount) * 100) >= 60) {
            return ["statusengaged", "is-engaged"];
        }

        return ["statusinprogress", "is-progress"];
    }

    /**
     * Builds the cohort option links.
     *
     * @param string $target Target.
     * @param array $cohorts Cohorts.
     * @param int $selectedcohortid Selected cohort id.
     * @param int $learnerid Learner id.
     * @return array
     * @throws \coding_exception
     * @throws \core\exception\moodle_exception
     */
    protected static function build_cohort_options(
        string $target,
        array $cohorts,
        int $selectedcohortid = 0,
        int $learnerid = 0
    ): array {
        $options = [];
        $options[] = [
            "name" => get_string("allcohorts", "gimidashboardreports_quizprogress"),
            "url" => self::build_url($target, 0, $learnerid),
            "selected" => $selectedcohortid === 0,
        ];

        foreach ($cohorts as $cohortid => $name) {
            $options[] = [
                "name" => $name,
                "url" => self::build_url($target, (int) $cohortid, $learnerid),
                "selected" => $selectedcohortid === (int) $cohortid,
            ];
        }

        return $options;
    }

    /**
     * Builds a report URL preserving the selected plugin.
     *
     * @param string $target Target.
     * @param int $cohortid Cohort id.
     * @param int $learnerid Learner id.
     * @return moodle_url
     * @throws \core\exception\moodle_exception
     */
    protected static function build_url(string $target, int $cohortid = 0, int $learnerid = 0): moodle_url {
        $params = [
            "target" => $target,
            "plugin" => "quizprogress",
        ];

        if ($cohortid > 0) {
            $params["cohortid"] = $cohortid;
        }

        if ($learnerid > 0) {
            $params["learnerid"] = $learnerid;
        }

        return new moodle_url("/local/gimidashboard/", $params);
    }

    /**
     * Formats a course link.
     *
     * @param object $selection Current selection.
     * @param object $course Course.
     * @param int $cohortid Cohort id.
     * @param int $learnerid Learner id.
     * @return string
     * @throws \core\exception\moodle_exception
     */
    protected static function format_course_link(object $selection, object $course, int $cohortid = 0, int $learnerid = 0): string {
        $label = format_string($course->fullname, true, ["context" => context_course::instance($course->id)]);
        if ($selection->type === "course") {
            return $label;
        }

        $url = self::build_url("course-" . $course->id, $cohortid, $learnerid);
        return '<a href="' . $url . '">' . $label . '</a>';
    }

    /**
     * Formats a percentage.
     *
     * @param float $value Value.
     * @return string
     */
    protected static function format_percent(float $value): string {
        return format_float($value) . "%";
    }

    /**
     * Formats a percentage or a dash when null.
     *
     * @param float|null $value Value.
     * @return string
     * @throws \coding_exception
     */
    protected static function format_percent_or_dash(?float $value): string {
        if ($value === null) {
            return get_string("notavailable", "gimidashboardreports_quizprogress");
        }

        return self::format_percent($value);
    }

    /**
     * Aggregates gradebook metrics by course.
     *
     * @param array $usergrademetrics Grade metrics by learner and course.
     * @return array
     */
    protected static function aggregate_grade_metrics_by_course(array $usergrademetrics): array {
        $result = [];

        foreach ($usergrademetrics as $gradesbycourse) {
            foreach ($gradesbycourse as $courseid => $metric) {
                $courseid = (int) $courseid;

                if (!isset($result[$courseid])) {
                    $result[$courseid] = (object) [
                        "scoretotal" => 0.0,
                        "scorecount" => 0,
                    ];
                }

                $result[$courseid]->scoretotal += (float) $metric->scoretotal;
                $result[$courseid]->scorecount += (int) $metric->scorecount;
            }
        }

        return $result;
    }
}
