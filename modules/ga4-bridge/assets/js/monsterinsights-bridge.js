/**
 * MonsterInsights storefront compatibility for Bricks + AJAX side-carts.
 *
 * MonsterInsights owns the tag and purchase/refund lifecycle. This file only
 * fills an event when the active page's dataLayer does not already contain it.
 */
(function (window, document) {
    'use strict';

    var config = window.fflaMonsterInsightsBridge || {};
    var measurementId = String(config.measurementId || '');
    var layer = window.dataLayer = window.dataLayer || [];
    var currentItems = Array.isArray(config.items) ? config.items.slice() : [];
    var bound = false;

    if (!/^G-[A-Z0-9]+$/.test(measurementId) || !currentItems.length) {
        return;
    }

    // The page product as printed by PHP, restored when a variation is cleared.
    var baseItem = Object.assign({}, currentItems[0]);
    var pageProductId = String(config.productId || baseItem.item_id || '');

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

    function eventAlreadySent(name, itemId, startIndex) {
        var start = Math.max(0, Number(startIndex) || 0);

        for (var index = start; index < layer.length; index++) {
            var command = asCommand(layer[index]);
            if (!command || command.type !== 'event' || command.name !== name) {
                continue;
            }

            var parameters = command.parameters || {};
            // Our own earlier fallback (e.g. a previous add to cart on this
            // page) is not MonsterInsights' event for the current action.
            if (parameters.ffla_bridge) {
                continue;
            }

            // A gtag event without send_to is broadcast to every configured
            // destination, including MonsterInsights, and is therefore also a
            // duplicate. With send_to present, only match its GA4 property.
            if (typeof parameters.send_to !== 'undefined' && !targetsMonsterInsights(parameters.send_to)) {
                continue;
            }

            if (!itemId) {
                return true;
            }

            var items = Array.isArray(parameters.items) ? parameters.items : [];
            if (items.some(function (item) {
                return String(item.item_id || item.id || '') === String(itemId);
            })) {
                return true;
            }
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

    function sendFallback(name, parameters, itemId, startIndex, delay) {
        window.setTimeout(function () {
            waitForTracker(function () {
                if (eventAlreadySent(name, itemId, startIndex)) {
                    return;
                }

                parameters.send_to = measurementId;
                parameters.ffla_bridge = 'monsterinsights_compatibility';
                try {
                    tracker()('event', name, parameters);
                } catch (error) {
                    // Analytics must never interrupt product or cart behavior.
                }
            });
        }, delay || 0);
    }

    function baseParameters(items, value) {
        return {
            currency: String(config.currency || 'USD'),
            value: Number(value || 0),
            items: items
        };
    }

    function beginProductEvent() {
        var firstItem = currentItems[0] || {};

        sendFallback(
            'view_item',
            baseParameters(currentItems, firstItem.price || config.value),
            '',
            0,
            900
        );
    }

    function bindWooCommerceEvents(attempt) {
        attempt = attempt || 0;

        if (bound) {
            return;
        }

        if (!window.jQuery || !document.body) {
            if (attempt < 40) {
                window.setTimeout(function () {
                    bindWooCommerceEvents(attempt + 1);
                }, 250);
            }
            return;
        }

        bound = true;
        var $ = window.jQuery;

        $(document.body).on('found_variation.fflaMonsterInsightsBridge', 'form.variations_form', function (event, variation) {
            if (!currentItems[0] || !variation) {
                return;
            }

            currentItems[0].item_id = String(variation.variation_id || currentItems[0].item_id || '');
            if (typeof variation.display_price !== 'undefined') {
                currentItems[0].price = Number(variation.display_price || 0);
            }

            // item_variant: the chosen options as the shopper sees them.
            var labels = [];
            $(this).find('select[name^="attribute_"]').each(function () {
                var $select = $(this);
                var label = String($select.find('option:selected').text() || '').trim();
                if ($select.val() && label) {
                    labels.push(label);
                }
            });
            if (labels.length) {
                currentItems[0].item_variant = labels.join(', ');
            } else {
                delete currentItems[0].item_variant;
            }
        });

        // Choice cleared: back to the parent product's data.
        $(document.body).on('reset_data.fflaMonsterInsightsBridge', 'form.variations_form', function () {
            currentItems[0] = Object.assign({}, baseItem);
        });

        $(document.body).on('added_to_cart.fflaMonsterInsightsBridge', function (event, fragments, cartHash, $button) {
            if (!currentItems[0]) {
                return;
            }

            var item = Object.assign({}, currentItems[0]);
            var startIndex = Math.max(0, layer.length - 6);
            var $source = $button && $button.jquery ? $button : $(event.target);
            var $form = $source.closest('form.cart');
            var quantity = Number($source.data('quantity') || $form.find('input.qty').val() || 1);
            var variationId = String($form.find('input[name="variation_id"]').val() || '');

            // Which product was added? Loop buttons carry data-product_id; the
            // product form names it in add-to-cart / product_id. Another
            // product's button (related products, upsells) is skipped, because
            // only this page's product data is available. When nothing names a
            // product (a side-cart that passes no button), it is this page's.
            var addedId = String(
                $source.data('product_id') ||
                $form.find('[name="add-to-cart"]').val() ||
                $form.find('input[name="product_id"]').val() ||
                ''
            );
            if (addedId && addedId !== pageProductId && addedId !== String(item.item_id) && addedId !== variationId) {
                return;
            }

            item.quantity = quantity > 0 ? quantity : 1;
            if (variationId && variationId !== '0') {
                item.item_id = variationId;
            }

            sendFallback(
                'add_to_cart',
                baseParameters([item], Number(item.price || 0) * item.quantity),
                '',
                startIndex,
                180
            );
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bindWooCommerceEvents();
            beginProductEvent();
        });
    } else {
        bindWooCommerceEvents();
        beginProductEvent();
    }
})(window, document);
