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
 * settings.php
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

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
}
