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
 * plugin_admin_page.php
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\admin;

use context_system;
use local_gimidashboard\header_color_manager;
use local_gimidashboard\report\report_manager;
use moodle_url;

/**
 * Builds the report plugin administration page.
 *
 * @package   local_gimidashboard
 */
class plugin_admin_page {
    /**
     * Processes enable, disable and ordering actions.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     * @throws \required_capability_exception
     */
    public function handle_actions(): void {
        $action = optional_param("action", "", PARAM_ALPHA);
        $component = optional_param("component", "", PARAM_COMPONENT);

        if ($action == "" || $component == "") {
            return;
        }

        require_sesskey();
        require_capability("moodle/site:config", context_system::instance());

        if ($action == "toggle") {
            report_manager::toggle_enabled($component);
        } else if ($action == "moveup") {
            report_manager::move($component, -1);
        } else if ($action == "movedown") {
            report_manager::move($component, 1);
        } else if ($action == "saveheadercolor") {
            $color = optional_param("basecolor", false, PARAM_RAW_TRIMMED);
            if ($color) {
                header_color_manager::set_base_color($component, $color);
            }
        }

        redirect(new moodle_url("/local/gimidashboard/admin_plugins.php"));
    }

    /**
     * Configures the page object.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function set_page(): void {
        global $PAGE;

        $PAGE->set_url(new moodle_url("/local/gimidashboard/admin_plugins.php"));
        $PAGE->set_context(context_system::instance());
        $PAGE->set_pagelayout("admin");
        $PAGE->set_title(get_string("manageplugins", "local_gimidashboard"));
        $PAGE->set_heading(get_string("manageplugins", "local_gimidashboard"));
    }

    /**
     * Exports template data for the admin page.
     *
     * @return array
     * @throws \coding_exception
     * @throws \core\exception\moodle_exception
     * @throws \dml_exception
     */
    public function export_for_template(): array {
        return [
            "description" => get_string("plugintabledescription", "local_gimidashboard"),
            "pluginsrows" => $this->render_table(),
            "dashboardurl" => new moodle_url("/local/gimidashboard/"),
            "dashboardlabel" => get_string("backtodashboard", "local_gimidashboard"),
        ];
    }

    /**
     * Renders the plugins table.
     *
     * @return array
     * @throws \coding_exception
     * @throws \core\exception\moodle_exception
     * @throws \dml_exception
     */
    protected function render_table(): array {
        global $OUTPUT;

        $rows = [];
        $reports = report_manager::get_ordered_reports();
        $total = count($reports);
        $systemcontext = context_system::instance();

        foreach ($reports as $index => $report) {
            $component = $report["component"];
            $enabled = report_manager::is_enabled($component);

            $supports = [];
            if (report_manager::supports_selection($component, "course")) {
                $supports[] = [
                    "label" => get_string("course", "local_gimidashboard"),
                    "class" => "badge text-bg-light local-gimidashboard-admin-badge",
                ];
            }
            if (report_manager::supports_selection($component, "category")) {
                $supports[] = [
                    "label" => get_string("category", "local_gimidashboard"),
                    "class" => "badge text-bg-light local-gimidashboard-admin-badge",
                ];
            }

            $basecolor = header_color_manager::get_base_color($component);
            $accentcolor = header_color_manager::calculate_accent_color($basecolor);

            $rows[] = [
                "position" => $index + 1,
                "pluginname" => format_string($report["displayname"], true, ["context" => $systemcontext]),
                "previewurl" => new moodle_url("/local/gimidashboard/view.php", [
                    "target" => "course-1",
                    "plugin" => $report["name"],
                ]),
                "previewtitle" => get_string("openonlyreport", "local_gimidashboard"),
                "component" => $component,

                "statusclass" => $enabled
                    ? "local-gimidashboard-admin-status is-enabled"
                    : "local-gimidashboard-admin-status is-disabled",
                "statuslabel" => $enabled
                    ? get_string("enabled", "local_gimidashboard")
                    : get_string("disabled", "local_gimidashboard"),
                "statusbadgeclass" => $enabled ? "badge badge-success" : "badge badge-danger",

                "supports" => $supports,

                "basecolor" => $basecolor,
                "accentcolor" => $accentcolor,
                "colorpreviewstyle" =>
                    "display:inline-block;width:22px;height:22px;border-radius:999px;border:1px solid rgba(15,23,42,0.12);" .
                    "background:linear-gradient(135deg, {$basecolor} 0%, {$accentcolor} 100%);",
                "savecolorurl" => new moodle_url("/local/gimidashboard/admin_plugins.php"),
                "sesskey" => sesskey(),
                "headercolorlabel" => get_string("headercolor", "local_gimidashboard"),
                "savecolorlabel" => get_string("savecolor", "local_gimidashboard"),

                "canmoveup" => $index > 0,
                "moveupurl" => new moodle_url("/local/gimidashboard/admin_plugins.php", [
                    "action" => "moveup",
                    "component" => $component,
                    "sesskey" => sesskey(),
                ]),
                "moveuplabel" => get_string("moveup", "local_gimidashboard"),
                "moveupicon" => $OUTPUT->pix_icon("t/up", get_string("moveup", "local_gimidashboard")),

                "canmovedown" => $index < ($total - 1),
                "movedownurl" => new moodle_url("/local/gimidashboard/admin_plugins.php", [
                    "action" => "movedown",
                    "component" => $component,
                    "sesskey" => sesskey(),
                ]),
                "movedownlabel" => get_string("movedown", "local_gimidashboard"),
                "movedownicon" => $OUTPUT->pix_icon("t/down", get_string("movedown", "local_gimidashboard")),

                "toggleurl" => new moodle_url("/local/gimidashboard/admin_plugins.php", [
                    "action" => "toggle",
                    "component" => $component,
                    "sesskey" => sesskey(),
                ]),
                "togglelabel" => $enabled
                    ? get_string("disable", "local_gimidashboard")
                    : get_string("enable", "local_gimidashboard"),
                "toggleicon" => $enabled
                    ? $OUTPUT->pix_icon("t/hide", get_string("disable", "local_gimidashboard"))
                    : $OUTPUT->pix_icon("t/show", get_string("enable", "local_gimidashboard")),
            ];
        }
        return $rows;
    }
}
