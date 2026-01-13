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
 * cohort_register.php
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_gimidashboard\local\permission;
use local_gimidashboard\local\selection;
use local_gimidashboard\manage\cohort_register_page;
use local_gimidashboard\report\filter_options;

require_login();

$syscontext = context_system::instance();
permission::require_capability();

$courseparam = optional_param('course', false, PARAM_RAW_TRIMMED);

$sel = selection::from_param($courseparam);
if (!$sel->is_allowed()) {
    // Do not allow forced unauthorized selection.
    $sel = selection::from_param('');
    $courseparam = '';
}

$PAGE->set_context($syscontext);
$PAGE->set_url(new moodle_url('/local/gimidashboard/cohort_register.php', ['course' => $courseparam]));
$PAGE->set_title('Cohort enrolment');
$PAGE->set_heading('Cohort enrolment');
$PAGE->add_body_class("gimidashboard");

$PAGE->requires->js_call_amd('local_gimidashboard/dashboard', 'chart');
$PAGE->requires->js_call_amd('local_gimidashboard/dashboard', 'search');

echo $OUTPUT->header();

$templatecontext = cohort_register_page::get_template_context($sel, $courseparam);
$templatecontext["is_course_selected"] = $courseparam;

if (is_siteadmin()) {
    $filtertemplatecontext = filter_options::get_template_context($courseparam);
    $templatecontext["filter"] = $OUTPUT->render_from_template('local_gimidashboard/filter_select', $filtertemplatecontext);
}

echo $OUTPUT->render_from_template('local_gimidashboard/cohort_register', $templatecontext);

echo $OUTPUT->footer();
