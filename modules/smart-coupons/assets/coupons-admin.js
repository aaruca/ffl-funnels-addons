/* Smart Coupons — coupon screen: show tier / buy X get Y options for their types, copy links. */
(function ($) {
	'use strict';

	function sync() {
		var type = $('#discount_type').val();
		$('.ffla-show-tiered').toggle(type === 'ffla_tiered');
		$('.ffla-show-bxgy').toggle(type === 'ffla_bxgy');
		// The amount field means nothing for these types.
		$('.coupon_amount_field').toggle(type !== 'ffla_tiered' && type !== 'ffla_bxgy');
	}
	$(document).on('change', '#discount_type', sync);
	$(sync);

	$(document).on('click', '[data-ffla-copy]', function () {
		var button = $(this);
		var value = button.attr('data-ffla-copy');
		var done = function () {
			button.text('✓');
		};
		if (navigator.clipboard) {
			navigator.clipboard.writeText(value).then(done);
		} else {
			done();
		}
	});
})(jQuery);
