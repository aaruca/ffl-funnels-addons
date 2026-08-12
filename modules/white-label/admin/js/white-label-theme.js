/**
 * White Label — recolour sidebar plugin icons that ship as a background-image
 * SVG (e.g. WooCommerce, SwiftSearch, Rank Math, WPCode).
 *
 * `color` can't touch a background image, and CSS `mask` must NOT be used here:
 * it promotes elements to their own GPU layer, which made large admin pages
 * (WooCommerce products/settings) render blank until a reflow. Instead we decode
 * the inline SVG, rewrite its fill to the sidebar icon colour, and set it back
 * as the background-image — same paint model as before, no extra layer.
 *
 * The colour comes from the --ffla-wl-sidebarIcon variable, which is declared on
 * `body.ffla-theme-<mode>`, so we read it from document.body (NOT documentElement)
 * and can re-run on the light/dark toggle to re-tint for the new mode. Each icon's
 * original SVG is cached so repeated tints never compound.
 *
 * This bakes in the resting colour only; hover/current recolouring is left to
 * dashicons (handled in CSS). Multi-colour icons flatten to one colour, which is
 * the intended behaviour for menu icons.
 */
(function () {
    'use strict';

    var SELECTOR = '#adminmenu .wp-menu-image.svg';

    function iconColour() {
        // The variable lives on body.ffla-theme-<mode>; read it there.
        var value = getComputedStyle(document.body)
            .getPropertyValue('--ffla-wl-sidebarIcon')
            .trim();
        return value || '#a7aaad';
    }

    // Pull the original SVG markup out of an element's background-image once and
    // cache it, so re-tinting works from the source rather than a prior tint.
    function originalSvg(el) {
        if (el.__fflaSvg !== undefined) {
            return el.__fflaSvg;
        }

        var image = window.getComputedStyle(el).backgroundImage;
        var match = image.match(/url\(["']?(data:image\/svg\+xml[^"')]+)["']?\)/i);
        if (!match) {
            el.__fflaSvg = null;
            return null;
        }

        var uri = match[1];
        var svg;
        try {
            if (uri.indexOf(';base64,') !== -1) {
                svg = atob(uri.split(';base64,')[1]);
            } else {
                svg = decodeURIComponent(uri.replace(/^data:image\/svg\+xml[^,]*,/i, ''));
            }
        } catch (e) {
            svg = null;
        }

        el.__fflaSvg = svg;
        return svg;
    }

    function tint(svg, colour) {
        // Rewrite every real fill and stroke (skip `none`, so outline icons keep
        // their shape) plus any currentColor references, to our colour. The
        // `stroke="`/`stroke:` patterns don't match stroke-width/linecap/etc.
        svg = svg
            .replace(/fill="(?!none")[^"]*"/gi, 'fill="' + colour + '"')
            .replace(/fill:\s*(?!none)[^;"']+/gi, 'fill:' + colour)
            .replace(/stroke="(?!none")[^"]*"/gi, 'stroke="' + colour + '"')
            .replace(/stroke:\s*(?!none)[^;"']+/gi, 'stroke:' + colour)
            .replace(/currentColor/gi, colour);

        // If the SVG declared no fill at all, add one on the root element.
        if (!/fill=|fill:/i.test(svg)) {
            svg = svg.replace(/<svg\b/i, '<svg fill="' + colour + '"');
        }

        return 'data:image/svg+xml,' + encodeURIComponent(svg);
    }

    function recolour() {
        var colour = iconColour();
        var icons = document.querySelectorAll(SELECTOR);

        // Read phase first (cache originals), then write phase, so we don't
        // interleave style reads and writes across the fixed sidebar.
        var jobs = [];
        Array.prototype.forEach.call(icons, function (el) {
            var svg = originalSvg(el);
            if (svg) {
                jobs.push([el, tint(svg, colour)]);
            }
        });
        jobs.forEach(function (job) {
            job[0].style.setProperty('background-image', 'url("' + job[1] + '")', 'important');
        });
    }

    // Defer to after first paint so a heavy admin page renders before we mutate
    // the sidebar (belt-and-braces against paint invalidation on large pages).
    function schedule() {
        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(recolour);
        } else {
            recolour();
        }
    }

    // Expose for the toggle, and re-tint when the theme mode changes.
    window.fflaWlRecolourIcons = schedule;
    document.addEventListener('ffla-wl-theme-changed', schedule);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', schedule);
    } else {
        schedule();
    }
})();
