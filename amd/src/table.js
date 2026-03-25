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
 * dashboard.js
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    "jquery",
    "local_gimidashboard/jszip",
    "local_gimidashboard/dataTables",
    "local_gimidashboard/dataTables.buttons",
    "local_gimidashboard/dataTables.buttons.colVis",
    "local_gimidashboard/dataTables.buttons.html5",
    "local_gimidashboard/dataTables.buttons.print"
], function ($) {
    /**
     * Initialize DataTables for dashboard tables.
     */
    function datatable(selector) {
        const tables = document.querySelectorAll(selector);
        if (!tables.length) {
            return;
        }

        tables.forEach((element) => {
            if (!$.fn || !$.fn.dataTable) {
                console.log("dataTable not found");
                return;
            }

            if ($.fn.dataTable.isDataTable(element)) {
                console.log("dataTable.isDataTable not found");
                return;
            }

            $(element).DataTable({
                autoWidth: false,
                responsive: true,
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                scrollX: true,
                select: true,
                layout: {
                    topStart: 'pageLength',
                    topEnd: 'search',
                    bottomStart: 'info',
                    bottomEnd: [
                        'paging',
                        {
                            buttons: [
                                {
                                    extend: 'print',
                                    title: element.dataset.title || null,
                                }, {
                                    extend: 'copyHtml5',
                                    title: element.dataset.title || null,
                                }, {
                                    extend: 'excelHtml5',
                                    title: element.dataset.title || null,
                                }, {
                                    extend: 'csvHtml5',
                                    title: element.dataset.title || null,
                                }
                            ]
                        }
                    ],
                }
            });
        });
    }

    return {
        datatable: datatable,
    };
});
