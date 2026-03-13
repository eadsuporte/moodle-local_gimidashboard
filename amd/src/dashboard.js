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
    "core/chartjs",
    "local_gimidashboard/jszip",
    "local_gimidashboard/dataTables",
    "local_gimidashboard/dataTables.buttons",
    "local_gimidashboard/dataTables.buttons.colVis",
    "local_gimidashboard/dataTables.buttons.html5",
    "local_gimidashboard/dataTables.buttons.print"
], function ($, Chart) {

    const truncate = (s, n) => {
        s = String(s || "");
        if (n <= 1) {
            return "…";
        }
        return s.length > n ? (s.substring(0, n - 1) + "…") : s;
    };

    /**
     * Initialize DataTables for dashboard tables.
     */
    function table() {
        const tables = document.querySelectorAll(".gimidashboard-table");
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

    /**
     * Render a chart from <script type="application/json" data-gimidashboard-chart="ID">...</script>
     * @param {string} chartId
     */
    function renderChart(chartId) {
        var canvas = document.getElementById(chartId);
        var script = document.querySelector(
            'script[data-gimidashboard-chart="' + chartId + '"]'
        );

        if (!canvas || !script) {
            return;
        }

        var payload = null;
        try {
            payload = JSON.parse(script.textContent);
        } catch (e) {
            return;
        }

        if (payload.shortenxlabels) {
            const limit = Number(payload.xlabellimit || 15);

            payload.options = payload.options || {};
            payload.options.scales = payload.options.scales || {};
            payload.options.scales.x = payload.options.scales.x || {};
            payload.options.scales.x.ticks = payload.options.scales.x.ticks || {};

            payload.options.scales.x.ticks.callback = function (value) {
                // Category scale: value costuma ser o índice; getLabelForValue é o mais seguro
                const full = this.getLabelForValue ? this.getLabelForValue(value) : (payload.labels[value] || "");
                return truncate(full, limit);
            };

            // Opcional: melhora legibilidade quando tem muitos cursos
            payload.options.scales.x.ticks.autoSkip = true;
            payload.options.scales.x.ticks.maxTicksLimit = 20;

            // Tooltip continua mostrando o nome completo
            payload.options.plugins = payload.options.plugins || {};
            payload.options.plugins.tooltip = payload.options.plugins.tooltip || {};
            payload.options.plugins.tooltip.callbacks = payload.options.plugins.tooltip.callbacks || {};
            payload.options.plugins.tooltip.callbacks.title = function (items) {
                return (items && items[0] && items[0].label) ? items[0].label : "";
            };
        }

        var ctx = canvas.getContext('2d');
        // eslint-disable-next-line no-new
        new Chart(ctx, {
            data: {
                labels: payload.labels || [],
                datasets: payload.datasets || []
            },
            options: payload.options || {}
        });
    }

    /**
     * Render all charts on the page.
     */
    function chart() {
        document
            .querySelectorAll('script[data-gimidashboard-chart]')
            .forEach(function (node) {
                var chartId = node.getAttribute("data-gimidashboard-chart");
                if (chartId) {
                    renderChart(chartId);
                }
            });
    }

    /**
     * Auto-submit on selection change.
     */
    function search() {
        var sel = document.getElementById("course");
        if (sel && sel.form) {
            sel.addEventListener("change", function () {
                sel.form.submit();
            });
        }
    }

    return {
        chart: chart,
        search: search,
        table: table,
    };
});
