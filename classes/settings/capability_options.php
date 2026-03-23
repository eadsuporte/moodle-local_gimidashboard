<?php

namespace local_gimidashboard\settings;

use coding_exception;
use dml_exception;

/**
 * Builds the capability list used by the plugin settings.
 *
 * @package   local_gimidashboard
 */
class capability_options {
    /**
     * Returns the capabilities that can be used to access reports.
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
