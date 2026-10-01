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

    // Light/dark toggle: hand svg-painter the new mode's icon colours and repaint
    // every painted icon in place (mirroring core's paint() resting colours),
    // then re-apply the instant hover handlers.
    //
    // Deliberately NOT wp.svgPainter.init(): core's init() pushes every icon into
    // its internal list again and binds another mouseenter/mouseleave pair each
    // call, so repeated toggles stacked duplicate handlers and delayed repaints.
    // setColors() + paintElement() change colours without binding anything.
    function repaintForMode(mode) {
        var colours = window.fflaWlIconColours && window.fflaWlIconColours[mode];
        var painter = window.wp && wp.svgPainter;
        if (!colours || !painter || typeof painter.setColors !== 'function' || typeof painter.paintElement !== 'function') {
            return;
        }

        if (window._wpColorScheme) {
            window._wpColorScheme.icons = colours;
        }
        painter.setColors({ icons: colours });

        // Same elements core's svg-painter paints: background-image SVG data URIs.
        $('#adminmenu .wp-menu-image, #wpadminbar .ab-item').each(function () {
            var $el = $(this);
            var bg = $el.css('background-image');
            if (!bg || bg.indexOf('data:image/svg+xml;base64') === -1) {
                return;
            }
            var $item = $el.parent().parent();
            var current = $item.hasClass('current') || $item.hasClass('wp-has-current-submenu');
            painter.paintElement($el, current ? 'current' : 'base');
        });
    }

    document.addEventListener('ffla-wl-theme-changed', function (e) {
        repaintForMode(e.detail && e.detail.mode);
        scheduleApply();
    });
})(jQuery);
