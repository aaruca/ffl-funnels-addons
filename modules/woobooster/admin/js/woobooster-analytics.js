/**
 * WooBooster Analytics — Revenue Chart (Chart.js)
 *
 * Reads WBAnalyticsChart (localized via PHP) and renders a stacked bar chart
 * showing total store revenue vs WooBooster-attributed revenue by day.
 *
 * @package FFL_Funnels_Addons
 */
(function () {
    'use strict';

    if (typeof WBAnalyticsChart === 'undefined' || typeof Chart === 'undefined') {
        return;
    }

    var data = WBAnalyticsChart;
    var ctx = document.getElementById('wb-revenue-chart');

    if (!ctx) {
        return;
    }

    // "Other" = total minus WB so bars stack to the full total.
    var otherData = data.total.map(function (val, i) {
        var diff = val - (data.wb[i] || 0);
        return diff > 0 ? diff : 0;
    });

    var currencySymbol = data.currency || '$';

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [
                {
                    label: 'WooBooster Revenue',
                    data: data.wb,
                    backgroundColor: '#0f6cbd',
                    borderRadius: 3,
                    order: 2
                },
                {
                    label: 'Other Revenue',
                    data: otherData,
                    backgroundColor: '#e2e8f0',
                    borderRadius: 3,
                    order: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'rectRounded',
                        padding: 20,
                        font: { size: 13 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            var val = context.parsed.y || 0;
                            return ' ' + context.dataset.label + ': ' + currencySymbol + val.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false },
                    ticks: { font: { size: 11 } }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        callback: function (value) {
                            return currencySymbol + value.toLocaleString();
                        },
                        font: { size: 11 }
                    },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                }
            }
        }
    });
})();
