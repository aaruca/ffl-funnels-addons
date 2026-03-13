/**
 * FFL Checkout — Vendor Selector Frontend.
 *
 * Handles radio-button changes for vendor/warehouse selection at checkout.
 * Sends AJAX requests to update cart item data and refreshes the checkout.
 *
 * @package FFL_Funnels_Addons
 */
(function ($) {
    'use strict';

    if (typeof fflVendor === 'undefined') {
        return;
    }

    var updating = false;

    /**
     * Bind change events on vendor radio buttons.
     */
    function bindVendorEvents() {
        var $container = $('#ffl-vendor-selector');
        if (!$container.length) {
            return;
        }

        // Unbind first to avoid duplicates after checkout refresh.
        $container.off('change.fflVendor', 'input[type="radio"]');
        $container.on('change.fflVendor', 'input[type="radio"]', onVendorChange);
    }

    /**
     * Handle vendor radio button change.
     */
    function onVendorChange() {
        if (updating) {
            return;
        }

        var $radio = $(this);
        var $item  = $radio.closest('.ffl-vendor-selector__item');
        var cartKey       = $item.data('cart-key');
        var warehouseId   = $radio.val();
        var price         = $radio.data('price');
        var sku           = $radio.data('sku');
        var shippingClass = $radio.data('shipping-class');

        if (!cartKey || !warehouseId) {
            return;
        }

        updating = true;

        // Disable all radios in this item and show loading.
        var $radios  = $item.find('input[type="radio"]');
        var $loading = $item.find('.ffl-vendor-selector__loading');

        $radios.prop('disabled', true);
        $loading.show();

        $.ajax({
            url: fflVendor.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ffl_update_cart_vendor',
                security: fflVendor.nonce,
                cart_item_key: cartKey,
                warehouse_id: warehouseId,
                price: price,
                sku: sku,
                shipping_class: shippingClass
            },
            success: function (response) {
                if (response.success) {
                    // Trigger WooCommerce checkout refresh.
                    $(document.body).trigger('update_checkout');
                } else {
                    // Revert selection on error.
                    alert(response.data || 'Error updating vendor.');
                }
            },
            error: function () {
                alert('Network error. Please try again.');
            },
            complete: function () {
                $radios.prop('disabled', false);
                $loading.hide();
                updating = false;
            }
        });
    }

    // ── Bootstrap ─────────────────────────────────────────────────────

    $(function () {
        bindVendorEvents();

        // Re-bind after WooCommerce refreshes the checkout.
        $(document.body).on('updated_checkout', function () {
            bindVendorEvents();
        });
    });

})(jQuery);
