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
 * Builds the cohorts report tables.
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\report;

use Exception;
use local_gimidashboard\selection;

/**
 * Builds the cohorts report tables.
 */
class cohorts_report {

    /**
     * Build template context.
     *
     * @param selection $selection
     * @param int[] $courseids Scope course ids
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
            "
             SELECT CONCAT(e.courseid, e.customint1) AS id, e.courseid, e.customint1 AS cohortid
               FROM {enrol} e
              WHERE e.enrol = 'cohort'
                AND e.status = 0
                AND e.customint1 IS NOT NULL
                AND e.courseid {$insql}
           GROUP BY e.courseid, e.customint1",
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
        $linkedcourseids = [];
        foreach ($pairs as $pair) {
            $cohortid = (int) $pair->cohortid;
            $linkedcourseid = (int) $pair->courseid;
            $cohorttocourses[$cohortid][$linkedcourseid] = $linkedcourseid;
            $cohortids[$cohortid] = $cohortid;
            $linkedcourseids[$linkedcourseid] = $linkedcourseid;
        }

        $cohortids = array_values($cohortids);
        $linkedcourseids = array_values($linkedcourseids);

        $cohorts = $DB->get_records_list('cohort', 'id', $cohortids, '', 'id,name');
        $coursenames = report_helper::get_course_names($linkedcourseids);

        // Load all valid cohort members once.
        $membersbycohort = [];
        if ($cohortids) {
            [$cohortinsql, $cohortparams] = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'coh');
            $sql = "
                 SELECT cm.cohortid, u.id, u.username, u.email
                   FROM {cohort_members} cm
                   JOIN {user} u ON u.id = cm.userid
                  WHERE cm.cohortid {$cohortinsql}
                    AND u.deleted = 0
                    AND u.suspended = 0
               ORDER BY cm.cohortid ASC, u.username ASC, u.email ASC";
            $memberrecordset = $DB->get_recordset_sql($sql, $cohortparams);

            foreach ($memberrecordset as $member) {
                $membersbycohort[(int) $member->cohortid][(int) $member->id] = $member;
            }
            $memberrecordset->close();
        }

        $allmemberids = [];
        foreach ($membersbycohort as $members) {
            foreach ($members as $memberid => $member) {
                $allmemberids[$memberid] = $memberid;
            }
        }
        $allmemberids = array_values($allmemberids);

        $activeusersbycourse = report_helper::get_active_enrolled_users_by_course($linkedcourseids, $allmemberids);
        $activeuserids = [];
        foreach ($activeusersbycourse as $users) {
            foreach ($users as $userid => $user) {
                $activeuserids[$userid] = $userid;
            }
        }
        $activeuserids = array_values($activeuserids);

        $groupnames = [];
        if ($linkedcourseids && $activeuserids) {
            [$courseinsql, $courseparams] = $DB->get_in_or_equal($linkedcourseids, SQL_PARAMS_NAMED, 'gc');
            [$userinsql, $userparams] = $DB->get_in_or_equal($activeuserids, SQL_PARAMS_NAMED, 'gu');
            $sql = "
                 SELECT g.courseid, gm.userid, g.name
                   FROM {groups_members} gm
                   JOIN {groups} g ON g.id = gm.groupid
                  WHERE g.courseid {$courseinsql}
                    AND gm.userid {$userinsql}
               ORDER BY g.courseid ASC, gm.userid ASC, g.name ASC";
            $grouprecordset = $DB->get_recordset_sql($sql, array_merge($courseparams, $userparams));

            foreach ($grouprecordset as $grouprecord) {
                $courseid = (int) $grouprecord->courseid;
                $userid = (int) $grouprecord->userid;
                $groupnames[$courseid][$userid][] = $grouprecord->name;
            }
            $grouprecordset->close();
        }

        $totalcompletable = [];
        if ($linkedcourseids) {
            [$moduleinsql, $moduleparams] = $DB->get_in_or_equal($linkedcourseids, SQL_PARAMS_NAMED, 'mc');
            $sql = "
                 SELECT cm.course, COUNT(1) AS total
                   FROM {course_modules} cm
                  WHERE cm.course {$moduleinsql}
                    AND cm.deletioninprogress = 0
                    AND cm.completion > 0
               GROUP BY cm.course";
            $modulerecords = $DB->get_records_sql($sql, $moduleparams);

            foreach ($modulerecords as $modulerecord) {
                $totalcompletable[(int) $modulerecord->course] = (int) $modulerecord->total;
            }
        }

        $completedbycourseanduser = [];
        if ($linkedcourseids && $activeuserids) {
            [$modulecourseinsql, $modulecourseparams] = $DB->get_in_or_equal($linkedcourseids, SQL_PARAMS_NAMED, 'pc');
            [$moduleuserinsql, $moduleuserparams] = $DB->get_in_or_equal($activeuserids, SQL_PARAMS_NAMED, 'pu');
            $sql = "
                 SELECT cm.course, cmc.userid, COUNT(1) AS donecount
                   FROM {course_modules_completion} cmc
                   JOIN {course_modules} cm
                     ON cm.id = cmc.coursemoduleid
                  WHERE cm.course {$modulecourseinsql}
                    AND cm.deletioninprogress = 0
                    AND cm.completion > 0
                    AND cmc.userid {$moduleuserinsql}
                    AND cmc.completionstate > 0
               GROUP BY cm.course, cmc.userid";
            $progressrecordset = $DB->get_recordset_sql($sql, array_merge($modulecourseparams, $moduleuserparams));

            foreach ($progressrecordset as $progressrecord) {
                $completedbycourseanduser[(int) $progressrecord->course][(int) $progressrecord->userid] =
                    (int) $progressrecord->donecount;
            }
            $progressrecordset->close();
        }

        $gradepct = report_helper::get_active_grade_percentages_by_course_and_user($linkedcourseids, $activeuserids);

        $certissued = [];
        $hascoursecertificate = $DB->get_manager()->table_exists('coursecertificate')
            && ($DB->get_manager()->table_exists('coursecertificate_issues')
                || $DB->get_manager()->table_exists('coursecertificate_issue'));

        if ($hascoursecertificate && $linkedcourseids && $activeuserids) {
            $issuestable = $DB->get_manager()->table_exists('coursecertificate_issues')
                ? 'coursecertificate_issues'
                : 'coursecertificate_issue';

            [$instanceinsql, $instanceparams] = $DB->get_in_or_equal($linkedcourseids, SQL_PARAMS_NAMED, 'ci');
            $sql = "
                 SELECT id, course
                   FROM {coursecertificate}
                  WHERE course {$instanceinsql}";
            $instances = $DB->get_records_sql($sql, $instanceparams);

            if ($instances) {
                $instancetocourse = [];
                $instanceids = [];
                foreach ($instances as $instance) {
                    $instanceid = (int) $instance->id;
                    $instanceids[$instanceid] = $instanceid;
                    $instancetocourse[$instanceid] = (int) $instance->course;
                }

                [$issueinsql, $issueparams] = $DB->get_in_or_equal(array_values($instanceids), SQL_PARAMS_NAMED, 'ii');
                [$issueuserinsql, $issueuserparams] = $DB->get_in_or_equal($activeuserids, SQL_PARAMS_NAMED, 'iu');
                $sql = "
                     SELECT coursecertificateid, userid
                       FROM {{$issuestable}}
                      WHERE coursecertificateid {$issueinsql}
                        AND userid {$issueuserinsql}";
                $issuerecordset = $DB->get_recordset_sql($sql, array_merge($issueparams, $issueuserparams));

                foreach ($issuerecordset as $issuerecord) {
                    $courseid = $instancetocourse[(int) $issuerecord->coursecertificateid] ?? null;
                    $userid = (int) $issuerecord->userid;
                    if ($courseid) {
                        $certissued[$courseid][$userid] = true;
                    }
                }
                $issuerecordset->close();
            }
        }

        $outcohorts = [];
        foreach ($cohortids as $cohortid) {
            if (empty($cohorts[$cohortid])) {
                continue;
            }

            $linkedcourses = array_values($cohorttocourses[$cohortid] ?? []);
            $members = $membersbycohort[$cohortid] ?? [];
            $rows = [];

            if ($linkedcourses && $members) {
                foreach ($linkedcourses as $courseid) {
                    $usersincourse = $activeusersbycourse[$courseid] ?? [];
                    if (!$usersincourse) {
                        continue;
                    }

                    foreach ($usersincourse as $userid => $user) {
                        if (empty($members[$userid])) {
                            continue;
                        }

                        $groups = $groupnames[$courseid][$userid] ?? [];
                        $grouptext = $groups ? implode(', ', $groups) : '-';

                        $progresspct = 0;
                        $total = $totalcompletable[$courseid] ?? 0;
                        if ($total > 0) {
                            $done = $completedbycourseanduser[$courseid][$userid] ?? 0;
                            $progresspct = round(($done / $total) * 100.0);
                        }

                        $rows[] = [
                            'username' => $members[$userid]->username,
                            'email' => $members[$userid]->email,
                            'course' => $coursenames[$courseid] ?? ('Course #' . $courseid),
                            'group' => $grouptext,
                            'progress' => $progresspct . '%',
                            'grade' => $gradepct[$courseid][$userid] ?? 0,
                            'certificate_yes' => !empty($certissued[$courseid][$userid]),
                            'certificate_no' => empty($certissued[$courseid][$userid]),
                        ];
                    }
                }
            }

            $outcohorts[] = [
                'name' => $cohorts[$cohortid]->name,
                'rows' => $rows,
            ];
        }

        return [
            'cohorts' => $outcohorts,
        ];
    }
}
