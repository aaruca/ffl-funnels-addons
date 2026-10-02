/* Smart Coupons — coupon screen: show tier / buy X get Y options for their types, copy links, and the live "Which products" summary. */
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

	/* ── Which products: AND / OR summary ─────────────────────────────── */

	var t = window.fflaCpnRules || {};
	var MAX = 6;

	function names(selector) {
		return $(selector).find('option:selected').map(function () {
			return $.trim($(this).text());
		}).get().filter(Boolean);
	}

	function op(word) {
		return $('<span class="ffla-sum__op">').text(String(word).toUpperCase());
	}

	// "in Rifles OR Shotguns" as one bordered group, so AND / OR between groups reads clearly.
	function group(prefix, list, joiner, kind) {
		var box = $('<span class="ffla-sum__group">').addClass(kind ? 'is-' + kind : '');
		if (prefix) {
			box.append($('<span class="ffla-sum__pre">').text(prefix));
		}
		list.slice(0, MAX).forEach(function (name, i) {
			if (i) {
				box.append(op(joiner));
			}
			box.append($('<b>').text(name));
		});
		if (list.length > MAX) {
			box.append($('<span class="ffla-sum__more">').text((t.more || '+%d more').replace('%d', list.length - MAX)));
		}
		return box;
	}

	function line(label, kind) {
		return $('<div class="ffla-sum__line">').addClass(kind ? 'is-' + kind : '').append($('<span class="ffla-sum__label">').text(label));
	}

	function rules() {
		var box = $('[data-ffla-rules]');
		if (!box.length) {
			return;
		}
		var cats = names('#ffla_cats');
		var tags = names('#ffla_tags');
		var catsMatch = box.find('input[name="ffla[cats_match]"]:checked').val() || 'any';
		var tagsMatch = box.find('input[name="ffla[tags_match]"]:checked').val() || 'any';
		var join = box.find('input[name="ffla[join]"]:checked').val() || 'and';
		var both = cats.length > 0 && tags.length > 0;

		box.find('[data-ffla-side="cats"]').toggleClass('is-set', cats.length > 0);
		box.find('[data-ffla-side="tags"]').toggleClass('is-set', tags.length > 0);
		box.find('[data-ffla-join]').toggleClass('is-idle', !both).toggleClass('is-or', join === 'or');
		box.find('[data-ffla-join-hint]').text(!both ? t.joinIdle : (join === 'or' ? t.joinOr : t.joinAnd));

		var out = box.find('[data-ffla-summary]').empty();
		var main = line(t.appliesTo, 'main');
		if (!cats.length && !tags.length) {
			main.append($('<span class="ffla-sum__group is-every">').text(t.every));
		} else {
			if (cats.length) {
				main.append(group(t.in, cats, catsMatch === 'all' ? t.and : t.or));
			}
			if (both) {
				main.append(op(join === 'or' ? t.or : t.and).addClass('is-join'));
			}
			if (tags.length) {
				main.append(group(t.tagged, tags, tagsMatch === 'all' ? t.and : t.or));
			}
		}
		out.append(main);

		var noCats = names('#ffla_exclude_cats');
		var noTags = names('#ffla_exclude_tags');
		if (noCats.length || noTags.length) {
			var never = line(t.never, 'never');
			if (noCats.length) {
				never.append(group(t.in, noCats, t.or, 'never'));
			}
			if (noCats.length && noTags.length) {
				never.append(op(t.or));
			}
			if (noTags.length) {
				never.append(group(t.tagged, noTags, t.or, 'never'));
			}
			out.append(never);
		}

		// WooCommerce's own Usage restriction still applies on top: show it so the whole rule is in one place.
		var wcOnly = names('#product_ids').concat(names('#product_categories'));
		var wcNever = names('#exclude_product_ids').concat(names('#exclude_product_categories'));
		if ($('#exclude_sale_items').is(':checked')) {
			wcNever.push(t.saleItems);
		}
		if (wcOnly.length) {
			out.append(line(t.wcOnly, 'wc').append(group('', wcOnly, t.or)));
		}
		if (wcNever.length) {
			out.append(line(t.wcNever, 'wc').append(group('', wcNever, t.or, 'never')));
		}

		if (box.attr('data-protect') === '1' && !$('input[name="ffla[allow_protected]"]').is(':checked')) {
			out.append($('<div class="ffla-sum__note">').text(t.guard));
		}
	}

	$(document).on('change', '[data-ffla-rules] select, [data-ffla-rules] input, #product_ids, #exclude_product_ids, #product_categories, #exclude_product_categories, #exclude_sale_items, input[name="ffla[allow_protected]"]', rules);
	$(rules);
})(jQuery);
