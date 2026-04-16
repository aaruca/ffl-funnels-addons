/**
 * FFL Funnels Addons — Shared Admin JS
 *
 * Handles: module toggles, check-for-updates button, and shared utilities.
 */
(function () {
  'use strict';

  var i18n = (window.fflaAdmin && window.fflaAdmin.i18n) || {};

  function t(key, fallback) {
    return (i18n && i18n[key]) ? String(i18n[key]) : (fallback || '');
  }

  document.addEventListener('DOMContentLoaded', function () {
    initModuleToggles();
    initCheckUpdate();
  });

  /* ─── Module toggles on the Dashboard ────────────────────────── */
  function initModuleToggles() {
    var toggles = document.querySelectorAll('.ffla-module-toggle');

    toggles.forEach(function (toggle) {
      toggle.addEventListener('change', function () {
        var moduleId = this.dataset.module;
        var active = this.checked ? 1 : 0;
        var card = this.closest('.ffla-module-card');
        var self = this;

        // Visual feedback
        if (card) {
          card.style.opacity = '0.6';
          card.style.pointerEvents = 'none';
        }

        fetch(fflaAdmin.ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: buildParams({
            action: 'ffla_toggle_module',
            nonce: fflaAdmin.nonce,
            module_id: moduleId,
            active: active
          })
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.success && data.data && data.data.reload) {
              window.location.reload();
              return;
            }
            if (card) {
              card.style.opacity = '1';
              card.style.pointerEvents = '';
            }
            // Revert the toggle so the UI reflects actual server state.
            self.checked = !self.checked;
            var msg = (data && data.data && data.data.message)
              ? data.data.message
              : t('genericError', 'An unexpected error occurred.');
            window.alert(msg);
          })
          .catch(function () {
            if (card) {
              card.style.opacity = '1';
              card.style.pointerEvents = '';
            }
            self.checked = !self.checked;
            window.alert(t('networkError', 'Network error. Please try again.'));
          });
      });
    });
  }

  /* ─── Check for Updates button ───────────────────────────────── */
  function initCheckUpdate() {
    var btn = document.getElementById('ffla-check-update');
    var result = document.getElementById('ffla-update-result');

    if (!btn) return;

    var defaultLabel = t('checkForUpdates', 'Check for Updates Now');

    btn.addEventListener('click', function () {
      btn.disabled = true;
      btn.textContent = t('checking', 'Checking...');
      if (result) result.textContent = '';

      fetch(fflaAdmin.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: buildParams({
          action: 'ffla_check_update',
          nonce: fflaAdmin.nonce
        })
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          btn.disabled = false;
          btn.textContent = defaultLabel;

          if (result) {
            result.textContent = (data && data.data && data.data.message)
              ? data.data.message
              : t('done', 'Done');
            result.style.color = (data && data.data && data.data.status === 'update_available')
              ? 'var(--wb-color-brand-foreground)'
              : 'var(--wb-color-success-foreground)';
          }
        })
        .catch(function () {
          btn.disabled = false;
          btn.textContent = defaultLabel;
          if (result) {
            result.textContent = t('networkError', 'Network error.');
            result.style.color = 'var(--wb-color-danger-foreground)';
          }
        });
    });
  }

  /* ─── Utility: Build URL-encoded params from object ──────────── */
  function buildParams(obj) {
    return Object.keys(obj).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(obj[k]);
    }).join('&');
  }
})();
