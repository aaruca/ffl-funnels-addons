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

    root.querySelectorAll('[data-ffla-wl-color]').forEach(function (field) {
        var swatch = field.querySelector('[data-ffla-wl-color-swatch]');
        var text = field.querySelector('[data-ffla-wl-color-text]');
        var clear = field.querySelector('[data-ffla-wl-color-clear]');
        if (!swatch || !text) {
            return;
        }

        // Picking from the swatch fills the text value.
        swatch.addEventListener('input', function () {
            text.value = swatch.value;
        });

        // Typing a valid hex updates the swatch.
        text.addEventListener('input', function () {
            if (HEX.test(text.value.trim())) {
                swatch.value = text.value.trim();
            }
        });

        // Clear = unset (blank means "inherit / keep default").
        if (clear) {
            clear.addEventListener('click', function () {
                text.value = '';
                text.focus();
            });
        }
    });

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

    /* ── Import/Export: copy the export JSON ───────────────────────────── */

    root.querySelectorAll('[data-ffla-wl-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            var textarea = button.closest('.wb-card__body').querySelector('.ffla-wl-io__json');
            if (!textarea) {
                return;
            }

            var done = function () {
                var label = button.textContent;
                button.textContent = 'Copied!';
                setTimeout(function () { button.textContent = label; }, 1500);
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
