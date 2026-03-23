<?php

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
