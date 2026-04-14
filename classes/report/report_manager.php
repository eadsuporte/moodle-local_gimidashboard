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
 * Discovers, orders and renders dashboard report subplugins.
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\report;

use core_plugin_manager;
use Exception;
use local_gimidashboard\access\config;
use local_gimidashboard\plugin_metadata;
use moodle_url;

/**
 * Class report_manager
 */
class report_manager {
    /**
     * Returns the installed report components ordered by admin configuration.
     *
     * @return array
     * @throws \coding_exception
     */
    public static function get_ordered_reports(): array {
        $plugins = core_plugin_manager::instance()->get_plugins_of_type("gimidashboardreports");
        $installed = [];

        foreach ($plugins as $name => $plugininfo) {
            $component = "gimidashboardreports_" . $name;
            $installed[$component] = [
                "component" => $component,
                "name" => $name,
                "displayname" => get_string("pluginname", $component),
                "classname" => "\\{$component}\\report",
                "release" => plugin_metadata::get_report_release($name),
            ];
        }

        $ordered = [];
        foreach (config::get_report_order() as $component) {
            if (isset($installed[$component])) {
                $ordered[$component] = $installed[$component];
                unset($installed[$component]);
            }
        }

        foreach ($installed as $component => $data) {
            $ordered[$component] = $data;
        }

        return array_values($ordered);
    }

    /**
     * Returns true when a report is enabled.
     *
     * @param string $component Report component.
     * @return bool
     */
    public static function is_enabled(string $component): bool {
        return !in_array($component, config::get_disabled_reports(), true);
    }

    /**
     * Toggles a report enabled state.
     *
     * @param string $component Report component.
     * @return void
     */
    public static function toggle_enabled(string $component): void {
        $disabled = config::get_disabled_reports();
        if (in_array($component, $disabled, true)) {
            $disabled = array_values(array_filter($disabled, static function(string $value) use ($component): bool {
                return $value !== $component;
            }));
        } else {
            $disabled[] = $component;
            $disabled = array_values(array_unique($disabled));
        }

        config::set_disabled_reports($disabled);
    }

    /**
     * Moves a report inside the ordered list.
     *
     * @param string $component Report component.
     * @param int $direction Direction offset.
     * @return void
     */
    public static function move(string $component, int $direction): void {
        $ordered = array_map(static function(array $report): string {
            return $report["component"];
        }, self::get_ordered_reports());

        $index = array_search($component, $ordered, true);
        if ($index === false) {
            return;
        }

        $targetindex = $index + $direction;
        if (!isset($ordered[$targetindex])) {
            return;
        }

        $temporary = $ordered[$targetindex];
        $ordered[$targetindex] = $ordered[$index];
        $ordered[$index] = $temporary;

        config::set_report_order($ordered);
    }

    /**
     * Returns true when a report supports the current selection type.
     *
     * @param string $component Report component.
     * @param string $selectiontype Selection type.
     * @return bool
     */
    public static function supports_selection(string $component, string $selectiontype): bool {
        $classname = "\\{$component}\\report";
        if (!class_exists($classname)) {
            return false;
        }

        if (!is_subclass_of($classname, report_interface::class)) {
            return false;
        }

        if ($selectiontype == "category") {
            return $classname::supports_category();
        }

        return $classname::supports_course();
    }

    /**
     * Renders the enabled reports for the current selection.
     *
     * @param string $selectiontype Selection type.
     * @param array $courses Accessible courses for the selection.
     * @param string $target Current selected target.
     * @param array $currentparams Current view parameters.
     * @param string $selectedplugin Selected report component.
     * @return array
     * @throws Exception
     */
    public static function render_reports(
        string $selectiontype,
        array $courses,
        string $target,
        array $currentparams = [],
        string $selectedplugin = ""
    ): array {
        global $OUTPUT;

        $reports = [];
        $selectedcomponent = $selectedplugin !== "" ? "gimidashboardreports_{$selectedplugin}" : "";

        foreach (self::get_ordered_reports() as $report) {
            $component = $report["component"];
            /** @var report_interface $classname */
            $classname = $report["classname"];

            if ($selectedcomponent !== "") {
                if ($selectedcomponent !== $component) {
                    continue;
                }
            } else if (!self::is_enabled($component)) {
                continue;
            }

            if (!self::supports_selection($component, $selectiontype)) {
                continue;
            }

            if ($selectiontype === "category" && !$classname::supports_category()) {
                continue;
            }

            if ($selectiontype !== "category" && !$classname::supports_course()) {
                continue;
            }

            $content = $classname::render($courses);
            $reportparams = $currentparams;
            $reportparams["target"] = $target;

            if (!optional_param("plugin", false, PARAM_COMPONENT)) {
                $reportparams["plugin"] = str_replace("gimidashboardreports_", "", $component);
                $reporturl = new moodle_url("/local/gimidashboard/", $reportparams);
                $label = get_string("openonlyreport", "local_gimidashboard");
                $reportlink = "<a href=\"{$reporturl}\" class=\"btn btn-primary text-nowrap\">{$label}</a>";
            } else {
                $reporturl = new moodle_url("/local/gimidashboard/", ["target" => $target]);
                $label = get_string("back");
                $reportlink = "<a href=\"{$reporturl}\" class=\"btn btn-primary text-nowrap\">{$label}</a>";
            }

            $reports[] = [
                "component" => $component,
                "html" => $OUTPUT->render_from_template("local_gimidashboard/report_card", [
                    "component" => $component,
                    "header" => $classname::get_header($courses, $reportlink),
                    "content" => $content,
                    "release" => $report['release'],
                ]),
            ];
        }

        return $reports;
    }
}
