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
 * Builds the "Course completion status" chart data.
 * Category selection: bars per course = enrolled; lines = completion% and grade average%.
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\report;

use Exception;
use local_gimidashboard\local\selection;

/**
 * Builds the "Course completion status" chart data.
 * Category selection: bars per course = enrolled; lines = completion% and grade average%.
 */
class course_completion_status {
    /**
     * Build template context for the chart.
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

        $labels = [];
        $enrolled = [];
        $completionpct = [];
        $gradeavg = [];

        foreach ($courseids as $courseid) {
            $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname', IGNORE_MISSING);
            if (!$course) {
                continue;
            }

            $labels[] = (string)$course->fullname;

            // Enrolled users (distinct).
            $enrolledcount = (int)$DB->get_field_sql(
                "SELECT COUNT(DISTINCT ue.userid)
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                   JOIN {user} u ON u.id = ue.userid
                  WHERE e.courseid = :courseid
                    AND e.status = 0
                    AND ue.status = 0
                    AND u.deleted = 0
                    AND u.suspended = 0",
                ['courseid' => $courseid]
            );
            $enrolled[] = $enrolledcount;

            // Completion % (course_completions).
            $completedcount = (int)$DB->get_field_sql(
                "SELECT COUNT(DISTINCT cc.userid)
                   FROM {course_completions} cc
                   JOIN {user} u ON u.id = cc.userid
                  WHERE cc.course = :courseid
                    AND cc.timecompleted IS NOT NULL
                    AND u.deleted = 0
                    AND u.suspended = 0",
                ['courseid' => $courseid]
            );
            $pct = 0.0;
            if ($enrolledcount > 0) {
                $pct = ($completedcount / $enrolledcount) * 100.0;
            }
            $completionpct[] = round($pct, 1);

            // Grade average % (course grade item).
            $avg = $DB->get_field_sql(
                "SELECT AVG((gg.finalgrade / gi.grademax) * 100)
                   FROM {grade_items} gi
                   JOIN {grade_grades} gg ON gg.itemid = gi.id
                   JOIN {user} u ON u.id = gg.userid
                  WHERE gi.courseid = :courseid
                    AND gi.itemtype = 'course'
                    AND gg.finalgrade IS NOT NULL
                    AND gi.grademax > 0
                    AND u.deleted = 0
                    AND u.suspended = 0",
                ['courseid' => $courseid]
            );
            $gradeavg[] = $avg === null ? 0 : round((float)$avg, 1);
        }

        if (!$labels) {
            return ['show' => false];
        }

        $chartid = 'gimidashboard_course_completion_status';

        $payload = [
            'labels' => $labels,
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => 'Learners enrolled',
                    'data' => $enrolled,
                    'yAxisID' => 'y',
                ],
                [
                    'type' => 'line',
                    'label' => 'Course completion',
                    'data' => $completionpct,
                    'yAxisID' => 'y1',
                    'tension' => 0.3,
                ],
                [
                    'type' => 'line',
                    'label' => 'Grade average',
                    'data' => $gradeavg,
                    'yAxisID' => 'y1',
                    'tension' => 0.3,
                ],
            ],
            'options' => [
                'responsive' => true,
                'plugins' => [
                    'legend' => ['display' => true],
                    'title' => ['display' => false],
                ],
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'title' => ['display' => true, 'text' => 'Learners'],
                    ],
                    'y1' => [
                        'beginAtZero' => true,
                        'position' => 'right',
                        'min' => 0,
                        'max' => 100,
                        'grid' => ['drawOnChartArea' => false],
                        'title' => ['display' => true, 'text' => '%'],
                    ],
                ],
            ],
        ];

        return [
            'show' => true,
            'title' => 'Course completion status',
            'chartid' => $chartid,
            'chartjson' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
}
