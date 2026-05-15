(function ($) {
    'use strict';

    $(document).ready(function () {
        initProductTab();
        initWidget();
    });

    function initProductTab() {
        var $tab = $('.ffla-loadout-tab');
        if (!$tab.length) {
            return;
        }

        // Tier tab switcher.
        $tab.on('click', '.ffla-loadout-tab__tier-btn', function () {
            var $btn = $(this);
            var slug = $btn.data('tier-slug');
            $btn.siblings().removeClass('is-active');
            $btn.addClass('is-active');
            $tab.find('.ffla-loadout-tab__panel').removeClass('is-active');
            $tab.find('.ffla-loadout-tab__panel[data-tier-slug="' + slug + '"]').addClass('is-active');
        });

        // Single item add.
        $tab.on('click', '.ffla-loadout-tab__add-btn', function () {
            var $btn = $(this);
            var $panel = $btn.closest('.ffla-loadout-tab__panel');
            var $tabRoot = $btn.closest('.ffla-loadout-tab');

            var data = {
                action: 'loadout_add_item',
                nonce: loadoutFrontend.nonce,
                product_id: $btn.data('product-id'),
                quantity: $btn.data('quantity') || 1,
                discount_pct: $btn.data('discount-pct') || 0,
                item_id: $btn.data('item-id') || 0,
                tier_id: $panel.data('tier-id') || 0,
                tier_slug: $panel.data('tier-slug') || '',
                loadout_id: $tabRoot.data('loadout-id') || 0,
                product_loadout_id: $tabRoot.data('product-loadout-id') || 0,
                source: 'product_tab',
            };

            addToCart($btn, data);
        });

        // Add entire tier.
        $tab.on('click', '.ffla-loadout-tab__add-tier-btn', function () {
            var $btn = $(this);
            var $tabRoot = $btn.closest('.ffla-loadout-tab');

            var data = {
                action: 'loadout_add_tier',
                nonce: loadoutFrontend.nonce,
                tier_id: $btn.data('tier-id') || 0,
                tier_slug: $btn.data('tier-slug') || '',
                loadout_id: $tabRoot.data('loadout-id') || 0,
                product_loadout_id: $tabRoot.data('product-loadout-id') || 0,
                source: 'product_tab',
            };

            addToCart($btn, data);
        });
    }

    function initWidget() {
        var $widget = $('.ffla-loadout');
        if (!$widget.length) {
            return;
        }

        // Tier tab switcher.
        $widget.on('click', '.ffla-loadout__tier-btn', function () {
            var $btn = $(this);
            var slug = $btn.data('tier-slug');
            $btn.siblings().removeClass('is-active').attr('aria-selected', 'false');
            $btn.addClass('is-active').attr('aria-selected', 'true');
            $widget.find('.ffla-loadout__panel').removeClass('is-active');
            $widget.find('.ffla-loadout__panel[data-tier-slug="' + slug + '"]').addClass('is-active');
            refreshCartSummary($widget);
        });

        // Single item add.
        $widget.on('click', '.ffla-loadout__add-btn', function () {
            var $btn = $(this);
            var $panel = $btn.closest('.ffla-loadout__panel');
            var $widgetRoot = $btn.closest('.ffla-loadout');

            var data = {
                action: 'loadout_add_item',
                nonce: loadoutFrontend.nonce,
                product_id: $btn.data('product-id'),
                quantity: $btn.data('quantity') || 1,
                discount_pct: $btn.data('discount-pct') || 0,
                item_id: $btn.data('item-id') || 0,
                tier_id: $panel.data('tier-id') || 0,
                loadout_id: $widgetRoot.data('loadout-id') || 0,
                source: 'widget',
            };

            addToCart($btn, data, function () {
                refreshCartSummary($widgetRoot);
            });
        });

        // Master ADD CART (entire tier).
        $widget.on('click', '.ffla-loadout__add-tier-btn', function () {
            var $btn = $(this);
            var $widgetRoot = $btn.closest('.ffla-loadout');

            var data = {
                action: 'loadout_add_tier',
                nonce: loadoutFrontend.nonce,
                tier_id: $btn.data('tier-id') || 0,
                loadout_id: $widgetRoot.data('loadout-id') || 0,
                source: 'widget',
            };

            addToCart($btn, data, function () {
                refreshCartSummary($widgetRoot);
            });
        });

        // Initial cart summary load.
        refreshCartSummary($widget);
    }

    function addToCart($btn, data, onSuccess) {
        var originalText = $btn.text();
        $btn.prop('disabled', true).text(loadoutFrontend.strings.adding);

        $.ajax({
            url: loadoutFrontend.ajaxUrl,
            method: 'POST',
            data: data,
            success: function (response) {
                if (response.success) {
                    $btn.text(loadoutFrontend.strings.added);
                    // Trigger WC fragment refresh.
                    $(document.body).trigger('added_to_cart', [
                        response.data.fragments || {},
                        response.data.cart_hash || '',
                        $btn,
                    ]);
                    if (onSuccess) onSuccess();
                    setTimeout(function () {
                        $btn.text(originalText).prop('disabled', false);
                    }, 1500);
                } else {
                    $btn.text(loadoutFrontend.strings.addError).prop('disabled', false);
                    setTimeout(function () { $btn.text(originalText); }, 2000);
                }
            },
            error: function () {
                $btn.text(loadoutFrontend.strings.addError).prop('disabled', false);
                setTimeout(function () { $btn.text(originalText); }, 2000);
            }
        });
    }

    function refreshCartSummary($widget) {
        var $summary = $widget.find('.ffla-loadout__cart-summary');
        if (!$summary.length) {
            return;
        }
        var loadoutId = $widget.data('loadout-id');

        $.ajax({
            url: loadoutFrontend.ajaxUrl,
            method: 'POST',
            data: {
                action: 'loadout_get_cart_summary',
                nonce: loadoutFrontend.nonce,
                loadout_id: loadoutId,
            },
            success: function (response) {
                if (!response.success) return;
                var d = response.data;
                var html = '';
                if (d.items.length === 0) {
                    html = '<p>' + 'Your cart is empty.' + '</p>';
                } else {
                    html = '<ul class="ffla-loadout__cart-list">';
                    d.items.forEach(function (item) {
                        html += '<li' + (item.is_bonus ? ' class="is-bonus"' : '') + '>';
                        html += '<span class="item-name">' + item.name + '</span>';
                        html += '<span class="item-qty">×' + item.quantity + '</span>';
                        html += '<span class="item-price">' + item.current + '</span>';
                        html += '</li>';
                    });
                    html += '</ul>';
                    html += '<p class="ffla-loadout__cart-savings">Savings: ' + d.savings + '</p>';
                    html += '<p class="ffla-loadout__cart-total">Total: ' + d.total + '</p>';
                }
                $summary.html(html);

                // Update progress bar.
                var $progress = $widget.find('.ffla-loadout__progress-bar');
                if ($progress.length) {
                    var $activePanel = $widget.find('.ffla-loadout__panel.is-active');
                    var threshold = parseInt($activePanel.data('threshold'), 10) || 0;
                    var tierId = $activePanel.data('tier-id');
                    var count = d.tier_counts[tierId] || 0;
                    var pct = threshold > 0 ? Math.min(100, (count / threshold) * 100) : 0;
                    $progress.css('width', pct + '%');
                    var $label = $widget.find('.ffla-loadout__progress-label');
                    if (threshold > 0 && count < threshold) {
                        $label.text((threshold - count) + ' more item(s) to unlock perks');
                    } else if (threshold > 0) {
                        $label.text('Perks unlocked!');
                    }
                }
            }
        });
    }

})(jQuery);
