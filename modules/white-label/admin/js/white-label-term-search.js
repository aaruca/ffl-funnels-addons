/**
 * White Label — product editor tools (category, brand & tag search, folding
 * tree, parent ticking, Products list filters).
 *
 * Works on WordPress's own markup and never replaces it, so core keeps
 * submitting the values:
 *  - hierarchical boxes (div.categorydiv: Product categories, Brands…): a search
 *    field above the All / Most Used tabs that filters the All checklist,
 *    keeps the ancestors of a match visible, highlights the match, counts the
 *    results, and a "Selected only" filter with a live selected count;
 *  - flat boxes (div.tagsdiv: Product tags…): a search field and a checklist of
 *    the existing terms under the core field, kept in sync with core's hidden
 *    textarea and tag chips (tagBox) in both directions;
 *  - Quick Edit / Bulk Edit (ul.cat-checklist on the products list): the same
 *    filter above each checklist. WordPress clones the Quick Edit template
 *    each time and re-inserts the Bulk Edit row, so new rows are picked up as
 *    they are added to the list.
 *
 * Matching: case- and accent-insensitive, anywhere in the name, every word
 * must match. Normalised names are computed once per list.
 *
 * Other tools (each switched on or off in White Label → Products):
 *  - collapse: an arrow on each parent category folds its subcategories, with
 *    Expand all / Collapse all; long lists start folded except the branches
 *    holding ticked terms; a search always shows every match;
 *  - autoParents: ticking a subcategory ticks its parents;
 *  - listFilters: the Products list's category, brand and tag filters become
 *    searchable (selectWoo, already loaded there by WooCommerce).
 */
(function ($) {
    'use strict';

    var cfg = window.fflaTermSearch || {};
    var features = cfg.features || {search: true};
    var COLLAPSE_MIN = Number(cfg.collapseMin) || 15;
    var taxonomies = cfg.taxonomies || {};
    var i18n = cfg.i18n || {};
    var MIN_CHARS = Number(cfg.minChars) || 2;
    var COMBINING = /[̀-ͯ]/g;
    var uid = 0;
    var boxByList = new WeakMap();

    /* ── Helpers ─────────────────────────────────────────────────────── */

    function t(key, fallback) {
        return typeof i18n[key] === 'string' && i18n[key] !== '' ? i18n[key] : fallback;
    }

    function format(text, n) {
        return String(text).replace('%d', String(n));
    }

    function formatName(text, name) {
        return String(text).replace('%s', String(name));
    }

    function nextId(prefix) {
        uid += 1;
        return prefix + uid;
    }

    function fold(text) {
        return String(text).normalize('NFD').replace(COMBINING, '').toLowerCase();
    }

    /** Folded text plus, per folded character, the index of its source character. */
    function indexName(name) {
        var text = '';
        var map = [];
        for (var i = 0; i < name.length; i++) {
            var folded = fold(name.charAt(i));
            for (var j = 0; j < folded.length; j++) {
                text += folded.charAt(j);
                map.push(i);
            }
        }
        return {text: text, map: map};
    }

    function queryWords(value) {
        return fold(value).split(/\s+/).filter(Boolean);
    }

    function escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function makeEntry(name, props) {
        var index = indexName(name);
        props.name = name;
        props.key = name.trim().toLowerCase();
        props.norm = index.text;
        props.map = index.map;
        props.state = '';
        props.html = '';
        return props;
    }

    /** The entry's name as HTML with every matched word wrapped in <mark>. */
    function highlight(entry, words) {
        var ranges = [];
        words.forEach(function (word) {
            var from = 0;
            var at;
            while ((at = entry.norm.indexOf(word, from)) !== -1) {
                ranges.push([entry.map[at], entry.map[at + word.length - 1] + 1]);
                from = at + word.length;
            }
        });
        if (!ranges.length) {
            return '';
        }

        ranges.sort(function (a, b) { return a[0] - b[0]; });
        var merged = [ranges[0]];
        for (var i = 1; i < ranges.length; i++) {
            var last = merged[merged.length - 1];
            if (ranges[i][0] <= last[1]) {
                last[1] = Math.max(last[1], ranges[i][1]);
            } else {
                merged.push(ranges[i]);
            }
        }

        var html = '';
        var pos = 0;
        merged.forEach(function (range) {
            html += escapeHtml(entry.name.slice(pos, range[0])) +
                '<mark class="ffla-ts-mark">' + escapeHtml(entry.name.slice(range[0], range[1])) + '</mark>';
            pos = range[1];
        });
        return html + escapeHtml(entry.name.slice(pos));
    }

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(fn, wait);
        };
    }

    /* ── Checklist entries (core markup) ─────────────────────────────── */

    /**
     * The term name inside a core checklist label, wrapped once in a span so it
     * can be highlighted without touching the checkbox. The label's text stays
     * exactly the same for other scripts.
     */
    function nameSpan(label) {
        for (var child = label.firstElementChild; child; child = child.nextElementSibling) {
            if (child.classList.contains('ffla-ts-name')) {
                return child;
            }
        }
        for (var node = label.firstChild; node; node = node.nextSibling) {
            if (node.nodeType === 3 && node.nodeValue.trim()) {
                var span = document.createElement('span');
                span.className = 'ffla-ts-name';
                span.textContent = node.nodeValue.trim();
                node.nodeValue = /^\s/.test(node.nodeValue) ? ' ' : '';
                label.insertBefore(span, node.nextSibling);
                return span;
            }
        }
        return null;
    }

    function buildEntries(list) {
        var entries = [];
        var byLi = new Map();

        Array.prototype.forEach.call(list.getElementsByTagName('li'), function (li) {
            var label = null;
            for (var child = li.firstElementChild; child; child = child.nextElementSibling) {
                if (child.tagName === 'LABEL') {
                    label = child;
                    break;
                }
            }
            var input = label ? label.querySelector('input[type="checkbox"]') : null;
            var span = label ? nameSpan(label) : null;
            if (!input || !span) {
                return;
            }

            var parentLi = li.parentElement ? li.parentElement.closest('li') : null;
            var entry = makeEntry(span.textContent, {
                li: li,
                input: input,
                span: span,
                parent: parentLi && list.contains(parentLi) ? (byLi.get(parentLi) || null) : null
            });
            byLi.set(li, entry);
            entries.push(entry);
        });

        return entries;
    }

    /* ── Folding tree ────────────────────────────────────────────────── */

    function childList(li) {
        for (var child = li.firstElementChild; child; child = child.nextElementSibling) {
            if (child.tagName === 'UL' && child.getElementsByTagName('li').length) {
                return child;
            }
        }
        return null;
    }

    function setCollapsed(entry, collapsed) {
        if (!entry.twisty) {
            return;
        }
        entry.li.classList.toggle('ffla-ts-collapsed', collapsed);
        entry.twisty.setAttribute('aria-expanded', String(!collapsed));
        entry.twisty.setAttribute('aria-label', formatName(collapsed ? t('expand', 'Show subcategories of %s') : t('collapse', 'Hide subcategories of %s'), entry.name));
    }

    /**
     * Give every parent category an arrow button. Lists of COLLAPSE_MIN terms
     * or more start folded, except branches that hold a ticked term, so the
     * current choice is always in view.
     */
    function setupTree(box, initial) {
        if (!features.collapse) {
            return;
        }
        var parents = 0;
        box.entries.forEach(function (entry) {
            var children = childList(entry.li);
            if (!children) {
                if (entry.twisty) {
                    entry.twisty.remove();
                    entry.twisty = null;
                    entry.li.classList.remove('ffla-ts-parent', 'ffla-ts-collapsed');
                }
                return;
            }
            parents++;
            if (!entry.twisty) {
                // Re-use a button left by an earlier pass (Quick Edit clones,
                // a rebuild after "+ Add new category").
                var existing = null;
                for (var child = entry.li.firstElementChild; child; child = child.nextElementSibling) {
                    if (child.classList && child.classList.contains('ffla-ts-twisty')) {
                        existing = child;
                        break;
                    }
                }
                var button = existing || document.createElement('button');
                if (!existing) {
                    button.type = 'button';
                    button.className = 'ffla-ts-twisty';
                    button.setAttribute('aria-controls', children.id || (children.id = nextId('ffla-ts-children-')));
                    entry.li.insertBefore(button, entry.li.firstChild);
                }
                entry.twisty = button;
                entry.li.classList.add('ffla-ts-parent');
                button.onclick = function (event) {
                    event.preventDefault();
                    setCollapsed(entry, !entry.li.classList.contains('ffla-ts-collapsed'));
                };
                button.onkeydown = function (event) {
                    if (event.key === 'Enter') {
                        // Never submit the product form / save Quick Edit.
                        event.stopPropagation();
                    }
                };
                setCollapsed(entry, entry.li.classList.contains('ffla-ts-collapsed'));
            }
        });

        box.list.classList.toggle('ffla-ts-tree', parents > 0);
        if (box.treeBar) {
            box.treeBar.hidden = parents === 0;
        }
        if (initial && parents > 0 && box.entries.length >= COLLAPSE_MIN) {
            box.entries.forEach(function (entry) {
                if (entry.twisty) {
                    setCollapsed(entry, !entry.li.querySelector('ul input[type="checkbox"]:checked'));
                }
            });
        }
    }

    function treeButtons(box, bar) {
        if (!features.collapse) {
            return;
        }
        var group = document.createElement('span');
        group.className = 'ffla-ts-tree-actions';
        group.hidden = true;
        [['expandAll', 'Expand all', false], ['collapseAll', 'Collapse all', true]].forEach(function (item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'button-link ffla-ts-tree-action';
            button.textContent = t(item[0], item[1]);
            button.addEventListener('click', function (event) {
                event.preventDefault();
                box.entries.forEach(function (entry) { setCollapsed(entry, item[2]); });
            });
            group.appendChild(button);
        });
        bar.appendChild(group);
        box.treeBar = group;
    }

    /* ── Ticking parents ─────────────────────────────────────────────── */

    function bindAutoParents(box, after) {
        if (!features.autoParents) {
            return;
        }
        box.list.addEventListener('change', function (event) {
            var input = event.target;
            if (!input || input.type !== 'checkbox' || !input.checked) {
                return;
            }
            var entry = null;
            for (var i = 0; i < box.entries.length; i++) {
                if (box.entries[i].input === input) {
                    entry = box.entries[i];
                    break;
                }
            }
            var changed = false;
            for (var parent = entry ? entry.parent : null; parent; parent = parent.parent) {
                if (!parent.input.checked && !parent.input.disabled) {
                    parent.input.checked = true;
                    changed = true;
                }
            }
            if (changed && after) {
                after();
            }
        });
    }

    /* ── Filtering ───────────────────────────────────────────────────── */

    function applyFilter(box) {
        var words = queryWords(box.input.value);
        var only = !!(box.only && box.only.checked);
        var active = words.length > 0 || only;
        var matches = 0;

        box.entries.forEach(function (entry) {
            entry.match = active &&
                (!only || entry.input.checked) &&
                words.every(function (word) { return entry.norm.indexOf(word) !== -1; });
            entry.show = !active || entry.match;
            if (entry.match) {
                matches++;
            }
        });

        // Keep the ancestors of every match visible (shown muted).
        if (active) {
            box.entries.forEach(function (entry) {
                if (!entry.match) {
                    return;
                }
                for (var parent = entry.parent; parent && !parent.show; parent = parent.parent) {
                    parent.show = true;
                }
            });
        }

        box.entries.forEach(function (entry) {
            var state = !entry.show ? 'hidden' : (active && !entry.match ? 'ancestor' : '');
            if (state !== entry.state) {
                entry.li.classList.toggle('ffla-ts-hidden', state === 'hidden');
                entry.li.classList.toggle('ffla-ts-ancestor', state === 'ancestor');
                entry.state = state;
            }

            var html = entry.match && words.length ? highlight(entry, words) : '';
            if (html !== entry.html) {
                if (html) {
                    entry.span.innerHTML = html;
                } else {
                    entry.span.textContent = entry.name;
                }
                entry.html = html;
            }
        });

        box.matches = matches;
        if (!box.remoteBusy) {
            setStatus(box, active && matches ? format(matches === 1 ? t('matchOne', '%d match') : t('matchMany', '%d matches'), matches) : '');
        }
        box.empty.hidden = !active || matches > 0;
        box.wrap.classList.toggle('is-filtering', active);
        // Folded branches open while searching, so every match is visible.
        box.list.classList.toggle('ffla-ts-filtering', active);

        if (active && box.showAll) {
            box.showAll();
        }
    }

    function setStatus(box, text) {
        if (box.status.textContent !== text) {
            box.status.textContent = text;
        }
    }

    function updateSelected(box) {
        if (!box.selectedEl) {
            return;
        }
        var seen = {};
        var count = 0;
        box.entries.forEach(function (entry) {
            if (entry.input.checked && !seen[entry.input.value]) {
                seen[entry.input.value] = true;
                count++;
            }
        });
        box.selectedEl.textContent = format(t('selected', '%d selected'), count);
    }

    /* ── Controls ────────────────────────────────────────────────────── */

    function createControls(conf, listId, withSelected, modifier) {
        var wrap = document.createElement('div');
        wrap.className = 'ffla-ts' + (modifier ? ' ' + modifier : '') + (features.search ? '' : ' ffla-ts--no-search');

        var inputId = nextId('ffla-ts-input-');
        var label = document.createElement('label');
        label.className = 'screen-reader-text';
        label.htmlFor = inputId;
        label.textContent = conf.searchLabel || t('search', 'Search…');

        var input = document.createElement('input');
        input.type = 'search';
        input.id = inputId;
        input.className = 'ffla-ts-input';
        input.placeholder = conf.placeholder || t('search', 'Search…');
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.setAttribute('aria-controls', listId);

        var bar = document.createElement('div');
        bar.className = 'ffla-ts-bar';

        var status = document.createElement('span');
        status.className = 'ffla-ts-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.setAttribute('aria-atomic', 'true');
        bar.appendChild(status);

        var selectedEl = null;
        var only = null;
        if (withSelected && features.search) {
            selectedEl = document.createElement('span');
            selectedEl.className = 'ffla-ts-count';
            bar.appendChild(selectedEl);

            var onlyLabel = document.createElement('label');
            onlyLabel.className = 'ffla-ts-only';
            only = document.createElement('input');
            only.type = 'checkbox';
            only.setAttribute('aria-controls', listId);
            onlyLabel.appendChild(only);
            onlyLabel.appendChild(document.createTextNode(' ' + t('selectedOnly', 'Selected only')));
            bar.appendChild(onlyLabel);
        }

        if (features.search) {
            wrap.appendChild(label);
            wrap.appendChild(input);
        }
        wrap.appendChild(bar);

        return {wrap: wrap, input: input, status: status, selectedEl: selectedEl, only: only, bar: bar};
    }

    function makeEmpty() {
        var empty = document.createElement('p');
        empty.className = 'ffla-ts-empty';
        empty.textContent = t('noMatches', 'No matches');
        empty.hidden = true;
        return empty;
    }

    /**
     * Keyboard: Enter never submits the product form or saves Quick Edit; Esc
     * clears the search (and only when there is one, so Esc still closes
     * Quick Edit from an empty field). Listening on the field itself runs
     * before Quick Edit's own handler on its table cell.
     */
    function bindKeys(box, onClear) {
        var clearedWithEscape = false;
        box.input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                event.stopPropagation();
            } else if (event.key === 'Escape' && box.input.value !== '') {
                event.preventDefault();
                event.stopPropagation();
                clearedWithEscape = true;
                box.input.value = '';
                onClear();
            }
        });
        // Quick / Bulk Edit close on Escape *keyup*: swallow the one that
        // belongs to the Escape that just cleared the search.
        box.input.addEventListener('keyup', function (event) {
            if (event.key === 'Escape' && clearedWithEscape) {
                clearedWithEscape = false;
                event.preventDefault();
                event.stopPropagation();
            }
        });
        if (box.only) {
            box.only.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    event.stopPropagation();
                }
            });
        }
    }

    /* ── Hierarchical boxes on the edit screen ───────────────────────── */

    function treeToolsOn() {
        return !!(features.search || features.collapse || features.autoParents);
    }

    function initCategoryBox(div) {
        if (!treeToolsOn() || div.hasAttribute('data-ffla-ts')) {
            return;
        }
        var taxonomy = div.id.replace(/^taxonomy-/, '');
        var conf = taxonomies[taxonomy];
        var list = document.getElementById(taxonomy + 'checklist');
        if (!conf || !conf.hierarchical || !list) {
            return;
        }
        div.setAttribute('data-ffla-ts', '1');

        var tabs = document.getElementById(taxonomy + '-tabs');
        var panel = document.getElementById(taxonomy + '-all');
        var allLink = tabs ? tabs.querySelector('a[href="#' + taxonomy + '-all"]') : null;
        var ui = createControls(conf, list.id, true, 'ffla-ts--box');
        div.insertBefore(ui.wrap, tabs && tabs.parentNode === div ? tabs : div.firstChild);

        var empty = makeEmpty();
        list.parentNode.insertBefore(empty, list.nextSibling);

        var box = {
            wrap: ui.wrap,
            list: list,
            input: ui.input,
            status: ui.status,
            selectedEl: ui.selectedEl,
            only: ui.only,
            empty: empty,
            entries: buildEntries(list),
            showAll: function () {
                // Search the All tab: switch to it through core's own tab handler.
                if (panel && allLink && $(panel).is(':hidden')) {
                    $(allLink).trigger('click');
                }
            }
        };
        boxByList.set(list, box);
        treeButtons(box, ui.bar);

        var resizable = function () {
            if (panel && box.entries.length > 7) {
                panel.classList.add('ffla-ts-resizable');
            }
        };
        resizable();
        updateSelected(box);
        setupTree(box, true);
        bindAutoParents(box, function () { updateSelected(box); });

        if (features.search) {
            var run = debounce(function () { applyFilter(box); }, 60);
            box.input.addEventListener('input', run);
            box.input.addEventListener('search', run);
            box.only.addEventListener('change', function () { applyFilter(box); });
            bindKeys(box, function () { applyFilter(box); });
        }

        // Ticks in either tab (core syncs "Most Used" on click, before change).
        div.addEventListener('change', function (event) {
            if (event.target && event.target.type === 'checkbox' && event.target !== box.only) {
                window.setTimeout(function () { updateSelected(box); }, 0);
            }
        });

        // Terms added with "+ Add new category" (or by other scripts) become
        // searchable. Only new <li> elements count, not our own highlighting.
        if (window.MutationObserver) {
            var rebuild = debounce(function () {
                box.entries = buildEntries(list);
                resizable();
                updateSelected(box);
                setupTree(box, false);
                applyFilter(box);
            }, 50);
            new MutationObserver(function (mutations) {
                var added = mutations.some(function (mutation) {
                    return Array.prototype.some.call(mutation.addedNodes, function (node) {
                        return node.nodeType === 1 && (node.tagName === 'LI' || (node.querySelector && node.querySelector('li')));
                    });
                });
                if (added) {
                    rebuild();
                }
            }).observe(list, {childList: true, subtree: true});
        }
    }

    /* ── Flat boxes (tags) on the edit screen ────────────────────────── */

    function tagDelimiter() {
        try {
            if (window.wp && window.wp.i18n && typeof window.wp.i18n._x === 'function') {
                return window.wp.i18n._x(',', 'tag delimiter') || ',';
            }
        } catch (e) {
            // Fall through to the default.
        }
        return ',';
    }

    function currentTags(box) {
        return box.textarea.value.split(tagDelimiter()).map(function (tag) {
            return tag.trim();
        }).filter(Boolean);
    }

    function syncChecks(box) {
        var assigned = {};
        currentTags(box).forEach(function (tag) { assigned[tag.toLowerCase()] = true; });
        box.entries.forEach(function (entry) {
            entry.input.checked = !!assigned[entry.key];
        });
    }

    function renderTagItems(box, names) {
        var fragment = document.createDocumentFragment();
        var seen = {};
        box.entries = [];
        names.forEach(function (name) {
            var key = String(name).trim().toLowerCase();
            if (!key || seen[key]) {
                return;
            }
            seen[key] = true;

            var li = document.createElement('li');
            var label = document.createElement('label');
            label.className = 'selectit';
            var input = document.createElement('input');
            input.type = 'checkbox';
            input.value = name;
            var span = document.createElement('span');
            span.className = 'ffla-ts-name';
            span.textContent = name;
            label.appendChild(input);
            label.appendChild(document.createTextNode(' '));
            label.appendChild(span);
            li.appendChild(label);
            fragment.appendChild(li);

            box.entries.push(makeEntry(String(name), {li: li, input: input, span: span, parent: null}));
        });
        box.list.textContent = '';
        box.list.appendChild(fragment);
        box.panel.classList.toggle('ffla-ts-resizable', box.entries.length > 6);
        syncChecks(box);
    }

    /** Assign or remove a term through core's textarea, then redraw core's chips. */
    function toggleTag(box, name, on) {
        var key = name.trim().toLowerCase();
        var tags = currentTags(box).filter(function (tag) { return tag.toLowerCase() !== key; });
        if (on) {
            tags.push(name);
        }
        box.textarea.value = tags.join(tagDelimiter());

        if (window.tagBox && typeof window.tagBox.quickClicks === 'function') {
            window.tagBox.userAction = on ? 'add' : 'remove';
            window.tagBox.quickClicks(box.root);
        } else {
            syncChecks(box);
        }
    }

    function initTagsBox(div) {
        if (!features.search) {
            return;
        }
        var taxonomy = div.id;
        var conf = taxonomies[taxonomy];
        var textarea = div.querySelector('textarea.the-tags');
        if (!conf || conf.hierarchical || !textarea || textarea.disabled || div.hasAttribute('data-ffla-ts')) {
            return;
        }
        div.setAttribute('data-ffla-ts', '1');

        var listId = nextId('ffla-ts-list-');
        var ui = createControls(conf, listId, false, 'ffla-ts--tags');
        var panel = document.createElement('div');
        panel.className = 'ffla-ts-panel';
        var list = document.createElement('ul');
        list.id = listId;
        list.className = 'ffla-ts-list';
        list.setAttribute('aria-label', conf.listLabel || conf.name || '');
        var empty = makeEmpty();
        panel.appendChild(list);
        panel.appendChild(empty);
        ui.wrap.appendChild(panel);
        div.appendChild(ui.wrap);

        var box = {
            root: div,
            wrap: ui.wrap,
            panel: panel,
            list: list,
            input: ui.input,
            status: ui.status,
            empty: empty,
            textarea: textarea,
            entries: [],
            remote: !!conf.remote,
            remoteBusy: false,
            request: 0
        };
        boxByList.set(list, box);

        // Large taxonomies: the list shows the assigned terms until 2+
        // characters are typed, then the server's matches.
        var showAssigned = function () {
            renderTagItems(box, currentTags(box));
            applyFilter(box);
            setStatus(box, box.input.value.trim() ? t('typeMore', 'Type at least 2 characters to search all terms.') : '');
        };

        var remoteSearch = debounce(function () {
            var tokens = box.input.value.trim().split(/\s+/).filter(Boolean);
            var longest = tokens.reduce(function (a, b) { return b.length > a.length ? b : a; }, '');
            if (longest.length < MIN_CHARS) {
                box.remoteBusy = false;
                showAssigned();
                return;
            }
            var request = ++box.request;
            box.remoteBusy = true;
            setStatus(box, t('searching', 'Searching…'));
            $.post(cfg.ajaxUrl, {action: cfg.action, nonce: cfg.nonce, taxonomy: taxonomy, q: longest})
                .done(function (response) {
                    if (request !== box.request) {
                        return;
                    }
                    box.remoteBusy = false;
                    if (!response || !response.success || !response.data || !Array.isArray(response.data.terms)) {
                        renderTagItems(box, []);
                        setStatus(box, t('searchFailed', 'The search could not be completed. Try again.'));
                        return;
                    }
                    renderTagItems(box, response.data.terms);
                    applyFilter(box); // Every typed word must match, not just the longest.
                })
                .fail(function () {
                    if (request === box.request) {
                        box.remoteBusy = false;
                        setStatus(box, t('searchFailed', 'The search could not be completed. Try again.'));
                    }
                });
        }, 250);

        if (box.remote) {
            showAssigned();
        } else {
            renderTagItems(box, conf.terms || []);
        }

        var run = box.remote ? remoteSearch : debounce(function () { applyFilter(box); }, 60);
        box.input.addEventListener('input', run);
        box.input.addEventListener('search', run);
        bindKeys(box, function () {
            box.request++;
            box.remoteBusy = false;
            if (box.remote) {
                showAssigned();
            } else {
                applyFilter(box);
            }
        });

        list.addEventListener('change', function (event) {
            var input = event.target;
            if (input && input.type === 'checkbox') {
                toggleTag(box, input.value, input.checked);
            }
        });
        list.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && event.target && event.target.type === 'checkbox') {
                event.preventDefault();
            }
        });

        // Core redraws the chips whenever its textarea changes (typing a tag,
        // the X on a chip, "Choose from the most used tags"): follow it.
        var chips = div.querySelector('ul.tagchecklist');
        if (chips && window.MutationObserver) {
            new MutationObserver(function () {
                if (box.remote && !box.remoteBusy && box.input.value.trim().length < MIN_CHARS) {
                    showAssigned();
                } else {
                    syncChecks(box);
                }
            }).observe(chips, {childList: true});
        }
    }

    /* ── Quick Edit / Bulk Edit on the products list ─────────────────── */

    function checklistTaxonomy(list) {
        var found = '';
        Array.prototype.forEach.call(list.classList, function (name) {
            if (name !== 'cat-checklist' && /-checklist$/.test(name)) {
                found = name.slice(0, -'-checklist'.length);
            }
        });
        return found;
    }

    function initInlineList(list) {
        if (!treeToolsOn()) {
            return;
        }
        var existing = boxByList.get(list);
        if (existing) {
            // Bulk Edit re-uses its row: start each time with a clear search.
            existing.input.value = '';
            existing.entries = buildEntries(list);
            setupTree(existing, true);
            applyFilter(existing);
            return;
        }

        var conf = taxonomies[checklistTaxonomy(list)] || {};
        if (!list.id) {
            list.id = nextId('ffla-ts-list-');
        }
        var ui = createControls(conf, list.id, false, 'ffla-ts--inline');
        list.parentNode.insertBefore(ui.wrap, list);
        var empty = makeEmpty();
        list.parentNode.insertBefore(empty, list.nextSibling);

        var box = {
            wrap: ui.wrap,
            list: list,
            input: ui.input,
            status: ui.status,
            empty: empty,
            entries: buildEntries(list)
        };
        boxByList.set(list, box);
        treeButtons(box, ui.bar);
        setupTree(box, true);
        bindAutoParents(box, null);

        if (features.search) {
            var run = debounce(function () { applyFilter(box); }, 60);
            box.input.addEventListener('input', run);
            box.input.addEventListener('search', run);
            bindKeys(box, function () { applyFilter(box); });
        }
    }

    function scanForChecklists(node) {
        if (!node || node.nodeType !== 1) {
            return;
        }
        if (node.matches('ul.cat-checklist')) {
            initInlineList(node);
            return;
        }
        Array.prototype.forEach.call(node.querySelectorAll('ul.cat-checklist'), initInlineList);
    }

    /* ── Start ───────────────────────────────────────────────────────── */

    /* ── Products list filters ───────────────────────────────────────── */

    function enhanceListFilters() {
        if (!features.listFilters || !$.fn.selectWoo) {
            return;
        }
        $('select.dropdown_product_cat, select.dropdown_product_brand, select.ffla-ts-tag-filter').each(function () {
            var $select = $(this);
            if ($select.hasClass('select2-hidden-accessible') || $select.data('select2')) {
                return;
            }
            $select.selectWoo({
                width: 'style',
                dropdownAutoWidth: true,
                minimumResultsForSearch: 6
            }).addClass('ffla-ts-filter');
        });
    }

    $(function () {
        enhanceListFilters();
        Array.prototype.forEach.call(document.querySelectorAll('div.categorydiv[id^="taxonomy-"]'), initCategoryBox);
        Array.prototype.forEach.call(document.querySelectorAll('div.tagsdiv[id]'), initTagsBox);

        // Products list: Quick Edit rows are clones inserted after the product
        // row; the Bulk Edit row is moved to the top of the list each time.
        var theList = document.getElementById('the-list');
        if (theList && window.MutationObserver) {
            new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    Array.prototype.forEach.call(mutation.addedNodes, scanForChecklists);
                });
            }).observe(theList, {childList: true});
        }
    });
})(jQuery);
