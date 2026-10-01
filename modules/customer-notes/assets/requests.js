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

	function format(str) {
		var args = [].slice.call(arguments, 1);
		return String(str).replace(/%(\d)\$[ds]/g, function (m, n) {
			return args[n - 1];
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
		var uid = Math.random().toString(36).slice(2, 8);
		var shownAt = Date.now();
		var error = h('div', { class: 'ffla-req-error' });
		var firearm = h('div', { class: 'ffla-req-notice ffla-req-notice--warn', hidden: true }, text(cfg.firearmNotice));

		var reasons = cfg.reasons[type] || {};
		var reason = h('select', { id: 'ffla-r-' + uid, name: 'reason', required: true }, [h('option', { value: '', text: t.chooseReason })].concat(
			Object.keys(reasons).map(function (k) {
				return h('option', { value: k, text: reasons[k] });
			})
		));

		var qtySelects = [];
		var rows = order.order.items.map(function (item) {
			var max = type === 'return' ? item.available : item.ordered;
			var select = h('select', { name: 'items[' + item.id + ']', 'aria-label': t.qty + ': ' + item.name, disabled: max < 1, 'data-firearm': item.firearm ? '1' : '' });
			for (var q = 0; q <= max; q++) {
				select.appendChild(h('option', { value: q, text: String(q) }));
			}
			select.addEventListener('change', updateFirearm);
			qtySelects.push(select);
			return h('li', { class: 'ffla-req-item' + (max < 1 ? ' is-disabled' : '') }, [
				h('span', { class: 'ffla-req-item-name' }, [
					item.name,
					item.firearm ? h('span', { class: 'ffla-req-tag', text: t.firearm }) : null,
					max < 1 ? h('span', { class: 'ffla-req-muted', text: ' — ' + t.notAvailable }) : null
				]),
				h('label', { class: 'ffla-req-qty' }, [h('span', { text: t.qty }), select])
			]);
		});

		function updateFirearm() {
			firearm.hidden = type !== 'return' || !qtySelects.some(function (s) {
				return s.dataset.firearm && +s.value > 0;
			});
		}

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

		var files = cfg.uploads ? this.fileInput(uid) : null;

		var form = h('form', { class: 'ffla-req-form', novalidate: true }, [
			h('h3', { class: 'ffla-req-h', 'data-focus': true, text: t[type] + ' — ' + t.order + ' #' + order.order.number }),
			error,
			h('p', { class: 'ffla-req-field' }, [h('label', { for: 'ffla-r-' + uid, text: t.reason + ' *' }), reason]),
			h('fieldset', { class: 'ffla-req-fieldset' }, [
				h('legend', { text: type === 'return' ? t.itemsReturn + ' *' : t.itemsIssue }),
				h('ul', { class: 'ffla-req-items' }, rows)
			]),
			firearm,
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
			var chosen = qtySelects.filter(function (s) {
				return +s.value > 0;
			});
			var problem = !reason.value || !message.value.trim() ? t.required
				: (type === 'return' && !chosen.length ? t.chooseItems : (files ? files.problem() : ''));
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

	App.prototype.fileInput = function (uid) {
		var cfg = this.cfg;
		var t = this.t;
		var input = h('input', { id: 'ffla-f-' + uid, type: 'file', name: 'files[]', multiple: true, accept: cfg.accept, 'aria-describedby': 'ffla-fh-' + uid });
		var status = h('span', { class: 'ffla-req-error' });
		function problem() {
			var list = [].slice.call(input.files || []);
			if (list.length > cfg.maxFiles) {
				return t.tooManyFiles;
			}
			return list.some(function (f) {
				return f.size > cfg.maxFileBytes;
			}) ? t.fileTooBig : '';
		}
		input.addEventListener('change', function () {
			status.textContent = problem();
		});
		return {
			node: h('p', { class: 'ffla-req-field' }, [
				h('label', { for: 'ffla-f-' + uid, text: t.files }),
				input,
				h('span', { id: 'ffla-fh-' + uid, class: 'ffla-req-muted', text: format(t.filesHint, cfg.maxFiles, Math.round(cfg.maxFileBytes / 1048576)) }),
				status
			]),
			problem: problem,
			append: function (fd) {
				[].forEach.call(input.files || [], function (f) {
					fd.append('files[]', f, f.name);
				});
			}
		};
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
			this.meta([[t.order, '#' + r.orderNumber], [t.reason, r.reason], [t.prefers, r.preferred], [t.opened, r.created], [t.updated, r.updated]]),
			this.tracker(r.steps),
			r.items.length ? h('div', { class: 'ffla-req-sub' }, [
				h('h4', { class: 'ffla-req-h', text: t.items }),
				h('ul', { class: 'ffla-req-plain' }, r.items.map(function (i) {
					return h('li', {}, [i.name + ' × ' + i.qty, i.firearm ? h('span', { class: 'ffla-req-tag', text: t.firearm }) : null]);
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
		if (r.instructions) {
			nodes.push(h('div', { class: 'ffla-req-notice ffla-req-notice--info' }, [h('strong', { text: t.instructions }), text(r.instructions)]));
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
