/**
 * @package local_gimidashboard
 */

define(['core/chartjs'], function (Chart) {
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
     * Init dashboard behaviors.
     */
    function init() {
        // Auto-submit on selection change.
        var sel = document.getElementById('course');
        if (sel && sel.form) {
            sel.addEventListener('change', function () {
                sel.form.submit();
            });
        }

        // Render all charts on the page.
        document
            .querySelectorAll('script[data-gimidashboard-chart]')
            .forEach(function (node) {
                var chartId = node.getAttribute('data-gimidashboard-chart');
                if (chartId) {
                    renderChart(chartId);
                }
            });
    }

    return {init: init};
});
