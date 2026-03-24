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
 * config.php
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
