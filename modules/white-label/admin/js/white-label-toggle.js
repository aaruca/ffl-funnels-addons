/**
 * White Label — light/dark toggle.
 *
 * Swaps the body theme class instantly on click and persists the choice to the
 * user's meta. Loaded on every admin page.
 */
(function () {
    'use strict';

    var cfg = window.fflaWlToggle;
    if (!cfg) {
        return;
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('#wp-admin-bar-ffla-wl-theme-toggle');
        if (!trigger) {
            return;
        }
        e.preventDefault();

        var body = document.body;
        var next = body.classList.contains('ffla-theme-dark') ? 'light' : 'dark';

        body.classList.toggle('ffla-theme-dark', next === 'dark');
        body.classList.toggle('ffla-theme-light', next === 'light');

        var indicator = trigger.querySelector('.ffla-wl-theme-toggle');
        if (indicator) {
            indicator.setAttribute('data-current', next);
        }

        // Let the icon recolour helper re-tint sidebar SVG icons for the new
        // mode (its --ffla-wl-sidebarIcon differs between light and dark).
        document.dispatchEvent(new CustomEvent('ffla-wl-theme-changed', { detail: { mode: next } }));

        var data = new FormData();
        data.append('action', cfg.action);
        data.append('nonce', cfg.nonce);
        data.append('mode', next);
        fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
    });
})();
