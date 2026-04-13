/**
 * Woo Sheets Sync — Module admin JS.
 *
 * Sync Now, Clear Log, Disconnect, and sheet tab groups (per-tab product rules).
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initSyncNow();
        initClearLog();
        initDisconnect();
        initTabGroups();
    });

    /* ── Sync Now ──────────────────────────────────── */

    function initSyncNow() {
        var btn = document.getElementById('wss-sync-now-btn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (btn.classList.contains('wss-loading')) return;

            btn.classList.add('wss-loading');
            btn.textContent = (fflaAdmin.i18n && fflaAdmin.i18n.activating) || 'Syncing…';

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
                        var d = resp.data || {};
                        var w = d.woo_to_sheet || {};
                        var s = d.sheet_to_woo || {};
                        var parts = [
                            '<div class="wb-message wb-message--success"><span>',
                            'Sync complete. ',
                            'Woo\u2192Sheet: ',
                            (w.updated || 0),
                            ' updated, ',
                            (w.appended || 0),
                            ' appended. ',
                            'Sheet\u2192Woo: ',
                            (s.updated || 0),
                            ' updated.'
                        ];

                        if (d.groups && d.groups.length) {
                            parts.push(' <strong>Per tab:</strong> ');
                            parts.push(
                                d.groups.map(function (g) {
                                    var label = escapeHtml(String(g.tab_name || ''));
                                    if (g.error) {
                                        return label + ' (\u2014 ' + escapeHtml(String(g.error)) + ')';
                                    }
                                    var w2 = g.woo_to_sheet || {};
                                    var s2 = g.sheet_to_woo || {};
                                    return label + ' (W\u2192S ' + (w2.updated || 0) + '/' + (w2.appended || 0) + ', S\u2192W ' + (s2.updated || 0) + ')';
                                }).join('; ')
                            );
                        }

                        parts.push('</span></div>');
                        result.innerHTML = parts.join('');
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

    /* ── Sheet tab groups (Dashboard) ──────────────── */

    function syncGroupsAjax(fd, onSuccessNoReload) {
        fd.append('action', 'wss_sync_groups');
        fd.append('nonce', fflaAdmin.nonce);

        return fetch(fflaAdmin.ajaxUrl, { method: 'POST', body: fd }).then(function (r) {
            return r.json();
        }).then(function (res) {
            if (!res.success) {
                var msg = (res.data && res.data.message) || 'Request failed.';
                window.alert(msg);
                return null;
            }
            if (typeof onSuccessNoReload === 'function') {
                onSuccessNoReload(res.data);
            } else {
                window.location.reload();
            }
            return res.data;
        }).catch(function () {
            window.alert('Network error.');
            return null;
        });
    }

    function getGroupId(card) {
        return card.getAttribute('data-group-id') || '';
    }

    function getExplicitProductIds(card) {
        var chips = card.querySelectorAll('.wss-group-product-chips .wss-chip[data-id]');
        var ids = [];
        chips.forEach(function (c) {
            ids.push(String(c.getAttribute('data-id')));
        });
        return ids;
    }

    function initTabGroups() {
        var root = document.getElementById('wss-groups-root');
        if (!root) return;

        var addBtn = document.getElementById('wss-add-tab-group');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                var fd = new FormData();
                fd.append('op', 'add_group');
                syncGroupsAjax(fd);
            });
        }

        root.addEventListener('click', function (e) {
            var rm = e.target.closest('.wss-remove-group-btn');
            if (rm && !rm.disabled) {
                if (!confirm('Remove this sheet tab group? Products may remain in other tabs.')) return;
                var card = rm.closest('.wss-sync-group');
                if (!card) return;
                var fd = new FormData();
                fd.append('op', 'remove_group');
                fd.append('group_id', getGroupId(card));
                syncGroupsAjax(fd);
                return;
            }

            var linkAll = e.target.closest('.wss-group-link-all');
            if (linkAll) {
                if (!confirm('Link every published product to this tab only? (Other rules on this tab will be cleared.)')) return;
                var c1 = linkAll.closest('.wss-sync-group');
                if (!c1) return;
                var fd1 = new FormData();
                fd1.append('op', 'link_all');
                fd1.append('group_id', getGroupId(c1));
                syncGroupsAjax(fd1);
                return;
            }

            var unlinkAll = e.target.closest('.wss-group-unlink-all');
            if (unlinkAll) {
                if (!confirm('Clear all rules for this tab (products, categories, tags)?')) return;
                var c2 = unlinkAll.closest('.wss-sync-group');
                if (!c2) return;
                var fd2 = new FormData();
                fd2.append('op', 'unlink_all');
                fd2.append('group_id', getGroupId(c2));
                syncGroupsAjax(fd2);
                return;
            }

            var addCat = e.target.closest('.wss-group-add-category');
            if (addCat) {
                var c3 = addCat.closest('.wss-sync-group');
                if (!c3) return;
                var sel = c3.querySelector('.wss-group-category-select');
                if (!sel || !sel.value) return;
                var fd3 = new FormData();
                fd3.append('op', 'link_category');
                fd3.append('group_id', getGroupId(c3));
                fd3.append('term_id', sel.value);
                syncGroupsAjax(fd3);
                return;
            }

            var addTag = e.target.closest('.wss-group-add-tag');
            if (addTag) {
                var c4 = addTag.closest('.wss-sync-group');
                if (!c4) return;
                var selT = c4.querySelector('.wss-group-tag-select');
                if (!selT || !selT.value) return;
                var fd4 = new FormData();
                fd4.append('op', 'link_tag');
                fd4.append('group_id', getGroupId(c4));
                fd4.append('term_id', selT.value);
                syncGroupsAjax(fd4);
                return;
            }

            var taxRm = e.target.closest('.wss-chip--taxonomy .wss-chip__remove');
            if (taxRm) {
                var chip = taxRm.closest('.wss-chip--taxonomy');
                var c5 = taxRm.closest('.wss-sync-group');
                if (!chip || !c5) return;
                var tid = chip.getAttribute('data-term-id');
                var tax = chip.getAttribute('data-taxonomy');
                var fd5 = new FormData();
                fd5.append('group_id', getGroupId(c5));
                if (tax === 'product_tag') {
                    fd5.append('op', 'unlink_tag');
                } else {
                    fd5.append('op', 'unlink_category');
                }
                fd5.append('term_id', tid);
                syncGroupsAjax(fd5);
                return;
            }

            var prodRm = e.target.closest('.wss-group-product-chips .wss-chip__remove');
            if (prodRm) {
                var pchip = prodRm.closest('.wss-chip');
                var c6 = prodRm.closest('.wss-sync-group');
                if (!pchip || !c6 || pchip.classList.contains('wss-chip--taxonomy')) return;
                var pid = pchip.getAttribute('data-id');
                var fd6 = new FormData();
                fd6.append('op', 'remove_product');
                fd6.append('group_id', getGroupId(c6));
                fd6.append('product_id', pid);
                syncGroupsAjax(fd6);
            }
        });

        var tabTimers = {};
        root.querySelectorAll('.wss-sync-group').forEach(function (card) {
            initGroupProductSearch(card);
            var inp = card.querySelector('.wss-group-tab-name');
            if (!inp) return;
            var gid = getGroupId(card);
            inp.addEventListener('input', function () {
                clearTimeout(tabTimers[gid]);
                tabTimers[gid] = setTimeout(function () {
                    var v = inp.value.trim();
                    if (!v) return;
                    var fd = new FormData();
                    fd.append('op', 'set_tab');
                    fd.append('group_id', gid);
                    fd.append('tab_name', v);
                    syncGroupsAjax(fd, function () {});
                }, 600);
            });
        });
    }

    function initGroupProductSearch(card) {
        var wrap = card.querySelector('.wss-product-search');
        if (!wrap) return;

        var input = wrap.querySelector('.wb-product-search__input');
        var dropdown = wrap.querySelector('.wb-autocomplete__dropdown');
        if (!input || !dropdown) return;

        var chipsContainer = card.querySelector('.wss-group-product-chips');
        if (!chipsContainer) return;

        var debounce;
        input.addEventListener('input', function () {
            clearTimeout(debounce);
            var term = input.value.trim();
            if (term.length < 2) {
                dropdown.style.display = 'none';
                return;
            }

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

                        var linkedHere = getExplicitProductIds(card);

                        res.data.products.forEach(function (p) {
                            var isHere = linkedHere.indexOf(String(p.id)) >= 0;
                            var item = document.createElement('div');
                            item.className = 'wb-autocomplete__item';
                            item.textContent = p.name + (isHere ? ' \u2713' : '');
                            if (!isHere) {
                                item.addEventListener('click', function () {
                                    var fd2 = new FormData();
                                    fd2.append('op', 'add_product');
                                    fd2.append('group_id', getGroupId(card));
                                    fd2.append('product_id', String(p.id));
                                    syncGroupsAjax(fd2);
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
            if (!wrap.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
})();
