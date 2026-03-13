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
 * Builds the 5 stats cards.
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\report;

use Exception;
use local_gimidashboard\selection;

/**
 * Builds the 5 stats cards.
 */
class stats_cards {
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
            return ["show" => false];
        }

        [$activewheresql, $activeparams] = report_helper::get_active_enrolment_conditions(
            "e.courseid", "ue.userid", $courseids, "cstats", null, "ustats", "nowstats"
        );
        $activesubquery = "
           SELECT DISTINCT e.courseid, ue.userid
             FROM {user_enrolments} ue
             JOIN {enrol} e ON e.id = ue.enrolid
             JOIN {user} u ON u.id = ue.userid
            WHERE {$activewheresql}";

        $combo = $DB->sql_concat("ae.userid", "'-'", "ae.courseid");

        // Total distinct learners across the selected scope with active enrolments only.
        $sql = "SELECT COUNT(DISTINCT ae.userid) FROM ({$activesubquery}) ae";
        $learners = $DB->get_field_sql($sql, $activeparams);

        // Total enrolments (unique user-course pairs) with active enrolments only.
        $sql = "SELECT COUNT(DISTINCT {$combo}) FROM ({$activesubquery}) ae";
        $totalpairs = $DB->get_field_sql($sql, $activeparams);

        // Completed pairs restricted to active enrolments only.
        $sql = "
             SELECT COUNT(DISTINCT {$combo})
               FROM ({$activesubquery}) ae
               JOIN {course_completions} cc
                 ON cc.course = ae.courseid
                AND cc.userid = ae.userid
                AND cc.timecompleted IS NOT NULL";
        $completedpairs = $DB->get_field_sql($sql, $activeparams);

        $completionpct = 0;
        if ($totalpairs > 0) {
            $completionpct = round(($completedpairs / $totalpairs) * 100.0);
        }

        // Never accessed pairs restricted to active enrolments only.
        $sql = "
             SELECT COUNT(DISTINCT {$combo})
               FROM ({$activesubquery}) ae
          LEFT JOIN {user_lastaccess} ula
                 ON ula.courseid = ae.courseid
                AND ula.userid = ae.userid
              WHERE ula.timeaccess IS NULL OR ula.timeaccess = 0";
        $neveraccessed = $DB->get_field_sql($sql, $activeparams);

        // Grade average restricted to active enrolments only.
        $sql = "
             SELECT AVG((gg.finalgrade / gi.grademax) * 100)
               FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id
               JOIN ({$activesubquery}) ae
                 ON ae.courseid = gi.courseid
                AND ae.userid = gg.userid
              WHERE gi.itemtype = 'course'
                AND gi.grademax > 0
                AND gg.finalgrade IS NOT NULL";
        $gradeavg = $DB->get_field_sql($sql, $activeparams);
        $gradeavg = $gradeavg === null ? 0 : round($gradeavg);

        // Feedback responses restricted to active enrolments only.
        $feedbackusers = 0;
        if ($DB->get_manager()->table_exists("feedback") && $DB->get_manager()->table_exists("feedback_completed")) {
            $sql = "
                 SELECT COUNT(DISTINCT fc.userid)
                   FROM {feedback_completed} fc
                   JOIN {feedback} f ON f.id = fc.feedback
                   JOIN ({$activesubquery}) ae
                     ON ae.courseid = f.course
                    AND ae.userid = fc.userid";
            $feedbackusers = $DB->get_field_sql($sql, $activeparams);
        }

        return [
            "cards" => [
                [
                    "value" => $learners,
                    "label" => "Learners Enrolled",
                ],
                [
                    "value" => "{$completionpct}%",
                    "label" => "Completion",
                ],
                [
                    "value" => $neveraccessed,
                    "label" => "Never Accessed",
                ],
                [
                    "value" => $gradeavg,
                    "label" => "Grade Average",
                ],
                [
                    "value" => $feedbackusers,
                    "label" => "User Feedback",
                ],
            ],
        ];
    }
}
