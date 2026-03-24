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
 * export.php
 *
 * @package   gimidashboardreports_fullacademydashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . "/../../../../config.php");

use gimidashboardreports_fullacademydashboard\report;
use local_gimidashboard\page\selection_resolver;

require_login();

$target = optional_param("target", "", PARAM_TEXT);
$dataformat = optional_param("dataformat", "excel", PARAM_ALPHA);

$dashboardpage = selection_resolver::resolve($target, $USER->id);
if (empty($dashboardpage->courses)) {
    throw new moodle_exception("invaliddata");
}

report::export($dashboardpage->courses, $dataformat);
