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
$PAGE->set_context(context_system::instance());

$target = optional_param("target", false, PARAM_TEXT);
$selectedplugin = optional_param("plugin", "", PARAM_COMPONENT);

$dashboardpage = selection_resolver::resolve($target, $USER->id);
if ($target !== false) {
    $existingparams = [];
    $currentparams = ["target" => $dashboardpage->target];
    foreach ($_GET as $name => $value) {
        if ($name == "target" || is_array($value)) {
            continue;
        }

        $cleanname = clean_param($name, PARAM_ALPHANUMEXT);
        if ($cleanname === "") {
            continue;
        }

        $cleanvalue = clean_param((string) $value, PARAM_RAW_TRIMMED);
        $existingparams[] = [
            "name" => $cleanname,
            "value" => $cleanvalue,
        ];
        $currentparams[$cleanname] = $cleanvalue;
    }

    $reports = report_manager::render_reports(
        $dashboardpage->type,
        $dashboardpage->courses,
        $dashboardpage->target,
        $currentparams,
        $selectedplugin
    );
    $mustachedata = [
        "groups" => $dashboardpage->groups,
        "reports" => $reports,
        "hasreports" => !empty($reports),
        "existingparams" => $existingparams,
    ];
} else {
    $mustachedata = [
        "groups" => $dashboardpage->groups,
        "reports" => ["html" => ""],
    ];
    $currentparams = [];
}

$PAGE->set_url(new moodle_url("/local/gimidashboard/view.php", $currentparams));
$PAGE->set_pagelayout("report");
$PAGE->set_title(get_string("pluginname", "local_gimidashboard"));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template("local_gimidashboard/view", $mustachedata);
echo $OUTPUT->footer();
