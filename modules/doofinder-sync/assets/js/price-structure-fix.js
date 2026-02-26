/**
 * Price Structure Fix
 *
 * Fixes nested <del>/<ins> price markup produced by certain themes/plugins
 * so that the original and discounted prices display correctly.
 */
jQuery(document).ready(function ($) {
    $('.price').each(function () {
        var $price = $(this);
        var $del = $price.find('del');
        if ($del.find('ins').length > 0) {
            var originalPriceHTML = $del.find('ins .woocommerce-Price-amount').parent().html();
            var currentPriceHTML = $price.find('del + ins .woocommerce-Price-amount').parent().html();
            var originalPriceText = $del.find('ins .woocommerce-Price-amount').text();
            var currentPriceText = $price.find('del + ins .woocommerce-Price-amount').text();
            var newHtml = '<del aria-hidden="true">' + originalPriceHTML + '</del> ';
            newHtml += '<span class="screen-reader-text">Original price was: ' + originalPriceText + '.</span>';
            newHtml += '<ins>' + currentPriceHTML + '</ins>';
            newHtml += '<span class="screen-reader-text">Current price is: ' + currentPriceText + '.</span>';
            $price.html(newHtml);
        }
    });
});
