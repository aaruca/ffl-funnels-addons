(function ($) {
    'use strict';

    var cfg = window.loadoutFrontend || {};
    var strings = cfg.strings || {};

    function t(key, fallback) {
        return strings[key] || fallback;
    }

    function escapeHtml(str) {
        return String(str === undefined || str === null ? '' : str).replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
        });
    }

    $(document).ready(function () {
        initGlobalHandlers();
        refreshAllCartSummaries();
    });

    function initGlobalHandlers() {
        // Tier tab switching — page-wide: shows every panel with this tier slug,
        // so tabs and panels may live in different elements.
        $(document).on('click', '.ffla-loadout__tier-btn', function () {
            var $btn = $(this);
            var slug = $btn.data('tier-slug');
            $btn.siblings('.ffla-loadout__tier-btn').removeClass('is-active').attr('aria-selected', 'false');
            $btn.addClass('is-active').attr('aria-selected', 'true');
            $('.ffla-loadout__panel').removeClass('is-active');
            $('.ffla-loadout__panel[data-tier-slug="' + slug + '"]').addClass('is-active');
            refreshAllCartSummaries();
        });

        // Add one item. The server validates everything against the stored
        // configuration; these values only say which item was clicked.
        $(document).on('click', '.ffla-loadout__add-btn', function () {
            var $btn = $(this);
            if ($btn.is(':disabled')) return;

            var $panel = $btn.closest('.ffla-loadout__panel');
            var $widgetRoot = $btn.closest('.ffla-loadout');

            var data = {
                action: 'loadout_add_item',
                nonce: cfg.nonce,
                product_id: $btn.data('product-id'),
                quantity: $btn.data('quantity') || 1,
                item_id: $btn.data('item-id') || 0,
                tier_id: $btn.data('tier-id') || $panel.data('tier-id') || 0,
                tier_slug: $btn.data('tier-slug') || $panel.data('tier-slug') || '',
                loadout_id: $btn.data('loadout-id') || $widgetRoot.data('loadout-id') || 0,
                product_loadout_id: $btn.data('product-loadout-id') || $widgetRoot.data('product-loadout-id') || 0,
            };

            if (!data.product_id) return;

            addToCart($btn, data, refreshAllCartSummaries);
        });

        // Add the entire tier as one cart line.
        $(document).on('click', '.ffla-loadout__add-tier-btn', function () {
            var $btn = $(this);
            if ($btn.is(':disabled')) return;
            var $widgetRoot = $btn.closest('.ffla-loadout');

            var data = {
                action: 'loadout_add_tier',
                nonce: cfg.nonce,
                tier_id: $btn.data('tier-id') || 0,
                tier_slug: $btn.data('tier-slug') || '',
                loadout_id: $btn.data('loadout-id') || $widgetRoot.data('loadout-id') || 0,
                product_loadout_id: $btn.data('product-loadout-id') || $widgetRoot.data('product-loadout-id') || 0,
            };

            addToCart($btn, data, refreshAllCartSummaries);
        });
    }

    function addToCart($btn, data, onSuccess) {
        var originalText = $btn.text();
        $btn.prop('disabled', true).text(t('adding', 'Adding...'));

        function fail(message) {
            $btn.text(t('addError', 'Could not add item.')).prop('disabled', false);
            if (message) {
                $btn.attr('title', message);
            }
            setTimeout(function () { $btn.text(originalText); }, 2000);
        }

        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            data: data,
            success: function (response) {
                if (!response || !response.success) {
                    fail(response && response.data && response.data.message ? response.data.message : '');
                    return;
                }

                var d = response.data || {};

                // On the cart or checkout page the cart table only updates on a
                // reload; elsewhere fire the standard WooCommerce events.
                if (isCartOrCheckoutPage()) {
                    window.location.href = d.cart_url || window.location.href;
                    return;
                }

                if (d.fragments && window.sessionStorage) {
                    try {
                        sessionStorage.setItem('wc_fragments_' + (window.wc_cart_fragments_params ? window.wc_cart_fragments_params.ajax_url_hash || '' : ''), JSON.stringify(d.fragments));
                        sessionStorage.setItem('wc_cart_hash', d.cart_hash || '');
                    } catch (e) { /* sessionStorage may be unavailable */ }
                }

                if (d.fragments) {
                    $.each(d.fragments, function (selector, html) {
                        $(selector).replaceWith(html);
                    });
                }

                // Set the "Added!" state before triggering events so WooCommerce
                // never replaces the button with a "View cart" link.
                $btn.removeAttr('title').prop('disabled', true).addClass('is-added').text(t('added', 'Added!'));

                $(document.body).trigger('wc_fragments_refreshed');
                $(document.body).trigger('added_to_cart', [
                    d.fragments || {},
                    d.cart_hash || '',
                ]);
                $(document.body).trigger('wc_fragment_refresh');

                if (onSuccess) onSuccess();
            },
            error: function () {
                fail('');
            }
        });
    }

    function isCartOrCheckoutPage() {
        if (cfg.cartUrl) {
            var here = window.location.pathname.replace(/\/+$/, '');
            var cartPath = '';
            try {
                cartPath = new URL(cfg.cartUrl).pathname.replace(/\/+$/, '');
            } catch (e) { /* older browsers */ }
            if (cartPath && here === cartPath) return true;
        }
        return $('body').hasClass('woocommerce-cart')
            || $('body').hasClass('woocommerce-checkout')
            || $('form.woocommerce-cart-form').length > 0;
    }

    function contextOf($root) {
        return {
            loadoutId: parseInt($root.data('loadout-id'), 10) || 0,
            productLoadoutId: parseInt($root.data('product-loadout-id'), 10) || 0,
        };
    }

    /**
     * One summary request per loadout context per refresh, shared by every
     * cart panel and progress bar of that context.
     */
    function makeFetcher() {
        var cache = {};
        return function (ctx) {
            var key = ctx.loadoutId + ':' + ctx.productLoadoutId;
            if (!cache[key]) {
                cache[key] = $.ajax({
                    url: cfg.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'loadout_get_cart_summary',
                        nonce: cfg.nonce,
                        loadout_id: ctx.loadoutId,
                        product_loadout_id: ctx.productLoadoutId,
                    },
                });
            }
            return cache[key];
        };
    }

    function refreshAllCartSummaries() {
        var fetchSummary = makeFetcher();

        $('.ffla-loadout__cart-summary').each(function () {
            var $summary = $(this);
            var ctx = contextOf($summary.closest('.ffla-loadout'));
            fetchSummary(ctx).done(function (response) {
                renderCartSummary($summary, response);
            });
        });

        $('.ffla-loadout__progress-bar').each(function () {
            var $bar = $(this);
            var $root = $bar.closest('.ffla-loadout');
            fetchSummary(contextOf($root)).done(function (response) {
                renderProgress($bar, $root, response);
            });
        });
    }

    function renderCartSummary($summary, response) {
        if (!response || !response.success) return;
        var d = response.data;
        var html = '';
        if (!d.items || d.items.length === 0) {
            html = '<p>' + escapeHtml(t('emptyCart', 'Your cart is empty.')) + '</p>';
        } else {
            html = '<ul class="ffla-loadout__cart-list">';
            d.items.forEach(function (item) {
                html += '<li' + (item.is_bonus ? ' class="is-bonus"' : '') + '>';
                html += '<span class="item-name">' + escapeHtml(item.name) + '</span>';
                html += '<span class="item-qty">×' + (parseInt(item.quantity, 10) || 0) + '</span>';
                // Prices are wc_price() HTML from the server.
                html += '<span class="item-price">' + item.current + '</span>';
                html += '</li>';
            });
            html += '</ul>';
            html += '<p class="ffla-loadout__cart-savings">' + escapeHtml(t('savings', 'Savings:')) + ' ' + d.savings + '</p>';
            html += '<p class="ffla-loadout__cart-total">' + escapeHtml(t('total', 'Total:')) + ' ' + d.total + '</p>';
        }
        $summary.html(html);
    }

    /**
     * The tier the bar follows: the active panel in its own widget, else the
     * active tab/panel of the same loadout on the page, else the first tier.
     */
    function activeTier($root) {
        var ctx = contextOf($root);
        var tiers = $root.data('tiers');
        if (typeof tiers === 'string') {
            try { tiers = JSON.parse(tiers); } catch (e) { tiers = null; }
        }
        tiers = Array.isArray(tiers) ? tiers : [];

        var $panel = $root.find('.ffla-loadout__panel.is-active').first();
        if (!$panel.length) {
            $('.ffla-loadout__panel.is-active').each(function () {
                var other = contextOf($(this).closest('.ffla-loadout'));
                if (!$panel.length && other.loadoutId === ctx.loadoutId && other.productLoadoutId === ctx.productLoadoutId) {
                    $panel = $(this);
                }
            });
        }
        if ($panel.length) {
            return {
                id: parseInt($panel.data('tier-id'), 10) || 0,
                slug: String($panel.data('tier-slug') || ''),
                threshold: parseInt($panel.data('threshold'), 10) || 0,
            };
        }

        var slug = '';
        $('.ffla-loadout__tier-btn.is-active').each(function () {
            var other = contextOf($(this).closest('.ffla-loadout'));
            if (!slug && other.loadoutId === ctx.loadoutId && other.productLoadoutId === ctx.productLoadoutId) {
                slug = String($(this).data('tier-slug') || '');
            }
        });
        var found = null;
        tiers.forEach(function (tier) {
            if (!found && slug && String(tier.slug) === slug) found = tier;
        });
        if (!found && tiers.length) found = tiers[0];
        if (!found) return null;
        return {
            id: parseInt(found.id, 10) || 0,
            slug: String(found.slug || ''),
            threshold: parseInt(found.threshold, 10) || 0,
        };
    }

    function renderProgress($bar, $root, response) {
        if (!response || !response.success) return;
        var tier = activeTier($root);
        if (!tier) return;

        var d = response.data;
        var count = tier.id
            ? ((d.tier_counts && d.tier_counts[tier.id]) || 0)
            : ((d.slug_counts && d.slug_counts[tier.slug]) || 0);
        var threshold = tier.threshold;
        var pct = threshold > 0 ? Math.min(100, (count / threshold) * 100) : 0;
        $bar.css('width', pct + '%');

        var $label = $bar.closest('.ffla-loadout__progress').find('.ffla-loadout__progress-label');
        if (threshold > 0 && count < threshold) {
            $label.text(t('moreToUnlock', '%d more item(s) to unlock perks').replace('%d', threshold - count));
        } else if (threshold > 0) {
            $label.text(t('unlocked', 'Perks unlocked!'));
        }
    }

})(jQuery);
