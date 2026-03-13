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
 * Plugin version and other meta-data are defined here.
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . "/../../config.php");

use local_gimidashboard\permission;
use local_gimidashboard\selection;
use local_gimidashboard\report\filter_options;
use local_gimidashboard\report\course_completion_status;
use local_gimidashboard\report\stats_cards;
use local_gimidashboard\report\cohorts_report;

require_login();
permission::require_capability();

$courseparam = optional_param("course", "", PARAM_RAW_TRIMMED);
$sel = selection::from_param($courseparam);

// If user tries to force an unauthorized selection, ignore it.
if (!$sel->is_allowed()) {
    $sel = selection::from_param("");
    $courseparam = "";
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url("/local/gimidashboard/index.php", ["course" => $courseparam]));
$PAGE->set_title("Academy Dashboard");
$PAGE->set_heading("Academy Dashboard");
$PAGE->add_body_class("gimidashboard");

$PAGE->requires->js_call_amd("local_gimidashboard/dashboard", "chart");
$PAGE->requires->js_call_amd("local_gimidashboard/dashboard", "search");
$PAGE->requires->js_call_amd("local_gimidashboard/dashboard", "table");

echo $OUTPUT->header();

// echo $OUTPUT->render_from_template('local_gimidashboard/index', []);

// Resolve course scope: category -> all courses in subtree; course -> itself.
$courseids = [];
if ($sel->is_course()) {
    $courseids = [$sel->courseid];
} else if ($sel->is_category()) {
    // Load only course ids for the selected category tree.
    $cat = core_course_category::get($sel->categoryid, IGNORE_MISSING, true);
    if ($cat) {
        $sql = "
             SELECT c.id
               FROM {course} c
               JOIN {course_categories} cc ON cc.id = c.category
              WHERE c.id <> 1
                AND (cc.id = :categoryid OR " . $DB->sql_like("cc.path", ":categorypath") . ")
           ORDER BY c.fullname ASC";
        $params = [
            "categoryid" => $cat->id,
            "categorypath" => $cat->path . "/%",
        ];
        $courseids = $DB->get_fieldset_sql($sql, $params);
    }
}

$templatecontext = filter_options::get_template_context($courseparam);
echo $OUTPUT->render_from_template("local_gimidashboard/filter_select", $templatecontext);

if (!$sel->is_course() && !$sel->is_category()) {
    echo "<div class=\"gimidashboard-empty\">Select a category or course to start.</div>";
} else {
    if ($sel->is_category()) {
        $templatecontext = course_completion_status::get_template_context($sel, $courseids);
        echo $OUTPUT->render_from_template("local_gimidashboard/course_completion_status", $templatecontext);
    }

    $templatecontext = stats_cards::get_template_context($sel, $courseids);
    echo $OUTPUT->render_from_template("local_gimidashboard/stats_cards", $templatecontext);

    $templatecontext = cohorts_report::get_template_context($sel, $courseids);
    echo $OUTPUT->render_from_template("local_gimidashboard/cohorts_tables", $templatecontext);
}

echo $OUTPUT->footer();
