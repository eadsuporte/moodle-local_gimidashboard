<?php

defined("MOODLE_INTERNAL") || die();

use local_gimidashboard\settings\capability_options;

/**
 * Plugin settings registration.
 *
 * @package   local_gimidashboard
 */
if ($hassiteconfig) {

    global $CFG, $PAGE, $ADMIN;

    $settings = new admin_settingpage("local_gimidashboard", get_string("pluginname", "local_gimidashboard"));
    $ADMIN->add("localplugins", $settings);

    $setting = new admin_setting_configmultiselect(
        "local_gimidashboard/reportcapabilities",
        get_string("reportcapabilities", "local_gimidashboard"),
        get_string("reportcapabilities_desc", "local_gimidashboard"),
        [],
        capability_options::get_choices()
    );
    $settings->add($setting);

    //$ADMIN->add("localplugins", $settings);
    //$ADMIN->add("localplugins", new admin_externalpage(
    //    "local_gimidashboard_plugins",
    //    get_string("adminplugins", "local_gimidashboard"),
    //    new moodle_url("/local/gimidashboard/admin_plugins.php"),
    //    "local/gimidashboard:manage"
    //));
}
