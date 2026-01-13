/**
 * @package local_gimidashboard
 */

define(['core/chartjs'], function (Chart) {

    const truncate = (s, n) => {
        s = String(s || "");
        if (n <= 1) {
            return "…";
        }
        return s.length > n ? (s.substring(0, n - 1) + "…") : s;
    };

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
                return (items && items[0] && items[0].label) ? items[0].label : '';
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
                var chartId = node.getAttribute('data-gimidashboard-chart');
                if (chartId) {
                    renderChart(chartId);
                }
            });
    }

    /**
     * Auto-submit on selection change.
     */
    function search() {
        var sel = document.getElementById('course');
        if (sel && sel.form) {
            sel.addEventListener('change', function () {
                sel.form.submit();
            });
        }
    }

    return {
        chart: chart,
        search: search,
    };
});
