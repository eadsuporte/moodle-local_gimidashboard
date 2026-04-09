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
use flexible_table;
use html_writer;
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
        }

        redirect(new moodle_url("/local/gimidashboard/admin_plugins.php"));
    }

    /**
     * Configures the page object.
     *
     * @return void
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
     */
    public function export_for_template(): array {
        return [
            "description" => get_string("plugintabledescription", "local_gimidashboard"),
            "tablehtml" => $this->render_table(),
            "dashboardurl" => new moodle_url("/local/gimidashboard/"),
            "dashboardlabel" => get_string("backtodashboard", "local_gimidashboard"),
        ];
    }

    /**
     * Renders the plugins table.
     *
     * @return string
     */
    protected function render_table(): string {
        $table = new flexible_table("local-gimidashboard-admin-plugins");
        $table->define_columns(["position", "plugin", "status", "supports", "actions"]);
        $table->define_headers([
            get_string("position", "local_gimidashboard"),
            get_string("reportplugin", "local_gimidashboard"),
            get_string("status", "local_gimidashboard"),
            get_string("supports", "local_gimidashboard"),
            get_string("actions", "local_gimidashboard"),
        ]);
        $table->set_attribute("class", "generaltable table-sm");
        $table->baseurl = "/local/gimidashboard/admin_plugins.php";
        $table->setup();

        global $OUTPUT;

        $reports = report_manager::get_ordered_reports();
        $total = count($reports);

        foreach ($reports as $index => $report) {
            $component = $report["component"];
            $enabled = report_manager::is_enabled($component);
            $supports = [];
            if (report_manager::supports_selection($component, "course")) {
                $supports[] = html_writer::tag("span", get_string("course", "local_gimidashboard"), [
                    "class" => "badge text-bg-light local-gimidashboard-admin-badge",
                ]);
            }
            if (report_manager::supports_selection($component, "category")) {
                $supports[] = html_writer::tag("span", get_string("category", "local_gimidashboard"), [
                    "class" => "badge text-bg-light local-gimidashboard-admin-badge",
                ]);
            }

            $previewurl = new moodle_url("/local/gimidashboard/view.php", [
                "target" => "course-1",
                "plugin" => $report["name"],
            ]);
            $pluginlabel = html_writer::link(
                $previewurl,
                format_string($report["displayname"], true, ["context" => context_system::instance()]),
                [
                    "class" => "local-gimidashboard-admin-plugin-link",
                    "title" => get_string("openonlyreport", "local_gimidashboard"),
                    "target" => "_blank",
                ]
            );
            $pluginmeta = html_writer::tag("small", s($component), ["class" => "text-muted d-block mt-1"]);

            $actions = [];
            if ($index > 0) {
                $actions[] = html_writer::link(
                    new moodle_url("/local/gimidashboard/admin_plugins.php", [
                        "action" => "moveup",
                        "component" => $component,
                        "sesskey" => sesskey(),
                    ]),
                    $OUTPUT->pix_icon("t/up", get_string("moveup", "local_gimidashboard")),
                    [
                        "class" => "btn btn-light btn-sm local-gimidashboard-admin-action",
                        "title" => get_string("moveup", "local_gimidashboard"),
                        "aria-label" => get_string("moveup", "local_gimidashboard"),
                    ]
                );
            }
            if ($index < ($total - 1)) {
                $actions[] = html_writer::link(
                    new moodle_url("/local/gimidashboard/admin_plugins.php", [
                        "action" => "movedown",
                        "component" => $component,
                        "sesskey" => sesskey(),
                    ]),
                    $OUTPUT->pix_icon("t/down", get_string("movedown", "local_gimidashboard")),
                    [
                        "class" => "btn btn-light btn-sm local-gimidashboard-admin-action",
                        "title" => get_string("movedown", "local_gimidashboard"),
                        "aria-label" => get_string("movedown", "local_gimidashboard"),
                    ]
                );
            }
            $actions[] = html_writer::link(
                new moodle_url("/local/gimidashboard/admin_plugins.php", [
                    "action" => "toggle",
                    "component" => $component,
                    "sesskey" => sesskey(),
                ]),
                $enabled
                    ? $OUTPUT->pix_icon("t/hide", get_string("disable", "local_gimidashboard"))
                    : $OUTPUT->pix_icon("t/show", get_string("enable", "local_gimidashboard")),
                [
                    "class" => "btn btn-light btn-sm local-gimidashboard-admin-action",
                    "title" => $enabled ? get_string("disable", "local_gimidashboard") :
                        get_string("enable", "local_gimidashboard"),
                    "aria-label" => $enabled ? get_string("disable", "local_gimidashboard") :
                        get_string("enable", "local_gimidashboard"),
                ]
            );

            $statuslabel = $enabled ? get_string("enabled", "local_gimidashboard") : get_string("disabled", "local_gimidashboard");
            $statusclass =
                $enabled ? "local-gimidashboard-admin-status is-enabled" : "local-gimidashboard-admin-status is-disabled";

            $table->add_data([
                html_writer::tag("span", (string) ($index + 1), ["class" => "local-gimidashboard-admin-position"]),
                html_writer::tag("div", $pluginlabel . $pluginmeta, ["class" => "local-gimidashboard-admin-plugin"]),
                html_writer::tag("span", $statuslabel, ["class" => $statusclass]),
                html_writer::tag("div", implode(" ", $supports), ["class" => "local-gimidashboard-admin-supports"]),
                html_writer::tag("div", implode("", $actions), ["class" => "local-gimidashboard-admin-actions"]),
            ]);
        }

        ob_start();
        $table->finish_output();
        return ob_get_clean();
    }
}
