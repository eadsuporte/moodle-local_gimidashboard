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
use local_gimidashboard\selection;

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
        if (!$selection->is_allowed() || empty($courseids)) {
            return ['show' => false];
        }

        $coursenames = report_helper::get_course_names($courseids);
        $enrolledcounts = report_helper::get_active_enrolled_counts_by_course($courseids);
        $completedcounts = report_helper::get_active_completion_counts_by_course($courseids);
        $gradeaverages = report_helper::get_active_grade_average_by_course($courseids);

        $labels = [];
        $enrolled = [];
        $completionpct = [];
        $gradeavg = [];

        foreach ($courseids as $courseid) {
            if (empty($coursenames[$courseid])) {
                continue;
            }

            $enrolledcount = $enrolledcounts[$courseid] ?? 0;
            if ($enrolledcount === 0) {
                continue;
            }

            $completedcount = $completedcounts[$courseid] ?? 0;
            $labels[] = $coursenames[$courseid];
            $enrolled[] = $enrolledcount;
            $completionpct[] = round(($completedcount / $enrolledcount) * 100.0, 1);
            $gradeavg[] = $gradeaverages[$courseid] ?? 0;
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
            'shortenxlabels' => true,
            'xlabellimit' => 15,
        ];

        return [
            'show' => true,
            'title' => 'Course completion status',
            'chartid' => $chartid,
            'chartjson' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
}
