/**
 * MonsterInsights storefront compatibility for Bricks product templates.
 *
 * MonsterInsights owns the tag and every cart and order event: its eCommerce
 * Addon sends add_to_cart, remove_from_cart, begin_checkout, purchase and
 * refunds from the server. This file only fills in view_item on a single
 * product page, when the page's dataLayer does not already contain one for
 * MonsterInsights (a custom Bricks template can skip the WooCommerce hook
 * MonsterInsights prints it from).
 *
 * It deliberately sends no add_to_cart: MonsterInsights' server-side event is
 * invisible to the dataLayer, so a browser copy would always be a duplicate.
 */
(function (window, document) {
    'use strict';

    var config = window.fflaMonsterInsightsBridge || {};
    var measurementId = String(config.measurementId || '');
    var layer = window.dataLayer = window.dataLayer || [];
    var items = Array.isArray(config.items) ? config.items.slice() : [];

    if (!/^G-[A-Z0-9]+$/.test(measurementId) || !items.length) {
        return;
    }

    function tracker() {
        return typeof window.__gtagTracker === 'function' ? window.__gtagTracker : null;
    }

    function asCommand(entry) {
        if (!entry) {
            return null;
        }

        if (Array.isArray(entry) || Object.prototype.toString.call(entry) === '[object Arguments]') {
            return {
                type: entry[0],
                name: entry[1],
                parameters: entry[2] || {}
            };
        }

        if (typeof entry === 'object' && entry.event) {
            return {
                type: 'event',
                name: entry.event,
                parameters: entry
            };
        }

        return null;
    }

    function targetsMonsterInsights(sendTo) {
        if (Array.isArray(sendTo)) {
            return sendTo.indexOf(measurementId) !== -1;
        }

        return String(sendTo || '').split(',').map(function (value) {
            return value.trim();
        }).indexOf(measurementId) !== -1;
    }

    function eventAlreadySent(name) {
        for (var index = 0; index < layer.length; index++) {
            var command = asCommand(layer[index]);
            if (!command || command.type !== 'event' || command.name !== name) {
                continue;
            }

            var parameters = command.parameters || {};
            // Our own earlier fallback is not MonsterInsights' event.
            if (parameters.ffla_bridge) {
                continue;
            }

            // A gtag event without send_to is broadcast to every configured
            // destination, including MonsterInsights, and is therefore also a
            // duplicate. With send_to present, only match its GA4 property.
            if (typeof parameters.send_to !== 'undefined' && !targetsMonsterInsights(parameters.send_to)) {
                continue;
            }

            return true;
        }

        return false;
    }

    function waitForTracker(callback, attempt) {
        attempt = attempt || 0;

        // MonsterInsights deliberately excludes selected logged-in roles.
        if (window.mi_track_user === false) {
            return;
        }

        if (tracker()) {
            callback();
            return;
        }

        if (attempt < 40) {
            window.setTimeout(function () {
                waitForTracker(callback, attempt + 1);
            }, 250);
        }
    }

    function sendViewItem() {
        window.setTimeout(function () {
            waitForTracker(function () {
                if (eventAlreadySent('view_item')) {
                    return;
                }

                var firstItem = items[0] || {};
                try {
                    tracker()('event', 'view_item', {
                        currency: String(config.currency || 'USD'),
                        value: Number(firstItem.price || config.value || 0),
                        items: items,
                        send_to: measurementId,
                        ffla_bridge: 'monsterinsights_compatibility'
                    });
                } catch (error) {
                    // Analytics must never interrupt product behavior.
                }
            });
        }, 900);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sendViewItem);
    } else {
        sendViewItem();
    }
})(window, document);
