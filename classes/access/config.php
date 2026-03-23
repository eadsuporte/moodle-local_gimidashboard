<?php
namespace local_gimidashboard\access;


/**
 * Reads plugin configuration values.
 *
 * @package   local_gimidashboard
 */
class config {
    /**
     * Returns the configured capability names.
     *
     * @return array
     */
    public static function get_report_capabilities(): array {
        $value = get_config("local_gimidashboard", "reportcapabilities");

        if (empty($value)) {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter($value));
        }

        return array_values(array_filter(array_map("trim", explode(",",  $value))));
    }

    /**
     * Returns the configured report order.
     *
     * @return array
     */
    public static function get_report_order(): array {
        $value = get_config("local_gimidashboard", "reportorder");

        if (empty($value)) {
            return [];
        }

        return array_values(array_filter(array_map("trim", explode(",",  $value))));
    }

    /**
     * Stores the report order.
     *
     * @param array $components Ordered component names.
     * @return void
     */
    public static function set_report_order(array $components): void {
        set_config("reportorder", implode(",", $components), "local_gimidashboard");
    }

    /**
     * Returns the disabled reports.
     *
     * @return array
     */
    public static function get_disabled_reports(): array {
        $value = get_config("local_gimidashboard", "disabledreports");

        if (empty($value)) {
            return [];
        }

        return array_values(array_filter(array_map("trim", explode(",",  $value))));
    }

    /**
     * Stores the disabled reports.
     *
     * @param array $components Disabled component names.
     * @return void
     */
    public static function set_disabled_reports(array $components): void {
        set_config("disabledreports", implode(",", $components), "local_gimidashboard");
    }
}
