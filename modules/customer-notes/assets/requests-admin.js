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
