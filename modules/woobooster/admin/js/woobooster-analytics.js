/**
 * WooBooster Analytics — Charts (Chart.js)
 *
 * Reads WBAnalyticsChart (localized via PHP) and renders:
 * 1. Stacked bar chart: total store revenue vs WooBooster-attributed revenue by day
 * 2. Donut chart: revenue breakdown by top rules
 *
 * @package FFL_Funnels_Addons
 */
(function () {
    'use strict';

    if (typeof WBAnalyticsChart === 'undefined' || typeof Chart === 'undefined') {
        return;
    }

    var d = WBAnalyticsChart;
    var currencySymbol = d.currency || '$';

    /* ─── Revenue Bar Chart ─── */
    var barCtx = document.getElementById('wb-revenue-chart');
    if (barCtx) {
        var otherData = d.total.map(function (val, i) {
            var diff = val - (d.wb[i] || 0);
            return diff > 0 ? diff : 0;
        });

        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: d.labels,
                datasets: [
                    {
                        label: 'WooBooster Revenue',
                        data: d.wb,
                        backgroundColor: '#0f6cbd',
                        borderRadius: 4,
                        order: 2
                    },
                    {
                        label: 'Other Revenue',
                        data: otherData,
                        backgroundColor: '#e2e8f0',
                        borderRadius: 4,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true, pointStyle: 'rectRounded', padding: 16, font: { size: 12 } }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,.85)',
                        titleFont: { size: 13 },
                        bodyFont: { size: 12 },
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: function (ctx) {
                                var val = ctx.parsed.y || 0;
                                return ' ' + ctx.dataset.label + ': ' + currencySymbol + val.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: { font: { size: 11 }, maxRotation: 45, minRotation: 0 }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: {
                            callback: function (v) { return currencySymbol + v.toLocaleString(); },
                            font: { size: 11 }
                        },
                        grid: { color: 'rgba(0,0,0,0.04)' }
                    }
                }
            }
        });
    }

    /* ─── Revenue by Rule Donut Chart ─── */
    var donutCtx = document.getElementById('wb-donut-chart');
    if (donutCtx && d.donut && d.donut.labels && d.donut.labels.length > 0) {
        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: d.donut.labels,
                datasets: [{
                    data: d.donut.values,
                    backgroundColor: d.donut.colors,
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 14,
                            font: { size: 12 },
                            generateLabels: function (chart) {
                                var data = chart.data;
                                var total = data.datasets[0].data.reduce(function (a, b) { return a + b; }, 0);
                                return data.labels.map(function (label, i) {
                                    var val = data.datasets[0].data[i];
                                    var pct = total > 0 ? Math.round((val / total) * 100) : 0;
                                    return {
                                        text: label + ' (' + pct + '%)',
                                        fillStyle: data.datasets[0].backgroundColor[i],
                                        strokeStyle: '#fff',
                                        lineWidth: 0,
                                        index: i
                                    };
                                });
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,.85)',
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: function (ctx) {
                                var val = ctx.parsed || 0;
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct = total > 0 ? Math.round((val / total) * 100) : 0;
                                return ' ' + ctx.label + ': ' + currencySymbol + val.toLocaleString(undefined, { minimumFractionDigits: 2 }) + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    } else if (donutCtx) {
        // No data — show empty state.
        donutCtx.parentElement.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--wb-color-neutral-foreground-3);font-size:14px;">No rule data yet</div>';
    }
})();
