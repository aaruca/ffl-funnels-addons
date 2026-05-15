(function ($) {
    'use strict';

    $(document).ready(function () {
        initProductTab();
        initWidget();
        initGlobalHandlers();
        refreshAllCartSummaries();
    });

    function initProductTab() {
        var $tab = $('.ffla-loadout-tab');
        if (!$tab.length) {
            return;
        }

        $tab.on('click', '.ffla-loadout-tab__tier-btn', function () {
            var $btn = $(this);
            var slug = $btn.data('tier-slug');
            $btn.siblings().removeClass('is-active');
            $btn.addClass('is-active');
            $tab.find('.ffla-loadout-tab__panel').removeClass('is-active');
            $tab.find('.ffla-loadout-tab__panel[data-tier-slug="' + slug + '"]').addClass('is-active');
        });

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
        // Monolithic widget: kept for backward compat with the existing Loadout element.
        $('.ffla-loadout').not('.ffla-loadout--cart-only').not('.ffla-loadout--progress-only').each(function () {
            var $widget = $(this);
            // Only initialize widget-specific behaviors for full monolithic widgets that contain panels.
            if (!$widget.find('.ffla-loadout__panel').length) {
                return;
            }
        });
    }

    function initGlobalHandlers() {
        // Tier tab switching — global. Toggles all panels with matching data-tier-slug.
        $(document).on('click', '.ffla-loadout__tier-btn', function () {
            var $btn = $(this);
            var slug = $btn.data('tier-slug');
            // Toggle active state within this nav group.
            $btn.siblings('.ffla-loadout__tier-btn').removeClass('is-active').attr('aria-selected', 'false');
            $btn.addClass('is-active').attr('aria-selected', 'true');
            // Toggle all panels (global) with matching slug visible, others hidden.
            $('.ffla-loadout__panel').removeClass('is-active');
            $('.ffla-loadout__panel[data-tier-slug="' + slug + '"]').addClass('is-active');
            refreshAllCartSummaries();
        });

        // Add item — global.
        $(document).on('click', '.ffla-loadout__add-btn', function () {
            var $btn = $(this);
            if ($btn.is(':disabled')) return;

            // Build data from button attributes first, fall back to ancestors.
            var $panel = $btn.closest('.ffla-loadout__panel');
            var $widgetRoot = $btn.closest('.ffla-loadout');

            var data = {
                action: 'loadout_add_item',
                nonce: loadoutFrontend.nonce,
                product_id: $btn.data('product-id'),
                quantity: $btn.data('quantity') || 1,
                discount_pct: $btn.data('discount-pct') || 0,
                item_id: $btn.data('item-id') || 0,
                tier_id: $btn.data('tier-id') || $panel.data('tier-id') || 0,
                tier_slug: $btn.data('tier-slug') || $panel.data('tier-slug') || '',
                loadout_id: $btn.data('loadout-id') || $widgetRoot.data('loadout-id') || 0,
                product_loadout_id: $btn.data('product-loadout-id') || $widgetRoot.data('product-loadout-id') || 0,
                source: $btn.data('source') || $widgetRoot.data('source') || 'widget',
            };

            if (!data.product_id) return;

            addToCart($btn, data, refreshAllCartSummaries);
        });

        // Add entire tier — global.
        $(document).on('click', '.ffla-loadout__add-tier-btn', function () {
            var $btn = $(this);
            var $widgetRoot = $btn.closest('.ffla-loadout');

            var data = {
                action: 'loadout_add_tier',
                nonce: loadoutFrontend.nonce,
                tier_id: $btn.data('tier-id') || 0,
                tier_slug: $btn.data('tier-slug') || '',
                loadout_id: $btn.data('loadout-id') || $widgetRoot.data('loadout-id') || 0,
                product_loadout_id: $btn.data('product-loadout-id') || $widgetRoot.data('product-loadout-id') || 0,
                source: $btn.data('source') || 'widget',
            };

            addToCart($btn, data, refreshAllCartSummaries);
        });
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
                    $(document.body).trigger('added_to_cart', [
                        response.data.fragments || {},
                        response.data.cart_hash || '',
                        $btn,
                    ]);
                    if (onSuccess) onSuccess();
                    setTimeout(function () { $btn.text(originalText).prop('disabled', false); }, 1500);
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

    function refreshAllCartSummaries() {
        $('.ffla-loadout__cart-summary').each(function () {
            var $summary = $(this);
            var $widgetRoot = $summary.closest('.ffla-loadout');
            refreshCartSummary($summary, $widgetRoot);
        });

        // Refresh progress bars too.
        $('.ffla-loadout__progress-bar').each(function () {
            var $bar = $(this);
            var $widgetRoot = $bar.closest('.ffla-loadout');
            refreshProgress($bar, $widgetRoot);
        });
    }

    function refreshCartSummary($summary, $widgetRoot) {
        var loadoutId = $widgetRoot.data('loadout-id') || 0;

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
                if (!d.items || d.items.length === 0) {
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
            }
        });
    }

    function refreshProgress($bar, $widgetRoot) {
        var loadoutId = $widgetRoot.data('loadout-id') || 0;
        // Find the active panel anywhere to derive tier_id + threshold.
        var $activePanel = $('.ffla-loadout__panel.is-active').first();
        var $activeTab = $('.ffla-loadout__tier-btn.is-active').first();
        var threshold = parseInt($activePanel.data('threshold'), 10) || 0;
        var tierId = parseInt($activePanel.data('tier-id') || $activeTab.data('tier-id'), 10) || 0;

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
                var count = (d.tier_counts && d.tier_counts[tierId]) || 0;
                var pct = threshold > 0 ? Math.min(100, (count / threshold) * 100) : 0;
                $bar.css('width', pct + '%');
                var $label = $bar.closest('.ffla-loadout__progress').find('.ffla-loadout__progress-label');
                if (threshold > 0 && count < threshold) {
                    $label.text((threshold - count) + ' more item(s) to unlock perks');
                } else if (threshold > 0) {
                    $label.text('Perks unlocked!');
                }
            }
        });
    }

})(jQuery);
