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
 * Language strings for the at-risk learners report.
 *
 * @package   gimidashboardreports_atrisk
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'At-risk learners';
$string['selectionlabel'] = 'Selection: {$a}';
$string['snapshotlabel'] = 'Snapshot: {$a}';
$string['flaggedlabel'] = 'Flagged learners: {$a}';
$string['heuristicnote'] = 'Heuristic model based on inactivity, no access, low progress, low grades and missing completions.';

$string['totallearners'] = 'Total learners';
$string['highriskcount'] = 'High risk';
$string['mediumriskcount'] = 'Medium risk';
$string['neveraccessedcount'] = 'Never accessed';
$string['inactive30count'] = '30+ days inactive';

$string['learner'] = 'Learner';
$string['pathways'] = 'Pathways';
$string['risk'] = 'Risk';
$string['progress'] = 'Progress';
$string['grade'] = 'Grade';
$string['completedcourses'] = 'Completed';
$string['activity'] = 'Activity';
$string['reasons'] = 'Reasons';
$string['actions'] = 'Actions';
$string['scorelabel'] = 'Score {$a}';
$string['coursescompletedfraction'] = '{$a->done}/{$a->total} courses';
$string['viewdetails'] = 'View learner';

$string['highrisk'] = 'High';
$string['mediumrisk'] = 'Medium';
$string['lowrisk'] = 'Low';

$string['never'] = 'Never';
$string['notavailable'] = '-';
$string['nopathways'] = 'No pathway';
$string['nomatchinglearners'] = 'No learners are currently flagged as medium or high risk for this selection.';

$string['allcoursesneveraccessed'] = 'Never accessed any selected course';
$string['neveraccessedcourses'] = 'Never accessed {$a} selected course(s)';
$string['inactive60days'] = 'Inactive for 60+ days';
$string['inactive30days'] = 'Inactive for 30+ days';
$string['inactive15days'] = 'Inactive for 15+ days';
$string['noprogressafterdays'] = 'No measurable progress after {$a} days';
$string['progressbelow10'] = 'Average progress below 10%';
$string['progressbelow25'] = 'Average progress below 25%';
$string['progressbelow50'] = 'Average progress below 50%';
$string['gradebelow40'] = 'Average grade below 40%';
$string['gradebelow60'] = 'Average grade below 60%';
$string['nocompletionsyet'] = 'No completed courses yet';

$string['activityneveraccessed'] = 'Never accessed • enrolled {$a} day(s) ago';
$string['activitylastaccess'] = '{$a->days} day(s) inactive • last access {$a->date}';
$string['activityrecentaccess'] = 'Last access {$a}';
