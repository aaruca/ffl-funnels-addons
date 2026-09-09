/**
 * White Label dashboard: business chart, provider tabs, lazy analytics, and
 * sortable tables.
 */
(function () {
    'use strict';

    var config = window.fflaWhiteLabelDashboard || {};
    var strings = config.strings || {};
    var analyticsChart = null;
    var requestSequence = 0;
    var responseCache = {};

    function cssVar(el, name, fallback) {
        var value = getComputedStyle(el).getPropertyValue(name);
        return (value && value.trim()) || fallback;
    }

    function makeElement(tag, className, text) {
        var el = document.createElement(tag);
        if (className) {
            el.className = className;
        }
        if (typeof text !== 'undefined') {
            el.textContent = text;
        }
        return el;
    }

    function isNumber(value) {
        return typeof value === 'number' && Number.isFinite(value);
    }

    function formatNumber(value, maximumFractionDigits) {
        if (!isNumber(value)) {
            return '—';
        }

        return new Intl.NumberFormat(undefined, {
            maximumFractionDigits: typeof maximumFractionDigits === 'number' ? maximumFractionDigits : 0
        }).format(value);
    }

    function formatMetric(value, format) {
        if ('percent' === format) {
            return isNumber(value) ? formatNumber(value, 2) + '%' : '—';
        }
        return formatNumber(value, format === 'decimal' ? 2 : 0);
    }

    function renderSalesChart() {
        var canvas = document.querySelector('.ffla-dash-chart');
        if (!canvas || typeof window.Chart === 'undefined') {
            return;
        }

        var series;
        try {
            series = JSON.parse(canvas.getAttribute('data-series') || '[]');
        } catch (error) {
            return;
        }
        if (!series.length) {
            return;
        }

        var currency = canvas.getAttribute('data-currency') || '$';
        var accent = cssVar(canvas, '--ffla-dash-accent', '#2563eb');
        var muted = cssVar(canvas, '--ffla-dash-muted', '#94a3b8');
        var grid = cssVar(canvas, '--ffla-dash-border', '#e6eaf1');
        var step = Math.ceil(series.length / 8);

        new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: series.map(function (point) { return point.l; }),
                datasets: [{
                    data: series.map(function (point) { return point.v; }),
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
                            label: function (context) {
                                return currency + Number(context.parsed.y).toLocaleString();
                            }
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
                            callback: function (value, index) {
                                return index % step === 0 ? this.getLabelForValue(value) : '';
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: grid, drawBorder: false },
                        ticks: {
                            color: muted,
                            maxTicksLimit: 5,
                            callback: function (value) {
                                return currency + Number(value).toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    }

    function makeSortable(table) {
        var headers = table.querySelectorAll('thead th');
        var tbody = table.querySelector('tbody');
        if (!tbody) {
            return;
        }

        Array.prototype.forEach.call(headers, function (th, index) {
            th.classList.add('is-sortable');
            th.setAttribute('tabindex', '0');
            th.setAttribute('aria-sort', 'none');
            th.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); th.click(); }
            });
            th.addEventListener('click', function () {
                var numeric = th.getAttribute('data-type') === 'num';
                var ascending = th.getAttribute('data-dir') !== 'asc';

                Array.prototype.forEach.call(headers, function (header) {
                    header.removeAttribute('data-dir');
                    header.setAttribute('aria-sort', 'none');
                });
                th.setAttribute('data-dir', ascending ? 'asc' : 'desc');
                th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');

                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                rows.sort(function (a, b) {
                    var cellA = a.children[index];
                    var cellB = b.children[index];
                    var valueA = numeric ? parseFloat(cellA.getAttribute('data-v') || '0') : cellA.textContent.trim().toLowerCase();
                    var valueB = numeric ? parseFloat(cellB.getAttribute('data-v') || '0') : cellB.textContent.trim().toLowerCase();
                    if (valueA < valueB) { return ascending ? -1 : 1; }
                    if (valueA > valueB) { return ascending ? 1 : -1; }
                    return 0;
                });
                rows.forEach(function (row) { tbody.appendChild(row); });
            });
        });
    }

    function setLoading(panel) {
        if (analyticsChart) {
            analyticsChart.destroy();
            analyticsChart = null;
        }

        panel.setAttribute('aria-busy', 'true');
        panel.textContent = '';
        var loading = makeElement('div', 'ffla-dash-loading');
        loading.appendChild(makeElement('span', 'spinner is-active'));
        loading.appendChild(document.createTextNode(strings.loading || 'Loading analytics…'));
        panel.appendChild(loading);
    }

    function renderState(panel, message, actionUrl) {
        panel.setAttribute('aria-busy', 'false');
        panel.textContent = '';

        var state = makeElement('div', 'ffla-dash-state');
        state.appendChild(makeElement('span', 'ffla-dash-state__icon', '↗'));
        state.appendChild(makeElement('p', '', message || strings.loadError || 'Analytics could not be loaded.'));

        if (actionUrl) {
            var action = makeElement('a', 'button button-primary', strings.openSettings || 'Open settings');
            action.href = actionUrl;
            state.appendChild(action);
        }
        panel.appendChild(state);
    }

    function renderMetrics(parent, metrics) {
        var grid = makeElement('div', 'ffla-dash-analytics-metrics');

        (metrics || []).forEach(function (metric) {
            var card = makeElement('div', 'ffla-dash-analytics-metric');
            var top = makeElement('div', 'ffla-dash-analytics-metric__top');
            var label = makeElement('span', 'ffla-dash-analytics-metric__label', metric.label || '');
            if (metric.hint) {
                label.title = metric.hint;
            }
            top.appendChild(label);

            if (isNumber(metric.delta)) {
                var up = metric.delta >= 0;
                var suffix = 'points' === metric.deltaFormat ? ' pp' : '%';
                var delta = makeElement(
                    'span',
                    'ffla-dash-delta ' + ((metric.lowerIsBetter ? !up : up) ? 'is-up' : 'is-down'),
                    (up ? '↗ +' : '↘ ') + formatNumber(metric.delta, 1) + suffix
                );
                top.appendChild(delta);
            }

            card.appendChild(top);
            card.appendChild(makeElement('strong', 'ffla-dash-analytics-metric__value', formatMetric(metric.value, metric.format)));
            card.appendChild(makeElement('span', 'ffla-dash-analytics-metric__sub', isNumber(metric.delta) ? (strings.previousPeriod || 'vs. previous period') : (strings.noComparison || 'Previous-period comparison unavailable')));
            grid.appendChild(card);
        });

        parent.appendChild(grid);
    }

    function createPanel(label, title, extraClass) {
        var panel = makeElement('div', 'ffla-dash-panel' + (extraClass ? ' ' + extraClass : ''));
        panel.appendChild(makeElement('span', 'ffla-dash-panel__label', label));
        if (title) {
            panel.appendChild(makeElement('h4', 'ffla-dash-panel__title', title));
        }
        return panel;
    }

    function appendSortIndicator(th) {
        th.appendChild(makeElement('span', 'ffla-dash-sort'));
    }

    function renderGoogleChart(canvas, series) {
        if (typeof window.Chart === 'undefined' || !series.length) { return; }
        var muted = cssVar(canvas, '--ffla-dash-muted', '#94a3b8');
        var datasets = [
            ['sessions', strings.sessions || 'Sessions', cssVar(canvas, '--ffla-dash-accent', '#2563eb')],
            ['pageviews', strings.pageviews || 'Pageviews', '#8b5cf6']
        ].filter(function (spec) {
            return series.some(function (point) { return isNumber(point[spec[0]]); });
        }).map(function (spec) {
            return { label: spec[1], data: series.map(function (point) { return point[spec[0]]; }),
                borderColor: spec[2], backgroundColor: 'transparent', tension: 0.25, pointRadius: 2, spanGaps: false };
        });
        if (!datasets.length) { return; }
        analyticsChart = new window.Chart(canvas, {
            type: 'line',
            data: { labels: series.map(function (point) { return point.label; }), datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { color: muted, usePointStyle: true } } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: muted, maxRotation: 0, autoSkip: true, maxTicksLimit: 7 } },
                    y: { beginAtZero: true, ticks: { color: muted }, grid: { color: cssVar(canvas, '--ffla-dash-border', '#e6eaf1') } }
                }
            }
        });
    }

    function renderProviderTables(parent, tables) {
        (tables || []).forEach(function (data) {
            var formats = data.formats || [];
            var panel = createPanel('MonsterInsights', data.title || '', 'ffla-dash-panel--full');
            if (!Array.isArray(data.rows) || !data.rows.length) {
                panel.appendChild(makeElement('p', 'ffla-dash-empty', strings.noRows || 'No rows were returned for this period.'));
                parent.appendChild(panel);
                return;
            }
            var wrap = makeElement('div', 'ffla-dash-table-wrap');
            wrap.setAttribute('tabindex', '0');
            wrap.setAttribute('role', 'region');
            wrap.setAttribute('aria-label', data.title || 'Report table');
            var table = makeElement('table', 'ffla-dash-table');
            var thead = document.createElement('thead');
            var headings = document.createElement('tr');
            (data.columns || []).forEach(function (label, i) {
                var th = makeElement('th', '', label);
                th.setAttribute('scope', 'col');
                th.setAttribute('data-type', formats[i] === 'text' ? 'text' : 'num');
                appendSortIndicator(th);
                headings.appendChild(th);
            });
            thead.appendChild(headings);
            table.appendChild(thead);
            var tbody = document.createElement('tbody');
            data.rows.forEach(function (row) {
                var tr = document.createElement('tr');
                (data.columns || []).forEach(function (_, i) {
                    var value = row[i];
                    var text = formats[i] === 'text' ? (value || '—') : formatMetric(value, formats[i]);
                    var td = makeElement('td', '', text);
                    if (formats[i] !== 'text') { td.setAttribute('data-v', isNumber(value) ? String(value) : '-1'); }
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            wrap.appendChild(table);
            panel.appendChild(wrap);
            parent.appendChild(panel);
            makeSortable(table);
        });
    }

    function renderGoogle(parent, data) {
        var split = makeElement('div', 'ffla-dash-analytics-grid');
        var chartPanel = createPanel('MonsterInsights · Google Analytics', strings.trend || 'Traffic trend');
        var canvas = null;
        if (Array.isArray(data.series) && data.series.length) {
            var chartWrap = makeElement('div', 'ffla-dash-chart-wrap ffla-dash-chart-wrap--analytics');
            canvas = document.createElement('canvas');
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', strings.trend || 'Sessions and pageviews by date');
            chartWrap.appendChild(canvas);
            chartPanel.appendChild(chartWrap);
        } else {
            chartPanel.appendChild(makeElement('p', 'ffla-dash-empty', strings.noRows || 'No trend data is available.'));
        }
        split.appendChild(chartPanel);
        var summary = createPanel('MonsterInsights', strings.searchTraffic || 'All-channel website traffic', 'ffla-dash-analytics-summary');
        if (data.dates) {
            summary.appendChild(makeElement('p', 'ffla-dash-analytics-copy',
                data.dates.start + ' – ' + data.dates.end + ' · ' + (strings.throughYesterday || 'Complete days through yesterday')));
        }
        summary.appendChild(makeElement('p', 'ffla-dash-analytics-copy', data.note || ''));
        if (data.report_url) {
            var link = makeElement('a', 'ffla-dash-report-link', (strings.openReport || 'Open full report') + ' →');
            link.href = data.report_url;
            summary.appendChild(link);
        }
        split.appendChild(summary);
        parent.appendChild(split);
        if (canvas) { renderGoogleChart(canvas, data.series); }
        renderProviderTables(parent, data.tables || []);
        if (data.ecommerce) {
            var commerce = data.ecommerce;
            var commercePanel = createPanel('Google Analytics', strings.ecommerce || 'MonsterInsights eCommerce', 'ffla-dash-panel--full');
            if (commerce.status === 'ready') {
                renderMetrics(commercePanel, commerce.metrics || []);
                commercePanel.appendChild(makeElement('p', 'ffla-dash-analytics-copy',
                    (commerce.currency ? commerce.currency + ' · ' : '') +
                    (strings.currencyNote || 'Monetary values use the Google Analytics property currency, not necessarily the store currency.')));
                renderProviderTables(commercePanel, commerce.tables || []);
            } else {
                commercePanel.appendChild(makeElement('p', 'ffla-dash-empty', commerce.message || strings.noRows));
            }
            parent.appendChild(commercePanel);
        }
    }

    function renderFunnel(parent, data) {
        var panel = createPanel('SnapFind', strings.searchFunnel || 'On-site search funnel');
        var funnel = makeElement('ul', 'ffla-dash-funnel');
        var stages = data.funnel || [];
        var maximum = stages.length ? Math.max(1, Number(stages[0].value) || 0) : 1;

        stages.forEach(function (stage) {
            var row = makeElement('li', 'ffla-dash-funnel__row');
            var width = Math.max(8, Math.round((Number(stage.value) || 0) / maximum * 100));
            var bar = makeElement('span', 'ffla-dash-funnel__bar');
            bar.style.width = width + '%';
            bar.appendChild(makeElement('strong', '', formatNumber(Number(stage.value) || 0, 0)));
            row.appendChild(bar);
            row.appendChild(makeElement('span', 'ffla-dash-funnel__label', stage.label || ''));
            funnel.appendChild(row);
        });
        panel.appendChild(funnel);
        parent.appendChild(panel);
    }

    function renderSearchTerms(parent, rows) {
        var panel = createPanel('SnapFind', strings.topSearchTerms || 'Top search terms');
        if (!rows || !rows.length) {
            panel.appendChild(makeElement('p', 'ffla-dash-empty', strings.noSearchTerms || 'No search terms to show.'));
            parent.appendChild(panel);
            return;
        }

        var table = makeElement('table', 'ffla-dash-table');
        table.setAttribute('data-ffla-sortable', '');
        var thead = document.createElement('thead');
        var headerRow = document.createElement('tr');
        [
            [strings.searchTerm || 'Search term', 'text'],
            [strings.searches || 'Searches', 'num'],
            [strings.productClicks || 'Product clicks', 'num'],
            [strings.ctr || 'CTR', 'num']
        ].forEach(function (heading) {
            var th = makeElement('th', '', heading[0]);
            th.setAttribute('data-type', heading[1]);
            appendSortIndicator(th);
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);

        var tbody = document.createElement('tbody');
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            tr.appendChild(makeElement('td', '', row.term || '—'));
            [
                [Number(row.searches) || 0, formatNumber(Number(row.searches) || 0, 0)],
                [Number(row.clicks) || 0, formatNumber(Number(row.clicks) || 0, 0)],
                [Number(row.ctr) || 0, formatNumber(Number(row.ctr) || 0, 2) + '%']
            ].forEach(function (cellData) {
                var td = makeElement('td', '', cellData[1]);
                td.setAttribute('data-v', String(cellData[0]));
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        panel.appendChild(table);
        parent.appendChild(panel);
        makeSortable(table);
    }

    function renderSnapFind(parent, data) {
        var split = makeElement('div', 'ffla-dash-analytics-grid');
        renderFunnel(split, data);
        renderSearchTerms(split, data.top_terms || []);
        parent.appendChild(split);
    }

    function renderAnalytics(panel, data) {
        if (analyticsChart) {
            analyticsChart.destroy();
            analyticsChart = null;
        }

        panel.setAttribute('aria-busy', 'false');
        panel.textContent = '';

        if (!data || 'unavailable' === data.status) {
            renderState(panel, data && data.message, data && data.action_url);
            return;
        }
        if ('no_data' === data.status) {
            renderState(panel, data.message, data.action_url);
            return;
        }

        renderMetrics(panel, data.metrics || []);
        if ('snapfind' === data.source) {
            renderSnapFind(panel, data);
        } else {
            renderGoogle(panel, data);
        }
    }

    function loadAnalytics(root, source, range, force) {
        var panel = root.querySelector('[data-ffla-analytics-panel]');
        var cacheKey = source + ':' + range;
        var currentRequest = ++requestSequence;
        if (!panel) {
            return;
        }

        if (!force && responseCache[cacheKey] && Date.now() - responseCache[cacheKey].time < 600000) {
            renderAnalytics(panel, responseCache[cacheKey].data);
            return;
        }

        setLoading(panel);
        var body = new URLSearchParams();
        body.set('action', config.action || 'ffla_wl_dashboard_analytics');
        body.set('nonce', config.nonce || '');
        body.set('source', source);
        body.set('range', String(range));
        if (force) {
            body.set('force', '1');
        }

        var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        var timeout = controller ? window.setTimeout(function () { controller.abort(); }, 45000) : null;
        window.fetch(config.ajaxUrl || window.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            signal: controller ? controller.signal : undefined
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (response) {
                if (currentRequest !== requestSequence) {
                    return;
                }
                if (!response || !response.success || !response.data) {
                    throw new Error('Invalid analytics response');
                }
                if (response.data.status === 'ready') {
                    responseCache[cacheKey] = { data: response.data, time: Date.now() };
                }
                renderAnalytics(panel, response.data);
            })
            .catch(function () {
                if (currentRequest === requestSequence) {
                    renderState(panel, strings.loadError || 'Analytics could not be loaded.');
                }
            })
            .finally(function () { if (timeout !== null) { window.clearTimeout(timeout); } });
    }

    function activateTab(root, source, shouldFocus) {
        Array.prototype.forEach.call(root.querySelectorAll('[data-ffla-source]'), function (button) {
            var active = button.getAttribute('data-ffla-source') === source;
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.setAttribute('tabindex', active ? '0' : '-1');
            if (active && shouldFocus) {
                button.focus();
            }
        });

        var panel = root.querySelector('[data-ffla-analytics-panel]');
        if (panel) {
            panel.setAttribute('aria-labelledby', 'ffla-dashboard-tab-' + source);
        }
    }

    function initAnalytics() {
        var root = document.querySelector('[data-ffla-analytics]');
        if (!root || !config.ajaxUrl) {
            return;
        }

        var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-ffla-source]'));
        var rangeSelect = root.querySelector('[data-ffla-analytics-range]');
        var source = ['google', 'snapfind'].indexOf(config.initialSource) >= 0 ? config.initialSource : 'google';
        var range = [7, 30, 90].indexOf(Number(config.initialRange)) >= 0 ? Number(config.initialRange) : 30;

        activateTab(root, source, false);
        if (rangeSelect) {
            rangeSelect.value = String(range);
        }
        loadAnalytics(root, source, range, Boolean(config.forceRefresh));

        tabs.forEach(function (button, index) {
            button.addEventListener('click', function () {
                source = button.getAttribute('data-ffla-source') || 'google';
                activateTab(root, source, false);
                loadAnalytics(root, source, range, false);
            });
            button.addEventListener('keydown', function (event) {
                if ('ArrowRight' !== event.key && 'ArrowLeft' !== event.key) {
                    return;
                }
                event.preventDefault();
                var offset = 'ArrowRight' === event.key ? 1 : -1;
                var next = (index + offset + tabs.length) % tabs.length;
                source = tabs[next].getAttribute('data-ffla-source') || 'google';
                activateTab(root, source, true);
                loadAnalytics(root, source, range, false);
            });
        });

        if (rangeSelect) {
            rangeSelect.addEventListener('change', function () {
                range = Number(rangeSelect.value) || 30;
                loadAnalytics(root, source, range, false);
            });
        }
    }

    function init() {
        renderSalesChart();
        Array.prototype.forEach.call(document.querySelectorAll('[data-ffla-sortable]'), makeSortable);
        initAnalytics();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
