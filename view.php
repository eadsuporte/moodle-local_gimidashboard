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
 * view.php
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . "/../../config.php");
require_once($CFG->libdir . "/adminlib.php");

use local_gimidashboard\page\selection_resolver;
use local_gimidashboard\report\report_manager;

require_login();

$target = optional_param("target", "", PARAM_TEXT);

$dashboardpage = selection_resolver::resolve($target, $USER->id);

$PAGE->set_url(new moodle_url("/local/gimidashboard/view.php", ["target" => $dashboardpage->target]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout("report");
$PAGE->set_title(get_string("pluginname", "local_gimidashboard"));
$PAGE->set_heading(get_string("pluginname", "local_gimidashboard"));

echo $OUTPUT->header();
$reports = report_manager::render_reports($dashboardpage->type, $dashboardpage->courses);
$mustachedata = [
    "groups" => $dashboardpage->groups,
    "reports" => $reports,
    "hasreports" => !empty($reports),
];

echo $OUTPUT->render_from_template("local_gimidashboard/view", $mustachedata);
echo $OUTPUT->footer();
