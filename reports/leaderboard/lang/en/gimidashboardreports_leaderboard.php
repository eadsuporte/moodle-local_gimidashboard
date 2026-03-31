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
 * Language strings for the leaderboard report.
 *
 * @package   gimidashboardreports_leaderboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Leaderboards';
$string['pathwayleaderboard'] = 'Pathway leaderboard';
$string['courseleaderboard'] = 'Course leaderboard';
$string['bestgrade'] = 'Best Grade';
$string['bestgradedescpathway'] = 'Average graded score across pathway courses where a score exists and is greater than zero.';
$string['bestgradedesccourse'] = 'Course score for the selected course only.';
$string['mostprogress'] = 'Most Progress';
$string['mostprogressdescpathway'] = 'Average completion percentage across all selected pathway courses, including courses not yet started.';
$string['mostprogressdesccourse'] = 'Completion percentage for the selected course only.';
$string['fastesttofinish'] = 'Fastest to Finish';
$string['fastesttofinishdesc'] = 'Days from enrolment to certificate issue. Fewer days ranks higher.';
$string['scope'] = 'Scope';
$string['pathway'] = 'Pathway';
$string['course'] = 'Course';
$string['learners'] = 'Learners';
$string['courses'] = 'Courses';
$string['snapshot'] = 'Snapshot';
$string['rank'] = 'Rank';
$string['learner'] = 'Learner';
$string['value'] = 'Value';
$string['details'] = 'Details';
$string['gradedcourses'] = '{$a} graded course(s)';
$string['pathwaycourses'] = '{$a} pathway course(s)';
$string['courseprogressdetails'] = '{$a}% complete';
$string['certissuedon'] = 'Issued on {$a}';
$string['notrankedassessment'] = 'Listed without rank until at least one assessment is graded.';
$string['notrankedcertificate'] = 'Listed without rank until a certificate has been issued.';
$string['nopathways'] = 'No pathway-linked cohort enrolments were found for the current selection.';
$string['choosepathway'] = 'Choose one pathway to lock the leaderboard scope before ranking learners.';
$string['switchpathway'] = 'Switch pathway';
$string['emptyboard'] = 'No learners matched this ranking yet.';
$string['emptyselection'] = 'No courses are available for the current selection.';
$string['scopenotice'] = 'Learners are compared only inside the selected pathway.';
$string['pathwaycount'] = '{$a} pathway(s) detected';
$string['autopathway'] = 'Pathway auto-selected because only one linked pathway was found.';
$string['rankscopepathway'] = 'Ranking inside one pathway only';
$string['rankscopecourse'] = 'Ranking inside one course and one pathway only';
$string['days'] = '{$a} day(s)';
$string['resetpathway'] = 'Clear pathway filter';
$string['nolearners'] = 'No active learners were found in this pathway for the current selection.';

$string['fastesttofinishtop5'] = 'Leaderboard - Fastest to Finish - TOP 5';
$string['fastesttofinishtop5desc'] = 'Top 5 learners with the shortest time between first course access and certificate issue date. Fewer days rank higher.';
$string['fastestpathwaydetails'] = '{$a->course} • Issued on {$a->date}';
$string['notrankedcertificateaccess'] = 'Listed without rank until a first course access and certificate issue date are both available.';