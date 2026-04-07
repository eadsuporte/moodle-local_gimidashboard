<?php

namespace local_gimidashboard\report;

use core_plugin_manager;
use Exception;
use local_gimidashboard\access\config;
use moodle_url;

/**
 * Discovers, orders and renders dashboard report subplugins.
 *
 * @package   local_gimidashboard
 */
class report_manager {
    /**
     * Returns the installed report components ordered by admin configuration.
     *
     * @return array
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
        foreach (self::get_ordered_reports() as $report) {
            $component = $report["component"];
            /** @var report_interface $classname */
            $classname = $report["classname"];

            if (!self::is_enabled($component)) {
                continue;
            }

            if (!self::supports_selection($component, $selectiontype)) {
                continue;
            }

            if ($selectedplugin !== "" && "gimidashboardreports_{$selectedplugin}" !== $component) {
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
                ]),
            ];
        }

        return $reports;
    }
}
