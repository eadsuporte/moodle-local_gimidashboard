<?php
require_once(__DIR__ . "/../../config.php");
require_once($CFG->libdir . "/adminlib.php");
require_once($CFG->libdir . "/tablelib.php");

use local_gimidashboard\admin\plugin_admin_page;

require_capability("moodle/site:config", context_system::instance());

$page = new plugin_admin_page();
$page->handle_actions();
$page->set_page();

echo $OUTPUT->header();
echo $OUTPUT->render_from_template("local_gimidashboard/admin_plugins", $page->export_for_template());
echo $OUTPUT->footer();
