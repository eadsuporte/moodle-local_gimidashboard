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
 * report_interface.php
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\report;


/**
 * Contract implemented by dashboard report subplugins.
 *
 * @package   local_gimidashboard
 */
interface report_interface {
    /**
     * Returns the report title.
     *
     * @param array $courses
     * @param string $extra
     * @return string
     */
    public static function get_header(array $courses, $extra=""): string;

    /**
     * Returns true when the report supports a single course selection.
     *
     * @return bool
     */
    public static function supports_course(): bool;

    /**
     * Returns true when the report supports a category selection.
     *
     * @return bool
     */
    public static function supports_category(): bool;

    /**
     * Renders the report HTML.
     *
     * @param array $courses Accessible course records.
     * @return string
     */
    public static function render(array $courses): string;
}
