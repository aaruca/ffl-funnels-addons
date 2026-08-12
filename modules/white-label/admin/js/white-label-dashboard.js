/**
 * White Label — dashboard behaviour: the sales bar chart (Chart.js) and the
 * sortable Top-search-terms table.
 */
(function () {
    'use strict';

    function cssVar(el, name, fallback) {
        var v = getComputedStyle(el).getPropertyValue(name);
        return (v && v.trim()) || fallback;
    }

    /* ── Sales bar chart ─────────────────────────────────────────────────── */
    function renderChart() {
        var canvas = document.querySelector('.ffla-dash-chart');
        if (!canvas || typeof window.Chart === 'undefined') {
            return;
        }

        var series;
        try {
            series = JSON.parse(canvas.getAttribute('data-series') || '[]');
        } catch (e) {
            return;
        }
        if (!series.length) {
            return;
        }

        var currency = canvas.getAttribute('data-currency') || '$';
        var accent = cssVar(canvas, '--ffla-dash-accent', '#2563eb');
        var muted = cssVar(canvas, '--ffla-dash-muted', '#94a3b8');
        var grid = cssVar(canvas, '--ffla-dash-border', '#e6eaf1');

        // Show a readable number of x-axis labels on long ranges.
        var step = Math.ceil(series.length / 8);

        new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: series.map(function (p) { return p.l; }),
                datasets: [{
                    data: series.map(function (p) { return p.v; }),
                    backgroundColor: accent,
                    borderRadius: 4,
                    maxBarThickness: 26,
                    categoryPercentage: 0.7,
                    barPercentage: 0.9
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return currency + Number(ctx.parsed.y).toLocaleString(); }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false, drawBorder: false },
                        ticks: {
                            color: muted,
                            maxRotation: 0,
                            autoSkip: false,
                            callback: function (val, index) { return index % step === 0 ? this.getLabelForValue(val) : ''; }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: grid, drawBorder: false },
                        ticks: {
                            color: muted,
                            maxTicksLimit: 5,
                            callback: function (v) { return currency + Number(v).toLocaleString(); }
                        }
                    }
                }
            }
        });
    }

    /* ── Sortable table ──────────────────────────────────────────────────── */
    function makeSortable(table) {
        var headers = table.querySelectorAll('thead th');
        var tbody = table.querySelector('tbody');
        if (!tbody) {
            return;
        }

        Array.prototype.forEach.call(headers, function (th, index) {
            th.classList.add('is-sortable');
            th.addEventListener('click', function () {
                var numeric = th.getAttribute('data-type') === 'num';
                var asc = th.getAttribute('data-dir') !== 'asc';

                // Reset indicators, set this one.
                Array.prototype.forEach.call(headers, function (h) { h.removeAttribute('data-dir'); });
                th.setAttribute('data-dir', asc ? 'asc' : 'desc');

                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                rows.sort(function (a, b) {
                    var ca = a.children[index];
                    var cb = b.children[index];
                    var va = numeric ? parseFloat(ca.getAttribute('data-v') || '0') : ca.textContent.trim().toLowerCase();
                    var vb = numeric ? parseFloat(cb.getAttribute('data-v') || '0') : cb.textContent.trim().toLowerCase();
                    if (va < vb) { return asc ? -1 : 1; }
                    if (va > vb) { return asc ? 1 : -1; }
                    return 0;
                });
                rows.forEach(function (r) { tbody.appendChild(r); });
            });
        });
    }

    function init() {
        renderChart();
        Array.prototype.forEach.call(document.querySelectorAll('[data-ffla-sortable]'), makeSortable);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
