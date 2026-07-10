(function () {
    'use strict';

    var cfg = window.fflaMediaCleaner;
    if (!cfg) {
        return;
    }

    var i18n = cfg.i18n || {};
    var state = { status: 'active', page: 1, search: '', scanning: false, searchTimer: null, total: 0 };

    function $(id) {
        return document.getElementById(id);
    }

    function post(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', cfg.nonce);
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function (v) { body.append(key + '[]', v); });
            } else {
                body.append(key, value);
            }
        });

        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); });
    }

    /* ---------------------------------------------------------------- Scan */

    function setScanning(on) {
        state.scanning = on;
        var scanBtn = $('ffla-mclean-scan-btn');
        var abortBtn = $('ffla-mclean-abort-btn');
        var progress = $('ffla-mclean-progress');
        if (scanBtn) { scanBtn.disabled = on; }
        if (abortBtn) { abortBtn.hidden = !on; }
        if (progress) { progress.hidden = !on; }
    }

    function setProgress(percent, label) {
        var fill = $('ffla-mclean-progress-fill');
        var lbl = $('ffla-mclean-progress-label');
        if (fill) { fill.style.width = Math.max(0, Math.min(100, percent)) + '%'; }
        if (lbl) { lbl.textContent = label || ''; }
    }

    function startScan() {
        setScanning(true);
        setProgress(1, i18n.scanning || 'Scanning…');
        post('ffla_mclean_scan_start', {})
            .then(function (res) {
                if (!res || !res.success) { return failScan(); }
                stepScan();
            })
            .catch(failScan);
    }

    function stepScan() {
        if (!state.scanning) { return; }
        post('ffla_mclean_scan_step', {})
            .then(function (res) {
                if (!res || !res.success) { return failScan(); }
                var data = res.data || {};
                setProgress(data.percent || 0, data.phase || '');
                if (data.done) {
                    finishScan();
                } else {
                    // Yield to the browser between batches.
                    window.setTimeout(stepScan, 60);
                }
            })
            .catch(failScan);
    }

    function finishScan() {
        setScanning(false);
        setProgress(100, i18n.scanComplete || 'Scan complete.');
        state.page = 1;
        loadResults();
    }

    function failScan() {
        setScanning(false);
        setProgress(0, i18n.scanError || 'The scan hit an error.');
    }

    function abortScan() {
        setScanning(false);
        post('ffla_mclean_scan_abort', {});
        setProgress(0, '');
    }

    /* ------------------------------------------------------------- Results */

    function loadResults() {
        post('ffla_mclean_results', { status: state.status, page: state.page, search: state.search })
            .then(function (res) {
                if (!res || !res.success) { return; }
                var data = res.data || {};
                state.total = typeof data.total === 'number' ? data.total : 0;
                var rows = $('ffla-mclean-rows');
                if (rows) { rows.innerHTML = data.html || ''; }
                updateStats(data.stats_html);
                renderPagination(data.page, data.pages);
                syncToolbar();
                var checkAll = $('ffla-mclean-check-all');
                if (checkAll) { checkAll.checked = false; }
            });
    }

    function updateStats(html) {
        var box = $('ffla-mclean-stats');
        if (box && typeof html === 'string') { box.innerHTML = html; }
    }

    function renderPagination(page, pages) {
        var box = $('ffla-mclean-pagination');
        if (!box) { return; }
        box.innerHTML = '';
        if (!pages || pages < 2) { return; }

        function pageBtn(label, target, disabled, current) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'button ffla-mclean-page' + (current ? ' is-current' : '');
            b.textContent = label;
            b.disabled = !!disabled;
            if (!disabled && !current) {
                b.addEventListener('click', function () { state.page = target; loadResults(); });
            }
            return b;
        }

        box.appendChild(pageBtn('‹', page - 1, page <= 1, false));
        var info = document.createElement('span');
        info.className = 'ffla-mclean-page-info';
        info.textContent = page + ' / ' + pages;
        box.appendChild(info);
        box.appendChild(pageBtn('›', page + 1, page >= pages, false));
    }

    /* Toolbar reflects which tab is active. */
    function syncToolbar() {
        var bulk = $('ffla-mclean-bulk-actions');
        var emptyBtn = $('ffla-mclean-empty-trash');
        var trashAllBtn = $('ffla-mclean-trash-all');
        if (emptyBtn) { emptyBtn.hidden = state.status !== 'trashed'; }
        // Trash-all only makes sense on the active Issues tab, and only when
        // there is something to trash.
        if (trashAllBtn) { trashAllBtn.hidden = !(state.status === 'active' && state.total > 0); }
        if (!bulk) { return; }

        var actions = [];
        if (state.status === 'active') {
            actions = [['trash', i18n.trash || 'Trash selected'], ['ignore', i18n.ignore || 'Ignore selected']];
        } else if (state.status === 'ignored') {
            actions = [['unignore', 'Stop ignoring selected']];
        } else if (state.status === 'trashed') {
            actions = [['restore', 'Restore selected'], ['delete', 'Delete selected permanently']];
        }

        bulk.innerHTML = '';
        actions.forEach(function (a) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'button ffla-mclean-bulk' + (a[0] === 'delete' ? ' ffla-mclean-bulk--danger' : '');
            b.textContent = a[1];
            b.dataset.op = a[0];
            b.addEventListener('click', function () { runBulk(a[0]); });
            bulk.appendChild(b);
        });
    }

    function selectedIds() {
        var ids = [];
        document.querySelectorAll('.ffla-mclean-cb:checked').forEach(function (cb) { ids.push(cb.value); });
        return ids;
    }

    function confirmFor(op) {
        if (op === 'trash') { return i18n.confirmTrash; }
        if (op === 'delete') { return i18n.confirmDelete; }
        return null;
    }

    function runAction(op, ids) {
        if (!ids.length) {
            window.alert(i18n.nothingSelected || 'Select at least one item.');
            return;
        }
        var msg = confirmFor(op);
        if (msg && !window.confirm(msg)) { return; }

        post('ffla_mclean_action', { op: op, ids: ids })
            .then(function (res) {
                if (res && res.success && res.data) {
                    updateStats(res.data.stats_html);
                }
                loadResults();
            });
    }

    function runBulk(op) {
        runAction(op, selectedIds());
    }

    /* ------------------------------------------------------- Trash all */

    function setTrashAllBusy(on, label) {
        var btn = $('ffla-mclean-trash-all');
        if (!btn) { return; }
        btn.disabled = on;
        btn.textContent = on ? (label || (i18n.working || 'Working…')) : (i18n.trashAll || 'Trash all');
    }

    /**
     * Trash every issue in the current tab, one server batch at a time, until
     * none remain. Stops if a batch makes no forward progress (some items
     * refuse to trash) so it can never loop forever.
     */
    function trashAll() {
        var total = state.total || 0;
        if (total < 1) {
            window.alert(i18n.nothingSelected || 'Nothing to trash.');
            return;
        }

        var msg = (i18n.confirmTrashAll || 'Move ALL %d items to the trash?').replace('%d', total);
        if (!window.confirm(msg)) { return; }

        var lastRemaining = Infinity;
        var stall = 0;

        function step() {
            post('ffla_mclean_bulk_all', { op: 'trash', status: state.status, search: state.search })
                .then(function (res) {
                    if (!res || !res.success) { finish(); return; }
                    var d = res.data || {};
                    updateStats(d.stats_html);
                    var remaining = typeof d.remaining === 'number' ? d.remaining : 0;
                    setTrashAllBusy(true, (i18n.trashingLeft || 'Trashing… %d left').replace('%d', remaining));

                    if (remaining <= 0 || d.batch === 0) { finish(); return; }
                    if (remaining >= lastRemaining) {
                        stall++;
                        if (stall >= 2) { finish(); return; }
                    } else {
                        stall = 0;
                    }
                    lastRemaining = remaining;
                    window.setTimeout(step, 60);
                })
                .catch(function () { finish(); });
        }

        function finish() {
            setTrashAllBusy(false);
            state.page = 1;
            loadResults();
        }

        setTrashAllBusy(true, i18n.working || 'Working…');
        step();
    }

    /* ------------------------------------------------------------- Wiring */

    function onRowActionClick(e) {
        var btn = e.target.closest('.ffla-mclean-row-action');
        if (!btn) { return; }
        var row = btn.closest('tr');
        if (!row) { return; }
        runAction(btn.dataset.op, [row.dataset.id]);
    }

    function init() {
        var scanBtn = $('ffla-mclean-scan-btn');
        if (scanBtn) { scanBtn.addEventListener('click', startScan); }

        var abortBtn = $('ffla-mclean-abort-btn');
        if (abortBtn) { abortBtn.addEventListener('click', abortScan); }

        var tabs = $('ffla-mclean-tabs');
        if (tabs) {
            tabs.addEventListener('click', function (e) {
                var tab = e.target.closest('.ffla-mclean-tab');
                if (!tab) { return; }
                tabs.querySelectorAll('.ffla-mclean-tab').forEach(function (t) { t.classList.remove('is-active'); });
                tab.classList.add('is-active');
                state.status = tab.dataset.status;
                state.page = 1;
                loadResults();
            });
        }

        var search = $('ffla-mclean-search');
        if (search) {
            search.addEventListener('input', function () {
                window.clearTimeout(state.searchTimer);
                state.searchTimer = window.setTimeout(function () {
                    state.search = search.value.trim();
                    state.page = 1;
                    loadResults();
                }, 350);
            });
        }

        var checkAll = $('ffla-mclean-check-all');
        if (checkAll) {
            checkAll.addEventListener('change', function () {
                document.querySelectorAll('.ffla-mclean-cb').forEach(function (cb) { cb.checked = checkAll.checked; });
            });
        }

        var rows = $('ffla-mclean-rows');
        if (rows) { rows.addEventListener('click', onRowActionClick); }

        var trashAllBtn = $('ffla-mclean-trash-all');
        if (trashAllBtn) { trashAllBtn.addEventListener('click', trashAll); }

        var emptyBtn = $('ffla-mclean-empty-trash');
        if (emptyBtn) {
            emptyBtn.addEventListener('click', function () {
                if (!window.confirm(i18n.confirmEmptyTrash || 'Permanently delete everything in the trash?')) { return; }
                post('ffla_mclean_empty_trash', {}).then(function (res) {
                    if (res && res.success && res.data) { updateStats(res.data.stats_html); }
                    loadResults();
                });
            });
        }

        syncToolbar();
        loadResults();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
