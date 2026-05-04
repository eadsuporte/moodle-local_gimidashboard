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
 * Base class for dashboard reports.
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\report;

use context_system;
use Exception;
use xmldb_field;
use xmldb_table;

/**
 * Base class with shared helpers for report subplugins.
 *
 * @package   local_gimidashboard
 */
 class base_report  {
    /**
     * Extracts unique course ids from course records.
     *
     * @param array $courses Accessible course records.
     * @return array
     */
    public static function extract_course_ids(array $courses): array {
        $courseids = [];
        foreach ($courses as $course) {
            if (!isset($course->id)) {
                continue;
            }

            $courseids[(int) $course->id] = (int) $course->id;
        }

        return array_values($courseids);
    }

    /**
     * Returns the enrolled courses for every selected learner.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
     public static function get_user_courses(array $courseids, array $userids): array {
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
            $result[(int) $record->userid][(int) $record->courseid] = (int) $record->courseid;
        }

        return $result;
    }

    /**
     * Returns the earliest enrolment time for every selected learner and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
     public static function get_user_enrolment_times(array $courseids, array $userids): array {
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
            $result[(int) $record->userid][(int) $record->courseid] = $record->enroltime ? (int) $record->enroltime : 0;
        }

        return $result;
    }

    /**
     * Returns the number of trackable modules for each course.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws Exception
     */
     public static function get_trackable_module_totals(array $courseids): array {
        global $DB;

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        $sql = "SELECT cm.course, COUNT(cm.id) AS total
                  FROM {course_modules} cm
                 WHERE cm.course {$coursesql}
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
     * @throws Exception
     */
     public static function get_completed_module_totals(array $courseids, array $userids): array {
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
            $result[(int) $record->userid][(int) $record->course] = (int) $record->total;
        }

        return $result;
    }

    /**
     * Returns the first course access timestamps by user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    public static function get_first_course_access_times(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $params = $courseparams + $userparams + [
            "eventname" => "\core\event\course_viewed",
        ];

        $sql = "SELECT CONCAT(userid, '-', courseid) AS unik,
                       userid,
                       courseid,
                       MIN(timecreated) AS firstaccess
                  FROM {logstore_standard_log}
                 WHERE courseid {$coursesql}
                   AND userid {$usersql}
                   AND eventname = :eventname
                   AND timecreated > 0
              GROUP BY userid, courseid";

        $records = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($records as $record) {
            $result[(int) $record->userid][(int) $record->courseid] = (int) $record->firstaccess;
        }

        return $result;
    }

    /**
     * Returns course completions by user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    public static function get_course_completions(array $courseids, array $userids): array {
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
            $result[(int) $record->userid][(int) $record->course] = (int) $record->timecompleted;
        }

        return $result;
    }

     /**
      * Returns finish timestamps by user and course.
      *
      * For now, finish means Moodle course completion.
      * This keeps leaderboards working even before certificates are issued.
      *
      * @param array $courseids Course ids.
      * @param array $userids User ids.
      * @return array
      * @throws Exception
      */
     public static function get_course_finish_times(array $courseids, array $userids): array {
         return self::get_course_completions($courseids, $userids);
     }

    /**
     * Returns the last access for each user and course.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    public static function get_last_access_by_course(array $courseids, array $userids): array {
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
            $result[(int) $record->userid][(int) $record->courseid] = (int) $record->timeaccess;
        }

        return $result;
    }

    /**
     * Returns linked cohort ids for the selected courses.
     *
     * @param array $courseids Course ids.
     * @return array
     * @throws Exception
     */
    public static function get_linked_cohort_ids(array $courseids): array {
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
     * Returns user pathways using cohort memberships.
     *
     * @param array $courseids Course ids.
     * @param array $userids User ids.
     * @return array
     * @throws Exception
     */
    public static function get_user_pathways(array $courseids, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        $cohortids = base_report::get_linked_cohort_ids($courseids);
        if (empty($cohortids)) {
            [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");
            $sql = "SELECT DISTINCT cm.cohortid
                      FROM {cohort_members} cm
                     WHERE cm.userid {$usersql}";
            $records = $DB->get_records_sql($sql, $userparams);
            foreach ($records as $record) {
                $cohortids[(int) $record->cohortid] = (int) $record->cohortid;
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
            $result[(int) $record->userid][(int) $record->id] = format_string(
                $record->name,
                true,
                ["context" => context_system::instance()]
            );
        }

        return $result;
    }

    /**
     * Calculates the progress percentage for a course.
     *
     * Trackable activities are the authoritative source because this is the same basis used by Moodle course cards.
     * The course completion record is only used as a fallback when the course has no trackable activities.
     *
     * @param int $trackable Trackable module count.
     * @param int $completed Completed module count.
     * @param bool $coursecompleted Course completion flag.
     * @return float
     */
    public static function calculate_course_progress(int $trackable, int $completed, bool $coursecompleted): float {
        if ($trackable > 0) {
            return min(100.0, round(($completed / $trackable) * 100, 1));
        }

        if ($coursecompleted) {
            return 100.0;
        }

        return 0.0;
    }

    /**
     * Returns course progress values for one learner.
     *
     * Every enrolled course is included, even when the progress is zero. This keeps averages consistent across reports.
     *
     * @param array $courseids Course ids for the learner.
     * @param array $moduletotals Trackable module totals keyed by course id.
     * @param array $completedmodules Completed module totals keyed by course id.
     * @param array $completions Course completion timestamps keyed by course id.
     * @return array Progress values keyed by course id.
     */
    public static function get_course_progresses(
        array $courseids,
        array $moduletotals,
        array $completedmodules,
        array $completions
    ): array {
        $progresses = [];

        foreach ($courseids as $courseid) {
            $courseid = (int) $courseid;
            $progresses[$courseid] = self::calculate_course_progress(
                (int) ($moduletotals[$courseid] ?? 0),
                (int) ($completedmodules[$courseid] ?? 0),
                !empty($completions[$courseid])
            );
        }

        return $progresses;
    }

    /**
     * Calculates the average progress for one learner across a selected course set.
     *
     * @param array $courseids Course ids for the learner.
     * @param array $moduletotals Trackable module totals keyed by course id.
     * @param array $completedmodules Completed module totals keyed by course id.
     * @param array $completions Course completion timestamps keyed by course id.
     * @return float
     */
    public static function calculate_average_course_progress(
        array $courseids,
        array $moduletotals,
        array $completedmodules,
        array $completions
    ): float {
        $progresses = self::get_course_progresses($courseids, $moduletotals, $completedmodules, $completions);

        if (empty($progresses)) {
            return 0.0;
        }

        return round(array_sum($progresses) / count($progresses), 1);
    }

     /**
      * Returns quiz grade metrics using Moodle gradebook final grades.
      *
      * This avoids recalculating quiz scores from quiz_attempts.sumgrades / quiz.sumgrades,
      * because quiz structure changes can make old attempts inconsistent with the current quiz total.
      *
      * @param array $courseids Course ids.
      * @param array $userids User ids.
      * @param bool $examsonly Whether only exam-like quizzes should be included.
      * @return array
      * @throws Exception
      */
     public static function get_quiz_grade_metrics(array $courseids, array $userids, bool $examsonly = false): array {
         global $DB, $CFG;

         require_once("{$CFG->dirroot}/lib/grade/constants.php");

         if (empty($courseids) || empty($userids)) {
             return [];
         }

         [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "courseg");
         [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "userg");

         $params = $courseparams + $userparams + [
                 "itemtype" => "mod",
                 "itemmodule" => "quiz",
             ];

         $wheres = [
             "q.course {$coursesql}",
             "gg.userid {$usersql}",
             "gi.itemtype = :itemtype",
             "gi.itemmodule = :itemmodule",
             "gi.iteminstance = q.id",
             "gi.courseid = q.course",
             "gg.finalgrade IS NOT NULL",
             "gi.grademax > gi.grademin",
         ];

         if ($examsonly) {
             $wheres[] = self::get_exam_name_sql("COALESCE(q.name, '')");
             $params += self::get_exam_name_like_params();
         }

         $sql = "SELECT CONCAT(gg.userid, '-', q.course) AS unik,
                   gg.userid,
                   q.course AS courseid,
                   SUM(((gg.finalgrade - gi.grademin) / (gi.grademax - gi.grademin)) * 100) AS scoretotal,
                   COUNT(gg.id) AS scorecount
              FROM {quiz} q
              JOIN {grade_items} gi
                ON gi.iteminstance = q.id
              JOIN {grade_grades} gg
                ON gg.itemid = gi.id
             WHERE " . implode(" AND ", $wheres) . "
          GROUP BY gg.userid, q.course";

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
      * Calculates the average quiz grade from metrics returned by get_quiz_grade_metrics().
      *
      * @param array $courseids Course ids to include.
      * @param array $grademetrics Grade metrics keyed by course id for one learner.
      * @return float|null
      */
     public static function calculate_average_quiz_grade(array $courseids, array $grademetrics): ?float {
         $scoretotal = 0.0;
         $scorecount = 0;

         foreach ($courseids as $courseid) {
             $courseid = (int) $courseid;
             $coursemetrics = $grademetrics[$courseid] ?? null;

             if ($coursemetrics === null || (int) $coursemetrics->scorecount <= 0) {
                 continue;
             }

             $scoretotal += (float) $coursemetrics->scoretotal;
             $scorecount += (int) $coursemetrics->scorecount;
         }

         return $scorecount > 0 ? round($scoretotal / $scorecount, 1) : null;
     }

     /**
      * Returns the SQL clause used to detect exam activities.
      *
      * @param string $primaryexpr Primary SQL expression.
      * @param string|null $secondaryexpr Optional secondary SQL expression.
      * @return string
      */
     public static function get_exam_name_sql(string $primaryexpr, ?string $secondaryexpr = null): string {
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

         return "(" . implode(" OR ", $parts) . ")";
     }

     /**
      * Returns the LIKE params used to detect exam activities.
      *
      * @param int $num Number of SQL expressions.
      * @return array
      */
     public static function get_exam_name_like_params(int $num = 1): array {
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
}
