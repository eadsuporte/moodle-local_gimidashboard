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
 * Simple cohort enrolment page.
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\manage;

use Exception;
use local_gimidashboard\local\selection;
use local_gimidashboard\local\scope_helper;
use local_gimidashboard\local\user_provisioner;
use local_gimidashboard\report\filter_options;

/**
 * Simple cohort enrolment page.
 */
class cohort_register_page {
    /**
     * Build template context and handle submission.
     *
     * @param selection $sel
     * @param string $courseparam
     * @return array
     * @throws Exception
     */
    public static function get_template_context(selection $sel, string $courseparam): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/cohort/lib.php');

        $isadmin = is_siteadmin();
        $param = ['course' => $courseparam];
        $templatecontext = [
            'isadmin' => $isadmin,
            'cohort_import_url' => (new \moodle_url('/local/gimidashboard/cohort_import.php', $param))->out(false),
            'cohort_register_url' => (new \moodle_url('/local/gimidashboard/cohort_register.php', $param))->out(false),
            'dashboard_url' => (new \moodle_url('/local/gimidashboard/', $param))->out(false),
            'scope_label' => scope_helper::get_scope_label($sel),
            'error' => '',
            'success' => false,
            'success_title' => '',
            'profileurl' => '',
            'userfullname' => '',
            'whatsapptxt' => '',
            'cohorts' => [],
            'hasselection' => ($sel->is_course() || $sel->is_category()),
        ];

        if (!$isadmin && !$templatecontext['hasselection']) {
            $templatecontext['error'] = 'Missing scope. Provide ?course=ID or ?course=cat-ID in the URL.';
            return $templatecontext;
        }

        // Load scope cohorts.
        $courseids = scope_helper::resolve_courseids($sel);
        $available = scope_helper::get_available_cohorts($courseids);

        foreach ($available as $c) {
            $templatecontext['cohorts'][] = [
                'id' => $c['id'],
                'name' => $c['name'],
            ];
        }

        if ($templatecontext['hasselection'] && empty($templatecontext['cohorts'])) {
            $templatecontext['error'] = 'No cohorts with members were found for this scope.';
        }

        // Handle submit.
        if (optional_param('submit', 0, PARAM_INT) && data_submitted()) {
            require_sesskey();

            $fullname = optional_param('fullname', '', PARAM_RAW_TRIMMED);
            $email = optional_param('email', '', PARAM_RAW_TRIMMED);
            $cohortid = optional_param('cohortid', 0, PARAM_INT);

            $fullname = trim($fullname);
            $email = trim($email);

            if ($fullname === '' || $email === '' || $cohortid <= 0) {
                $templatecontext['error'] = 'Please fill Full name, Email and Cohort.';
                return $templatecontext;
            }

            if (!validate_email($email)) {
                $templatecontext['error'] = 'Invalid email.';
                return $templatecontext;
            }

            // Ensure selected cohort is allowed for this scope.
            $allowedids = array_map(static fn($x) => $x['id'], $available);
            if (!in_array($cohortid, $allowedids)) {
                $templatecontext['error'] = 'Selected cohort is not available for this scope.';
                return $templatecontext;
            }

            // Create or load user.
            $prov = user_provisioner::get_or_create_by_email($fullname, $email);
            $user = $prov['user'];

            // Add to cohort (avoid duplicates).
            if (!$DB->record_exists('cohort_members', ['cohortid' => $cohortid, 'userid' => $user->id])) {
                cohort_add_member($cohortid, $user->id);
            }

            $profileurl = (new \moodle_url('/user/profile.php', ['id' => $user->id]))->out(false);

            $templatecontext['success'] = true;
            $templatecontext['success_title'] =
                $prov['isnew'] ? 'User created and enrolled successfully.' : 'User enrolled successfully.';
            $templatecontext['profileurl'] = $profileurl;
            $templatecontext['userfullname'] = fullname($user);
            $templatecontext['whatsapptxt'] = user_provisioner::build_whatsapp_text($user, $prov['isnew'], $prov['password']);
        }

        return $templatecontext;
    }
}
