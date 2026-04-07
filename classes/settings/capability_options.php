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
 * capability_options.php
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\settings;

use coding_exception;
use dml_exception;

/**
 * Builds the role list used by the plugin settings.
 *
 * @package   local_gimidashboard
 */
class capability_options {
    /**
     * Returns the roles that can be used to access reports.
     *
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function get_choices(): array {
        global $DB;

        $roles = $DB->get_records("role", null, "sortorder ASC");
        $roleoptions = [];
        foreach ($roles as $role) {
            if ($role->id == 5) {
                continue;
            } else if ($role->id == 6) {
                continue;
            } else if ($role->id == 7) {
                continue;
            } else if ($role->id == 8) {
                continue;
            }
            $roleoptions[$role->id] = role_get_name($role);
        }

        asort($roleoptions);
        return $roleoptions;
    }
}
