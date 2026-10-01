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

  /* ─── Narrow screens: the sidebar is a scrolling strip; show the current page ── */
  var current = document.querySelector('.wb-sidebar [aria-current="page"]');
  if (current && window.matchMedia('(max-width: 900px)').matches) {
    current.scrollIntoView({ block: 'nearest', inline: 'center' });
  }

  /* ─── Actions bar: unsaved-changes state and Discard ─────────── */
  document.querySelectorAll('.wb-actions-bar').forEach(function (bar) {
    if (bar.hasAttribute('data-savebar')) { return; } // The screen handles it itself.
    var form = bar.closest('form');
    if (!form || String(form.getAttribute('method') || '').toLowerCase() !== 'post') { return; }

    var status = document.createElement('p');
    status.className = 'wb-actions-bar__status';
    status.setAttribute('role', 'status');
    bar.insertBefore(status, bar.firstChild);

    var discard = document.createElement('button');
    discard.type = 'button';
    discard.className = 'wb-btn wb-btn--subtle wb-actions-bar__discard';
    discard.textContent = t('discard', 'Discard');
    bar.insertBefore(discard, bar.querySelector('[type="submit"]'));

    function snapshot() {
      var out = [];
      new FormData(form).forEach(function (value, key) {
        if (key === '_wpnonce' || key === '_wp_http_referer') { return; }
        out.push(key + '=' + (typeof value === 'string' ? value : (value && value.name) || ''));
      });
      return out.join('&');
    }
    var initial = snapshot();
    var dirty = false;
    function track() {
      dirty = snapshot() !== initial;
      bar.setAttribute('data-state', dirty ? 'dirty' : 'clean');
      status.textContent = dirty ? t('unsaved', 'Unsaved changes') : t('noChanges', 'No unsaved changes');
    }
    track();
    form.addEventListener('input', track);
    form.addEventListener('change', track);
    discard.addEventListener('click', function () {
      form.reset();
      form.querySelectorAll('input, select, textarea').forEach(function (el) {
        el.dispatchEvent(new Event('change', { bubbles: true }));
      });
      track();
    });
    // Saving (normal post or a module's own AJAX save) makes the current values the saved ones.
    form.addEventListener('submit', function () { initial = snapshot(); track(); });
    window.addEventListener('beforeunload', function (event) {
      if (dirty) { event.preventDefault(); event.returnValue = ''; }
    });
  });

  /* ─── Utility: Build URL-encoded params from object ──────────── */
  function buildParams(obj) {
    return Object.keys(obj).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(obj[k]);
    }).join('&');
  }
})();
