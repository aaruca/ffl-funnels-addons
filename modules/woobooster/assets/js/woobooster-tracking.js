/**
 * WooBooster Tracking — JS Attribution.
 *
 * Intercepts WooCommerce AJAX add-to-cart events and appends
 * the WooBooster rule ID when the product came from a recommendation.
 */
(function ($) {
    'use strict';

    if (typeof WooBoosterTracking === 'undefined') {
        return;
    }

    // Build a fast product_id -> rule_id lookup map.
    var map = {};
    var recs = WooBoosterTracking.recommendations || [];

    for (var i = 0; i < recs.length; i++) {
        var rec = recs[i];
        var pids = rec.product_ids || [];
        for (var j = 0; j < pids.length; j++) {
            map[pids[j]] = rec.rule_id;
        }
    }

    // Intercept WooCommerce AJAX add-to-cart (fires before the request).
    $(document.body).on('adding_to_cart', function (e, btn, data) {
        var pid = null;

        if (data && data.product_id) {
            pid = data.product_id;
        } else if (btn && btn.data('product_id')) {
            pid = btn.data('product_id');
        }

        if (pid && map[pid]) {
            data.wb_rule_id = map[pid];
        }
    });

})(jQuery);
