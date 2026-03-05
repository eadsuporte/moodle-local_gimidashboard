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
use local_gimidashboard\local\selection;

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
            return ['show' => false];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');

        $combo = $DB->sql_concat('ue.userid', "'-'", 'e.courseid');

        // Total distinct learners (unique users across all courses).
        $learners = $DB->get_field_sql("
             SELECT COUNT(DISTINCT ue.userid)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid
              WHERE e.courseid {$insql}
                AND e.status = 0
                AND ue.status = 0
                AND u.deleted = 0
                AND u.suspended = 0",
            $params
        );

        // Total enrolments (unique user-course pairs).
        $totalpairs = $DB->get_field_sql("
             SELECT COUNT(DISTINCT {$combo})
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid
              WHERE e.courseid {$insql}
                AND e.status = 0
                AND ue.status = 0
                AND u.deleted = 0
                AND u.suspended = 0",
            $params
        );

        // Completed pairs.
        $completedpairs = $DB->get_field_sql("
             SELECT COUNT(DISTINCT {$combo})
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid
               JOIN {course_completions} cc
                 ON cc.course = e.courseid
                AND cc.userid = ue.userid
                AND cc.timecompleted IS NOT NULL
              WHERE e.courseid {$insql}
                AND e.status = 0
                AND ue.status = 0
                AND u.deleted = 0
                AND u.suspended = 0",
            $params
        );

        $completionpct = 0;
        if ($totalpairs > 0) {
            $completionpct = round(($completedpairs / $totalpairs) * 100.0);
        }

        // Never accessed pairs (user_lastaccess missing/0).
        $neveraccessed = $DB->get_field_sql("
             SELECT COUNT(DISTINCT $combo)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid
          LEFT JOIN {user_lastaccess} ula
                 ON ula.courseid = e.courseid
                AND ula.userid = ue.userid
              WHERE e.courseid {$insql}
                AND e.status = 0
                AND ue.status = 0
                AND u.deleted = 0
                AND u.suspended = 0
                AND (ula.timeaccess IS NULL OR ula.timeaccess = 0)",
            $params
        );

        // Grade average % for enrolled users (course total grade item).
        $gradeavg = $DB->get_field_sql("
             SELECT AVG((gg.finalgrade / gi.grademax) * 100)
               FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id
               JOIN {user} u ON u.id = gg.userid
              WHERE gi.courseid {$insql}
                AND gi.itemtype = 'course'
                AND gg.finalgrade IS NOT NULL
                AND gi.grademax > 0
                AND u.deleted = 0
                AND u.suspended = 0",
            $params
        );
        $gradeavg = $gradeavg === null ? 0 : round($gradeavg);

        // Feedback responses (distinct users who completed any feedback in these courses).
        $feedbackusers = 0;
        if ($DB->get_manager()->table_exists('feedback') && $DB->get_manager()->table_exists('feedback_completed')) {
            $feedbackusers = $DB->get_field_sql("
                 SELECT COUNT(DISTINCT fc.userid)
                   FROM {feedback_completed} fc
                   JOIN {feedback} f ON f.id = fc.feedback
                  WHERE f.course {$insql}",
                $params
            );
        }

        return [
            'cards' => [
                [
                    'value' => $learners,
                    'label' => 'Learners Enrolled',
                ],
                [
                    'value' => $completionpct . '%',
                    'label' => 'Completion',
                ],
                [
                    'value' => $neveraccessed,
                    'label' => 'Never Accessed',
                ],
                [
                    'value' => $gradeavg,
                    'label' => 'Grade Average',
                ],
                [
                    'value' => $feedbackusers,
                    'label' => 'User Feedback',
                ],
            ],
        ];
    }
}
