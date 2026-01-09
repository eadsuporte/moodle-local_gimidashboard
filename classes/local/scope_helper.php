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
 * Helpers for resolving scope (course/category) and cohort availability.
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\local;

use Exception;

/**
 * Helpers for resolving scope (course/category) and cohort availability.
 */
class scope_helper {
    /**
     * Resolve course ids for the selection.
     *
     * @param selection $sel
     * @return int[]
     * @throws Exception
     */
    public static function resolve_courseids(selection $sel): array {
        $courseids = [];

        if ($sel->is_course()) {
            return [$sel->courseid];
        }

        if ($sel->is_category()) {
            $cat = \core_course_category::get($sel->categoryid, IGNORE_MISSING, true);
            if ($cat) {
                $courses = $cat->get_courses(['recursive' => true]);
                foreach ($courses as $c) {
                    if ($c->id === 1) {
                        continue;
                    }
                    $courseids[] = $c->id;
                }
            }
        }

        return $courseids;
    }

    /**
     * List cohorts linked to the scope via enrol='cohort' AND that already have members.
     *
     * @param int[] $courseids
     * @return array[] Each item: ['id' => int, 'name' => string]
     * @throws Exception
     */
    public static function get_available_cohorts(array $courseids): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');

        $sql = "
                SELECT DISTINCT ch.id, ch.name
                  FROM {enrol} e
                  JOIN {cohort} ch ON ch.id = e.customint1
                  JOIN {cohort_members} cm ON cm.cohortid = ch.id
                 WHERE e.enrol = 'cohort'
                   AND e.status = 0
                   AND e.customint1 IS NOT NULL
                   AND e.courseid {$insql}
              ORDER BY ch.name ASC";

        $recs = $DB->get_records_sql($sql, $params);

        $out = [];
        foreach ($recs as $r) {
            $out[] = [
                'id' => $r->id,
                'name' => $r->name,
            ];
        }

        return $out;
    }

    /**
     * Return a human readable scope label.
     *
     * @param selection $sel
     * @return string
     * @throws Exception
     */
    public static function get_scope_label(selection $sel): string {
        global $DB;

        if ($sel->is_course()) {
            $c = $DB->get_record('course', ['id' => $sel->courseid], 'fullname', IGNORE_MISSING);
            return $c ? ('Course: ' . $c->fullname) : ('Course #' . $sel->courseid);
        }

        if ($sel->is_category()) {
            $cat = $DB->get_record('course_categories', ['id' => $sel->categoryid], 'name', IGNORE_MISSING);
            return $cat ? ('Category: ' . $cat->name) : ('Category #' . $sel->categoryid);
        }

        return '';
    }
}
