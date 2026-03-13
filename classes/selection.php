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
 * Handles dashboard selection (course or category) coming from the filter.
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard;

use context_course;
use context_coursecat;
use Exception;

/**
 * Handles dashboard selection (course or category) coming from the filter.
 */
class selection {
    /** @var int|null */
    public ?int $courseid = null;

    /** @var int|null */
    public ?int $categoryid = null;

    /**
     * Build selection from request param.
     *
     * @param string $raw Example: "cat-12" or "34"
     * @return self
     */
    public static function from_param(string $raw): self {
        $self = new self();

        $raw = trim($raw);
        if ($raw === "") {
            return $self;
        }

        if (preg_match('/^cat-(\d+)$/', $raw, $m)) {
            $self->categoryid = $m[1];
            return $self;
        }

        if (ctype_digit($raw)) {
            $self->courseid = $raw;
        }

        return $self;
    }

    /**
     * Check if current user can view this selection.
     *
     * Rule used:
     * - Category: moodle/category:manage in that category context
     * - Course: moodle/course:viewparticipants in that course context
     *
     * @return bool
     * @throws Exception
     */
    public function is_allowed(): bool {
        global $USER;

        if ($this->categoryid) {
            $ctx = context_coursecat::instance($this->categoryid, IGNORE_MISSING);
            return $ctx ? has_capability("moodle/category:manage", $ctx, $USER) : false;
        }

        if ($this->courseid) {
            $ctx = context_course::instance($this->courseid, IGNORE_MISSING);
            if (!$ctx) {
                return false;
            }
            return has_capability("moodle/course:viewparticipants", $ctx, $USER);
        }

        return true;
    }

    /**
     * Returns whether a category is selected.
     *
     * @return bool
     */
    public function is_category(): bool {
        return !empty($this->categoryid);
    }

    /**
     * Returns whether a course is selected.
     *
     * @return bool
     */
    public function is_course(): bool {
        return !empty($this->courseid);
    }
}
