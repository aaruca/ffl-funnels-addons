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
