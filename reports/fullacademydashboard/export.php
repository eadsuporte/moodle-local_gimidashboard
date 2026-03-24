<?php

require_once(__DIR__ . "/../../../../config.php");

use local_gimidashboard\page\selection_resolver;

require_login();

$target = optional_param("target", "", PARAM_TEXT);
$dataformat = optional_param("dataformat", "excel", PARAM_ALPHA);

$dashboardpage = selection_resolver::resolve($target, $USER->id);
if (empty($dashboardpage->courses)) {
    throw new moodle_exception("invaliddata");
}

\gimidashboardreports_fullacademydashboard\report::export($dashboardpage->courses, $dataformat);
