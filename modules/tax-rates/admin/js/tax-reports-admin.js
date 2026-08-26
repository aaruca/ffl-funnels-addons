/**
 * Sales Tax Reports admin progressive enhancements.
 *
 * The report remains usable when JavaScript is unavailable. This file only
 * adds navigation, convenience controls and client-side exploration of data
 * already rendered by WordPress.
 */
(function () {
    'use strict';

    var config = window.fflaTaxReportsAdmin || {};
    var strings = Object.assign({
        advancedFilters: 'Advanced filters',
        copied: 'Copied',
        copyFailed: 'Could not copy',
        copyValue: 'Copy {label}: {value}',
        custom: 'Custom',
        dropFiles: 'Drop files here or choose files',
        dropUnsupported: 'Your browser could not attach the dropped files. Choose them with the file picker.',
        filesSelected: '{count} file(s) selected: {files}',
        nexusProgress: '{value}% of threshold',
        noMatchingRows: 'No matching rows.',
        previousMonth: 'Previous month',
        previousQuarter: 'Previous quarter',
        priorYear: 'Prior year',
        reportPeriod: 'Report period',
        rowsShown: '{shown} of {total} rows shown',
        searchStates: 'Search states…',
        searchTable: 'Search this table',
        sortAscending: 'Sort ascending',
        sortDescending: 'Sort descending',
        yearToDate: 'Year to date'
    }, config.i18n || {});
    var idCounter = 0;

    function format(template, replacements) {
        return Object.keys(replacements || {}).reduce(function (result, key) {
            return result.replace(new RegExp('\\{' + key + '\\}', 'g'), replacements[key]);
        }, template);
    }

    function uniqueId(prefix) {
        idCounter += 1;
        return prefix + '-' + idCounter;
    }

    function normalize(value) {
        var normalized = String(value || '').trim().toLowerCase();

        if (typeof normalized.normalize === 'function') {
            normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        return normalized;
    }

    function escapeSelector(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }

        return String(value).replace(/[^a-zA-Z0-9_-]/g, function (character) {
            return '\\' + character;
        });
    }

    function dispatchValueChange(input, value) {
        if (!input) {
            return;
        }

        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function formatDate(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');

        return year + '-' + month + '-' + day;
    }

    function getPeriodDates(period, today) {
        var start;
        var end;
        var currentQuarterStart;

        today = new Date(today.getFullYear(), today.getMonth(), today.getDate());

        switch (period) {
            case 'previous-month':
                end = new Date(today.getFullYear(), today.getMonth(), 0);
                start = new Date(end.getFullYear(), end.getMonth(), 1);
                break;

            case 'previous-quarter':
                currentQuarterStart = new Date(
                    today.getFullYear(),
                    Math.floor(today.getMonth() / 3) * 3,
                    1
                );
                end = new Date(currentQuarterStart.getFullYear(), currentQuarterStart.getMonth(), 0);
                start = new Date(end.getFullYear(), Math.floor(end.getMonth() / 3) * 3, 1);
                break;

            case 'ytd':
                start = new Date(today.getFullYear(), 0, 1);
                end = today;
                break;

            case 'prior-year':
                start = new Date(today.getFullYear() - 1, 0, 1);
                end = new Date(today.getFullYear() - 1, 11, 31);
                break;

            default:
                return null;
        }

        return {
            start: formatDate(start),
            end: formatDate(end)
        };
    }

    function setPressedButton(buttons, activeButton) {
        buttons.forEach(function (button) {
            var isActive = button === activeButton;
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            button.classList.toggle('is-active', isActive);
        });
    }

    function createPeriodPresets(form, startInput, endInput) {
        var existing = form.querySelector('.ffla-tax-report-period-presets, [data-tax-period-presets]');

        if (existing) {
            return existing;
        }

        var presets = document.createElement('div');
        var options = [
            ['previous-month', strings.previousMonth],
            ['previous-quarter', strings.previousQuarter],
            ['ytd', strings.yearToDate],
            ['prior-year', strings.priorYear],
            ['custom', strings.custom]
        ];

        presets.className = 'ffla-tax-report-period-presets';
        presets.setAttribute('data-tax-period-presets', '');
        presets.setAttribute('role', 'group');
        presets.setAttribute('aria-label', strings.reportPeriod);

        options.forEach(function (option) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ffla-tax-report-period-button';
            button.setAttribute('data-tax-period', option[0]);
            button.setAttribute('aria-pressed', 'false');
            button.textContent = option[1];
            presets.appendChild(button);
        });

        var grid = startInput.closest('.ffla-tax-report-filter-grid') || startInput.parentElement;
        grid.parentNode.insertBefore(presets, grid);

        return presets;
    }

    function initPeriodPresets(scope) {
        scope.querySelectorAll('form.ffla-tax-report-filters, form[data-tax-report-filters]').forEach(function (form) {
            var startInput = form.querySelector('input[type="date"][name="date_from"], [data-tax-date-from]');
            var endInput = form.querySelector('input[type="date"][name="date_to"], [data-tax-date-to]');
            var presetSelect = form.querySelector('[data-ffla-period-preset], select[name="period_preset"]');
            var stateFilter = form.querySelector('[data-ffla-state-filter], select[name="states[]"]');
            var presetField;
            var stateField;

            if (!startInput || !endInput || form.dataset.periodPresetsReady === 'true') {
                return;
            }

            var container = createPeriodPresets(form, startInput, endInput);
            var buttons = Array.from(container.querySelectorAll('[data-tax-period]'));

            presetField = presetSelect ? presetSelect.closest('label') : null;
            stateField = stateFilter ? stateFilter.closest('label') : null;
            if (presetField) {
                presetField.classList.add('ffla-tax-report-preset-field--fallback');
            }
            if (stateField) {
                stateField.classList.add('ffla-tax-report-state-field');
            }

            container.addEventListener('click', function (event) {
                var button = event.target.closest('[data-tax-period]');
                var period;
                var dates;

                if (!button || !container.contains(button)) {
                    return;
                }

                period = button.getAttribute('data-tax-period');
                setPressedButton(buttons, button);
                if (presetSelect) {
                    dispatchValueChange(presetSelect, {
                        'previous-month': 'previous_month',
                        'previous-quarter': 'previous_quarter',
                        'ytd': 'year_to_date',
                        'prior-year': 'previous_year',
                        'custom': 'custom'
                    }[period] || 'custom');
                }

                if (period === 'custom') {
                    startInput.focus();
                    return;
                }

                dates = getPeriodDates(period, new Date());
                if (!dates) {
                    return;
                }

                dispatchValueChange(startInput, dates.start);
                dispatchValueChange(endInput, dates.end);
            });

            [startInput, endInput].forEach(function (input) {
                input.addEventListener('input', function (event) {
                    if (!event.isTrusted) {
                        return;
                    }

                    var customButton = container.querySelector('[data-tax-period="custom"]');
                    if (customButton) {
                        setPressedButton(buttons, customButton);
                    }
                    if (presetSelect) {
                        presetSelect.value = 'custom';
                    }
                });
            });

            if (presetSelect) {
                presetSelect.addEventListener('change', function () {
                    var period = {
                        'previous_month': 'previous-month',
                        'previous_quarter': 'previous-quarter',
                        'year_to_date': 'ytd',
                        'previous_year': 'prior-year'
                    }[presetSelect.value];
                    var dates = period ? getPeriodDates(period, new Date()) : null;
                    var button = period ? container.querySelector('[data-tax-period="' + period + '"]') : container.querySelector('[data-tax-period="custom"]');

                    if (button) {
                        setPressedButton(buttons, button);
                    }
                    if (dates) {
                        dispatchValueChange(startInput, dates.start);
                        dispatchValueChange(endInput, dates.end);
                    }
                });
            }

            var matchingButton = buttons.find(function (button) {
                var period = button.getAttribute('data-tax-period');
                var dates = getPeriodDates(period, new Date());
                return dates && dates.start === startInput.value && dates.end === endInput.value;
            });
            setPressedButton(buttons, matchingButton || container.querySelector('[data-tax-period="custom"]'));

            form.dataset.periodPresetsReady = 'true';
        });
    }

    function initStateSearch(scope) {
        var $ = window.jQuery;

        if (!$ || !$.fn) {
            return;
        }

        scope.querySelectorAll('[data-ffla-state-filter]').forEach(function (select) {
            var $select = $(select);
            var method;

            if (select.dataset.stateSearchReady === 'true') {
                return;
            }

            method = typeof $.fn.selectWoo === 'function' ? 'selectWoo' :
                (typeof $.fn.select2 === 'function' ? 'select2' : '');

            if (!method) {
                return;
            }

            if (!$select.hasClass('select2-hidden-accessible')) {
                $select[method]({
                    allowClear: true,
                    closeOnSelect: false,
                    placeholder: select.getAttribute('data-placeholder') || strings.searchStates,
                    width: '100%'
                });
            }

            select.dataset.stateSearchReady = 'true';
        });
    }

    function resolveTabPanel(tab, tabSet) {
        var target = tab.getAttribute('aria-controls') || tab.getAttribute('data-tax-report-tab');

        if (!target) {
            return null;
        }

        target = target.replace(/^#/, '');
        return tabSet.querySelector('#' + escapeSelector(target)) ||
            document.getElementById(target) ||
            tabSet.querySelector('[data-tax-report-panel="' + escapeSelector(target) + '"]');
    }

    function activateTab(tab, tabs, panels, moveFocus, updateHash) {
        var activePanel = resolveTabPanel(tab, tab.closest('[data-tax-report-tabs], .ffla-tax-report-tab-layout'));

        tabs.forEach(function (candidate) {
            var isActive = candidate === tab;
            candidate.setAttribute('aria-selected', isActive ? 'true' : 'false');
            candidate.setAttribute('tabindex', isActive ? '0' : '-1');
            candidate.classList.toggle('is-active', isActive);
        });

        panels.forEach(function (panel) {
            var isActive = panel === activePanel;
            panel.hidden = !isActive;
            panel.classList.toggle('is-active', isActive);
        });

        if (moveFocus) {
            tab.focus();
        }

        if (updateHash && activePanel && activePanel.id && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + activePanel.id);
        }
    }

    function initTabs(scope) {
        scope.querySelectorAll('[data-tax-report-tabs], .ffla-tax-report-tab-layout').forEach(function (tabSet) {
            var tabList = tabSet.querySelector('[role="tablist"], .ffla-tax-report-tabs');
            var tabs;
            var panels;
            var initial;

            if (!tabList || tabSet.dataset.tabsReady === 'true') {
                return;
            }

            tabList.setAttribute('role', 'tablist');
            tabs = Array.from(tabList.querySelectorAll('[role="tab"], [data-tax-report-tab]'));
            panels = Array.from(tabSet.querySelectorAll('[role="tabpanel"], [data-tax-report-panel]'));

            if (!tabs.length || !panels.length) {
                return;
            }

            tabs.forEach(function (tab) {
                var panel = resolveTabPanel(tab, tabSet);

                tab.setAttribute('role', 'tab');
                if (!tab.id) {
                    tab.id = uniqueId('ffla-tax-report-tab');
                }

                if (panel) {
                    if (!panel.id) {
                        panel.id = uniqueId('ffla-tax-report-panel');
                    }
                    tab.setAttribute('aria-controls', panel.id);
                    panel.setAttribute('role', 'tabpanel');
                    panel.setAttribute('aria-labelledby', tab.id);
                    panel.setAttribute('tabindex', '0');
                }
            });

            initial = tabs.find(function (tab) {
                var panel = resolveTabPanel(tab, tabSet);
                return panel && window.location.hash === '#' + panel.id;
            }) || tabs.find(function (tab) {
                return tab.getAttribute('aria-selected') === 'true' || tab.classList.contains('is-active');
            }) || tabs[0];

            activateTab(initial, tabs, panels, false, false);

            tabList.addEventListener('click', function (event) {
                var tab = event.target.closest('[role="tab"]');
                if (tab && tabList.contains(tab)) {
                    event.preventDefault();
                    activateTab(tab, tabs, panels, false, true);
                }
            });

            tabList.addEventListener('keydown', function (event) {
                var current = event.target.closest('[role="tab"]');
                var index;
                var nextIndex;

                if (!current) {
                    return;
                }

                index = tabs.indexOf(current);
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    nextIndex = (index + 1) % tabs.length;
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    nextIndex = (index - 1 + tabs.length) % tabs.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = tabs.length - 1;
                } else {
                    return;
                }

                event.preventDefault();
                activateTab(tabs[nextIndex], tabs, panels, true, true);
            });

            tabSet.dataset.tabsReady = 'true';
        });
    }

    function fallbackCopy(value) {
        var textarea = document.createElement('textarea');
        var copied;

        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        copied = document.execCommand('copy');
        textarea.remove();

        return copied ? Promise.resolve() : Promise.reject(new Error('Copy failed'));
    }

    function copyText(value) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(value);
        }

        return fallbackCopy(value);
    }

    function initCopyableKpis(scope) {
        scope.querySelectorAll('.ffla-tax-report-kpi, [data-copy-kpi]').forEach(function (card) {
            var valueElement = card.querySelector('[data-kpi-value], strong');
            var labelElement = card.querySelector('[data-kpi-label], span');
            var status;
            var resetTimer;

            if (!valueElement || card.dataset.copyReady === 'true') {
                return;
            }

            status = document.createElement('span');
            status.className = 'ffla-tax-report-copy-status';
            status.setAttribute('aria-live', 'polite');
            status.setAttribute('aria-atomic', 'true');
            card.appendChild(status);

            if (!card.matches('button, a, input')) {
                card.setAttribute('role', 'button');
                card.setAttribute('tabindex', '0');
            }

            card.setAttribute('aria-label', format(strings.copyValue, {
                label: labelElement ? labelElement.textContent.trim() : 'value',
                value: valueElement.textContent.trim()
            }));

            function performCopy() {
                var value = card.getAttribute('data-copy-value') || valueElement.textContent.trim();

                window.clearTimeout(resetTimer);
                copyText(value).then(function () {
                    status.textContent = strings.copied;
                    card.classList.add('is-copied');
                    resetTimer = window.setTimeout(function () {
                        status.textContent = '';
                        card.classList.remove('is-copied');
                    }, 2000);
                }).catch(function () {
                    status.textContent = strings.copyFailed;
                    card.classList.add('has-copy-error');
                    resetTimer = window.setTimeout(function () {
                        status.textContent = '';
                        card.classList.remove('has-copy-error');
                    }, 3000);
                });
            }

            card.addEventListener('click', performCopy);
            card.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    performCopy();
                }
            });

            card.dataset.copyReady = 'true';
        });
    }

    function getCellSortValue(row, index) {
        var cell = row.cells[index];
        return cell ? (cell.getAttribute('data-sort-value') || cell.textContent.trim()) : '';
    }

    function sortableValue(value) {
        var trimmed = String(value || '').trim();
        var numeric;

        if (/^\d{4}-\d{2}-\d{2}(?:[ T].*)?$/.test(trimmed)) {
            return { type: 'date', value: Date.parse(trimmed.replace(' ', 'T')) || 0 };
        }

        if (/^\(?[-+]?[$£€]?\s*[\d,.]+\s*%?\)?$/.test(trimmed)) {
            numeric = Number(trimmed.replace(/[^\d.-]/g, ''));
            if (trimmed.charAt(0) === '(') {
                numeric *= -1;
            }
            if (!Number.isNaN(numeric)) {
                return { type: 'number', value: numeric };
            }
        }

        return { type: 'text', value: normalize(trimmed) };
    }

    function compareValues(left, right, collator) {
        if (left.type === right.type && left.type !== 'text') {
            return left.value - right.value;
        }

        return collator.compare(String(left.value), String(right.value));
    }

    function getDataRows(table) {
        return Array.from(table.tBodies).reduce(function (rows, body) {
            return rows.concat(Array.from(body.rows));
        }, []).filter(function (row) {
            return !row.querySelector('td[colspan]') && !row.classList.contains('ffla-tax-report-empty-row');
        });
    }

    function createTableToolbar(table) {
        var wrapper = table.closest('.ffla-tax-report-table-wrap') || table.parentElement;
        var toolbar = wrapper.querySelector(':scope > .ffla-tax-report-table-tools');
        var label;
        var screenReaderText;
        var search;
        var count;

        if (toolbar) {
            return toolbar;
        }

        toolbar = document.createElement('div');
        toolbar.className = 'ffla-tax-report-table-tools';
        label = document.createElement('label');
        label.className = 'ffla-tax-report-search';
        screenReaderText = document.createElement('span');
        screenReaderText.className = 'screen-reader-text';
        screenReaderText.textContent = strings.searchTable;
        search = document.createElement('input');
        search.type = 'search';
        search.className = 'regular-text';
        search.placeholder = strings.searchTable;
        count = document.createElement('span');
        count.className = 'ffla-tax-report-row-count';
        count.setAttribute('aria-live', 'polite');
        label.appendChild(screenReaderText);
        label.appendChild(search);
        toolbar.appendChild(label);
        toolbar.appendChild(count);
        wrapper.insertBefore(toolbar, table);

        return toolbar;
    }

    function initReportTable(table) {
        var rows;
        var toolbar;
        var search;
        var count;
        var collator;
        var headings;
        var emptyRow;

        if (table.dataset.tableReady === 'true') {
            return;
        }

        table.classList.add('ffla-tax-report-table');

        rows = getDataRows(table);
        if (!rows.length) {
            table.dataset.tableReady = 'true';
            return;
        }

        rows.forEach(function (row, index) {
            row.dataset.originalIndex = String(index);
            row.dataset.searchText = normalize(row.textContent);
        });

        toolbar = createTableToolbar(table);
        search = toolbar.querySelector('input[type="search"]');
        count = toolbar.querySelector('.ffla-tax-report-row-count');
        collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
        headings = Array.from(table.tHead ? table.tHead.rows[table.tHead.rows.length - 1].cells : []);
        emptyRow = document.createElement('tr');
        emptyRow.className = 'ffla-tax-report-empty-row';
        emptyRow.hidden = true;
        var emptyCell = document.createElement('td');
        emptyCell.colSpan = Math.max(1, headings.length);
        emptyCell.textContent = strings.noMatchingRows;
        emptyRow.appendChild(emptyCell);
        table.tBodies[0].appendChild(emptyRow);

        function filterRows() {
            var query = normalize(search.value);
            var shown = 0;

            rows.forEach(function (row) {
                var matches = !query || row.dataset.searchText.indexOf(query) !== -1;
                row.hidden = !matches;
                row.setAttribute('aria-hidden', matches ? 'false' : 'true');
                if (matches) {
                    shown += 1;
                }
            });

            emptyRow.hidden = shown !== 0;
            count.textContent = format(strings.rowsShown, { shown: shown, total: rows.length });
        }

        search.addEventListener('input', filterRows);
        filterRows();

        headings.forEach(function (heading, columnIndex) {
            var text = heading.textContent.trim();
            var button;

            if (heading.getAttribute('data-sortable') === 'false' || !text) {
                return;
            }

            button = document.createElement('button');
            button.type = 'button';
            button.className = 'ffla-tax-report-sort';
            button.textContent = text;
            button.setAttribute('aria-label', text + ': ' + strings.sortAscending);
            heading.textContent = '';
            heading.appendChild(button);
            heading.setAttribute('aria-sort', 'none');

            button.addEventListener('click', function () {
                var descending = heading.getAttribute('aria-sort') === 'ascending';
                var direction = descending ? -1 : 1;
                var sortedRows = rows.slice().sort(function (leftRow, rightRow) {
                    var left = sortableValue(getCellSortValue(leftRow, columnIndex));
                    var right = sortableValue(getCellSortValue(rightRow, columnIndex));
                    var result = compareValues(left, right, collator);

                    if (result === 0) {
                        result = Number(leftRow.dataset.originalIndex) - Number(rightRow.dataset.originalIndex);
                    }

                    return result * direction;
                });

                headings.forEach(function (candidate) {
                    candidate.setAttribute('aria-sort', 'none');
                    candidate.classList.remove('is-sorted');
                });
                heading.setAttribute('aria-sort', descending ? 'descending' : 'ascending');
                heading.classList.add('is-sorted');
                button.setAttribute('aria-label', text + ': ' + (descending ? strings.sortAscending : strings.sortDescending));

                sortedRows.forEach(function (row) {
                    row.parentNode.appendChild(row);
                });
                emptyRow.parentNode.appendChild(emptyRow);
            });
        });

        table.dataset.tableReady = 'true';
    }

    function initTables(scope) {
        scope.querySelectorAll('.ffla-tax-report-table, .ffla-tax-report-table-wrap > table, table[data-tax-report-table]').forEach(initReportTable);
    }

    function createAdvancedFilterDisclosure(form) {
        var elements = Array.from(form.querySelectorAll(
            ':scope > .wb-card__body > .ffla-tax-report-statuses, ' +
            ':scope > .wb-card__body > .ffla-tax-report-options'
        ));
        var panel;
        var button;
        var shouldExpand;

        if (!elements.length || form.querySelector('[data-tax-report-advanced]')) {
            return;
        }

        panel = document.createElement('div');
        panel.className = 'ffla-tax-report-advanced-panel';
        panel.setAttribute('data-tax-report-advanced', '');
        panel.id = uniqueId('ffla-tax-report-advanced');
        elements[0].parentNode.insertBefore(panel, elements[0]);
        elements.forEach(function (element) {
            panel.appendChild(element);
        });

        button = document.createElement('button');
        button.type = 'button';
        button.className = 'ffla-tax-report-disclosure';
        button.setAttribute('data-tax-report-disclosure', '');
        button.setAttribute('aria-controls', panel.id);
        shouldExpand = Boolean(
            panel.querySelector('select[name="report_detail"] option[value="advanced"]:checked') ||
            panel.querySelector('input[name="include_negative_orders"]:checked')
        );
        button.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
        button.textContent = strings.advancedFilters;
        panel.parentNode.insertBefore(button, panel);
        panel.hidden = !shouldExpand;
        panel.classList.toggle('is-open', shouldExpand);
    }

    function initDisclosures(scope) {
        scope.querySelectorAll('form.ffla-tax-report-filters').forEach(createAdvancedFilterDisclosure);

        scope.querySelectorAll('[data-tax-report-disclosure]').forEach(function (button) {
            var panelId = button.getAttribute('aria-controls');
            var panel = panelId ? document.getElementById(panelId) : null;

            if (!panel || button.dataset.disclosureReady === 'true') {
                return;
            }

            button.addEventListener('click', function () {
                var expanded = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                panel.hidden = expanded;
                panel.classList.toggle('is-open', !expanded);
            });

            button.dataset.disclosureReady = 'true';
        });
    }

    function statusTone(status) {
        var value = normalize(status).replace(/[_-]+/g, ' ');

        if (/pass|ready|success|complete|completed|sent|healthy|matched|reconciled/.test(value)) {
            return 'success';
        }
        if (/warn|review|warning|approaching|pending|partial|retry/.test(value)) {
            return 'warning';
        }
        if (/fail|error|failed|failure|exceeded|under collected|critical|unhealthy/.test(value)) {
            return 'danger';
        }
        if (/info|processing|running|scheduled/.test(value)) {
            return 'info';
        }
        return 'neutral';
    }

    function decorateStatusElement(element, status) {
        var tone = statusTone(status);
        element.classList.add('ffla-tax-report-badge', 'ffla-tax-report-badge--' + tone);
        element.setAttribute('data-status-tone', tone);
    }

    function initStatusBadges(scope) {
        scope.querySelectorAll('[data-tax-status-badge], .ffla-tax-report-status').forEach(function (element) {
            decorateStatusElement(
                element,
                element.getAttribute('data-tax-status-badge') || element.getAttribute('data-status') || element.textContent
            );
        });

        scope.querySelectorAll('.ffla-tax-report-table').forEach(function (table) {
            var headings = Array.from(table.tHead ? table.tHead.rows[table.tHead.rows.length - 1].cells : []);
            var statusColumns = [];

            headings.forEach(function (heading, index) {
                var label = normalize(heading.textContent);
                if (/status|severity|health/.test(label)) {
                    statusColumns.push(index);
                }
            });

            getDataRows(table).forEach(function (row) {
                statusColumns.forEach(function (columnIndex) {
                    var cell = row.cells[columnIndex];
                    var target;

                    if (!cell || cell.querySelector('.ffla-tax-report-badge')) {
                        return;
                    }

                    target = cell.firstElementChild;
                    if (!target || !target.matches('code, span, strong')) {
                        target = document.createElement('span');
                        target.textContent = cell.textContent.trim();
                        cell.textContent = '';
                        cell.appendChild(target);
                    }
                    decorateStatusElement(target, target.textContent);
                });
            });
        });
    }

    function initHealthCards(scope) {
        scope.querySelectorAll('.ffla-tax-report-health-card, .ffla-tax-report-reconciliation-card, [data-tax-health]').forEach(function (card) {
            var status = card.getAttribute('data-tax-health') || card.getAttribute('data-status');
            var tone;
            if (status) {
                tone = statusTone(status);
                card.classList.add('ffla-tax-report-health-card--' + tone);
                card.setAttribute('data-status-tone', tone);
            }
        });
    }

    function initNexusProgress(scope) {
        scope.querySelectorAll('[data-nexus-progress], .ffla-tax-report-nexus-progress').forEach(function (element) {
            var rawValue = element.getAttribute('data-nexus-progress') || element.getAttribute('value') || '0';
            var actualValue = Math.max(0, Number(rawValue) || 0);
            var value = Math.min(100, actualValue);
            var bar = element.matches('progress') ? element : element.querySelector('progress, .ffla-tax-report-progress__bar');

            if (element.matches('progress')) {
                element.max = 100;
                element.value = value;
                element.setAttribute('aria-label', format(strings.nexusProgress, { value: actualValue }));
                element.setAttribute('aria-valuetext', format(strings.nexusProgress, { value: actualValue }));
                return;
            }

            element.setAttribute('role', 'progressbar');
            element.setAttribute('aria-valuemin', '0');
            element.setAttribute('aria-valuemax', '100');
            element.setAttribute('aria-valuenow', String(value));
            element.setAttribute('aria-label', format(strings.nexusProgress, { value: actualValue }));
            element.setAttribute('aria-valuetext', format(strings.nexusProgress, { value: actualValue }));
            if (bar) {
                bar.style.width = value + '%';
            }
        });
    }

    function acceptedFiles(input, files) {
        var accept = input.accept;
        var rules;

        if (!accept) {
            return files;
        }

        rules = accept.split(',').map(function (rule) {
            return rule.trim().toLowerCase();
        });

        return files.filter(function (file) {
            var name = file.name.toLowerCase();
            var type = file.type.toLowerCase();
            return rules.some(function (rule) {
                if (rule.charAt(0) === '.') {
                    return name.endsWith(rule);
                }
                if (rule.endsWith('/*')) {
                    return type.indexOf(rule.slice(0, -1)) === 0;
                }
                return type === rule;
            });
        });
    }

    function initDropZones(scope) {
        scope.querySelectorAll('.ffla-tax-report-dropzone, [data-tax-report-dropzone]').forEach(function (zone) {
            var input = zone.querySelector('input[type="file"]');
            var status = zone.querySelector('[data-file-status], .ffla-tax-report-file-status');
            var dragDepth = 0;

            if (!input || zone.dataset.dropzoneReady === 'true') {
                return;
            }

            if (!status) {
                status = document.createElement('span');
                status.className = 'ffla-tax-report-file-status';
                status.setAttribute('data-file-status', '');
                zone.appendChild(status);
            }

            status.setAttribute('aria-live', 'polite');
            if (zone.tagName.toLowerCase() !== 'label') {
                zone.setAttribute('tabindex', '0');
                zone.setAttribute('role', 'button');
                zone.setAttribute('aria-label', zone.getAttribute('aria-label') || strings.dropFiles);
            }

            function announce(files) {
                var names = Array.from(files || []).map(function (file) {
                    return file.name;
                });
                status.textContent = names.length ? format(strings.filesSelected, {
                    count: names.length,
                    files: names.join(', ')
                }) : '';
                zone.classList.toggle('has-files', names.length > 0);
            }

            zone.addEventListener('click', function (event) {
                if (event.target !== input && !event.target.closest('label, button, a')) {
                    input.click();
                }
            });
            if (zone.tagName.toLowerCase() !== 'label') {
                zone.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        input.click();
                    }
                });
            }
            zone.addEventListener('dragenter', function (event) {
                event.preventDefault();
                dragDepth += 1;
                zone.classList.add('is-dragover');
            });
            zone.addEventListener('dragover', function (event) {
                event.preventDefault();
                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'copy';
                }
            });
            zone.addEventListener('dragleave', function () {
                dragDepth -= 1;
                if (dragDepth <= 0) {
                    dragDepth = 0;
                    zone.classList.remove('is-dragover');
                }
            });
            zone.addEventListener('drop', function (event) {
                var files;
                var transfer;
                var assigned = false;

                event.preventDefault();
                dragDepth = 0;
                zone.classList.remove('is-dragover');
                files = acceptedFiles(input, Array.from(event.dataTransfer.files || []));

                if (!files.length) {
                    announce([]);
                    return;
                }

                try {
                    transfer = new DataTransfer();
                    files.slice(0, input.multiple ? files.length : 1).forEach(function (file) {
                        transfer.items.add(file);
                    });
                    input.files = transfer.files;
                    assigned = input.files.length > 0;
                } catch (error) {
                    assigned = false;
                }

                if (!assigned) {
                    status.textContent = strings.dropUnsupported;
                    zone.classList.remove('has-files');
                    input.focus();
                    return;
                }

                announce(input.files);
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
            input.addEventListener('change', function () {
                announce(input.files);
            });

            announce(input.files);
            zone.dataset.dropzoneReady = 'true';
        });
    }

    function init(scope) {
        initTabs(scope);
        initStateSearch(scope);
        initPeriodPresets(scope);
        initDisclosures(scope);
        initCopyableKpis(scope);
        initTables(scope);
        initStatusBadges(scope);
        initHealthCards(scope);
        initNexusProgress(scope);
        initDropZones(scope);
        document.documentElement.classList.add('ffla-tax-reports-enhanced');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
}());
