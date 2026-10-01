(function () {
    'use strict';
    async function send(root, data) {
        const result = root.querySelector('.ffla-ops-result');
        const buttons = Array.from(root.querySelectorAll('button'));
        buttons.forEach(button => { button.disabled = true; });
        root.setAttribute('aria-busy', 'true');
        result.textContent = 'Working…'; result.dataset.error = 'false';
        try {
            const response = await fetch(fflaOps.ajax, {method: 'POST', credentials: 'same-origin', body: data});
            const body = await response.json();
            if (!body.success) { throw new Error(body.data?.message || 'The request failed. Reload and check the order before trying again.'); }
            result.textContent = body.data.message;
            if (body.data.reload) {
                const link = document.createElement('a');
                link.href = window.location.href; link.textContent = ' Reload order';
                result.appendChild(link);
                const update = document.querySelector('#publish');
                if (update) { update.disabled = true; }
                // Do not auto-refresh: the main WooCommerce form may contain unrelated unsaved edits.
                return;
            }
            if (body.data.html) {
                const fragment = document.createElement('template');
                fragment.innerHTML = body.data.html;
                const replacement = fragment.content.querySelector('[data-ffla-order]');
                if (replacement) {
                    replacement.querySelector('.ffla-ops-result').textContent = body.data.message;
                    root.replaceWith(replacement);
                    return;
                }
            }
            buttons.forEach(button => { button.disabled = false; });
        } catch (error) {
            result.textContent = error.message; result.dataset.error = 'true';
            buttons.forEach(button => { button.disabled = false; });
        } finally { root.removeAttribute('aria-busy'); }
    }
    document.addEventListener('change', function (event) {
        const root = event.target.closest('[data-ffla-order]');
        if (root && event.target.name?.startsWith('ops[')) { root.dataset.dirty = 'true'; }
    });
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-ops-action], [data-template-action]');
        if (!button || button.disabled) { return; }
        event.preventDefault();
        if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) { return; }
        const order = button.closest('[data-ffla-order]');
        const root = order || button.closest('[data-ffla-template]');
        if (order && order.dataset.dirty === 'true' && button.dataset.opsAction !== 'save') {
            root.querySelector('.ffla-ops-result').textContent = 'Save Order Management changes before this separate action.';
            return;
        }
        const data = new FormData();
        root.querySelectorAll('input[name], textarea[name], select[name]').forEach(input => {
            if (input.disabled || ((input.type === 'checkbox' || input.type === 'radio') && !input.checked)) { return; }
            if (input.type === 'file') { if (input.files[0]) { data.append(input.name, input.files[0]); } }
            else { data.append(input.name, input.value); }
        });
        data.set('action', order ? 'ffla_ops' : 'ffla_ops_template');
        data.set('nonce', root.dataset.nonce);
        data.set('operation', button.dataset.opsAction || button.dataset.templateAction);
        if (order) { data.set('order', order.dataset.fflaOrder); data.set('intent', order.dataset.intent); }
        send(root, data);
    });
}());

/* Settings screen: one section at a time, live section status, switch dependencies and unsaved-changes bar. */
(function () {
    'use strict';
    const root = document.querySelector('[data-ffla-settings]');
    if (!root) { return; }
    const form = root.querySelector('[data-ffla-settings-form]');
    const links = Array.from(root.querySelectorAll('[data-section-link]'));
    const panels = Array.from(root.querySelectorAll('[data-section]'));
    const sectionField = root.querySelector('[data-section-field]');
    const bar = root.querySelector('[data-savebar]');
    const status = root.querySelector('[data-save-status]');
    root.classList.add('is-js');

    function show(slug, focus) {
        const panel = panels.find(p => p.dataset.section === slug) || panels[0];
        panels.forEach(p => p.classList.toggle('is-active', p === panel));
        links.forEach(a => { if (a.dataset.sectionLink === panel.dataset.section) { a.setAttribute('aria-current', 'true'); } else { a.removeAttribute('aria-current'); } });
        const tool = panel.classList.contains('ffla-set-tool');
        root.toggleAttribute('data-tool-active', tool);
        if (sectionField && !tool) { sectionField.value = panel.dataset.section; }
        if (focus) {
            const heading = panel.querySelector('h2');
            if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus({preventScroll: true}); }
            if (root.getBoundingClientRect().top < 0) { root.scrollIntoView({block: 'start'}); }
        }
    }
    links.forEach(a => a.addEventListener('click', function (event) {
        event.preventDefault();
        show(a.dataset.sectionLink, true);
        history.replaceState(null, '', '#' + a.dataset.sectionLink);
    }));
    window.addEventListener('hashchange', () => show(location.hash.slice(1)));
    show(location.hash.slice(1) || 'general');
    if (!form) { return; }
    // A hidden section cannot show the browser's own message for an invalid value, so open it first.
    form.addEventListener('invalid', event => { const panel = event.target.closest('[data-section]'); if (panel) { show(panel.dataset.section); } }, true);

    const rows = Array.from(form.querySelectorAll('[data-key]'));
    const switches = {};
    const labels = {};
    const requires = {};
    rows.forEach(row => {
        const key = row.dataset.key;
        const input = row.querySelector('input.ffla-set-switch');
        if (input) { switches[key] = input; }
        labels[key] = (row.querySelector('.ffla-set-label') || {}).textContent || key;
        requires[key] = (row.dataset.requires || '').split(' ').filter(Boolean);
    });
    function on(key, seen) {
        seen = seen || new Set();
        if (seen.has(key)) { return true; }
        seen.add(key);
        if (switches[key] && !switches[key].checked) { return false; }
        return requires[key].every(k => on(k, seen));
    }
    function list(names) {
        return names.length < 2 ? names.join('') : names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1];
    }
    function refresh() {
        rows.forEach(row => {
            const missing = requires[row.dataset.key].filter(k => !on(k));
            row.classList.toggle('is-waiting', missing.length > 0);
            // The section's main switch says it once ("Turn this on…"), so rows only name other switches.
            const panel = row.closest('[data-section]');
            const main = panel ? panel.dataset.main : '';
            const named = missing.filter(k => !(k === main && switches[main] && !switches[main].checked));
            const note = row.querySelector('[data-needs-text]');
            if (note) {
                note.hidden = !named.length;
                note.textContent = named.length ? 'Works once ' + list(named.map(k => labels[k])) + ' ' + (named.length > 1 ? 'are' : 'is') + ' on.' : '';
            }
            const hint = row.querySelector('[data-main-hint]');
            if (hint) { hint.hidden = !(switches[row.dataset.key] && !switches[row.dataset.key].checked && panel.querySelector('.ffla-set-row[data-requires]')); }
            const state = row.querySelector('[data-switch-state]');
            if (state && switches[row.dataset.key]) { state.textContent = switches[row.dataset.key].checked ? 'On' : 'Off'; }
        });
        panels.forEach(panel => {
            const target = root.querySelector('[data-state-for="' + panel.dataset.section + '"]');
            if (!target) { return; }
            const inputs = Array.from(panel.querySelectorAll('input.ffla-set-switch'));
            const main = panel.dataset.main && switches[panel.dataset.main];
            const count = inputs.filter(i => i.checked).length;
            let text = 'Off';
            if (!(main && !main.checked) && count) { text = count === inputs.length ? 'On' : count + ' of ' + inputs.length + ' on'; }
            target.textContent = text;
            const dot = target.parentElement.querySelector('[data-dot]');
            if (dot) { dot.dataset.dot = 'Off' === text ? 'off' : 'on'; }
        });
    }

    const snapshot = () => { const data = new FormData(form); data.delete('ffla_section'); return new URLSearchParams(data).toString(); }; // Changing section is not an edit.
    let initial = snapshot();
    let dirty = false;
    function track() {
        dirty = snapshot() !== initial;
        bar.dataset.state = dirty ? 'dirty' : 'clean';
        status.textContent = dirty ? 'You have unsaved changes.' : 'No unsaved changes.';
    }
    form.addEventListener('change', () => { refresh(); track(); });
    form.addEventListener('input', track);
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    refresh();
}());
