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
 * @package   gimidashboardreports_certifications
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gimidashboardreports_certifications;

use context_course;
use Exception;
use local_gimidashboard\header_helper;
use local_gimidashboard\page\selection_resolver;
use local_gimidashboard\report\base_report;
use local_gimidashboard\report\report_interface;
use stdClass;
use xmldb_field;
use xmldb_table;

/**
 * Course certifications report.
 *
 * @package   gimidashboardreports_certifications
 */
class report implements report_interface {
    /**
     * Returns the report title.
     *
     * @param array $courses Accessible course records.
     * @param string $extra Extra header html.
     * @return string
     * @throws Exception
     */
    public static function get_header(array $courses, $extra = ""): string {
        $reportdata = self::prepare_report_data($courses);
        if (empty($reportdata->courseids)) {
            return "";
        }

        return header_helper::render_standard_header(
            get_string("pluginname", "gimidashboardreports_certifications"),
            $reportdata->selection,
            $reportdata->courseids,
            [
                header_helper::get_scope_context_label($reportdata->selection, $reportdata->courseids),
                get_string("certifieduserscount", "gimidashboardreports_certifications", $reportdata->summary->certifiedusers),
            ],
            $extra,
            "gimidashboardreports_certifications"
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
     * Renders the report html.
     *
     * @param array $courses Accessible course records.
     * @return string
     * @throws Exception
     */
    public static function render(array $courses): string {
        global $OUTPUT, $PAGE;

        $reportdata = self::prepare_report_data($courses);
        if (empty($reportdata->courseids)) {
            return "";
        }

        $pagelength = optional_param("plugin", false, PARAM_COMPONENT) ? 50 : 10;
        $PAGE->requires->js_call_amd(
            "local_gimidashboard/dashboard",
            "datatable",
            ["#gimi-certifications-course-table", $pagelength]
        );
        $PAGE->requires->js_call_amd(
            "local_gimidashboard/dashboard",
            "datatable",
            ["#gimi-certifications-user-table", $pagelength]
        );
        $PAGE->requires->js_call_amd(
            "local_gimidashboard/dashboard",
            "datatable",
            ["#gimi-certifications-certificate-course-table", $pagelength]
        );

        return $OUTPUT->render_from_template("gimidashboardreports_certifications/content", [
            "kpis" => [
                [
                    "label" => get_string("enrolled", "gimidashboardreports_certifications"),
                    "value" => $reportdata->summary->enrolled,
                ],
                [
                    "label" => get_string("certifiedusers", "gimidashboardreports_certifications"),
                    "value" => $reportdata->summary->certifiedusers,
                ],
                [
                    "label" => get_string("certifiedlast2years", "gimidashboardreports_certifications"),
                    "value" => $reportdata->summary->recentcertified,
                ],
                [
                    "label" => get_string("approvedrate", "gimidashboardreports_certifications"),
                    "value" => self::format_percent($reportdata->summary->approvedrate),
                ],
                [
                    "label" => get_string("failrate", "gimidashboardreports_certifications"),
                    "value" => self::format_percent($reportdata->summary->failrate),
                ],
            ],
            "helptext" => get_string("detailhelp", "gimidashboardreports_certifications"),
            "periodlabel" => get_string(
                "periodsince",
                "gimidashboardreports_certifications",
                userdate($reportdata->recentthreshold, get_string("strftimedate", "langconfig"))
            ),
            "certifiedlabel" => get_string(
                "certifieduserscount",
                "gimidashboardreports_certifications",
                $reportdata->summary->certifiedusers
            ),
            "courserows" => array_values($reportdata->courserows),
            "hascourserows" => !empty($reportdata->courserows),
            "userrows" => array_values($reportdata->userrows),
            "hasuserrows" => !empty($reportdata->userrows),
            "certificatecourserows" => array_values($reportdata->certificatecourserows),
            "hascertificatecourserows" => !empty($reportdata->certificatecourserows),
        ]);
    }

    /**
     * Prepares report data.
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
        $selection = selection_resolver::resolve(optional_param("target", "", PARAM_TEXT), $USER->id);
        $recentthreshold = self::get_two_year_threshold();

        if (empty($courseids)) {
            return (object) [
                "courseids" => [],
                "selection" => $selection,
                "recentthreshold" => $recentthreshold,
                "courserows" => [],
                "userrows" => [],
                "certificatecourserows" => [],
                "summary" => self::build_empty_summary(),
            ];
        }

        $coursemap = self::map_courses($courses);
        $learners = self::get_learners($courseids);
        $userids = array_keys($learners);
        $enrolments = self::get_enrolment_pairs($courseids, $userids);
        $coursecompletions = base_report::get_course_completions($courseids, $userids);
        $certificateissues = self::get_certificate_issues($courseids, $userids);
        $certificatecourserows = self::get_certificate_course_rows($courseids);

        $coursematrix = self::build_course_matrix($coursemap);
        $certifieduserids = [];
        $recentcertifieduserids = [];
        $userrows = [];
        $totalenrolled = 0;
        $totalcertified = 0;
        $totalrecentcertified = 0;

        foreach ($enrolments as $userid => $usercourses) {
            if (empty($learners[$userid])) {
                continue;
            }

            foreach ($usercourses as $courseid => $unused) {
                if (empty($coursemap[$courseid])) {
                    continue;
                }

                $totalenrolled++;
                $coursematrix[$courseid]->enrolled++;

                $issue = $certificateissues[$userid][$courseid] ?? null;
                $completiontime = ($coursecompletions[$userid][$courseid] ?? 0);
                $certification = self::resolve_certification($issue, $completiontime);

                if (!$certification->certified) {
                    continue;
                }

                $totalcertified++;
                $certifieduserids[$userid] = $userid;
                $coursematrix[$courseid]->certified++;

                if ($certification->time >= $recentthreshold) {
                    $totalrecentcertified++;
                    $recentcertifieduserids[$userid] = $userid;
                    $coursematrix[$courseid]->recentcertified++;
                }

                if ($certification->time > $coursematrix[$courseid]->latestcertification) {
                    $coursematrix[$courseid]->latestcertification = $certification->time;
                }

                $userrows[] = self::build_user_row(
                    $learners[$userid],
                    $coursemap[$courseid],
                    $certification,
                    $recentthreshold
                );
            }
        }

        $courserows = self::build_course_rows($coursematrix);
        $summary = self::build_summary(
            $totalenrolled,
            $totalcertified,
            $totalrecentcertified,
            count($certifieduserids),
            count($recentcertifieduserids)
        );

        usort($userrows, static function(array $left, array $right): int {
            if ($left["issuedatesort"] == $right["issuedatesort"]) {
                return strcmp($left["lastname"] . $left["firstname"], $right["lastname"] . $right["firstname"]);
            }

            return $right["issuedatesort"] <=> $left["issuedatesort"];
        });

        $returndata = (object) [
            "courseids" => $courseids,
            "selection" => $selection,
            "recentthreshold" => $recentthreshold,
            "courserows" => $courserows,
            "userrows" => $userrows,
            "certificatecourserows" => $certificatecourserows,
            "summary" => $summary,
        ];

        return $returndata;
    }

    /**
     * Returns enrolled learners.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws Exception
     */
    protected static function get_learners(array $courseids): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params["confirmed"] = 1;

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
                 WHERE e.courseid {$coursesql}
                   AND e.status = 0
                   AND ue.status = 0
                   AND u.deleted = 0
                   AND u.confirmed = :confirmed
              ORDER BY u.firstname ASC, u.lastname ASC, u.email ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Returns active enrolment pairs keyed by user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_enrolment_pairs(array $courseids, array $userids): array {
        global $DB;

        if (empty($courseids) || empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT DISTINCT CONCAT(ue.userid, '-', e.courseid) AS uniqid,
                                ue.userid,
                                e.courseid
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
            $result[$record->userid][$record->courseid] = true;
        }

        return $result;
    }

    /**
     * Returns certificate issues from supported certificate plugins.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_certificate_issues(array $courseids, array $userids): array {
        $result = [];

        if (empty($courseids) || empty($userids)) {
            return $result;
        }

        self::merge_certificate_results($result, self::get_simplecertificate_issues($courseids, $userids));
        self::merge_certificate_results($result, self::get_coursecertificate_issues($courseids, $userids));

        return $result;
    }

    /**
     * Returns course rows grouped by certificate module type.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws Exception
     */
    protected static function get_certificate_course_rows(array $courseids): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        $rows = [];
        foreach (self::get_certificate_module_definitions() as $definition) {
            if (!self::table_exists($definition["table"])) {
                continue;
            }

            [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, $definition["paramprefix"]);
            $params = $courseparams + [
                $definition["paramprefix"] . "modname" => $definition["modname"],
            ];

            $namefield = self::field_exists($definition["table"], "name") ? "activity.name" : "''";
            $sql = "SELECT cm.id AS coursemoduleid,
                           cm.course AS courseid,
                           cm.instance,
                           c.fullname AS coursefullname,
                           c.shortname AS courseshortname,
                           {$namefield} AS activityname
                      FROM {course_modules} cm
                      JOIN {modules} m
                        ON m.id = cm.module
                       AND m.name = :{$definition["paramprefix"]}modname
                      JOIN {course} c
                        ON c.id = cm.course
                 LEFT JOIN {{$definition["table"]}} activity
                        ON activity.id = cm.instance
                     WHERE cm.course -- {$coursesql}
                  ORDER BY c.fullname ASC, c.shortname ASC, activityname ASC";

            $records = $DB->get_records_sql($sql, $params);
            if (empty($records)) {
                continue;
            }

            $modulecourseids = [];
            $moduleinstancecount = count($records);
            $modulerows = [];

            foreach ($records as $record) {
                $courseid = $record->courseid;
                $modulecourseids[$courseid] = $courseid;
                $key = $definition["modname"] . "-" . $courseid;

                if (empty($modulerows[$key])) {
                    $context = context_course::instance($courseid);
                    $coursename = format_string($record->coursefullname, true, ["context" => $context]);

                    $modulerows[$key] = [
                        "moduleorder" => $definition["order"],
                        "certificatecomponent" => "mod_" . $definition["modname"],
                        "certificatename" => get_string($definition["stringkey"], "gimidashboardreports_certifications"),
                        "totalinstances" => 0,
                        "totalinstancessort" => 0,
                        "totalcourses" => 0,
                        "totalcoursessort" => 0,
                        "course" => "<a href='?target=course-{$record->courseid}&plugin=certifications'>{$coursename}</a>",
                        "courseshortname" => format_string($record->courseshortname, true, ["context" => $context]),
                        "courseinstances" => 0,
                        "courseinstancessort" => 0,
                        "activities" => [],
                    ];
                }

                $modulerows[$key]["courseinstances"]++;
                $modulerows[$key]["courseinstancessort"]++;

                $activityname = trim(($record->activityname ?? ""));
                if ($activityname == "") {
                    $activityname = get_string("activitywithoutname", "gimidashboardreports_certifications", $record->instance);
                }

                $context = context_course::instance($courseid);
                $modulerows[$key]["activities"][] = format_string($activityname, true, ["context" => $context]);
            }

            $modulecoursecount = count($modulecourseids);
            foreach ($modulerows as $key => $row) {
                $row["totalinstances"] = $moduleinstancecount;
                $row["totalinstancessort"] = $moduleinstancecount;
                $row["totalcourses"] = $modulecoursecount;
                $row["totalcoursessort"] = $modulecoursecount;
                $row["activities"] = implode(", ", array_values(array_unique($row["activities"])));
                $rows[] = $row;
            }
        }

        usort($rows, static function(array $left, array $right): int {
            if ($left["moduleorder"] == $right["moduleorder"]) {
                return strcmp($left["course"], $right["course"]);
            }

            return $left["moduleorder"] <=> $right["moduleorder"];
        });

        return $rows;
    }

    /**
     * Returns the supported certificate module definitions used by the course table.
     *
     * @return array
     */
    protected static function get_certificate_module_definitions(): array {
        return [
            [
                "order" => 10,
                "modname" => "coursecertificate",
                "table" => "coursecertificate",
                "stringkey" => "coursecertificate",
                "paramprefix" => "coursecert",
            ],
            [
                "order" => 20,
                "modname" => "linkedincert",
                "table" => "linkedincert",
                "stringkey" => "linkedincert",
                "paramprefix" => "linkedincert",
            ],
            [
                "order" => 30,
                "modname" => "simplecertificate",
                "table" => "simplecertificate",
                "stringkey" => "simplecertificate",
                "paramprefix" => "simplecert",
            ],
        ];
    }

    /**
     * Returns mod_simplecertificate issue data.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_simplecertificate_issues(array $courseids, array $userids): array {
        global $DB;

        if (!self::table_exists("simplecertificate") || !self::table_exists("simplecertificate_issues")) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "scourse");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "suser");

        $timefield = self::field_exists("simplecertificate_issues", "timecreated") ? "issues.timecreated" : "0";
        $whereextra = "";
        if (self::field_exists("simplecertificate_issues", "timedeleted")) {
            $whereextra .= " AND (issues.timedeleted IS NULL OR issues.timedeleted = 0)";
        }

        $sql = "SELECT CONCAT(issues.userid, '-', activity.course) AS uniqid,
                       issues.userid,
                       activity.course AS courseid,
                       COUNT(DISTINCT issues.id) AS total,
                       MIN({$timefield}) AS firstissued,
                       MAX({$timefield}) AS lastissued
                  FROM {simplecertificate_issues} issues
                  JOIN {simplecertificate} activity
                    ON activity.id = issues.certificateid
                 WHERE activity.course {$coursesql}
                   AND issues.userid {$usersql}
                   {$whereextra}
              GROUP BY issues.userid, activity.course";

        $records = $DB->get_records_sql($sql, $courseparams + $userparams);

        return self::normalize_issue_records($records, "simplecertificate");
    }

    /**
     * Returns mod_coursecertificate issue data stored by tool_certificate.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_coursecertificate_issues(array $courseids, array $userids): array {
        global $DB;

        if (!self::table_exists("coursecertificate") || !self::table_exists("tool_certificate_issues")) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "ccourse");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "cuser");

        $timefield = self::field_exists("tool_certificate_issues", "timecreated") ? "issues.timecreated" : "0";
        $whereextra = "";
        if (self::field_exists("tool_certificate_issues", "archived")) {
            $whereextra .= " AND issues.archived = 0";
        }

        $params = $courseparams + $userparams + [
            "component" => "mod_coursecertificate",
        ];

        $sql = "SELECT CONCAT(issues.userid, '-', activity.course) AS uniqid,
                       issues.userid,
                       activity.course AS courseid,
                       COUNT(DISTINCT issues.id) AS total,
                       MIN({$timefield}) AS firstissued,
                       MAX({$timefield}) AS lastissued
                  FROM {tool_certificate_issues} issues
                  JOIN {coursecertificate} activity
                    ON activity.template = issues.templateid
                   AND activity.course = issues.courseid
                 WHERE activity.course {$coursesql}
                   AND issues.userid {$usersql}
                   AND issues.component = :component
                   {$whereextra}
              GROUP BY issues.userid, activity.course";

        $records = $DB->get_records_sql($sql, $params);

        return self::normalize_issue_records($records, "coursecertificate");
    }

    /**
     * Converts database certificate records to the internal structure.
     *
     * @param array $records Records.
     * @param string $source Source code.
     * @return array
     */
    protected static function normalize_issue_records(array $records, string $source): array {
        $result = [];
        foreach ($records as $record) {
            $userid = $record->userid;
            $courseid = $record->courseid;
            $result[$userid][$courseid] = (object) [
                "count" => $record->total,
                "firstissued" => $record->firstissued,
                "lastissued" => $record->lastissued,
                "source" => $source,
            ];
        }

        return $result;
    }

    /**
     * Merges certificate results preserving the latest issue timestamp.
     *
     * @param array $target Target data.
     * @param array $source Source data.
     * @return void
     */
    protected static function merge_certificate_results(array &$target, array $source): void {
        foreach ($source as $userid => $courses) {
            foreach ($courses as $courseid => $issue) {
                if (empty($target[$userid][$courseid])) {
                    $target[$userid][$courseid] = $issue;
                    continue;
                }

                $target[$userid][$courseid]->count += $issue->count;
                if ($target[$userid][$courseid]->firstissued <= 0
                    || ($issue->firstissued > 0 && $issue->firstissued < $target[$userid][$courseid]->firstissued)) {
                    $target[$userid][$courseid]->firstissued = $issue->firstissued;
                }
                if ($issue->lastissued > $target[$userid][$courseid]->lastissued) {
                    $target[$userid][$courseid]->lastissued = $issue->lastissued;
                    $target[$userid][$courseid]->source = $issue->source;
                }
            }
        }
    }

    /**
     * Resolves the certification state for one learner/course pair.
     *
     * @param object|null $issue Certificate issue object.
     * @param int $completiontime Moodle course completion timestamp.
     * @return stdClass
     */
    protected static function resolve_certification(?object $issue, int $completiontime): stdClass {
        if (!empty($issue) && $issue->count > 0) {
            $time = $issue->lastissued;
            if ($time <= 0) {
                $time = $issue->firstissued;
            }

            return (object) [
                "certified" => true,
                "time" => $time,
                "source" => $issue->source,
                "count" => $issue->count,
            ];
        }

        if ($completiontime > 0) {
            return (object) [
                "certified" => true,
                "time" => $completiontime,
                "source" => "completion",
                "count" => 1,
            ];
        }

        return (object) [
            "certified" => false,
            "time" => 0,
            "source" => "",
            "count" => 0,
        ];
    }

    /**
     * Returns a map with formatted course names.
     *
     * @param array $courses Course records.
     * @return array
     */
    protected static function map_courses(array $courses): array {
        $result = [];
        foreach ($courses as $course) {
            $courseid = $course->id;
            $result[$courseid] = (object) [
                "id" => $courseid,
                "fullname" => format_string(
                    $course->fullname,
                    true,
                    ["context" => context_course::instance($courseid)]
                ),
                "shortname" => format_string(
                    $course->shortname,
                    true,
                    ["context" => context_course::instance($courseid)]
                ),
            ];
        }

        return $result;
    }

    /**
     * Builds an empty course statistics matrix.
     *
     * @param array $coursemap Course map.
     * @return array
     */
    protected static function build_course_matrix(array $coursemap): array {
        $matrix = [];
        foreach ($coursemap as $courseid => $course) {
            $matrix[$courseid] = (object) [
                "course" => $course,
                "enrolled" => 0,
                "certified" => 0,
                "recentcertified" => 0,
                "latestcertification" => 0,
            ];
        }

        return $matrix;
    }

    /**
     * Builds course summary rows.
     *
     * @param array $coursematrix Course statistics matrix.
     * @return array
     */
    protected static function build_course_rows(array $coursematrix): array {
        $rows = [];
        foreach ($coursematrix as $courseid => $stats) {
            $failed = max(0, $stats->enrolled - $stats->certified);
            $approvedrate = self::calculate_rate($stats->certified, $stats->enrolled);
            $failrate = self::calculate_rate($failed, $stats->enrolled);

            $rows[] = [
                "coursename" => $stats->course->fullname,
                "enrolled" => $stats->enrolled,
                "enrolledsort" => $stats->enrolled,
                "certified" => $stats->certified,
                "certifiedsort" => $stats->certified,
                "recentcertified" => $stats->recentcertified,
                "recentcertifiedsort" => $stats->recentcertified,
                "failed" => $failed,
                "failedsort" => $failed,
                "approvedrate" => self::format_percent($approvedrate),
                "approvedratesort" => $approvedrate,
                "failrate" => self::format_percent($failrate),
                "failratesort" => $failrate,
                "latestcertification" => self::format_date($stats->latestcertification),
                "latestcertificationsort" => $stats->latestcertification,
            ];
        }

        usort($rows, static function(array $left, array $right): int {
            return strcmp($left["coursename"], $right["coursename"]);
        });

        return $rows;
    }

    /**
     * Builds a certified user row.
     *
     * @param object $user User record.
     * @param object $course Course object.
     * @param object $certification Certification data.
     * @param int $recentthreshold Recent threshold timestamp.
     * @return array
     */
    protected static function build_user_row(
        object $user,
        object $course,
        object $certification,
        int $recentthreshold
    ): array {
        $recent = $certification->time >= $recentthreshold;

        return [
            "firstname" => s($user->firstname),
            "lastname" => s($user->lastname),
            "email" => s($user->email),
            "coursename" => s($course->fullname),
            "issuedate" => self::format_date($certification->time),
            "issuedatesort" => $certification->time,
            "recentlabel" => get_string($recent ? "yes" : "no", "gimidashboardreports_certifications"),
            "recentsort" => $recent ? 1 : 0,
            "source" => self::format_source($certification->source),
            "status" => get_string("certified", "gimidashboardreports_certifications"),
            "statusclass" => "is-certified",
        ];
    }

    /**
     * Builds the global summary.
     *
     * @param int $totalenrolled Total learner/course enrolments.
     * @param int $totalcertified Total certified learner/course pairs.
     * @param int $totalrecentcertified Total recent certified learner/course pairs.
     * @param int $certifiedusers Distinct certified users.
     * @param int $recentcertifiedusers Distinct recently certified users.
     * @return stdClass
     */
    protected static function build_summary(
        int $totalenrolled,
        int $totalcertified,
        int $totalrecentcertified,
        int $certifiedusers,
        int $recentcertifiedusers
    ): stdClass {
        $failed = max(0, $totalenrolled - $totalcertified);

        return (object) [
            "enrolled" => $totalenrolled,
            "certified" => $totalcertified,
            "recentcertified" => $totalrecentcertified,
            "certifiedusers" => $certifiedusers,
            "recentcertifiedusers" => $recentcertifiedusers,
            "failed" => $failed,
            "approvedrate" => self::calculate_rate($totalcertified, $totalenrolled),
            "failrate" => self::calculate_rate($failed, $totalenrolled),
        ];
    }

    /**
     * Builds an empty summary object.
     *
     * @return stdClass
     */
    protected static function build_empty_summary(): stdClass {
        return self::build_summary(0, 0, 0, 0, 0);
    }

    /**
     * Returns true when a table exists.
     *
     * @param string $tablename Table name without prefix.
     * @return bool
     */
    protected static function table_exists(string $tablename): bool {
        global $DB;

        return $DB->get_manager()->table_exists(new xmldb_table($tablename));
    }

    /**
     * Returns true when a field exists.
     *
     * @param string $tablename Table name without prefix.
     * @param string $fieldname Field name.
     * @return bool
     */
    protected static function field_exists(string $tablename, string $fieldname): bool {
        global $DB;

        return $DB->get_manager()->field_exists(new xmldb_table($tablename), new xmldb_field($fieldname));
    }

    /**
     * Returns the threshold for the last two years.
     *
     * @return int
     */
    protected static function get_two_year_threshold(): int {
        return strtotime("-2 years", time());
    }

    /**
     * Calculates a percentage rate.
     *
     * @param int $part Part.
     * @param int $total Total.
     * @return float
     */
    protected static function calculate_rate(int $part, int $total): float {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 1);
    }

    /**
     * Formats a percentage.
     *
     * @param float $value Value.
     * @return string
     */
    protected static function format_percent(float $value): string {
        return format_float($value, 1) . "%";
    }

    /**
     * Formats a timestamp.
     *
     * @param int $timestamp Timestamp.
     * @return string
     */
    protected static function format_date(int $timestamp): string {
        if ($timestamp <= 0) {
            return get_string("never", "gimidashboardreports_certifications");
        }

        return userdate($timestamp, get_string("strftimedate", "langconfig"));
    }

    /**
     * Formats a certification source code.
     *
     * @param string $source Source code.
     * @return string
     */
    protected static function format_source(string $source): string {
        $known = [
            "simplecertificate" => "simplecertificate",
            "coursecertificate" => "coursecertificate",
            "completion" => "completedcourse",
        ];

        return get_string($known[$source] ?? "unknownsource", "gimidashboardreports_certifications");
    }
}
