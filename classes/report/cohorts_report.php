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
 * Builds the cohort tables:
 *  - For each cohort linked to a course (enrol method "cohort"),
 *    list members with one line per course.
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\report;

use Exception;
use local_gimidashboard\local\selection;

/**
 * Builds the cohort tables:
 * - For each cohort linked to a course (enrol method "cohort"),
 *   list members with one line per course.
 */
class cohorts_report {

    /**
     * Build template context.
     *
     * @param selection $selection
     * @param int[] $courseids
     * @return array
     * @throws Exception
     */
    public static function get_template_context(selection $selection, array $courseids): array {
        global $DB;

        if (!$selection->is_allowed() || empty($courseids)) {
            return ['show' => false];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');

        // Cohort-course mapping from enrol='cohort' (customint1 = cohortid).
        $pairs = $DB->get_records_sql(
            "SELECT DISTINCT e.courseid, e.customint1 AS cohortid
               FROM {enrol} e
              WHERE e.enrol = 'cohort'
                AND e.status = 0
                AND e.customint1 IS NOT NULL
                AND e.courseid $insql",
            $params
        );

        if (!$pairs) {
            return [
                'show' => true,
                'cohorts' => [],
                'message' => 'No cohorts found for the selected scope.',
            ];
        }

        $cohorttocourses = [];
        $cohortids = [];
        foreach ($pairs as $p) {
            $cohortid = (int)$p->cohortid;
            $courseid = (int)$p->courseid;
            $cohorttocourses[$cohortid][] = $courseid;
            $cohortids[$cohortid] = $cohortid;
        }
        $cohortids = array_values($cohortids);

        $cohorts = $DB->get_records_list('cohort', 'id', $cohortids, '', 'id,name');

        // Preload courses names.
        $courserecs = $DB->get_records_list('course', 'id', $courseids, '', 'id,fullname');
        $coursenames = [];
        foreach ($courserecs as $c) {
            $coursenames[(int)$c->id] = (string)$c->fullname;
        }

        // Detect mod_coursecertificate tables (best-effort).
        $hascoursecertificate = $DB->get_manager()->table_exists('coursecertificate')
            && ($DB->get_manager()->table_exists('coursecertificate_issues') || $DB->get_manager()->table_exists('coursecertificate_issue'));

        $issuesTable = null;
        if ($hascoursecertificate) {
            $issuesTable = $DB->get_manager()->table_exists('coursecertificate_issues')
                ? 'coursecertificate_issues'
                : 'coursecertificate_issue';
        }

        $outcohorts = [];

        foreach ($cohorts as $cohort) {
            $cohortid = (int)$cohort->id;
            $linkedcourses = $cohorttocourses[$cohortid] ?? [];
            if (!$linkedcourses) {
                continue;
            }

            // Members of cohort.
            $members = $DB->get_records_sql(
                "SELECT u.id, u.username, u.email
                   FROM {cohort_members} cm
                   JOIN {user} u ON u.id = cm.userid
                  WHERE cm.cohortid = :cohortid
                    AND u.deleted = 0
                    AND u.suspended = 0
               ORDER BY u.username ASC",
                ['cohortid' => $cohortid]
            );

            if (!$members) {
                $outcohorts[] = [
                    'name' => (string)$cohort->name,
                    'rows' => [],
                ];
                continue;
            }

            $memberids = array_map(static fn($u) => (int)$u->id, $members);

            // Preload per-course helpers (groups, progress, grades, certificate issues).
            $percourse = [];

            foreach ($linkedcourses as $courseid) {
                $courseid = (int)$courseid;
                $useridsforthiscourse = $memberids;

                // Groups: get all group memberships for these users.
                $groupnames = [];
                if ($useridsforthiscourse) {
                    [$uinsql, $uparams] = $DB->get_in_or_equal($useridsforthiscourse, SQL_PARAMS_NAMED, 'u');
                    $uparams['courseid'] = $courseid;

                    $grouprecs = $DB->get_records_sql(
                        "SELECT gm.userid, g.name
                           FROM {groups_members} gm
                           JOIN {groups} g ON g.id = gm.groupid
                          WHERE g.courseid = :courseid
                            AND gm.userid $uinsql",
                        $uparams
                    );

                    foreach ($grouprecs as $gr) {
                        $uid = (int)$gr->userid;
                        $groupnames[$uid][] = (string)$gr->name;
                    }
                }

                // Progress: total completable modules and completed per user.
                $totalcompletable = (int)$DB->get_field_sql(
                    "SELECT COUNT(1)
                       FROM {course_modules} cm
                      WHERE cm.course = :courseid
                        AND cm.deletioninprogress = 0
                        AND cm.completion > 0",
                    ['courseid' => $courseid]
                );

                $completedcount = [];
                if ($totalcompletable > 0 && $useridsforthiscourse) {
                    [$uinsql, $uparams] = $DB->get_in_or_equal($useridsforthiscourse, SQL_PARAMS_NAMED, 'u');
                    $uparams['courseid'] = $courseid;

                    $done = $DB->get_records_sql(
                        "SELECT cmc.userid, COUNT(1) AS donecount
                           FROM {course_modules_completion} cmc
                           JOIN {course_modules} cm
                             ON cm.id = cmc.coursemoduleid
                            AND cm.course = :courseid
                            AND cm.deletioninprogress = 0
                            AND cm.completion > 0
                          WHERE cmc.userid $uinsql
                            AND cmc.completionstate > 0
                       GROUP BY cmc.userid",
                        $uparams
                    );

                    foreach ($done as $d) {
                        $completedcount[(int)$d->userid] = (int)$d->donecount;
                    }
                }

                // Grades: course total grade item.
                $gradepct = [];
                if ($useridsforthiscourse) {
                    [$uinsql, $uparams] = $DB->get_in_or_equal($useridsforthiscourse, SQL_PARAMS_NAMED, 'u');
                    $uparams['courseid'] = $courseid;

                    $graderecs = $DB->get_records_sql(
                        "SELECT gg.userid, gg.finalgrade, gi.grademax
                           FROM {grade_items} gi
                           JOIN {grade_grades} gg ON gg.itemid = gi.id
                          WHERE gi.courseid = :courseid
                            AND gi.itemtype = 'course'
                            AND gi.grademax > 0
                            AND gg.finalgrade IS NOT NULL
                            AND gg.userid $uinsql",
                        $uparams
                    );

                    foreach ($graderecs as $g) {
                        $uid = (int)$g->userid;
                        $pct = ((float)$g->finalgrade / (float)$g->grademax) * 100.0;
                        $gradepct[$uid] = (int)round($pct);
                    }
                }

                // Certificate issues: best-effort for mod_coursecertificate.
                $certissued = [];
                if ($hascoursecertificate && $issuesTable) {
                    $instances = $DB->get_records('coursecertificate', ['course' => $courseid], '', 'id');
                    $instanceids = array_keys($instances);

                    if ($instanceids && $useridsforthiscourse) {
                        [$iinsql, $iparams] = $DB->get_in_or_equal($instanceids, SQL_PARAMS_NAMED, 'i');
                        [$uinsql, $uparams] = $DB->get_in_or_equal($useridsforthiscourse, SQL_PARAMS_NAMED, 'u');
                        $sqlparams = array_merge($iparams, $uparams);

                        $issuerecs = $DB->get_records_sql(
                            "SELECT userid
                               FROM {{$issuesTable}}
                              WHERE coursecertificateid $iinsql
                                AND userid $uinsql",
                            $sqlparams
                        );

                        foreach ($issuerecs as $ir) {
                            $certissued[(int)$ir->userid] = true;
                        }
                    }
                }

                $percourse[$courseid] = [
                    'groups' => $groupnames,
                    'totalcompletable' => $totalcompletable,
                    'completed' => $completedcount,
                    'gradepct' => $gradepct,
                    'certissued' => $certissued,
                ];
            }

            // Build rows: one line per (user, course).
            $rows = [];
            foreach ($members as $m) {
                $userid = (int)$m->id;

                foreach ($linkedcourses as $courseid) {
                    $courseid = (int)$courseid;
                    $helpers = $percourse[$courseid] ?? null;
                    if (!$helpers) {
                        continue;
                    }

                    $groups = $helpers['groups'][$userid] ?? [];
                    $grouptext = $groups ? implode(', ', $groups) : '-';

                    $progresspct = 0;
                    $total = (int)$helpers['totalcompletable'];
                    if ($total > 0) {
                        $done = (int)($helpers['completed'][$userid] ?? 0);
                        $progresspct = (int)round(($done / $total) * 100.0);
                    }

                    $grade = (int)($helpers['gradepct'][$userid] ?? 0);
                    $issued = !empty($helpers['certissued'][$userid]);

                    $rows[] = [
                        'username' => (string)$m->username,
                        'email' => (string)$m->email,
                        'course' => $coursenames[$courseid] ?? ('Course #' . $courseid),
                        'group' => $grouptext,
                        'progress' => $progresspct . '%',
                        'grade' => (string)$grade,
                        'certificate_yes' => $issued,
                        'certificate_no' => !$issued,
                    ];
                }
            }

            $outcohorts[] = [
                'name' => (string)$cohort->name,
                'rows' => $rows,
            ];
        }

        return [
            'cohorts' => $outcohorts,
        ];
    }
}
