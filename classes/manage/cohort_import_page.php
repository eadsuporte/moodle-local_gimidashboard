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
 * Bulk cohort import page (admin only enforced in PHP page).
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
 * Bulk cohort import page (admin only enforced in PHP page).
 */
class cohort_import_page {
    /**
     * Parse "fullname | email" or tab-separated lines.
     *
     * @param string $raw
     * @return array[] Each item: ['fullname' => string, 'email' => string]
     */
    private static function parse_rows(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $lines = preg_split('/\R/u', $raw);
        $out = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $fullname = '';
            $email = '';

            if (strpos($line, '|') !== false) {
                [$a, $b] = array_map('trim', explode('|', $line, 2));
                $fullname = $a;
                $email = $b;
            } else if (strpos($line, "\t") !== false) {
                [$a, $b] = array_map('trim', explode("\t", $line, 2));
                $fullname = $a;
                $email = $b;
            } else {
                // Last fallback: split by multiple spaces.
                $parts = preg_split('/\s{2,}/', $line);
                if (count($parts) >= 2) {
                    $fullname = trim($parts[0]);
                    $email = trim($parts[1]);
                }
            }

            if ($fullname !== '' && $email !== '') {
                $out[] = ['fullname' => $fullname, 'email' => $email];
            }
        }

        return $out;
    }

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
        $context = [
            'isadmin' => $isadmin,
            'actionurl' => (new \moodle_url('/local/gimidashboard/cohort_import.php', ['course' => $courseparam]))->out(false),
            'simpleurl' => (new \moodle_url('/local/gimidashboard/cohort_register.php', ['course' => $courseparam]))->out(false),
            'dashboardurl' => (new \moodle_url('/local/gimidashboard/', ['course' => $courseparam]))->out(false),
            'error' => '',
            'success' => false,
            'success_title' => '',
            'cohorts' => [],
            'results' => [],
            'hasselection' => ($sel->is_course() || $sel->is_category()),
        ];

        if (!$context['hasselection']) {
            $context['error'] = 'Select a category or course to start.';
            return $context;
        }

        $courseids = scope_helper::resolve_courseids($sel);
        $available = scope_helper::get_available_cohorts($courseids);

        foreach ($available as $c) {
            $context['cohorts'][] = [
                'id' => $c['id'],
                'name' => $c['name'],
            ];
        }

        if (empty($context['cohorts'])) {
            $context['error'] = 'No cohorts with members were found for this scope.';
            return $context;
        }

        if (optional_param('submit', 0, PARAM_INT) && data_submitted()) {
            require_sesskey();

            $raw = optional_param('rows', '', PARAM_RAW);
            $cohortid = optional_param('cohortid', 0, PARAM_INT);

            if ($cohortid <= 0) {
                $context['error'] = 'Select a cohort.';
                return $context;
            }

            $allowedids = array_map(static fn($x) => $x['id'], $available);
            if (!in_array($cohortid, $allowedids, true)) {
                $context['error'] = 'Selected cohort is not available for this scope.';
                return $context;
            }

            $rows = self::parse_rows($raw);
            if (empty($rows)) {
                $context['error'] = 'No valid rows found. Paste "Full name | Email" (one per line).';
                return $context;
            }

            $ok = 0;
            $errors = 0;

            foreach ($rows as $r) {
                $fullname = trim($r['fullname']);
                $email = trim($r['email']);

                if ($fullname === '' || $email === '' || !validate_email($email)) {
                    $errors++;
                    $context['results'][] = [
                        'status' => 'Error',
                        'fullname' => $fullname !== '' ? $fullname : '(missing name)',
                        'email' => $email !== '' ? $email : '(missing email)',
                        'profileurl' => '',
                        'whatsapptxt' => 'Invalid data on this line.',
                    ];
                    continue;
                }

                $prov = user_provisioner::get_or_create_by_email($fullname, $email);
                $user = $prov['user'];

                if (!$DB->record_exists('cohort_members', ['cohortid' => $cohortid, 'userid' => $user->id])) {
                    cohort_add_member($cohortid, $user->id);
                }

                $profileurl = (new \moodle_url('/user/profile.php', ['id' => $user->id]))->out(false);

                $ok++;
                $context['results'][] = [
                    'status' => $prov['isnew'] ? 'Created' : 'Existing',
                    'fullname' => fullname($user),
                    'email' => $user->email,
                    'profileurl' => $profileurl,
                    'whatsapptxt' => user_provisioner::build_whatsapp_text($user, $prov['isnew'], $prov['password']),
                ];
            }

            $context['success'] = true;
            $context['success_title'] = "Import finished. Success: {$ok}. Errors: {$errors}.";
        }

        return $context;
    }
}
