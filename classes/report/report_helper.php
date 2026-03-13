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
 * Shared report helpers.
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\report;

/**
 * Shared report helpers.
 */
class report_helper {
    /**
     * Returns the SQL conditions used to define an active enrolment.
     *
     * @param string $coursefield Field that stores the course id.
     * @param string $userfield Field that stores the user id.
     * @param array $courseids Course ids.
     * @param string $courseprefix Prefix for course SQL params.
     * @param int[]|null $userids Optional user ids.
     * @param string $userprefix Prefix for user SQL params.
     * @param string $nowprefix Prefix for the time SQL param.
     * @return array{0:string,1:array}
     */
    public static function get_active_enrolment_conditions(
        string $coursefield,
        string $userfield,
        array $courseids,
        string $courseprefix = 'c',
        ?array $userids = null,
        string $userprefix = 'u',
        string $nowprefix = 'nowactive'
    ): array {
        global $DB;

        [$courseinsql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, $courseprefix);

        $params[$nowprefix] = time();

        $sql = " {$coursefield} {$courseinsql}
                  AND e.status = 0
                  AND ue.status = 0
                  AND u.deleted = 0
                  AND u.suspended = 0
                  AND (ue.timestart = 0 OR ue.timestart <= :{$nowprefix})
                  AND (ue.timeend = 0 OR ue.timeend > :{$nowprefix})";

        if ($userids !== null) {
            if (empty($userids)) {
                return ['1 = 0', []];
            }

            [$userinsql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, $userprefix);
            $sql .= " AND {$userfield} {$userinsql}";
            $params = array_merge($params, $userparams);
        }

        return [$sql, $params];
    }

    /**
     * Returns a subquery with distinct active enrolments.
     *
     * @param int[] $courseids Course ids.
     * @param string $courseprefix Prefix for course SQL params.
     * @param int[]|null $userids Optional user ids.
     * @param string $userprefix Prefix for user SQL params.
     * @param string $nowprefix Prefix for the time SQL param.
     * @return array{0:string,1:array}
     */
    public static function get_active_enrolment_subquery(
        array $courseids,
        string $courseprefix = 'c',
        ?array $userids = null,
        string $userprefix = 'u',
        string $nowprefix = 'nowactive'
    ): array {
        [$wheresql, $params] = self::get_active_enrolment_conditions(
            'e.courseid',
            'ue.userid',
            $courseids,
            $courseprefix,
            $userids,
            $userprefix,
            $nowprefix
        );

        $sql = "
            SELECT DISTINCT e.courseid, ue.userid
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {user} u ON u.id = ue.userid
             WHERE {$wheresql}";
        return [$sql, $params];
    }

    /**
     * Returns active enrolled users indexed by course and user.
     *
     * @param int[] $courseids Course ids.
     * @param int[]|null $userids Optional user ids.
     * @return array<int, array<int, object>>
     */
    public static function get_active_enrolled_users_by_course(array $courseids, ?array $userids = null): array {
        global $DB;

        if (empty($courseids) || ($userids !== null && empty($userids))) {
            return [];
        }

        [$wheresql, $params] = self::get_active_enrolment_conditions('e.courseid', 'ue.userid', $courseids, 'ca', $userids, 'ua');

        $sql = "
             SELECT DISTINCT e.courseid, u.id, u.username, u.email
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid
              WHERE {$wheresql}
           ORDER BY e.courseid ASC, u.username ASC, u.email ASC";
        $recordset = $DB->get_recordset_sql($sql, $params);

        $indexed = [];
        foreach ($recordset as $record) {
            $indexed[(int) $record->courseid][(int) $record->id] = $record;
        }
        $recordset->close();

        return $indexed;
    }

    /**
     * Returns enrolled counts by course for active enrolments only.
     *
     * @param int[] $courseids Course ids.
     * @return array<int, int>
     */
    public static function get_active_enrolled_counts_by_course(array $courseids): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$wheresql, $params] = self::get_active_enrolment_conditions('e.courseid', 'ue.userid', $courseids, 'ce');

        $sql = "
             SELECT e.courseid, COUNT(DISTINCT ue.userid) AS total
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid
              WHERE {$wheresql}
           GROUP BY e.courseid";
        $records = $DB->get_records_sql($sql, $params);

        $counts = [];
        foreach ($records as $record) {
            $counts[(int) $record->courseid] = (int) $record->total;
        }

        return $counts;
    }

    /**
     * Returns completion counts by course for active enrolments only.
     *
     * @param int[] $courseids Course ids.
     * @return array<int, int>
     */
    public static function get_active_completion_counts_by_course(array $courseids): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$wheresql, $params] = self::get_active_enrolment_conditions('e.courseid', 'ue.userid', $courseids, 'cc');

        $sql = "
             SELECT e.courseid, COUNT(DISTINCT ue.userid) AS total
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid
               JOIN {course_completions} cc
                 ON cc.course = e.courseid
                AND cc.userid = ue.userid
                AND cc.timecompleted IS NOT NULL
              WHERE {$wheresql}
           GROUP BY e.courseid";
        $records = $DB->get_records_sql($sql, $params);

        $counts = [];
        foreach ($records as $record) {
            $counts[(int) $record->courseid] = (int) $record->total;
        }

        return $counts;
    }

    /**
     * Returns course grade averages for active enrolments only.
     *
     * @param int[] $courseids Course ids.
     * @return array<int, float>
     */
    public static function get_active_grade_average_by_course(array $courseids): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$subquerysql, $subqueryparams] = self::get_active_enrolment_subquery($courseids, 'cg', null, 'ug', 'nowgradeavg');
        [$courseinsql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'gic');
        $params = array_merge($subqueryparams, $courseparams);

        $sql = "
             SELECT gi.courseid, AVG((gg.finalgrade / gi.grademax) * 100) AS averagepct
               FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id
               JOIN {user} u ON u.id = gg.userid
               JOIN ({$subquerysql}) ae
                 ON ae.courseid = gi.courseid
                AND ae.userid = gg.userid
              WHERE gi.courseid {$courseinsql}
                AND gi.itemtype = 'course'
                AND gi.grademax > 0
                AND gg.finalgrade IS NOT NULL
           GROUP BY gi.courseid";
        $records = $DB->get_records_sql($sql, $params);

        $averages = [];
        foreach ($records as $record) {
            $averages[(int) $record->courseid] = round((float) $record->averagepct, 1);
        }

        return $averages;
    }

    /**
     * Returns course grade percentages by user for active enrolments only.
     *
     * @param int[] $courseids Course ids.
     * @param int[] $userids User ids.
     * @return array<int, array<int, int>>
     */
    public static function get_active_grade_percentages_by_course_and_user(array $courseids, array $userids): array {
        global $DB;

        if (empty($courseids) || empty($userids)) {
            return [];
        }

        [$subquerysql, $subqueryparams] = self::get_active_enrolment_subquery($courseids, 'cp', $userids, 'up', 'nowgradeuser');
        [$courseinsql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'gupc');
        $params = array_merge($subqueryparams, $courseparams);

        $sql = "
             SELECT gi.courseid, gg.userid, MAX((gg.finalgrade / gi.grademax) * 100) AS gradepct
               FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id
               JOIN {user} u ON u.id = gg.userid
               JOIN ({$subquerysql}) ae
                 ON ae.courseid = gi.courseid
                AND ae.userid = gg.userid
              WHERE gi.courseid {$courseinsql}
                AND gi.itemtype = 'course'
                AND gi.grademax > 0
                AND gg.finalgrade IS NOT NULL
           GROUP BY gi.courseid, gg.userid";
        $recordset = $DB->get_recordset_sql($sql, $params);

        $grades = [];
        foreach ($recordset as $record) {
            $grades[(int) $record->courseid][(int) $record->userid] = round((float) $record->gradepct);
        }
        $recordset->close();

        return $grades;
    }

    /**
     * Returns all course names for the provided ids.
     *
     * @param int[] $courseids Course ids.
     * @return array<int, string>
     */
    public static function get_course_names(array $courseids): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        $records = $DB->get_records_list('course', 'id', $courseids, '', 'id,fullname');
        $names = [];
        foreach ($records as $record) {
            $names[(int) $record->id] = $record->fullname;
        }

        return $names;
    }
}
