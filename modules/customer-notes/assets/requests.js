/**
 * Customer requests (issues & returns) — storefront app for [ffla_order_requests].
 *
 * The page HTML is static and cache-safe; every private detail comes from
 * admin-ajax. User content is always inserted as text, never as HTML.
 */
(function () {
	'use strict';

	function h(tag, attrs, children) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) {
				return;
			}
			if (k === 'class') {
				el.className = v;
			} else if (k === 'text') {
				el.textContent = v;
			} else if (k.indexOf('on') === 0) {
				el.addEventListener(k.slice(2), v);
			} else {
				el.setAttribute(k, v === true ? '' : String(v));
			}
		});
		[].concat(children === undefined ? [] : children).forEach(function (c) {
			if (c === null || c === undefined || c === false || c === '') {
				return;
			}
			el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
		});
		return el;
	}

	/** Plain text with line breaks kept. */
	function text(value, cls) {
		var el = h('div', { class: cls || 'ffla-req-text' });
		String(value || '').split('\n').forEach(function (line, i) {
			if (i) {
				el.appendChild(document.createElement('br'));
			}
			el.appendChild(document.createTextNode(line));
		});
		return el;
	}

	function notice(message, kind) {
		return h('div', { class: 'ffla-req-notice ffla-req-notice--' + (kind || 'info'), role: kind === 'error' ? 'alert' : 'status' }, message);
	}

	/** sprintf-style: %s / %d in order, %1$s numbered, %% a literal percent. */
	function format(str) {
		var args = [].slice.call(arguments, 1);
		var next = 0;
		return String(str).replace(/%(?:(\d)\$)?([ds%])/g, function (m, n, type) {
			if (type === '%') {
				return '%';
			}
			return String(n ? args[n - 1] : args[next++]);
		});
	}

	function App(root) {
		this.root = root;
		this.cfg = JSON.parse(root.getAttribute('data-config') || '{}');
		this.t = this.cfg.i18n || {};
		this.view = root.querySelector('.ffla-req-app');
		this.primary = root.id === 'ffla-requests';
		this.session = { loggedIn: false };
		this.order = null;
		this.start();
	}

	App.prototype.post = function (action, fields) {
		var self = this;
		var body = fields instanceof FormData ? fields : new FormData();
		if (!(fields instanceof FormData)) {
			Object.keys(fields || {}).forEach(function (k) {
				if (fields[k] !== undefined && fields[k] !== null) {
					body.append(k, fields[k]);
				}
			});
		}
		body.append('action', 'ffla_req_' + action);
		if (this.session.nonce && !body.has('nonce')) {
			body.append('nonce', this.session.nonce);
		}
		return fetch(this.cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (res) {
				return res.json();
			}, function () {
				throw new Error(self.t.retry);
			})
			.then(function (json) {
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || self.t.retry);
				}
				return json.data;
			}, function (e) {
				throw new Error(e && e.message ? e.message : self.t.retry);
			});
	};

	App.prototype.render = function (nodes, focus) {
		this.view.textContent = '';
		[].concat(nodes).forEach(function (n) {
			if (n) {
				this.view.appendChild(n);
			}
		}, this);
		var target = this.view.querySelector('[data-focus]');
		if (focus !== false && target) {
			target.setAttribute('tabindex', '-1');
			target.focus({ preventScroll: true });
			var rect = this.root.getBoundingClientRect();
			if (rect.top < 0 || rect.top > window.innerHeight) {
				this.root.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		}
	};

	App.prototype.loading = function () {
		this.render(h('p', { class: 'ffla-req-loading', text: '…' }), false);
	};

	App.prototype.start = function () {
		var self = this;
		var params = new URLSearchParams(window.location.search);
		var number = params.get('ffla_request');
		var key = params.get('key');

		this.post('session', this.cfg.embedded ? { embedded: 1 } : {})
			.then(function (s) {
				self.session = s;
			}, function () {})
			.then(function () {
				if (self.primary && number && key) {
					return self.post('view', { number: number, key: key }).then(function (data) {
						self.showRequest(data, {}, false);
					}, function (e) {
						self.clearUrl();
						self.home(e.message);
					});
				}
				var preset = params.get('ffla_order');
				if (!self.cfg.embedded && preset && self.session.loggedIn) {
					return self.openOrder(preset);
				}
				if (self.cfg.embedded && self.session.loggedIn) {
					return self.post('verify', { order: self.cfg.order }).then(function (data) {
						self.showOrder(data, false);
					}, function (e) {
						self.render(notice(e.message, 'error'));
					});
				}
				self.home('', false);
			});
	};

	/* ── Start: signed-in orders and / or the lookup form ─────────────── */

	App.prototype.home = function (error, focus) {
		var self = this;
		var t = this.t;
		var s = this.session;
		var nodes = [];

		if (error) {
			nodes.push(notice(error, 'error'));
		}
		if (this.cfg.intro && !this.cfg.embedded && !s.loggedIn) {
			nodes.push(text(this.cfg.intro, 'ffla-req-intro'));
		}

		if (s.loggedIn && s.orders) {
			if (s.requests && s.requests.length) {
				nodes.push(h('h3', { class: 'ffla-req-h', 'data-focus': !!this.cfg.account, text: t.yourRequests }));
				nodes.push(this.requestList(s.requests, {}));
			} else if (this.cfg.account) {
				nodes.push(h('h3', { class: 'ffla-req-h', text: t.yourRequests }));
				nodes.push(h('p', { class: 'ffla-req-muted', text: t.noRequests }));
			}
			nodes.push(h('h3', { class: 'ffla-req-h', 'data-focus': !this.cfg.account || !(s.requests && s.requests.length), text: t.yourOrders }));
			nodes.push(h('p', { class: 'ffla-req-muted', text: t.pickOrder }));
			if (s.orders.length) {
				nodes.push(h('ul', { class: 'ffla-req-list' }, s.orders.map(function (o) {
					return h('li', {}, h('button', {
						type: 'button',
						class: 'ffla-req-row',
						onclick: function () {
							self.openOrder(o.number);
						}
					}, [
						h('strong', { text: t.order + ' #' + o.number }),
						h('span', { class: 'ffla-req-muted', text: [o.date, o.status, o.total].filter(Boolean).join(' · ') })
					]));
				})));
			} else {
				nodes.push(h('p', { class: 'ffla-req-muted', text: t.noOrders }));
			}
		}

		if (this.cfg.guests) {
			var form = this.findForm();
			if (s.loggedIn && s.orders) {
				nodes.push(h('details', { class: 'ffla-req-more' }, [h('summary', { text: t.otherOrder }), form]));
			} else {
				nodes.push(form);
			}
		} else if (!s.loggedIn) {
			nodes.push(h('p', { 'data-focus': true }, [t.signIn + ' ', h('a', { href: this.cfg.loginUrl, text: t.signInLink })]));
		}

		this.render(nodes, focus);
	};

	App.prototype.findForm = function () {
		var self = this;
		var t = this.t;
		var preset = new URLSearchParams(window.location.search).get('ffla_order') || '';
		var uid = Math.random().toString(36).slice(2, 8);
		var error = h('div', { class: 'ffla-req-error' });
		var form = h('form', { class: 'ffla-req-form', novalidate: true }, [
			h('h3', { class: 'ffla-req-h', 'data-focus': !this.session.loggedIn, text: t.findTitle }),
			error,
			h('p', { class: 'ffla-req-field' }, [
				h('label', { for: 'ffla-o-' + uid, text: t.orderNumber }),
				h('input', { id: 'ffla-o-' + uid, name: 'order', type: 'text', required: true, autocomplete: 'off', inputmode: 'text', maxlength: 60, value: preset })
			]),
			h('p', { class: 'ffla-req-field' }, [
				h('label', { for: 'ffla-e-' + uid, text: t.email }),
				h('input', { id: 'ffla-e-' + uid, name: 'email', type: 'email', required: true, autocomplete: 'email', maxlength: 200 })
			]),
			this.honeypot(),
			h('p', {}, h('button', { type: 'submit', class: 'button ffla-req-btn ffla-req-btn--primary', text: t.continue }))
		]);
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			error.textContent = '';
			if (!form.order.value.trim() || !form.email.value.trim()) {
				error.appendChild(notice(t.required, 'error'));
				return;
			}
			self.busy(form, true);
			self.post('verify', { order: form.order.value.trim(), email: form.email.value.trim(), website: form.website.value })
				.then(function (data) {
					if (data.kind === 'request') {
						self.showRequest(data);
					} else {
						self.showOrder(data);
					}
				}, function (err) {
					self.busy(form, false);
					error.appendChild(notice(err.message, 'error'));
				});
		});
		return form;
	};

	App.prototype.honeypot = function () {
		return h('div', { class: 'ffla-req-hp', 'aria-hidden': 'true' }, h('label', {}, ['Website', h('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' })]));
	};

	App.prototype.busy = function (form, on) {
		form.setAttribute('aria-busy', on ? 'true' : 'false');
		[].forEach.call(form.querySelectorAll('button, input, select, textarea'), function (el) {
			el.disabled = on;
		});
	};

	App.prototype.openOrder = function (number) {
		var self = this;
		this.loading();
		this.post('verify', { order: number }).then(function (data) {
			self.showOrder(data);
		}, function (e) {
			self.home(e.message);
		});
	};

	/* ── Order: its requests and the two entry points ─────────────────── */

	App.prototype.showOrder = function (data, focus) {
		var self = this;
		var t = this.t;
		var o = data.order;
		this.order = data;
		this.clearUrl();

		var nodes = [];
		if (!this.cfg.embedded) {
			nodes.push(this.backButton(function () {
				self.order = null;
				self.home();
			}));
		}
		nodes.push(h('div', { class: 'ffla-req-card' }, [
			h('h3', { class: 'ffla-req-h', 'data-focus': true, text: t.order + ' #' + o.number }),
			this.meta([[t.placed, o.date], [t.status, o.status], [t.total, o.total]])
		]));

		if (data.requests.length) {
			nodes.push(h('h4', { class: 'ffla-req-h', text: t.requestsOnOrder }));
			nodes.push(this.requestList(data.requests, { ticket: data.ticket }));
		}

		nodes.push(h('h4', { class: 'ffla-req-h', text: t.whatHappened }));
		nodes.push(h('div', { class: 'ffla-req-choices' }, this.cfg.types.map(function (type) {
			var blocked = data.eligibility[type];
			return h('div', { class: 'ffla-req-choice' }, [
				h('button', {
					type: 'button',
					class: 'button ffla-req-btn ffla-req-choice-btn',
					disabled: !!blocked,
					'aria-describedby': 'ffla-why-' + type,
					onclick: function () {
						self.showForm(type);
					}
				}, [h('strong', { text: t[type] }), h('span', { text: t[type + 'Hint'] })]),
				blocked ? h('p', { id: 'ffla-why-' + type, class: 'ffla-req-muted', text: blocked }) : null
			]);
		})));

		this.render(nodes, focus);
	};

	App.prototype.requestList = function (list, auth) {
		var self = this;
		return h('ul', { class: 'ffla-req-list' }, list.map(function (r) {
			return h('li', {}, h('button', {
				type: 'button',
				class: 'ffla-req-row',
				onclick: function () {
					self.loading();
					self.post('view', Object.assign({ number: r.number }, auth)).then(function (data) {
						self.showRequest(data);
					}, function (e) {
						self.home(e.message);
					});
				}
			}, [
				h('strong', { text: r.typeLabel + ' ' + r.number }),
				h('span', { class: 'ffla-req-badge ffla-req-badge--' + (r.open ? r.status : 'closed'), text: r.statusLabel }),
				h('span', { class: 'ffla-req-muted', text: r.updated })
			]));
		}));
	};

	/* ── New request form ─────────────────────────────────────────────── */

	App.prototype.showForm = function (type) {
		var self = this;
		var t = this.t;
		var cfg = this.cfg;
		var order = this.order;
		var money = this.moneyFormatter(order.currency);
		var uid = Math.random().toString(36).slice(2, 8);
		var shownAt = Date.now();
		var error = h('div', { class: 'ffla-req-error' });
		var firearm = h('div', { class: 'ffla-req-notice ffla-req-notice--warn', hidden: true }, text(cfg.firearmNotice));
		var feeBox = h('div', { class: 'ffla-req-notice ffla-req-notice--info', hidden: true, 'aria-live': 'polite' });

		var reasons = cfg.reasons[type] || {};
		var reason = h('select', { id: 'ffla-r-' + uid, name: 'reason', required: true }, [h('option', { value: '', text: t.chooseReason })].concat(
			Object.keys(reasons).map(function (k) {
				return h('option', { value: k, text: reasons[k] });
			})
		));

		var qtySelects = [];
		var rows = order.order.items.map(function (item) {
			var blocked = type === 'return' && item.blocked;
			var max = type === 'return' ? (blocked ? 0 : item.available) : item.ordered;
			var select = h('select', { name: 'items[' + item.id + ']', 'aria-label': t.qty + ': ' + item.name, disabled: max < 1 });
			for (var q = 0; q <= max; q++) {
				select.appendChild(h('option', { value: q, text: String(q) }));
			}
			select.fflaItem = item;
			select.addEventListener('change', update);
			qtySelects.push(select);
			var why = blocked ? t.cannotReturn + ': ' + item.blocked : (max < 1 ? t.notAvailable : '');
			return h('li', { class: 'ffla-req-item' + (max < 1 ? ' is-disabled' : '') }, [
				h('span', { class: 'ffla-req-item-name' }, [
					item.name,
					item.firearm ? h('span', { class: 'ffla-req-tag', text: t.firearm }) : null,
					type === 'return' && !blocked && item.fee > 0 ? h('span', { class: 'ffla-req-tag ffla-req-tag--fee', text: format(t.feeLine, item.fee) }) : null,
					why ? h('span', { class: 'ffla-req-muted ffla-req-why', text: why }) : null,
					type === 'return' && !blocked && item.note ? h('span', { class: 'ffla-req-muted ffla-req-why', text: item.note }) : null
				]),
				h('label', { class: 'ffla-req-qty' }, [h('span', { text: t.qty }), select])
			]);
		});

		var ffl = type === 'return' && cfg.fflRequired ? this.fflFields(uid, order.order.dealer) : null;
		var files = cfg.uploads ? this.fileInput(uid) : null;

		function photoNeeded() {
			return !!files && (cfg.photoReasons || []).indexOf(reason.value) !== -1;
		}

		function update() {
			var chosen = qtySelects.filter(function (s) {
				return +s.value > 0;
			});
			var guns = chosen.some(function (s) {
				return s.fflaItem.firearm;
			});
			firearm.hidden = type !== 'return' || !guns;
			if (ffl) {
				ffl.node.hidden = !guns;
			}
			if (files) {
				files.setRequired(photoNeeded());
			}

			// Restocking fee preview (returns only).
			feeBox.textContent = '';
			feeBox.hidden = true;
			if (type !== 'return') {
				return;
			}
			var withFee = chosen.filter(function (s) {
				return s.fflaItem.fee > 0;
			});
			if (!withFee.length || !reason.value) {
				return;
			}
			if ((cfg.feeWaived || []).indexOf(reason.value) !== -1) {
				feeBox.appendChild(h('strong', { text: t.feeTitle + ': ' }));
				feeBox.appendChild(document.createTextNode(t.feeWaivedNote));
				feeBox.hidden = false;
				return;
			}
			var gross = 0;
			var fee = 0;
			chosen.forEach(function (s) {
				var line = s.fflaItem.price * s.value;
				gross += line;
				fee += line * s.fflaItem.fee / 100;
			});
			feeBox.appendChild(h('strong', { text: t.feeTitle + ': ' }));
			feeBox.appendChild(document.createTextNode(format(t.feeEstimate, money(fee), money(gross - fee))));
			feeBox.hidden = false;
		}
		reason.addEventListener('change', update);

		var prefs = cfg.preferences || {};
		var preferred = h('select', { id: 'ffla-p-' + uid, name: 'preferred' }, [h('option', { value: '', text: t.noPreference })].concat(
			Object.keys(prefs).map(function (k) {
				return h('option', { value: k, text: prefs[k] });
			})
		));

		var counter = h('span', { class: 'ffla-req-muted ffla-req-counter', 'aria-live': 'polite' });
		var message = h('textarea', { id: 'ffla-m-' + uid, name: 'message', rows: 6, required: true, maxlength: cfg.maxText, 'aria-describedby': 'ffla-mh-' + uid });
		message.addEventListener('input', function () {
			var left = cfg.maxText - message.value.length;
			counter.textContent = left < 500 ? left + ' ' + t.charsLeft : '';
		});

		var form = h('form', { class: 'ffla-req-form', novalidate: true }, [
			h('h3', { class: 'ffla-req-h', 'data-focus': true, text: t[type] + ' — ' + t.order + ' #' + order.order.number }),
			error,
			h('p', { class: 'ffla-req-field' }, [h('label', { for: 'ffla-r-' + uid, text: t.reason + ' *' }), reason]),
			h('fieldset', { class: 'ffla-req-fieldset' }, [
				h('legend', { text: type === 'return' ? t.itemsReturn + ' *' : t.itemsIssue }),
				h('ul', { class: 'ffla-req-items' }, rows)
			]),
			feeBox,
			firearm,
			ffl ? ffl.node : null,
			h('p', { class: 'ffla-req-field' }, [h('label', { for: 'ffla-p-' + uid, text: t.preferred }), preferred]),
			h('p', { class: 'ffla-req-field' }, [
				h('label', { for: 'ffla-m-' + uid, text: t.message + ' *' }),
				message,
				h('span', { id: 'ffla-mh-' + uid, class: 'ffla-req-muted', text: t.messageHint }),
				counter
			]),
			files ? files.node : null,
			this.honeypot(),
			h('p', { class: 'ffla-req-actions' }, [
				h('button', { type: 'submit', class: 'button ffla-req-btn ffla-req-btn--primary', text: t.submit }),
				h('button', {
					type: 'button',
					class: 'button ffla-req-btn ffla-req-btn--link',
					text: t.back,
					onclick: function () {
						self.showOrder(order);
					}
				})
			])
		]);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			error.textContent = '';
			if (self.waitForFiles(form, files)) {
				return;
			}
			var chosen = qtySelects.filter(function (s) {
				return +s.value > 0;
			});
			var guns = chosen.some(function (s) {
				return s.fflaItem.firearm;
			});
			var problem = !reason.value || !message.value.trim() ? t.required
				: (type === 'return' && !chosen.length ? t.chooseItems
					: (photoNeeded() && !files.count() ? t.photoNeeded
						: (ffl && guns ? ffl.problem() : '') || (files ? files.problem() : '')));
			if (problem) {
				error.appendChild(notice(problem, 'error'));
				error.scrollIntoView({ block: 'nearest' });
				return;
			}
			var fd = new FormData();
			fd.append('ticket', order.ticket);
			fd.append('order', order.order.number);
			fd.append('type', type);
			fd.append('reason', reason.value);
			fd.append('preferred', preferred.value);
			fd.append('message', message.value);
			fd.append('website', form.website.value);
			fd.append('elapsed', String(Date.now() - shownAt));
			if (cfg.embedded) {
				fd.append('embedded', '1');
			}
			chosen.forEach(function (s) {
				fd.append(s.name, s.value);
			});
			if (ffl && guns) {
				ffl.append(fd);
			}
			if (files) {
				files.append(fd);
			}
			var submit = form.querySelector('[type=submit]');
			self.busy(form, true);
			submit.textContent = t.sending;
			self.post('submit', fd).then(function (data) {
				self.showRequest(data, { created: true });
			}, function (err) {
				self.busy(form, false);
				submit.textContent = t.submit;
				error.appendChild(notice(err.message, 'error'));
				error.scrollIntoView({ block: 'nearest' });
			});
		});

		this.render(form);
	};

	/** Format a number as money in the order's currency. */
	App.prototype.moneyFormatter = function (c) {
		c = c || { symbol: '$', decimals: 2, dec: '.', thou: ',', format: '%1$s%2$s' };
		return function (n) {
			var parts = (Math.round(n * Math.pow(10, c.decimals)) / Math.pow(10, c.decimals)).toFixed(c.decimals).split('.');
			parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thou);
			return format(c.format, c.symbol, parts.join(c.dec));
		};
	};

	/** FFL dealer for a firearm return: the order's dealer or another one. */
	App.prototype.fflFields = function (uid, orderDealer) {
		var t = this.t;
		function field(key, label, attrs) {
			var input = h('input', Object.assign({ id: 'ffla-ffl-' + key + '-' + uid, type: 'text', name: 'ffl[' + key + ']', maxlength: 120 }, attrs || {}));
			return { input: input, node: h('p', { class: 'ffla-req-field ffla-req-field--' + key }, [h('label', { for: input.id, text: label }), input]) };
		}
		var f = {
			name: field('name', t.fflName + ' *', { autocomplete: 'organization' }),
			license: field('license', t.fflLicense + ' *', { 'aria-describedby': 'ffla-ffl-lh-' + uid, maxlength: 25 }),
			address: field('address', t.fflAddress, { autocomplete: 'street-address', maxlength: 200 }),
			city: field('city', t.fflCity + ' *', { maxlength: 80 }),
			state: field('state', t.fflState + ' *', { maxlength: 40 }),
			postcode: field('postcode', t.fflZip, { maxlength: 20, inputmode: 'numeric' }),
			phone: field('phone', t.fflPhone, { type: 'tel', maxlength: 40 }),
			email: field('email', t.fflEmail, { type: 'email', maxlength: 120 })
		};
		f.license.node.appendChild(h('span', { id: 'ffla-ffl-lh-' + uid, class: 'ffla-req-muted', text: t.fflLicenseHint }));
		var manual = h('div', { class: 'ffla-req-ffl-grid', hidden: !!orderDealer }, Object.keys(f).map(function (k) {
			return f[k].node;
		}));
		var useOrder = null;
		var choice = null;
		if (orderDealer) {
			useOrder = h('input', { type: 'radio', name: 'ffla-ffl-src-' + uid, value: 'order', checked: true });
			var other = h('input', { type: 'radio', name: 'ffla-ffl-src-' + uid, value: 'customer' });
			var sync = function () {
				manual.hidden = useOrder.checked;
			};
			useOrder.addEventListener('change', sync);
			other.addEventListener('change', sync);
			choice = h('div', { class: 'ffla-req-ffl-choice' }, [
				h('label', { class: 'ffla-req-radio' }, [useOrder, h('span', {}, [h('strong', { text: t.fflUseOrder }), h('span', { class: 'ffla-req-muted ffla-req-why', text: orderDealer })])]),
				h('label', { class: 'ffla-req-radio' }, [other, h('span', { text: t.fflOther })])
			]);
		}
		var node = h('fieldset', { class: 'ffla-req-fieldset ffla-req-ffl', hidden: true }, [
			h('legend', { text: t.fflTitle + ' *' }),
			h('p', { class: 'ffla-req-muted', text: t.fflHint }),
			choice,
			manual
		]);
		function fromOrder() {
			return !!useOrder && useOrder.checked;
		}
		return {
			node: node,
			problem: function () {
				if (fromOrder()) {
					return '';
				}
				var license = f.license.input.value.replace(/[\s-]/g, '').toUpperCase();
				return !f.name.input.value.trim() || !/^[0-9]{9}[A-Z][0-9]{5}$/.test(license) || !f.city.input.value.trim() || !f.state.input.value.trim() ? t.fflMissing : '';
			},
			append: function (fd) {
				fd.append('ffl[source]', fromOrder() ? 'order' : 'customer');
				if (!fromOrder()) {
					Object.keys(f).forEach(function (k) {
						fd.append('ffl[' + k + ']', f[k].input.value);
					});
				}
			}
		};
	};

	App.prototype.fileInput = function (uid) {
		var cfg = this.cfg;
		var t = this.t;
		var lib = window.fflaReqFiles;
		var input = h('input', { id: 'ffla-f-' + uid, type: 'file', name: 'files[]', multiple: true, accept: cfg.accept, 'aria-describedby': 'ffla-fh-' + uid });
		var status = h('span', { class: 'ffla-req-error', role: 'status', 'aria-live': 'polite' });
		var label = h('label', { for: 'ffla-f-' + uid, text: t.files });
		var need = h('span', { class: 'ffla-req-need', hidden: true, text: t.photoHint });
		var limits = { perUpload: cfg.maxFiles, maxFileBytes: cfg.maxFileBytes, postLimit: cfg.postLimit };
		function problem() {
			var issue = lib ? lib.check(input.files, limits) : '';
			return issue === 'count' ? format(t.tooManyFiles, cfg.maxFiles)
				: (issue === 'size' ? t.fileTooBig : (issue === 'total' ? t.tooLarge : ''));
		}
		if (lib) {
			lib.wire(input);
		}
		input.addEventListener('ffla-files-busy', function () {
			status.textContent = t.preparing;
		});
		input.addEventListener('ffla-files-ready', function () {
			status.textContent = problem();
		});
		input.addEventListener('change', function () {
			if (!lib || !lib.busy(input)) {
				status.textContent = problem();
			}
		});
		return {
			node: h('p', { class: 'ffla-req-field' }, [
				label,
				need,
				input,
				h('span', { id: 'ffla-fh-' + uid, class: 'ffla-req-muted', text: format(t.filesHint, Math.round(cfg.maxFileBytes / 1048576)) }),
				status
			]),
			problem: problem,
			count: function () {
				return (input.files || []).length;
			},
			busy: function () {
				return !!lib && lib.busy(input);
			},
			ready: function () {
				return lib ? lib.ready(input) : Promise.resolve();
			},
			setRequired: function (on) {
				need.hidden = !on;
				label.textContent = t.files.replace(/\s*\(.*\)$/, '') + (on ? ' *' : '');
				if (!on) {
					label.textContent = t.files;
				}
			},
			append: function (fd) {
				[].forEach.call(input.files || [], function (f) {
					fd.append('files[]', f, f.name);
				});
			}
		};
	};

	/** While photos are being shrunk, hold the submit and send once they are ready. */
	App.prototype.waitForFiles = function (form, files) {
		if (!files || !files.busy()) {
			return false;
		}
		var self = this;
		this.busy(form, true);
		files.ready().then(function () {
			self.busy(form, false);
			form.dispatchEvent(new Event('submit', { cancelable: true }));
		});
		return true;
	};

	/* ── One request: tracker, history, reply ─────────────────────────── */

	App.prototype.showRequest = function (data, opts, focus) {
		var self = this;
		var t = this.t;
		var r = data.request;
		opts = opts || {};
		this.setUrl(r);

		var nodes = [];
		if (this.order && this.order.order.number === r.orderNumber) {
			nodes.push(this.backButton(function () {
				self.loading();
				self.post('verify', { order: r.orderNumber, ticket: self.order.ticket }).then(function (d) {
					self.showOrder(d);
				}, function () {
					self.showOrder(self.order);
				});
			}, t.viewOrder));
		}
		if (opts.created) {
			nodes.push(notice(t.created, 'success'));
		}
		if (opts.message) {
			nodes.push(notice(opts.message, 'success'));
		}

		nodes.push(h('div', { class: 'ffla-req-card' }, [
			h('div', { class: 'ffla-req-head' }, [
				h('h3', { class: 'ffla-req-h', 'data-focus': true, text: r.typeLabel + ' ' + r.number }),
				h('span', { class: 'ffla-req-badge ffla-req-badge--' + (r.open ? r.status : 'closed'), text: r.statusLabel })
			]),
			this.meta([[t.order, '#' + r.orderNumber], [t.reason, r.reason], [t.prefers, r.preferred], [t.opened, r.created], [t.updated, r.updated], [t.refunded, r.refunded], [t.fflLabel, r.ffl]]),
			this.tracker(r.steps),
			r.items.length ? h('div', { class: 'ffla-req-sub' }, [
				h('h4', { class: 'ffla-req-h', text: t.items }),
				h('ul', { class: 'ffla-req-plain' }, r.items.map(function (i) {
					return h('li', {}, [
						i.name + ' × ' + i.qty,
						i.firearm ? h('span', { class: 'ffla-req-tag', text: t.firearm }) : null,
						i.fee > 0 ? h('span', { class: 'ffla-req-tag ffla-req-tag--fee', text: format(t.feeLine, i.fee) }) : null
					]);
				}))
			]) : null
		]));

		if (r.resolution) {
			nodes.push(h('div', { class: 'ffla-req-notice ffla-req-notice--resolution' }, [
				h('strong', { text: t.resolution + ': ' + r.resolution.label }),
				r.resolution.date ? h('span', { class: 'ffla-req-muted', text: ' · ' + r.resolution.date }) : null,
				r.resolution.note ? text(r.resolution.note) : null
			]));
		}
		if (r.instructions || r.labels.length) {
			nodes.push(h('div', { class: 'ffla-req-notice ffla-req-notice--info' }, [
				r.instructions ? h('strong', { text: t.instructions }) : null,
				r.instructions ? text(r.instructions) : null,
				r.labels.length ? h('div', { class: 'ffla-req-labels' }, [
					h('strong', { text: t.label }),
					h('span', { class: 'ffla-req-muted', text: ' ' + t.labelHint }),
					h('ul', { class: 'ffla-req-plain' }, r.labels.map(function (f) {
						return h('li', {}, h('a', { href: f.url, target: '_blank', rel: 'noopener', class: 'ffla-req-download' }, '⬇ ' + f.name + ' (' + f.size + ')'));
					}))
				]) : null
			]));
		}
		if (r.shipment) {
			nodes.push(h('div', { class: 'ffla-req-notice ffla-req-notice--success' }, [
				h('strong', { text: t.shipment + ': ' }),
				r.shipment.carrier + ' ' + r.shipment.tracking + ' ',
				r.shipment.link ? h('a', { href: r.shipment.link, target: '_blank', rel: 'noopener', text: t.trackPackage }) : null
			]));
		}
		if (r.canTrack) {
			nodes.push(this.trackingForm(r));
		}
		if (r.canRate) {
			nodes.push(this.ratingBox(r));
		}
		if (r.firearmNotice) {
			nodes.push(h('div', { class: 'ffla-req-notice ffla-req-notice--warn' }, text(r.firearmNotice)));
		}

		nodes.push(h('h4', { class: 'ffla-req-h', text: t.timeline }));
		nodes.push(h('ol', { class: 'ffla-req-timeline' }, r.timeline.map(function (e) {
			return h('li', { class: 'ffla-req-event ffla-req-event--' + e.who + ' ffla-req-event--' + e.kind }, [
				h('div', { class: 'ffla-req-event-head' }, [h('strong', { text: e.title }), h('time', { class: 'ffla-req-muted', text: e.time })]),
				e.text ? text(e.text) : null,
				e.files.length ? h('ul', { class: 'ffla-req-files' }, e.files.map(function (f) {
					return h('li', {}, h('a', { href: f.url, target: '_blank', rel: 'noopener' }, [
						f.image ? h('img', { src: f.url, alt: '', loading: 'lazy' }) : null,
						h('span', { text: f.name + ' (' + f.size + ')' })
					]));
				})) : null
			]);
		})));

		if (r.canReply) {
			nodes.push(this.replyForm(r));
		}
		var tools = h('p', { class: 'ffla-req-actions' });
		if (navigator.clipboard && r.url) {
			tools.appendChild(h('button', {
				type: 'button',
				class: 'button ffla-req-btn ffla-req-btn--link',
				text: t.copyLink,
				onclick: function (e) {
					var btn = e.currentTarget;
					navigator.clipboard.writeText(r.url).then(function () {
						btn.textContent = t.copied;
					});
				}
			}));
		}
		if (r.canWithdraw) {
			tools.appendChild(this.withdrawControl(r));
		}
		nodes.push(tools);

		this.render(nodes, focus);
	};

	App.prototype.trackingForm = function (r) {
		var self = this;
		var t = this.t;
		var uid = Math.random().toString(36).slice(2, 8);
		var error = h('div', { class: 'ffla-req-error' });
		var carriers = this.cfg.carriers || {};
		var carrier = h('select', { id: 'ffla-c-' + uid, name: 'carrier' }, Object.keys(carriers).map(function (k) {
			return h('option', { value: k, text: carriers[k], selected: r.shipment && r.shipment.carrier === carriers[k] });
		}));
		var tracking = h('input', { id: 'ffla-tn-' + uid, type: 'text', name: 'tracking', maxlength: 60, autocomplete: 'off', value: r.shipment ? r.shipment.tracking : '' });
		var form = h('form', { class: 'ffla-req-form ffla-req-ship', novalidate: true }, [
			h('h4', { class: 'ffla-req-h', text: t.shipTitle }),
			h('p', { class: 'ffla-req-muted', text: t.shipHint }),
			error,
			h('div', { class: 'ffla-req-inline' }, [
				h('p', { class: 'ffla-req-field' }, [h('label', { for: 'ffla-c-' + uid, text: t.carrier }), carrier]),
				h('p', { class: 'ffla-req-field' }, [h('label', { for: 'ffla-tn-' + uid, text: t.trackingNumber }), tracking])
			]),
			this.honeypot(),
			h('p', {}, h('button', { type: 'submit', class: 'button ffla-req-btn ffla-req-btn--primary', text: t.saveTracking }))
		]);
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			error.textContent = '';
			if (tracking.value.replace(/[\s-]/g, '').length < 6) {
				error.appendChild(notice(t.required, 'error'));
				return;
			}
			self.busy(form, true);
			self.post('tracking', { number: r.number, key: r.key, carrier: carrier.value, tracking: tracking.value, website: form.website.value }).then(function (data) {
				self.showRequest(data, { message: t.saveTracking + ' ✓' });
			}, function (err) {
				self.busy(form, false);
				error.appendChild(notice(err.message, 'error'));
			});
		});
		return form;
	};

	App.prototype.ratingBox = function (r) {
		var self = this;
		var t = this.t;
		var box = h('div', { class: 'ffla-req-rate', id: 'ffla-rate' });
		function stars(n) {
			return '★★★★★'.slice(0, n) + '☆☆☆☆☆'.slice(0, 5 - n);
		}
		function showDone() {
			box.textContent = '';
			box.appendChild(h('h4', { class: 'ffla-req-h', text: t.rateTitle }));
			box.appendChild(h('p', {}, [
				h('span', { class: 'ffla-req-stars', 'aria-hidden': 'true', text: stars(r.rating) }),
				' ' + format(t.rateStars, r.rating) + ' — ' + t.rateThanks
			]));
			if (r.ratingComment) {
				box.appendChild(text(r.ratingComment, 'ffla-req-text ffla-req-quote'));
			}
			box.appendChild(h('button', { type: 'button', class: 'button ffla-req-btn ffla-req-btn--link', text: t.rateChange, onclick: showForm }));
		}
		function showForm() {
			box.textContent = '';
			var uid = Math.random().toString(36).slice(2, 8);
			var error = h('div', { class: 'ffla-req-error' });
			var radios = [1, 2, 3, 4, 5].map(function (n) {
				var input = h('input', { type: 'radio', name: 'rating', value: n, id: 'ffla-s' + n + '-' + uid, checked: r.rating === n });
				return h('span', { class: 'ffla-req-star' }, [input, h('label', { for: input.id, title: format(t.rateStars, n) }, [
					h('span', { 'aria-hidden': 'true', text: '★' }),
					h('span', { class: 'screen-reader-text', text: format(t.rateStars, n) })
				])]);
			});
			function paint() {
				var picked = box.querySelector('input[name=rating]:checked');
				var value = picked ? +picked.value : 0;
				radios.forEach(function (star, i) {
					star.classList.toggle('is-on', i < value);
				});
			}
			radios.forEach(function (star) {
				star.querySelector('input').addEventListener('change', paint);
			});
			var comment = h('textarea', { id: 'ffla-rc-' + uid, rows: 2, maxlength: 1000 }, r.ratingComment || '');
			var form = h('form', { class: 'ffla-req-form', novalidate: true }, [
				h('h4', { class: 'ffla-req-h', text: t.rateTitle }),
				error,
				h('fieldset', { class: 'ffla-req-stars-input' }, [h('legend', { class: 'screen-reader-text', text: t.rateTitle })].concat(radios)),
				h('p', { class: 'ffla-req-field' }, [h('label', { for: 'ffla-rc-' + uid, text: t.rateComment }), comment]),
				h('p', {}, h('button', { type: 'submit', class: 'button ffla-req-btn ffla-req-btn--primary', text: t.rateSend }))
			]);
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				error.textContent = '';
				var picked = form.querySelector('input[name=rating]:checked');
				if (!picked) {
					error.appendChild(notice(t.rateChoose, 'error'));
					return;
				}
				self.busy(form, true);
				self.post('rate', { number: r.number, key: r.key, rating: picked.value, comment: comment.value }).then(function (data) {
					r.rating = data.request.rating;
					r.ratingComment = data.request.ratingComment;
					showDone();
				}, function (err) {
					self.busy(form, false);
					error.appendChild(notice(err.message, 'error'));
				});
			});
			box.appendChild(form);
			paint();
		}
		if (r.rating) {
			showDone();
		} else {
			showForm();
		}
		return box;
	};

	App.prototype.replyForm = function (r) {
		var self = this;
		var t = this.t;
		var uid = Math.random().toString(36).slice(2, 8);
		var error = h('div', { class: 'ffla-req-error' });
		var files = this.cfg.uploads && r.uploads ? this.fileInput(uid) : null;
		var message = h('textarea', { id: 'ffla-y-' + uid, name: 'message', rows: 4, required: true, maxlength: this.cfg.maxText });
		var form = h('form', { class: 'ffla-req-form ffla-req-reply', novalidate: true }, [
			h('h4', { class: 'ffla-req-h' }, h('label', { for: 'ffla-y-' + uid, text: t.reply })),
			r.reopenNote ? h('p', { class: 'ffla-req-muted', text: t.replyReopen }) : null,
			error,
			message,
			files ? files.node : null,
			this.honeypot(),
			h('p', {}, h('button', { type: 'submit', class: 'button ffla-req-btn ffla-req-btn--primary', text: t.sendReply }))
		]);
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			error.textContent = '';
			if (self.waitForFiles(form, files)) {
				return;
			}
			var problem = !message.value.trim() ? t.required : (files ? files.problem() : '');
			if (problem) {
				error.appendChild(notice(problem, 'error'));
				return;
			}
			var fd = new FormData();
			fd.append('number', r.number);
			fd.append('key', r.key);
			fd.append('message', message.value);
			fd.append('website', form.website.value);
			if (files) {
				files.append(fd);
			}
			self.busy(form, true);
			self.post('reply', fd).then(function (data) {
				self.showRequest(data, { message: t.sendReply + ' ✓' });
			}, function (err) {
				self.busy(form, false);
				error.appendChild(notice(err.message, 'error'));
			});
		});
		return form;
	};

	App.prototype.withdrawControl = function (r) {
		var self = this;
		var t = this.t;
		var wrap = h('span', { class: 'ffla-req-withdraw' });
		var start = h('button', { type: 'button', class: 'button ffla-req-btn ffla-req-btn--link ffla-req-danger', text: t.withdraw });
		wrap.appendChild(start);
		start.addEventListener('click', function () {
			var uid = Math.random().toString(36).slice(2, 8);
			var why = h('textarea', { id: 'ffla-w-' + uid, rows: 2, maxlength: 1000 });
			var error = h('div', { class: 'ffla-req-error' });
			var box = h('div', { class: 'ffla-req-confirm', role: 'group' }, [
				error,
				h('label', { for: 'ffla-w-' + uid, text: t.withdrawWhy }),
				why,
				h('p', { class: 'ffla-req-actions' }, [
					h('button', {
						type: 'button',
						class: 'button ffla-req-btn ffla-req-danger',
						text: t.withdrawConfirm,
						onclick: function () {
							self.busy(box, true);
							self.post('withdraw', { number: r.number, key: r.key, message: why.value }).then(function (data) {
								self.showRequest(data);
							}, function (err) {
								self.busy(box, false);
								error.appendChild(notice(err.message, 'error'));
							});
						}
					}),
					h('button', {
						type: 'button',
						class: 'button ffla-req-btn ffla-req-btn--link',
						text: t.withdrawKeep,
						onclick: function () {
							wrap.replaceChild(start, box);
							start.focus();
						}
					})
				])
			]);
			wrap.replaceChild(box, start);
			why.focus();
		});
		return wrap;
	};

	/* ── Pieces ───────────────────────────────────────────────────────── */

	App.prototype.tracker = function (steps) {
		var t = this.t;
		return h('ol', { class: 'ffla-req-tracker' }, steps.map(function (s) {
			return h('li', { class: 'is-' + s.state, 'aria-current': s.state === 'current' ? 'step' : null }, [
				h('span', { class: 'ffla-req-dot', 'aria-hidden': 'true' }),
				h('span', { class: 'ffla-req-step', text: s.label }),
				s.state === 'skipped' ? h('span', { class: 'screen-reader-text', text: ' (' + t.stepSkipped + ')' }) : null
			]);
		}));
	};

	App.prototype.meta = function (pairs) {
		return h('dl', { class: 'ffla-req-meta' }, pairs.filter(function (p) {
			return p[1];
		}).map(function (p) {
			return h('div', {}, [h('dt', { text: p[0] }), h('dd', { text: p[1] })]);
		}));
	};

	App.prototype.backButton = function (onclick, label) {
		return h('p', { class: 'ffla-req-back' }, h('button', { type: 'button', class: 'button ffla-req-btn ffla-req-btn--link', onclick: onclick, text: '← ' + (label || this.t.back) }));
	};

	App.prototype.setUrl = function (r) {
		if (!this.primary || !window.history.replaceState) {
			return;
		}
		var url = new URL(window.location.href);
		url.searchParams.set('ffla_request', r.number);
		url.searchParams.set('key', r.key);
		url.hash = 'ffla-requests';
		window.history.replaceState(null, '', url.toString());
	};

	App.prototype.clearUrl = function () {
		if (!this.primary || !window.history.replaceState) {
			return;
		}
		var url = new URL(window.location.href);
		if (url.searchParams.has('ffla_request')) {
			url.searchParams.delete('ffla_request');
			url.searchParams.delete('key');
			window.history.replaceState(null, '', url.toString());
		}
	};

	function init() {
		[].forEach.call(document.querySelectorAll('.ffla-req[data-config]'), function (root) {
			if (!root.fflaReq) {
				root.fflaReq = new App(root);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
