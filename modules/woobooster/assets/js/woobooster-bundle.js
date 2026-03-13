/**
 * WooBooster Bundle — Frontend JS.
 *
 * Handles checkbox toggling, price recalculation, and AJAX add-to-cart
 * for the Frequently Bought Together widget.
 */
(function ($) {
  'use strict';

  var cfg = window.wooboosterBundleConfig || {};

  function formatPrice(amount, data) {
    var neg = amount < 0;
    amount = Math.abs(amount);
    var formatted = amount.toFixed(data.decimals);

    // Apply separators.
    var parts = formatted.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, data.thousand_sep);
    formatted = parts.join(data.decimal_sep);

    var symbol = data.currency_symbol;
    switch (data.currency_pos) {
      case 'left':
        formatted = symbol + formatted;
        break;
      case 'right':
        formatted = formatted + symbol;
        break;
      case 'left_space':
        formatted = symbol + ' ' + formatted;
        break;
      case 'right_space':
        formatted = formatted + ' ' + symbol;
        break;
    }

    return (neg ? '-' : '') + formatted;
  }

  function recalculate($bundle) {
    var bundleData = $bundle.data('bundleConfig');
    if (!bundleData) return;

    var totalOriginal = 0;
    var totalDiscounted = 0;

    $bundle.find('.wb-bundle-item').each(function () {
      var $item = $(this);
      var $cb = $item.find('input[type="checkbox"]');
      if (!$cb.is(':checked')) return;

      totalOriginal += parseFloat($item.data('original-price')) || 0;
      totalDiscounted += parseFloat($item.data('price')) || 0;
    });

    var $total = $bundle.find('.wb-bundle-total__prices');
    if (!$total.length) return;

    var html = '';
    if (totalDiscounted < totalOriginal) {
      html += '<del class="wb-bundle-total__original">' + formatPrice(totalOriginal, bundleData) + '</del> ';
      html += '<ins class="wb-bundle-total__discounted">' + formatPrice(totalDiscounted, bundleData) + '</ins>';
      var savings = totalOriginal - totalDiscounted;
      html += ' <span class="wb-bundle-total__savings">(Save ' + formatPrice(savings, bundleData) + ')</span>';
    } else {
      html += '<span class="wb-bundle-total__discounted">' + formatPrice(totalOriginal, bundleData) + '</span>';
    }

    $total.html(html);
  }

  function initBundle($bundle) {
    // Parse bundle config.
    var $dataInput = $bundle.find('.wb-bundle-data');
    if ($dataInput.length) {
      try {
        $bundle.data('bundleConfig', JSON.parse($dataInput.val()));
      } catch (e) {
        // Ignore parse errors.
      }
    }

    // Checkbox changes.
    $bundle.on('change', 'input[type="checkbox"]', function () {
      recalculate($bundle);
    });

    // Add to Cart button.
    $bundle.on('click', '.wb-bundle-add-to-cart', function (e) {
      e.preventDefault();

      if (cfg.isBuilder === '1') return;

      var $btn = $(this);
      var bundleId = $btn.data('bundle-id');

      // Gather checked products.
      var productIds = [];
      $bundle.find('.wb-bundle-item input[type="checkbox"]:checked').each(function () {
        productIds.push($(this).val());
      });

      if (!productIds.length) {
        alert(cfg.i18n ? cfg.i18n.noItems : 'Please select at least one product.');
        return;
      }

      // Disable button.
      var originalText = $btn.text();
      $btn.prop('disabled', true).text(cfg.i18n ? cfg.i18n.adding : 'Adding...');

      $.post(cfg.ajaxUrl, {
        action: 'woobooster_add_bundle_to_cart',
        nonce: cfg.nonce,
        bundle_id: bundleId,
        product_ids: productIds
      })
        .done(function (response) {
          if (response.success) {
            $btn.text(cfg.i18n ? cfg.i18n.added : 'Added to Cart!');

            // Update WC cart fragments.
            if (response.data && response.data.fragments) {
              $.each(response.data.fragments, function (selector, fragment) {
                $(selector).replaceWith(fragment);
              });
            }

            // Trigger WC added_to_cart event.
            $(document.body).trigger('added_to_cart', [response.data.fragments, response.data.cart_hash, $btn]);

            setTimeout(function () {
              $btn.prop('disabled', false).text(originalText);
            }, 2000);
          } else {
            var msg = (response.data && response.data.message) || (cfg.i18n ? cfg.i18n.error : 'Error adding to cart.');
            $btn.prop('disabled', false).text(originalText);
            alert(msg);
          }
        })
        .fail(function () {
          $btn.prop('disabled', false).text(originalText);
          alert(cfg.i18n ? cfg.i18n.error : 'Error adding to cart.');
        });
    });
  }

  // Init on DOM ready.
  $(function () {
    $('.wb-bundle').each(function () {
      initBundle($(this));
    });
  });

  // Support Bricks re-renders.
  $(document).on('bricks/ajax/load_page', function () {
    $('.wb-bundle').each(function () {
      if (!$(this).data('bundleConfig')) {
        initBundle($(this));
      }
    });
  });
})(jQuery);
