/**
 * White Label module — admin behaviour.
 *
 * For now this only switches tabs client-side. There is a single form and a
 * single Save button; the tabs are purely an organisational device.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-ffla-wl]');
    if (!root) {
        return;
    }

    function activateTab(tabSlug) {
        root.querySelectorAll('[data-ffla-wl-tab]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.fflaWlTab === tabSlug);
        });

        root.querySelectorAll('[data-ffla-wl-panel]').forEach(function (panel) {
            panel.hidden = panel.dataset.fflaWlPanel !== tabSlug;
        });

        // Remember the active tab so a save returns to it.
        var activeInput = root.querySelector('[data-ffla-wl-active-tab]');
        if (activeInput) {
            activeInput.value = tabSlug;
        }
    }

    root.querySelectorAll('[data-ffla-wl-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            activateTab(button.dataset.fflaWlTab);
        });
    });

    /* ── Colour fields ─────────────────────────────────────────────────── */

    var HEX = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;
    var NAME_RE = /\[styles\]\[(light|dark)\]\[([A-Za-z0-9]+)\]/;

    // <input type="color"> only accepts #rrggbb, so expand a valid #rgb shorthand
    // before assigning it — otherwise the browser silently rejects it and the
    // swatch jumps to black, desyncing from the (valid) typed value.
    function toSwatchHex(value) {
        value = value.trim();
        if (/^#[0-9a-fA-F]{6}$/.test(value)) {
            return value;
        }
        if (/^#[0-9a-fA-F]{3}$/.test(value)) {
            return '#' + value[1] + value[1] + value[2] + value[2] + value[3] + value[3];
        }
        return null;
    }

    // Live preview: rebuild a mode-scoped <style> from the current inputs, so
    // edits show on this page in real time. It mirrors the CSS the module
    // injects server-side (body.ffla-theme-<mode>{--ffla-wl-<key>:<hex>}) and is
    // appended last, so it overrides the saved values until the page reloads.
    // Only set values are emitted; a blank field falls back to the saved/default.
    // Chrome (sidebar, top bar, submenu, buttons, borders) previews here;
    // dashboard-only colours preview on the dashboard page.
    var previewEl = null;
    function renderPreview() {
        var modes = { light: {}, dark: {} };
        root.querySelectorAll('[data-ffla-wl-color-text]').forEach(function (input) {
            var m = input.name.match(NAME_RE);
            if (!m) {
                return;
            }
            var val = input.value.trim();
            if (HEX.test(val)) {
                modes[m[1]][m[2]] = val;
            }
        });

        var css = '';
        ['light', 'dark'].forEach(function (mode) {
            var decl = '';
            Object.keys(modes[mode]).forEach(function (key) {
                decl += '--ffla-wl-' + key + ':' + modes[mode][key] + ';';
            });
            if (decl) {
                css += 'body.ffla-theme-' + mode + '{' + decl + '}';
            }
        });

        if (!previewEl) {
            previewEl = document.createElement('style');
            previewEl.id = 'ffla-wl-live-preview';
            document.head.appendChild(previewEl);
        }
        previewEl.textContent = css;
    }

    root.querySelectorAll('[data-ffla-wl-color]').forEach(function (field) {
        var swatch = field.querySelector('[data-ffla-wl-color-swatch]');
        var text = field.querySelector('[data-ffla-wl-color-text]');
        if (!swatch || !text) {
            return;
        }

        // Picking from the swatch fills the text value.
        swatch.addEventListener('input', function () {
            text.value = swatch.value;
            renderPreview();
        });

        // Typing a valid hex (or clearing the field) updates the swatch + preview.
        text.addEventListener('input', function () {
            var full = toSwatchHex(text.value);
            if (full) {
                swatch.value = full;
            }
            renderPreview();
        });
    });

    // Seed the preview so it becomes the authority for subsequent edits.
    renderPreview();

    /* ── Restrictions: hiding a top-level item hides/blocks its children ──── */

    root.querySelectorAll('.ffla-wl-menutree__item').forEach(function (item) {
        var top = item.querySelector('.ffla-wl-menutree__top input[type="checkbox"]');
        var children = item.querySelectorAll('.ffla-wl-menutree__children input[type="checkbox"]');
        if (!top || !children.length) {
            return;
        }

        // Parent toggles every child.
        top.addEventListener('change', function () {
            Array.prototype.forEach.call(children, function (child) {
                child.checked = top.checked;
            });
        });

        // Children keep the parent in sync: all checked → parent checked;
        // any unchecked → parent unchecked.
        Array.prototype.forEach.call(children, function (child) {
            child.addEventListener('change', function () {
                top.checked = Array.prototype.every.call(children, function (c) {
                    return c.checked;
                });
            });
        });
    });

    /* ── Menu tab: drag-to-reorder (native, no dependencies) ─────────────── */

    root.querySelectorAll('[data-ffla-wl-sortable]').forEach(function (list) {
        var dragging = null;

        function afterElement(y) {
            var items = Array.prototype.slice.call(
                list.querySelectorAll('.ffla-wl-sortable__item:not(.is-dragging)')
            );
            var closest = null;
            var closestOffset = Number.NEGATIVE_INFINITY;
            items.forEach(function (child) {
                var box = child.getBoundingClientRect();
                var offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closestOffset) {
                    closestOffset = offset;
                    closest = child;
                }
            });
            return closest;
        }

        list.addEventListener('dragstart', function (e) {
            var item = e.target.closest('.ffla-wl-sortable__item');
            if (!item) {
                return;
            }
            dragging = item;
            item.classList.add('is-dragging');
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', ''); } catch (err) {}
            }
        });

        list.addEventListener('dragend', function () {
            if (dragging) {
                dragging.classList.remove('is-dragging');
                dragging = null;
            }
        });

        list.addEventListener('dragover', function (e) {
            if (!dragging) {
                return;
            }
            e.preventDefault();
            var after = afterElement(e.clientY);
            if (after == null) {
                list.appendChild(dragging);
            } else {
                list.insertBefore(dragging, after);
            }
        });
    });

    /* ── Menu tab: add / remove custom dividers ──────────────────────────── */

    var dividerSeq = 0;
    root.querySelectorAll('[data-ffla-wl-add-divider]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var list = root.querySelector('[data-ffla-wl-sortable]');
            var tpl = root.querySelector('[data-ffla-wl-divider-template]');
            if (!list || !tpl || !tpl.content) {
                return;
            }
            var template = tpl.content.firstElementChild;
            if (!template) {
                return;
            }
            var prefix = list.getAttribute('data-ffla-wl-divider-prefix') || 'ffla-divider-';
            // Monotonic counter guarantees uniqueness even for rapid clicks within
            // the same millisecond (Date.now() alone could collide).
            var token = prefix + Date.now() + '-' + (++dividerSeq);
            var node = template.cloneNode(true);
            var input = node.querySelector('input[type="hidden"]');
            if (input) {
                input.value = token;
            }
            list.appendChild(node);
        });
    });

    // Remove a divider (delegated).
    root.addEventListener('click', function (e) {
        var remove = e.target.closest('[data-ffla-wl-remove]');
        if (!remove) {
            return;
        }
        var item = remove.closest('.ffla-wl-sortable__item');
        if (item) {
            item.remove();
        }
    });

    /* ── Import/Export: copy the export JSON ───────────────────────────── */

    root.querySelectorAll('[data-ffla-wl-copy]').forEach(function (button) {
        // Capture the original label once, so a rapid second click can't record
        // "Copied!" as the label and leave it stuck.
        var originalLabel = button.textContent;
        var resetTimer = null;

        button.addEventListener('click', function () {
            var card = button.closest('.wb-card__body');
            var textarea = card ? card.querySelector('.ffla-wl-io__json') : null;
            if (!textarea) {
                return;
            }

            var done = function () {
                button.textContent = 'Copied!';
                if (resetTimer) {
                    clearTimeout(resetTimer);
                }
                resetTimer = setTimeout(function () { button.textContent = originalLabel; }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textarea.value).then(done, function () {
                    textarea.select();
                    document.execCommand('copy');
                    done();
                });
            } else {
                textarea.select();
                document.execCommand('copy');
                done();
            }
        });
    });
})();
