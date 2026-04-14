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
 * grade.php
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\report;

/**
 * Class grade
 */
class grade {
    /**
     * Function get_course_grade_percentages
     *
     * @param array $courseids
     * @param array $userids
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function get_course_grade_percentages(array $courseids, array $userids): array {
        global $DB, $CFG;

        require_once("{$CFG->dirroot}/lib/grade/constants.php");

        if (empty($courseids) || empty($userids)) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, "course");
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "user");

        $params = $courseparams + $userparams + [
                "gradetypevalue" => GRADE_TYPE_VALUE,
                "gradetypescale" => GRADE_TYPE_SCALE,
            ];

        $sql = "SELECT gg.userid,
                       gi.courseid,
                       CASE
                           WHEN gg.finalgrade IS NULL THEN NULL
    
                           WHEN gi.gradetype = :gradetypevalue
                                AND gi.grademax > gi.grademin THEN
                                ((gg.finalgrade - gi.grademin) / (gi.grademax - gi.grademin)) * 100
    
                           WHEN gi.gradetype = :gradetypescale
                                AND sc.scale IS NOT NULL
                                AND (CHAR_LENGTH(sc.scale) - CHAR_LENGTH(REPLACE(sc.scale, ',', ''))) > 0 THEN
                                ((gg.finalgrade - 1) /
                                 (CHAR_LENGTH(sc.scale) - CHAR_LENGTH(REPLACE(sc.scale, ',', '')))) * 100
    
                           ELSE NULL
                       END AS gradepercent
                  FROM {grade_items}  gi
                  JOIN {grade_grades} gg ON gg.itemid = gi.id
             LEFT JOIN {scale}        sc ON sc.id = gi.scaleid
                 WHERE gi.courseid {$coursesql}
                   AND gg.userid   {$usersql}
                   AND gi.itemtype = 'course'";

        $records = $DB->get_records_sql($sql, $params);
        $result = [];

        foreach ($records as $record) {
            if ($record->gradepercent === null) {
                $result[$record->userid][$record->courseid] = null;
                continue;
            }

            $gradepercent = (float) $record->gradepercent;
            $gradepercent = max(0.0, min(100.0, $gradepercent));

            $result[$record->userid][$record->courseid] = round($gradepercent, 1);
        }

        return $result;
    }
}
