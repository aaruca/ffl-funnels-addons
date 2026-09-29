/**
 * White Label — make plugin SVG menu-icon hover instant.
 *
 * WordPress core's svg-painter recolours plugin SVG icons on hover, but its
 * mouseleave handler waits 100ms (setTimeout, to match hoverIntent) and the
 * first hover decodes a fresh data-URI — so plugin icons feel laggy next to
 * dashicons (which recolour instantly via CSS `color`).
 *
 * We reuse svg-painter's own `paintElement()` (and its per-colour cache) but add
 * our own instant, namespaced hover handlers with no delay, and pre-warm the
 * focus cache so even the first hover is snappy. We don't unbind anything, so
 * hoverIntent / submenu fly-outs are untouched.
 */
(function ($) {
    'use strict';

    function apply() {
        if (!window.wp || !wp.svgPainter || typeof wp.svgPainter.paintElement !== 'function') {
            return false;
        }
        var painter = wp.svgPainter;

        $('#adminmenu li.menu-top').each(function () {
            var $li = $(this);
            var $icon = $li.find('.wp-menu-image.svg').first();
            if (!$icon.length) {
                return;
            }

            var resting = function () {
                return ($li.hasClass('current') || $li.hasClass('wp-has-current-submenu')) ? 'current' : 'base';
            };

            // Pre-warm the focus cache (decode now, not on first hover), then
            // restore the correct resting colour.
            painter.paintElement($icon, 'focus');
            painter.paintElement($icon, resting());

            // Instant hover — no 100ms mouseleave delay. Namespaced so re-applying
            // (after a theme toggle) can clear our own handlers without touching
            // svg-painter's or hoverIntent's.
            $li.off('.fflaWlIcon')
                .on('mouseenter.fflaWlIcon focusin.fflaWlIcon', function () {
                    painter.paintElement($icon, resting() === 'current' ? 'current' : 'focus');
                })
                .on('mouseleave.fflaWlIcon focusout.fflaWlIcon', function () {
                    painter.paintElement($icon, resting());
                });
        });

        return true;
    }

    // svg-painter's DOM-ready init may not have run yet (slow admin, many
    // plugins). Poll briefly until it is ready rather than giving up after one
    // fixed delay — otherwise the instant-hover handlers silently never bind.
    function scheduleApply() {
        var attempts = 0;
        (function tick() {
            if (apply() || attempts >= 40) {
                return;
            }
            attempts += 1;
            window.setTimeout(tick, 50);
        })();
    }

    $(function () {
        scheduleApply();
    });

    // svg-painter re-inits on our light/dark toggle; re-apply instant handlers.
    document.addEventListener('ffla-wl-theme-changed', function () {
        scheduleApply();
    });
})(jQuery);
