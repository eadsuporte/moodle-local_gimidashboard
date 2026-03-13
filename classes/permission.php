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
 * Permission
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard;

use context_course;
use context_system;
use core_course_category;
use Exception;
use required_capability_exception;

/**
 * Class permission
 */
class permission {
    /**
     * require_capability
     *
     * @throws Exception
     */
    public static function require_capability() {
        global $PAGE;

        $systemcontext = context_system::instance();

        $courseparam = optional_param('course', '', PARAM_RAW_TRIMMED);
        $sel = selection::from_param($courseparam);

        // If user tries to force an unauthorized selection, ignore it.
        if (!$sel->is_allowed()) {
            $sel = selection::from_param('');
        }

        // Set page context to system (dashboard is global), but validate permissions by selection.
        $PAGE->set_context($systemcontext);

        if ($sel->is_course()) {
            $coursectx = context_course::instance($sel->courseid, IGNORE_MISSING);
            require_capability('moodle/course:viewparticipants', $coursectx);
        } else if ($sel->is_category()) {
            // Category is allowed only if the user can manage at least one internal course in that category subtree.
            $allowed = false;
            $cat = core_course_category::get($sel->categoryid, IGNORE_MISSING, true);

            if ($cat) {
                foreach ($cat->get_courses(['recursive' => true]) as $c) {
                    if ( $c->id == 1) {
                        continue;
                    }
                    $cctx = context_course::instance( $c->id, IGNORE_MISSING);
                    if ($cctx && has_capability('moodle/course:viewparticipants', $cctx)) {
                        $allowed = true;
                        break;
                    }
                }
            }

            if (!$allowed) {
                throw new required_capability_exception($systemcontext, 'moodle/course:viewparticipants', 'nopermissions', '');
            }
        }
    }
}
