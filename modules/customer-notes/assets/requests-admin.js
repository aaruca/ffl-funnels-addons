/* Customer requests — staff screens: reply / note tabs, copy link, request type toggle. */
(function () {
	'use strict';

	document.querySelectorAll('.ffla-req-tabs').forEach(function (tabs) {
		var buttons = tabs.querySelectorAll('[role=tab]');
		buttons.forEach(function (button) {
			button.addEventListener('click', function () {
				buttons.forEach(function (b) {
					var on = b === button;
					b.classList.toggle('is-active', on);
					b.setAttribute('aria-selected', on ? 'true' : 'false');
					var panel = document.getElementById(b.getAttribute('aria-controls'));
					if (panel) {
						panel.hidden = !on;
					}
				});
				var panel = document.getElementById(button.getAttribute('aria-controls'));
				var field = panel && panel.querySelector('textarea');
				if (field) {
					field.focus();
				}
			});
		});
	});

	document.querySelectorAll('[data-ffla-copy]').forEach(function (button) {
		button.addEventListener('click', function () {
			var input = button.parentNode.querySelector('input');
			if (!input) {
				return;
			}
			input.select();
			var done = function () {
				button.textContent = (window.fflaReqAdmin && window.fflaReqAdmin.copied) || 'Copied';
			};
			if (navigator.clipboard) {
				navigator.clipboard.writeText(input.value).then(done);
			} else {
				document.execCommand('copy');
				done();
			}
		});
	});

	// Saved replies: insert into the target textarea at the cursor.
	document.querySelectorAll('[data-ffla-insert]').forEach(function (select) {
		select.addEventListener('change', function () {
			var field = document.getElementById(select.getAttribute('data-ffla-insert'));
			if (!field || !select.value) {
				return;
			}
			var start = field.selectionStart || 0;
			var end = field.selectionEnd || 0;
			var before = field.value.slice(0, start);
			var after = field.value.slice(end);
			field.value = before + (before && !/\n$/.test(before) ? '\n' : '') + select.value + after;
			select.value = '';
			field.focus();
		});
	});

	// Rules / replies repeaters.
	document.querySelectorAll('[data-ffla-add]').forEach(function (button) {
		button.addEventListener('click', function () {
			var name = button.getAttribute('data-ffla-add');
			var template = document.querySelector('template[data-ffla-template="' + name + '"]');
			var body = document.querySelector('[data-ffla-repeater="' + name + '"] tbody');
			if (!template || !body) {
				return;
			}
			var html = template.innerHTML.replace(/__i__/g, 'n' + Date.now());
			body.insertAdjacentHTML('beforeend', html);
			var first = body.lastElementChild && body.lastElementChild.querySelector('select, input, textarea');
			if (first) {
				first.focus();
			}
		});
	});
	document.addEventListener('click', function (e) {
		var remove = e.target.closest && e.target.closest('[data-ffla-remove]');
		if (remove) {
			var row = remove.closest('tr');
			if (row) {
				row.parentNode.removeChild(row);
			}
		}
	});

	// Refund total preview.
	document.querySelectorAll('[data-ffla-refund]').forEach(function (box) {
		var form = box.closest('form');
		var currency = JSON.parse(box.getAttribute('data-currency') || '{}');
		var remaining = parseFloat(box.getAttribute('data-remaining')) || 0;
		var out = box.querySelector('[data-ffla-refund-total]');
		var feeOut = box.querySelector('[data-ffla-refund-fee]');
		function money(n) {
			var value = n.toFixed(currency.decimals === undefined ? 2 : currency.decimals);
			return (currency.format || '%1$s%2$s').replace('%1$s', currency.symbol || '').replace('%2$s', value);
		}
		function update() {
			var gross = 0;
			box.querySelectorAll('input[data-unit]').forEach(function (input) {
				gross += (parseFloat(input.getAttribute('data-unit')) || 0) * (parseInt(input.value, 10) || 0);
			});
			var fee = gross * Math.min(100, Math.max(0, parseFloat(form.fee.value) || 0)) / 100;
			var total = gross - fee + Math.max(0, parseFloat(form.extra.value) || 0);
			out.textContent = money(total);
			out.style.color = total > remaining + 0.0001 ? '#b32d2e' : '';
			feeOut.textContent = fee > 0 ? '(' + money(fee) + ' fee kept)' : '';
		}
		form.addEventListener('input', update);
		update();
	});

	var radios = document.querySelectorAll('[data-ffla-type]');
	function syncType() {
		var current = document.querySelector('[data-ffla-type]:checked');
		document.querySelectorAll('[data-ffla-for]').forEach(function (el) {
			el.hidden = !current || el.getAttribute('data-ffla-for') !== current.value;
		});
	}
	radios.forEach(function (r) {
		r.addEventListener('change', syncType);
	});
	if (radios.length) {
		syncType();
	}
})();
