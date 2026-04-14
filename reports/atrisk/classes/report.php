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
 * @package   gimidashboardreports_atrisk
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gimidashboardreports_atrisk;

use coding_exception;
use context_course;
use context_system;
use core_text;
use Exception;
use local_gimidashboard\page\selection_resolver;
use local_gimidashboard\report\report_interface;
use moodle_url;
use stdClass;

/**
 * At-risk learners report.
 *
 * @package   gimidashboardreports_atrisk
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
        global $OUTPUT;

        $reportdata = self::prepare_report_data($courses);
        if (empty($reportdata->courseids)) {
            return "";
        }

        $academyname = strtoupper(
            strip_tags(
                $reportdata->selection->label !== ""
                    ? $reportdata->selection->label
                    : get_string("pluginname", "gimidashboardreports_atrisk")
            )
        );

        $subtitleparts = [
            get_string("selectionlabel", "gimidashboardreports_atrisk", strip_tags($reportdata->selection->label)),
            get_string("flaggedlabel", "gimidashboardreports_atrisk", count($reportdata->rows)),
            get_string(
                "snapshotlabel",
                "gimidashboardreports_atrisk",
                userdate(time(), get_string("strftimedatefullshort", "langconfig"))
            ),
        ];

        return $OUTPUT->render_from_template("local_gimidashboard/content_title", [
            "academyname" => $academyname,
            "pluginname" => get_string("pluginname", "gimidashboardreports_atrisk"),
            "subtitle" => implode(" • ", $subtitleparts),
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

        $pagelength = optional_param("plugin", false, PARAM_COMPONENT) ? 50 : 5;
        $PAGE->requires->js_call_amd("local_gimidashboard/dashboard", "datatable", ["#gimi-atrisk-table", $pagelength]);
        $PAGE->requires->js_call_amd("local_gimidashboard/dashboard", "datatable", ["#gimi-atrisk-engagement-table", $pagelength]);

        return $OUTPUT->render_from_template("gimidashboardreports_atrisk/content", [
            "kpis" => [
                [
                    "label" => get_string("totallearners", "gimidashboardreports_atrisk"),
                    "value" => $reportdata->summary->totallearners,
                ],
                [
                    "label" => get_string("highriskcount", "gimidashboardreports_atrisk"),
                    "value" => $reportdata->summary->highrisk,
                ],
                [
                    "label" => get_string("mediumriskcount", "gimidashboardreports_atrisk"),
                    "value" => $reportdata->summary->mediumrisk,
                ],
                [
                    "label" => get_string("neveraccessedcount", "gimidashboardreports_atrisk"),
                    "value" => $reportdata->summary->neveraccessed,
                ],
                [
                    "label" => get_string("inactive30count", "gimidashboardreports_atrisk"),
                    "value" => $reportdata->summary->inactive30,
                ],
            ],
            "rows" => array_values($reportdata->rows),
            "hasrows" => !empty($reportdata->rows),
            "engagementkpis" => [
                [
                    "label" => get_string("enrolledlearners", "gimidashboardreports_atrisk"),
                    "value" => $reportdata->engagement->summary->enrolled,
                ],
                [
                    "label" => get_string("engagedlearners", "gimidashboardreports_atrisk"),
                    "value" => $reportdata->engagement->summary->engaged,
                ],
                [
                    "label" => get_string("notengagedlearners", "gimidashboardreports_atrisk"),
                    "value" => $reportdata->engagement->summary->notengaged,
                ],
                [
                    "label" => get_string("completedlearners", "gimidashboardreports_atrisk"),
                    "value" => $reportdata->engagement->summary->completed,
                ],
            ],
            "engagementchartsvg" => self::render_engagement_chart_svg($reportdata->engagement->chart),
            "engagementchartlegend" => [
                [
                    "color" => "#2563eb",
                    "label" => get_string("engagedlearners", "gimidashboardreports_atrisk"),
                ],
                [
                    "color" => "#f59e0b",
                    "label" => get_string("notengagedlearners", "gimidashboardreports_atrisk"),
                ],
                [
                    "color" => "#16a34a",
                    "label" => get_string("completedlearners", "gimidashboardreports_atrisk"),
                ],
            ],
            "engagementrows" => array_values($reportdata->engagement->rows),
            "hasengagementrows" => !empty($reportdata->engagement->rows),
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

        $courseids = self::extract_course_ids($courses);
        $selection = selection_resolver::resolve(optional_param("target", "", PARAM_TEXT), $USER->id);

        $base = (object) [
            "courseids" => $courseids,
            "selection" => $selection,
            "rows" => [],
            "allrows" => [],
            "summary" => (object) [
                "totallearners" => 0,
                "highrisk" => 0,
                "mediumrisk" => 0,
                "neveraccessed" => 0,
                "inactive30" => 0,
            ],
            "engagement" => (object) [
                "summary" => (object) [
                    "enrolled" => 0,
                    "engaged" => 0,
                    "notengaged" => 0,
                    "completed" => 0,
                ],
                "chart" => (object) [
                    "labels" => [],
                    "series" => [],
                ],
                "rows" => [],
            ],
        ];

        if (empty($courseids)) {
            $cache = $base;
            return $cache;
        }

        $users = self::get_learners($courseids);
        if (empty($users)) {
            $cache = $base;
            return $cache;
        }

        $userids = array_keys($users);
        $usercourses = self::get_user_courses($courseids, $userids);
        $enroltimes = self::get_user_enrolment_times($courseids, $userids);
        $moduletotals = self::get_trackable_module_totals($courseids);
        $completedmodules = self::get_completed_module_totals($courseids, $userids);
        $gradepercentages = self::get_course_grade_percentages($courseids, $userids);
        $completions = self::get_course_completions($courseids, $userids);
        $lastaccesses = self::get_last_access_by_course($courseids, $userids);
        $pathways = self::get_user_pathways($courseids, $userids);

        $allrows = [];
        $rows = [];

        foreach ($users as $userid => $user) {
            $row = self::build_user_row(
                $user,
                $selection,
                $usercourses[$userid] ?? [],
                $enroltimes[$userid] ?? [],
                $moduletotals,
                $completedmodules[$userid] ?? [],
                $gradepercentages[$userid] ?? [],
                $completions[$userid] ?? [],
                $lastaccesses[$userid] ?? [],
                $pathways[$userid] ?? []
            );

            $allrows[$userid] = $row;
            if ($row["risklevel"] !== "low") {
                $rows[$userid] = $row;
            }
        }

        uasort($allrows, [self::class, "sort_rows"]);
        uasort($rows, [self::class, "sort_rows"]);

        $base->allrows = $allrows;
        $base->rows = $rows;
        $base->summary = self::build_summary($allrows);
        $base->engagement = self::build_engagement_data(
            $courses,
            $allrows,
            $usercourses,
            $completions,
            $lastaccesses,
            $selection
        );

        $cache = $base;
        return $cache;
    }

    /**
     * Sort callback for risk rows.
     *
     * @param array $left Left row.
     * @param array $right Right row.
     * @return int
     */
    protected static function sort_rows(array $left, array $right): int {
        if ($left["riskscore"] !== $right["riskscore"]) {
            return $right["riskscore"] <=> $left["riskscore"];
        }

        if ($left["daysinactivevalue"] !== $right["daysinactivevalue"]) {
            return $right["daysinactivevalue"] <=> $left["daysinactivevalue"];
        }

        if ($left["avgprogressraw"] !== $right["avgprogressraw"]) {
            return $left["avgprogressraw"] <=> $right["avgprogressraw"];
        }

        return core_text::strtolower($left["learnername"]) <=> core_text::strtolower($right["learnername"]);
    }

    /**
     * Builds one learner row.
     *
     * @param stdClass $user User record.
     * @param object $selection Current selection.
     * @param array $usercourseids Selected course ids for the learner.
     * @param array $enroltimes Enrolment times keyed by course id.
     * @param array $moduletotals Trackable modules keyed by course id.
     * @param array $completedmodules Completed modules keyed by course id.
     * @param array $gradepercentages Grade percentages keyed by course id.
     * @param array $completions Course completions keyed by course id.
     * @param array $lastaccesses Last accesses keyed by course id.
     * @param array $pathways Pathway names keyed by cohort id.
     * @return array
     * @throws coding_exception
     * @throws \Exception
     */
    protected static function build_user_row(
        stdClass $user,
        object $selection,
        array $usercourseids,
        array $enroltimes,
        array $moduletotals,
        array $completedmodules,
        array $gradepercentages,
        array $completions,
        array $lastaccesses,
        array $pathways
    ): array {
        $coursecount = count($usercourseids);
        $completedcourses = count($completions);
        $neveraccessedcourses = 0;
        $latestaccess = 0;
        $earliestenrol = 0;
        $progressvalues = [];
        $gradevalues = [];

        foreach ($usercourseids as $courseid) {
            $modules = (int) ($moduletotals[$courseid] ?? 0);
            $done = (int) ($completedmodules[$courseid] ?? 0);
            $completed = !empty($completions[$courseid]);
            $lastaccess = (int) ($lastaccesses[$courseid] ?? 0);
            $enroltime = (int) ($enroltimes[$courseid] ?? 0);

            if ($lastaccess <= 0) {
                $neveraccessedcourses++;
            } else if ($lastaccess > $latestaccess) {
                $latestaccess = $lastaccess;
            }

            if ($enroltime > 0 && ($earliestenrol === 0 || $enroltime < $earliestenrol)) {
                $earliestenrol = $enroltime;
            }

            if ($completed) {
                $progressvalues[] = 100.0;
            } else if ($modules > 0) {
                $progressvalues[] = round(($done / max(1, $modules)) * 100, 1);
            }

            if (array_key_exists($courseid, $gradepercentages) && $gradepercentages[$courseid] !== null) {
                $gradevalues[] = (float) $gradepercentages[$courseid];
            }
        }

        $avgprogress = !empty($progressvalues) ? round(array_sum($progressvalues) / count($progressvalues), 1) : 0.0;
        $avggrade = !empty($gradevalues) ? round(array_sum($gradevalues) / count($gradevalues), 1) : null;
        $dayssinceenrol = $earliestenrol > 0 ? (int) floor((time() - $earliestenrol) / DAYSECS) : 0;
        $daysinactive = $latestaccess > 0 ? (int) floor((time() - $latestaccess) / DAYSECS) : null;

        [$riskscore, $risklevel, $reasons] = self::calculate_risk(
            $coursecount,
            $completedcourses,
            $neveraccessedcourses,
            $avgprogress,
            $avggrade,
            $daysinactive,
            $dayssinceenrol
        );

        $activitydisplay = self::format_activity_display($latestaccess, $daysinactive, $dayssinceenrol);
        $pathwaysdisplay =
            !empty($pathways) ? implode(", ", array_values($pathways)) : get_string("nopathways", "gimidashboardreports_atrisk");

        return [
            "userid" => (int) $user->id,
            "learnername" => fullname($user),
            "email" => s($user->email),
            "pathwaysdisplay" => $pathwaysdisplay,
            "riskscore" => $riskscore,
            "risklevel" => $risklevel,
            "leveldisplay" => get_string($risklevel . "risk", "gimidashboardreports_atrisk"),
            "levelclass" => "is-" . $risklevel,
            "scoredisplay" => get_string("scorelabel", "gimidashboardreports_atrisk", $riskscore),
            "avgprogressraw" => $avgprogress,
            "progressdisplay" => self::format_percent($avgprogress),
            "avggraderaw" => $avggrade,
            "gradedisplay" => self::format_percent($avggrade),
            "completedcoursesraw" => $completedcourses,
            "completeddisplay" => get_string(
                "coursescompletedfraction",
                "gimidashboardreports_atrisk",
                (object) [
                    "done" => $completedcourses,
                    "total" => $coursecount,
                ]
            ),
            "daysinactivevalue" => $daysinactive === null ? $dayssinceenrol : $daysinactive,
            "latestaccessvalue" => $latestaccess,
            "completedallcourses" => $coursecount > 0 && $completedcourses >= $coursecount,
            "engagedflag" => $latestaccess > 0,
            "activitydisplay" => $activitydisplay,
            "reasons" => $reasons,
            "detailurl" => self::build_learner_url($selection->target, (int) $user->id),
            "neveraccessedall" => $coursecount > 0 && $neveraccessedcourses === $coursecount,
            "inactive30flag" => ($daysinactive !== null && $daysinactive >= 30) ||
                ($daysinactive === null && $dayssinceenrol >= 30),
        ];
    }

    /**
     * Calculates the risk score and reasons.
     *
     * @param int $coursecount Total selected courses.
     * @param int $completedcourses Completed courses count.
     * @param int $neveraccessedcourses Never accessed courses count.
     * @param float $avgprogress Average progress.
     * @param float|null $avggrade Average grade.
     * @param int|null $daysinactive Days inactive.
     * @param int $dayssinceenrol Days since first enrolment.
     * @return array
     * @throws coding_exception
     */
    protected static function calculate_risk(
        int $coursecount,
        int $completedcourses,
        int $neveraccessedcourses,
        float $avgprogress,
        ?float $avggrade,
        ?int $daysinactive,
        int $dayssinceenrol
    ): array {
        $score = 0;
        $reasons = [];

        if ($coursecount > 0 && $completedcourses >= $coursecount) {
            return [0, "low", []];
        }

        if ($coursecount > 0 && $neveraccessedcourses === $coursecount) {
            $score += 60;
            $reasons[] = get_string("allcoursesneveraccessed", "gimidashboardreports_atrisk");
        } else if ($neveraccessedcourses > 0) {
            $score += min(30, $neveraccessedcourses * 10);
            $reasons[] = get_string("neveraccessedcourses", "gimidashboardreports_atrisk", $neveraccessedcourses);
        }

        if ($daysinactive === null) {
            if ($dayssinceenrol >= 30) {
                $score += 30;
            } else if ($dayssinceenrol >= 15) {
                $score += 20;
            } else if ($dayssinceenrol >= 7) {
                $score += 10;
            }
        } else if ($daysinactive >= 60) {
            $score += 35;
            $reasons[] = get_string("inactive60days", "gimidashboardreports_atrisk");
        } else if ($daysinactive >= 30) {
            $score += 25;
            $reasons[] = get_string("inactive30days", "gimidashboardreports_atrisk");
        } else if ($daysinactive >= 15) {
            $score += 15;
            $reasons[] = get_string("inactive15days", "gimidashboardreports_atrisk");
        }

        if ($dayssinceenrol >= 14 && $avgprogress <= 0.0 && $completedcourses === 0) {
            $score += 20;
            $reasons[] = get_string("noprogressafterdays", "gimidashboardreports_atrisk", $dayssinceenrol);
        } else if ($avgprogress < 10) {
            $score += 25;
            $reasons[] = get_string("progressbelow10", "gimidashboardreports_atrisk");
        } else if ($avgprogress < 25) {
            $score += 20;
            $reasons[] = get_string("progressbelow25", "gimidashboardreports_atrisk");
        } else if ($avgprogress < 50) {
            $score += 10;
            $reasons[] = get_string("progressbelow50", "gimidashboardreports_atrisk");
        }

        if ($avggrade !== null && $avggrade < 40) {
            $score += 25;
            $reasons[] = get_string("gradebelow40", "gimidashboardreports_atrisk");
        } else if ($avggrade !== null && $avggrade < 60) {
            $score += 15;
            $reasons[] = get_string("gradebelow60", "gimidashboardreports_atrisk");
        }

        if ($completedcourses === 0 && $dayssinceenrol >= 30) {
            $score += 10;
            $reasons[] = get_string("nocompletionsyet", "gimidashboardreports_atrisk");
        }

        $score = max(0, min(100, $score));
        if ($score >= 70) {
            $level = "high";
        } else if ($score >= 40) {
            $level = "medium";
        } else {
            $level = "low";
        }

        $reasons = array_values(array_unique($reasons));
        return [$score, $level, $reasons];
    }

    /**
     * Builds the engagement summary, chart and learner rows.
     *
     * @param array $courses Accessible course records.
     * @param array $allrows All learner rows.
     * @param array $usercourses Selected course ids per learner.
     * @param array $completions Course completions by learner and course.
     * @param array $lastaccesses Last accesses by learner and course.
     * @param object $selection Current selection.
     * @return object
     * @throws Exception
     */
    protected static function build_engagement_data(
        array $courses,
        array $allrows,
        array $usercourses,
        array $completions,
        array $lastaccesses,
        object $selection
    ): object {
        $coursemap = [];
        foreach ($courses as $course) {
            $coursemap[(int) $course->id] = [
                "courseid" => (int) $course->id,
                "coursename" => format_string($course->fullname, true, ["context" => context_course::instance((int) $course->id)]),
                "enrolled" => 0,
                "engaged" => 0,
                "notengaged" => 0,
                "completed" => 0,
            ];
        }

        $rows = [];
        $summary = (object) [
            "enrolled" => count($allrows),
            "engaged" => 0,
            "notengaged" => 0,
            "completed" => 0,
        ];

        foreach ($allrows as $userid => $row) {
            $selectedcourses = $usercourses[$userid] ?? [];
            foreach ($selectedcourses as $courseid) {
                if (!isset($coursemap[$courseid])) {
                    continue;
                }

                $coursemap[$courseid]["enrolled"]++;
                if (!empty($completions[$userid][$courseid])) {
                    $coursemap[$courseid]["completed"]++;
                } else if (!empty($lastaccesses[$userid][$courseid])) {
                    $coursemap[$courseid]["engaged"]++;
                } else {
                    $coursemap[$courseid]["notengaged"]++;
                }
            }

            if (!empty($row["completedallcourses"])) {
                $summary->completed++;
            } else if (!empty($row["engagedflag"])) {
                $summary->engaged++;
            } else {
                $summary->notengaged++;
            }

            [$statuskey, $statusclass, $statusweight] = self::resolve_engagement_status($row);
            $rows[] = [
                "learnername" => $row["learnername"],
                "email" => $row["email"],
                "pathwaysdisplay" => $row["pathwaysdisplay"],
                "statuslabel" => get_string($statuskey, "gimidashboardreports_atrisk"),
                "statusclass" => $statusclass,
                "statusweight" => $statusweight,
                "progressdisplay" => $row["progressdisplay"],
                "completeddisplay" => $row["completeddisplay"],
                "progressraw" => $row["avgprogressraw"],
                "lastaccessdisplay" => self::format_last_access_label((int) $row["latestaccessvalue"]),
                "daysinactivevalue" => $row["daysinactivevalue"],
                "detailurl" => self::build_learner_url($selection->target, (int) $row["userid"]),
            ];
        }

        usort($rows, static function(array $left, array $right): int {
            if ($left["statusweight"] !== $right["statusweight"]) {
                return $left["statusweight"] <=> $right["statusweight"];
            }

            $leftprogress = (float) $left["progressraw"];
            $rightprogress = (float) $right["progressraw"];
            if ($leftprogress !== $rightprogress) {
                return $leftprogress <=> $rightprogress;
            }

            if ($left["daysinactivevalue"] !== $right["daysinactivevalue"]) {
                return $right["daysinactivevalue"] <=> $left["daysinactivevalue"];
            }

            return core_text::strtolower($left["learnername"]) <=> core_text::strtolower($right["learnername"]);
        });

        $chartrows = array_values($coursemap);
        usort($chartrows, static function(array $left, array $right): int {
            return core_text::strtolower($left["coursename"]) <=> core_text::strtolower($right["coursename"]);
        });

        return (object) [
            "summary" => $summary,
            "chart" => (object) [
                "labels" => array_map(static function(array $row): string {
                    return $row["coursename"];
                }, $chartrows),
                "series" => [
                    [
                        "name" => get_string("engagedlearners", "gimidashboardreports_atrisk"),
                        "color" => "#2563eb",
                        "values" => array_map(static function(array $row): int {
                            return $row["engaged"];
                        }, $chartrows),
                    ],
                    [
                        "name" => get_string("notengagedlearners", "gimidashboardreports_atrisk"),
                        "color" => "#f59e0b",
                        "values" => array_map(static function(array $row): int {
                            return $row["notengaged"];
                        }, $chartrows),
                    ],
                    [
                        "name" => get_string("completedlearners", "gimidashboardreports_atrisk"),
                        "color" => "#16a34a",
                        "values" => array_map(static function(array $row): int {
                            return $row["completed"];
                        }, $chartrows),
                    ],
                ],
            ],
            "rows" => $rows,
        ];
    }

    /**
     * Resolves the engagement status for a learner.
     *
     * @param array $row Learner row.
     * @return array
     */
    protected static function resolve_engagement_status(array $row): array {
        if (!empty($row["completedallcourses"])) {
            return ["completedstatus", "is-completed", 2];
        }

        if (!empty($row["engagedflag"])) {
            return ["engagedstatus", "is-engaged", 1];
        }

        return ["notengagedstatus", "is-notengaged", 0];
    }

    /**
     * Builds the summary counters.
     *
     * @param array $rows All learner rows.
     * @return object
     */
    protected static function build_summary(array $rows): object {
        $summary = (object) [
            "totallearners" => count($rows),
            "highrisk" => 0,
            "mediumrisk" => 0,
            "neveraccessed" => 0,
            "inactive30" => 0,
        ];

        foreach ($rows as $row) {
            if ($row["risklevel"] === "high") {
                $summary->highrisk++;
            } else if ($row["risklevel"] === "medium") {
                $summary->mediumrisk++;
            }

            if (!empty($row["neveraccessedall"])) {
                $summary->neveraccessed++;
            }

            if (!empty($row["inactive30flag"])) {
                $summary->inactive30++;
            }
        }

        return $summary;
    }

    /**
     * Returns the learners enrolled in the selected courses.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws Exception
     */
    protected static function get_learners(array $courseids): array {
        global $DB;

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $params["uconfirmed"] = 1;
        $params["ususpended"] = 0;

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
                   AND u.confirmed = :uconfirmed
                   AND u.suspended = :ususpended
              ORDER BY u.firstname ASC, u.lastname ASC, u.email ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Returns the selected course ids for each learner.
     *
     * @param array $courseids Selected course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_user_courses(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT DISTINCT CONCAT(ue.userid, '-', e.courseid) AS unik,
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
            $result[$record->userid][$record->courseid] = (int) $record->courseid;
        }

        return $result;
    }

    /**
     * Returns the earliest enrolment time for each learner and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_user_enrolment_times(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT CONCAT(ue.userid, '-', e.courseid) AS unik,
                       ue.userid,
                       e.courseid,
                       MIN(CASE
                               WHEN ue.timecreated > 0 THEN ue.timecreated
                               WHEN ue.timestart > 0 THEN ue.timestart
                               ELSE NULL
                           END) AS enroltime
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
            $result[$record->userid][$record->courseid] = $record->enroltime ? (int) $record->enroltime : 0;
        }

        return $result;
    }

    /**
     * Returns the number of trackable modules in each course.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws Exception
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
            $result[$record->course] = (int) $record->total;
        }

        return $result;
    }

    /**
     * Returns completed module totals by learner and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_completed_module_totals(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT CONCAT(cmc.userid, '-', cm.course) AS unik,
                       cmc.userid,
                       cm.course,
                       COUNT(DISTINCT cmc.coursemoduleid) AS total
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
            $result[$record->userid][$record->course] = (int) $record->total;
        }

        return $result;
    }

    /**
     * Returns course total grade percentages by learner and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
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
                       CASE
                           WHEN gg.finalgrade IS NULL THEN NULL
                           WHEN gi.grademax <= gi.grademin THEN NULL
                           ELSE ((gg.finalgrade - gi.grademin) / (gi.grademax - gi.grademin)) * 100
                       END AS gradepercent
                  FROM {grade_items} gi
                  JOIN {grade_grades} gg
                    ON gg.itemid = gi.id
                 WHERE gi.courseid {$coursesql}
                   AND gg.userid {$usersql}
                   AND gi.itemtype = 'course'
                   AND gi.hidden = 0";

        $records = $DB->get_records_sql($sql, $courseparams + $userparams);
        $result = [];
        foreach ($records as $record) {
            $result[$record->userid][$record->courseid] = is_null($record->gradepercent)
                ? null
                : round((float) $record->gradepercent, 1);
        }

        return $result;
    }

    /**
     * Returns course completions by learner and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_course_completions(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT CONCAT(userid, '-', course, '-', timecompleted) AS unik,
                       userid,
                       course,
                       timecompleted
                  FROM {course_completions}
                 WHERE course {$coursesql}
                   AND userid {$usersql}
                   AND timecompleted > 0";
        $records = $DB->get_records_sql($sql, $courseparams + $userparams);

        $result = [];
        foreach ($records as $record) {
            $result[$record->userid][$record->course] = (int) $record->timecompleted;
        }

        return $result;
    }

    /**
     * Returns the last access timestamps by learner and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_last_access_by_course(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $sql = "SELECT CONCAT(userid, '-', courseid) AS unik,
                       userid,
                       courseid,
                       MAX(timeaccess) AS timeaccess
                  FROM {user_lastaccess}
                 WHERE courseid {$coursesql}
                   AND userid {$usersql}
              GROUP BY userid, courseid";
        $records = $DB->get_records_sql($sql, $courseparams + $userparams);

        $result = [];
        foreach ($records as $record) {
            $result[$record->userid][$record->courseid] = (int) $record->timeaccess;
        }

        return $result;
    }

    /**
     * Returns pathway names for each learner using linked cohort memberships.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    protected static function get_user_pathways(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        $cohortids = self::get_linked_cohort_ids($courseids);
        if (empty($cohortids)) {
            [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");
            $sql = "SELECT DISTINCT cm.cohortid
                      FROM {cohort_members} cm
                     WHERE cm.userid {$usersql}";
            $records = $DB->get_records_sql($sql, $userparams);
            foreach ($records as $record) {
                $cohortids[$record->cohortid] = (int) $record->cohortid;
            }
        }

        if (empty($cohortids)) {
            return [];
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");
        [$cohortsql, $cohortparams] = $DB->get_in_or_equal(array_values($cohortids), SQL_PARAMS_NAMED, "cohort");

        $sql = "SELECT CONCAT(cm.userid, '-', c.id) AS unik,
                       cm.userid,
                       c.id,
                       c.name
                  FROM {cohort_members} cm
                  JOIN {cohort} c
                    ON c.id = cm.cohortid
                 WHERE cm.userid {$usersql}
                   AND c.id {$cohortsql}
              ORDER BY c.name ASC";
        $records = $DB->get_records_sql($sql, $userparams + $cohortparams);

        $result = [];
        foreach ($records as $record) {
            $result[$record->userid][$record->id] = format_string(
                $record->name,
                true,
                ["context" => context_system::instance()]
            );
        }

        return $result;
    }

    /**
     * Returns linked cohort ids from course cohort enrolments.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws Exception
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
            $result[$record->cohortid] = (int) $record->cohortid;
        }

        return $result;
    }

    /**
     * Renders the grouped bar chart for engagement by course.
     *
     * @param object $chart Chart data.
     * @return string
     * @throws Exception
     */
    protected static function render_engagement_chart_svg(object $chart): string {
        $labels = $chart->labels ?? [];
        $series = $chart->series ?? [];
        if (empty($labels) || empty($series)) {
            return "";
        }

        $count = count($labels);
        $width = max(980, 180 * $count);
        $height = 360;
        $paddingleft = 58;
        $paddingright = 18;
        $paddingtop = 18;
        $paddingbottom = 90;
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
            s(get_string("engagementchartarialabel", "gimidashboardreports_atrisk")) . '">';
        $parts[] = '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" rx="18" fill="#ffffff"></rect>';

        for ($step = 0; $step <= 4; $step++) {
            $value = ($maxvalue / 4) * (4 - $step);
            $y = $paddingtop + ($plotheight / 4) * $step;
            $parts[] = '<line x1="' . $paddingleft . '" y1="' . $y . '" x2="' . ($width - $paddingright) .
                '" y2="' . $y . '" stroke="#e2e8f0" stroke-width="1"></line>';
            $parts[] = '<text x="' . ($paddingleft - 8) . '" y="' . ($y + 4) .
                '" text-anchor="end" font-size="11" fill="#64748b">' . format_float($value, 0) . '</text>';
        }

        $groupwidth = $plotwidth / max(1, $count);
        $groupinnerpadding = 24;
        $barspergroup = count($series);
        $barwidth = min(28, max(14, ($groupwidth - $groupinnerpadding) / max(1, $barspergroup)));

        foreach ($labels as $index => $label) {
            $groupx = $paddingleft + ($groupwidth * $index);
            $centerx = $groupx + ($groupwidth / 2);
            $shortlabel = self::shorten_chart_label($label, 22);
            $parts[] = '<g><title>' . s($label) . '</title><text x="' . round($centerx, 2) . '" y="' . ($height - 22) .
                '" text-anchor="middle" font-size="11" fill="#64748b">' . s($shortlabel) . '</text></g>';
        }

        foreach ($series as $seriesindex => $item) {
            foreach ($item["values"] as $index => $value) {
                $groupx = $paddingleft + ($groupwidth * $index);
                $startx = $groupx + (($groupwidth - ($barwidth * $barspergroup)) / 2);
                $x = $startx + ($seriesindex * $barwidth);
                $barheight = ($value / $maxvalue) * $plotheight;
                $y = $paddingtop + $plotheight - $barheight;
                $parts[] = '<g><title>' . s($item["name"] . ': ' . format_float($value, 0) . ' • ' . $labels[$index]) . '</title>' .
                    '<rect x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round($barwidth - 4, 2) .
                    '" height="' . round($barheight, 2) . '" rx="6" fill="' . $item["color"] . '"></rect></g>';
            }
        }

        $parts[] = '</svg>';
        return implode("", $parts);
    }

    /**
     * Shortens chart labels without losing the original tooltip.
     *
     * @param string $label Original label.
     * @param int $maxlength Maximum length.
     * @return string
     */
    protected static function shorten_chart_label(string $label, int $maxlength = 24): string {
        if (core_text::strlen($label) <= $maxlength) {
            return $label;
        }

        return core_text::substr($label, 0, $maxlength - 1) . '…';
    }

    /**
     * Formats the last access label for the engagement table.
     *
     * @param int $timestamp Last access timestamp.
     * @return string
     * @throws coding_exception
     */
    protected static function format_last_access_label(int $timestamp): string {
        if ($timestamp <= 0) {
            return get_string("never", "gimidashboardreports_atrisk");
        }

        return userdate($timestamp, get_string("strftimedatefullshort", "langconfig"));
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
     * Formats a percent value.
     *
     * @param float|null $value Numeric value.
     * @return string
     * @throws coding_exception
     */
    protected static function format_percent(?float $value): string {
        if ($value === null) {
            return get_string("notavailable", "gimidashboardreports_atrisk");
        }

        return format_float($value) . "%";
    }

    /**
     * Formats the activity summary for the learner.
     *
     * @param int $latestaccess Latest access timestamp.
     * @param int|null $daysinactive Days inactive.
     * @param int $dayssinceenrol Days since enrolment.
     * @return string
     * @throws coding_exception
     */
    protected static function format_activity_display(int $latestaccess, ?int $daysinactive, int $dayssinceenrol): string {
        if ($latestaccess <= 0) {
            return get_string("activityneveraccessed", "gimidashboardreports_atrisk", $dayssinceenrol);
        }

        if ($daysinactive !== null && $daysinactive > 0) {
            return get_string(
                "activitylastaccess",
                "gimidashboardreports_atrisk",
                (object) [
                    "days" => $daysinactive,
                    "date" => userdate($latestaccess, get_string("strftimedatefullshort", "langconfig")),
                ]
            );
        }

        return get_string(
            "activityrecentaccess",
            "gimidashboardreports_atrisk",
            userdate($latestaccess, get_string("strftimedatefullshort", "langconfig"))
        );
    }

    /**
     * Builds the drill-down url to the full academy dashboard.
     *
     * @param string $target Current selection target.
     * @param int $userid Learner id.
     * @return string
     * @throws Exception
     */
    protected static function build_learner_url(string $target, int $userid): string {
        $params = [
            "target" => $target,
            "plugin" => "fullacademydashboard",
            "learnerid" => $userid,
        ];

        return (new moodle_url("/local/gimidashboard/", $params))->out(false);
    }
}
