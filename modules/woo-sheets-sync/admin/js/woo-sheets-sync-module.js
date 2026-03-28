/**
 * Woo Sheets Sync — Module admin JS.
 *
 * Handles Sync Now, Clear Log, Disconnect, Product linking via AJAX.
 * Uses the global `fflaAdmin` object for ajaxUrl and nonce.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initSyncNow();
        initClearLog();
        initDisconnect();
        initProductLinker();
    });

    /* ── Sync Now ──────────────────────────────────── */

    function initSyncNow() {
        var btn = document.getElementById('wss-sync-now-btn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (btn.classList.contains('wss-loading')) return;

            btn.classList.add('wss-loading');
            btn.textContent = fflaAdmin.i18n.activating || 'Syncing…';

            var result = document.getElementById('wss-sync-result');
            if (result) result.innerHTML = '';

            var body = new FormData();
            body.append('action', 'wss_manual_sync');
            body.append('nonce', fflaAdmin.nonce);

            fetch(fflaAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    btn.classList.remove('wss-loading');
                    btn.textContent = 'Sync Now';

                    if (!result) return;

                    if (resp.success) {
                        var d = resp.data;
                        var w = d.woo_to_sheet || {};
                        var s = d.sheet_to_woo || {};
                        result.innerHTML =
                            '<div class="wb-message wb-message--success">' +
                            '<span>Sync complete. ' +
                            'Woo\u2192Sheet: ' + (w.updated || 0) + ' updated, ' + (w.appended || 0) + ' appended. ' +
                            'Sheet\u2192Woo: ' + (s.updated || 0) + ' updated.</span></div>';
                    } else {
                        result.innerHTML =
                            '<div class="wb-message wb-message--danger">' +
                            '<span>' + (resp.data && resp.data.message || 'Sync failed.') + '</span></div>';
                    }
                })
                .catch(function () {
                    btn.classList.remove('wss-loading');
                    btn.textContent = 'Sync Now';
                    if (result) {
                        result.innerHTML =
                            '<div class="wb-message wb-message--danger"><span>Network error.</span></div>';
                    }
                });
        });
    }

    /* ── Clear Log ─────────────────────────────────── */

    function initClearLog() {
        var btn = document.getElementById('wss-clear-log-btn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (!confirm('Clear all sync log entries?')) return;

            var body = new FormData();
            body.append('action', 'wss_clear_log');
            body.append('nonce', fflaAdmin.nonce);

            fetch(fflaAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        var container = document.getElementById('wss-log-container');
                        if (container) container.innerHTML = '<p>No log entries yet.</p>';
                    }
                });
        });
    }

    /* ── Disconnect ────────────────────────────────── */

    function initDisconnect() {
        var btn = document.getElementById('wss-disconnect-btn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (!confirm('Disconnect your Google account?')) return;

            var body = new FormData();
            body.append('action', 'wss_disconnect');
            body.append('nonce', fflaAdmin.nonce);

            fetch(fflaAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp.success) window.location.reload();
                });
        });
    }

    /* ── Product Linker (Dashboard) ────────────────── */

    // Track linked product IDs in memory.
    var linkedIds = (window.wssSyncedIds || []).slice();

    function initProductLinker() {
        var container = document.getElementById('wss-linked-products');
        if (!container) return;

        // Load existing chips.
        if (linkedIds.length) {
            resolveAndRenderChips(linkedIds, container);
        }

        // Search autocomplete.
        initProductSearch(container);

        // Link All / Unlink All.
        initBulkActions(container);

        // Link by taxonomy.
        initTaxonomyLink(container);
    }

    function resolveAndRenderChips(ids, container) {
        var fd = new FormData();
        fd.append('action', 'wss_resolve_product_names');
        fd.append('nonce', fflaAdmin.nonce);
        fd.append('ids', ids.join(','));

        fetch(fflaAdmin.ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success && res.data.names) {
                    removeEmptyState();
                    Object.keys(res.data.names).forEach(function (pid) {
                        if (!container.querySelector('[data-id="' + pid + '"]')) {
                            addChip(container, pid, res.data.names[pid]);
                        }
                    });
                }
            });
    }

    function initProductSearch(chipsContainer) {
        var searchEl = document.getElementById('wss-product-search');
        if (!searchEl) return;

        var input    = searchEl.querySelector('.wb-product-search__input');
        var dropdown = searchEl.querySelector('.wb-autocomplete__dropdown');
        if (!input || !dropdown) return;

        var debounce;
        input.addEventListener('input', function () {
            clearTimeout(debounce);
            var term = input.value.trim();
            if (term.length < 2) { dropdown.style.display = 'none'; return; }

            debounce = setTimeout(function () {
                var fd = new FormData();
                fd.append('action', 'wss_search_products');
                fd.append('nonce', fflaAdmin.nonce);
                fd.append('search', term);

                fetch(fflaAdmin.ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        dropdown.innerHTML = '';
                        if (!res.success || !res.data.products.length) {
                            dropdown.style.display = 'none';
                            return;
                        }
                        res.data.products.forEach(function (p) {
                            var isLinked = linkedIds.indexOf(String(p.id)) >= 0;
                            var item = document.createElement('div');
                            item.className = 'wb-autocomplete__item';
                            item.textContent = p.name + (isLinked ? ' \u2713' : '');
                            if (!isLinked) {
                                item.addEventListener('click', function () {
                                    linkProduct(p.id, p.name, chipsContainer);
                                    dropdown.style.display = 'none';
                                    input.value = '';
                                });
                            } else {
                                item.style.opacity = '0.5';
                                item.style.cursor = 'default';
                            }
                            dropdown.appendChild(item);
                        });
                        dropdown.style.display = 'block';
                    });
            }, 300);
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.wss-product-search')) {
                dropdown.style.display = 'none';
            }
        });
    }

    function linkProduct(id, name, chipsContainer) {
        var fd = new FormData();
        fd.append('action', 'wss_save_sync_products');
        fd.append('nonce', fflaAdmin.nonce);
        fd.append('sync_action', 'add');
        fd.append('product_id', id);

        fetch(fflaAdmin.ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    linkedIds.push(String(id));
                    removeEmptyState();
                    addChip(chipsContainer, id, name);
                    updateCount();
                }
            });
    }

    function unlinkProduct(id, chip) {
        var fd = new FormData();
        fd.append('action', 'wss_save_sync_products');
        fd.append('nonce', fflaAdmin.nonce);
        fd.append('sync_action', 'remove');
        fd.append('product_id', id);

        fetch(fflaAdmin.ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    linkedIds = linkedIds.filter(function (v) { return v !== String(id); });
                    chip.remove();
                    updateCount();
                    if (!linkedIds.length) showEmptyState();
                }
            });
    }

    function addChip(container, id, label) {
        if (container.querySelector('[data-id="' + id + '"]')) return;

        var chip = document.createElement('span');
        chip.className = 'wss-chip';
        chip.setAttribute('data-id', id);
        chip.innerHTML = escapeHtml(label) + ' <button type="button" class="wss-chip__remove">&times;</button>';
        chip.querySelector('.wss-chip__remove').addEventListener('click', function () {
            unlinkProduct(id, chip);
        });
        container.appendChild(chip);
    }

    function initBulkActions(chipsContainer) {
        var linkAll   = document.getElementById('wss-link-all');
        var unlinkAll = document.getElementById('wss-unlink-all');

        if (linkAll) {
            linkAll.addEventListener('click', function () {
                if (!confirm('Link ALL products for sync?')) return;
                bulkAction('link_all', chipsContainer);
            });
        }

        if (unlinkAll) {
            unlinkAll.addEventListener('click', function () {
                if (!confirm('Unlink ALL products from sync?')) return;
                bulkAction('unlink_all', chipsContainer);
            });
        }
    }

    function bulkAction(action, chipsContainer) {
        var fd = new FormData();
        fd.append('action', 'wss_save_sync_products');
        fd.append('nonce', fflaAdmin.nonce);
        fd.append('sync_action', action);

        fetch(fflaAdmin.ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    // Reload page to refresh chips.
                    window.location.reload();
                }
            });
    }

    function initTaxonomyLink(chipsContainer) {
        var buttons = document.querySelectorAll('.wss-link-tax-btn');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var taxonomy = btn.getAttribute('data-taxonomy');
                var selectId = btn.getAttribute('data-select');
                var select   = document.getElementById(selectId);
                if (!select || !select.value) return;

                var fd = new FormData();
                fd.append('action', 'wss_link_by_taxonomy');
                fd.append('nonce', fflaAdmin.nonce);
                fd.append('taxonomy', taxonomy);
                fd.append('term_id', select.value);

                btn.disabled = true;
                btn.textContent = 'Linking...';

                fetch(fflaAdmin.ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        btn.disabled = false;
                        btn.textContent = 'Link';
                        select.value = '';

                        if (res.success) {
                            // Add new IDs and chips.
                            var names = res.data.names || {};
                            Object.keys(names).forEach(function (pid) {
                                if (linkedIds.indexOf(String(pid)) < 0) {
                                    linkedIds.push(String(pid));
                                }
                                removeEmptyState();
                                addChip(chipsContainer, pid, names[pid]);
                            });
                            updateCount();

                            // Show feedback.
                            var feedback = document.createElement('span');
                            feedback.className = 'wss-bulk-link__feedback';
                            feedback.textContent = res.data.message;
                            btn.parentNode.appendChild(feedback);
                            setTimeout(function () { feedback.remove(); }, 3000);
                        }
                    });
            });
        });
    }

    /* ── Helpers ────────────────────────────────────── */

    function updateCount() {
        var el = document.getElementById('wss-product-count');
        if (el) el.textContent = linkedIds.length + ' linked';
    }

    function removeEmptyState() {
        var empty = document.getElementById('wss-empty-state');
        if (empty) empty.remove();
    }

    function showEmptyState() {
        var container = document.getElementById('wss-linked-products');
        if (container && !document.getElementById('wss-empty-state')) {
            var p = document.createElement('p');
            p.className = 'wss-empty-state';
            p.id = 'wss-empty-state';
            p.textContent = 'No products linked yet. Use the options above to add products.';
            container.appendChild(p);
        }
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
})();
