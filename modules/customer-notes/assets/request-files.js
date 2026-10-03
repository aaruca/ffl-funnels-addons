/*
 * Customer requests — attachments and the photo viewer, shared by the
 * customer form and the staff screens.
 *
 * Picker (wire(input)): thumbnails before sending, remove one file, choosing
 * again adds to the list instead of replacing it, drag & drop, and pasting a
 * screenshot (Ctrl+V / ⌘V). Phone photos are shrunk in the browser (longest
 * side 2000 px, what the server keeps) so many fit in one message; iPhone HEIC
 * photos become JPEG where the browser can read them (Safari), otherwise the
 * server converts them when it can, otherwise the file is flagged. Videos are
 * sent as they are. The input keeps the final list, so plain form posts and
 * FormData both send exactly what the picker shows. Browsers without
 * DataTransfer keep the plain input.
 *
 * Viewer: any link with data-ffla-view="image|video" opens full size, with
 * arrows (keyboard and swipe) through the others in the same
 * [data-ffla-gallery] block.
 *
 * window.fflaReqFiles:
 *   wire(input, cfg, t)  enhance a file input (cfg: limits, t: strings)
 *   ready(input)         Promise that resolves when its files are ready
 *   busy(input)          true while photos are being prepared
 *   check(files, cfg)    '' or 'count' / 'size' / 'total' for what is too much
 *   clear(input)         empty the picker (after a successful send)
 */
(function () {
	'use strict';

	var MAX_EDGE = 2000;
	var QUALITY = 0.85;
	var MIN_BYTES = 600 * 1024; // Smaller JPEG / PNG files are left alone.

	var canSwap = (function () {
		try {
			return typeof DataTransfer === 'function' && !!new DataTransfer().items && typeof File === 'function';
		} catch (e) {
			return false;
		}
	})();
	var canDraw = typeof createImageBitmap === 'function' && !!document.createElement('canvas').toBlob;

	function fmt(str) {
		var args = [].slice.call(arguments, 1);
		var next = 0;
		return String(str || '').replace(/%(?:(\d)\$)?([ds%])/g, function (m, n, type) {
			return type === '%' ? '%' : String(n ? args[n - 1] : args[next++]);
		});
	}

	function sizeLabel(bytes) {
		return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
	}

	function ext(name) {
		var m = /\.([a-z0-9]+)$/i.exec(name || '');
		return m ? m[1].toLowerCase() : '';
	}

	function typeOf(file) {
		var e = ext(file.name);
		if (/^image\/hei[cf]/.test(file.type) || e === 'heic' || e === 'heif') {
			return 'heic';
		}
		if (/^video\/(mp4|quicktime)$/.test(file.type) || e === 'mp4' || e === 'm4v' || e === 'mov') {
			return 'video';
		}
		if (/^image\/(jpeg|png)$/.test(file.type)) {
			return 'image';
		}
		if (file.type === 'application/pdf' || e === 'pdf') {
			return 'pdf';
		}
		return '';
	}

	function decode(file) {
		// Honour the camera's rotation where the option exists; some browsers reject the options object.
		return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () {
			return createImageBitmap(file);
		});
	}

	/** Draw to a canvas no larger than 2000 px and encode; resolves to a File or null. */
	function redraw(file, mime, name, force) {
		return decode(file).then(function (bitmap) {
			var scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
			if (scale === 1 && !force && mime === 'image/png') {
				if (bitmap.close) {
					bitmap.close();
				}
				return null; // A PNG within 2000 px (a screenshot): keep it sharp.
			}
			var canvas = document.createElement('canvas');
			canvas.width = Math.max(1, Math.round(bitmap.width * scale));
			canvas.height = Math.max(1, Math.round(bitmap.height * scale));
			canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
			if (bitmap.close) {
				bitmap.close();
			}
			return new Promise(function (resolve) {
				canvas.toBlob(function (blob) {
					canvas.width = canvas.height = 0; // Free the memory now, not at garbage collection.
					if (!blob || (!force && blob.size >= file.size)) {
						resolve(null);
						return;
					}
					resolve(new File([blob], name, { type: mime, lastModified: file.lastModified || Date.now() }));
				}, mime, QUALITY);
			});
		});
	}

	/**
	 * Get one file ready to send. Resolves to { file } or { error }.
	 */
	function prepareOne(file, cfg, t) {
		var kind = typeOf(file);
		if (kind === 'video') {
			if (!cfg.maxVideoBytes) {
				return Promise.resolve({ error: t.videosOff });
			}
			return Promise.resolve(file.size > cfg.maxVideoBytes ? { error: fmt(t.videoTooBig, Math.floor(cfg.maxVideoBytes / 1048576)) } : { file: file });
		}
		if (kind === 'pdf') {
			return Promise.resolve(file.size > cfg.maxFileBytes ? { error: fmt(t.fileTooBig, Math.round(cfg.maxFileBytes / 1048576)) } : { file: file });
		}
		if (kind === 'heic') {
			var jpgName = file.name.replace(/\.[^.]+$/, '') + '.jpg';
			var fallback = function () {
				if (cfg.heicServer && file.size <= cfg.maxFileBytes) {
					return { file: file }; // The server converts it.
				}
				return { error: fmt(t.heicNoConvert, file.name) };
			};
			if (!canDraw) {
				return Promise.resolve(fallback());
			}
			return redraw(file, 'image/jpeg', jpgName, true).then(function (out) {
				return out ? { file: out } : fallback();
			}, fallback);
		}
		if (kind === 'image') {
			var done = function (f) {
				return f.size > cfg.maxFileBytes ? { error: fmt(t.fileTooBig, Math.round(cfg.maxFileBytes / 1048576)) } : { file: f };
			};
			if (!canDraw || file.size < MIN_BYTES) {
				return Promise.resolve(done(file));
			}
			return redraw(file, file.type, file.name, false).then(function (out) {
				return done(out || file);
			}, function () {
				return done(file);
			});
		}
		return Promise.resolve({ error: fmt(t.badType, file.name) });
	}

	function el(tag, attrs, kids) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') {
				node.textContent = attrs[k];
			} else if (k === 'class') {
				node.className = attrs[k];
			} else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) {
				node.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
			}
		});
		(kids || []).forEach(function (kid) {
			if (kid) {
				node.appendChild(typeof kid === 'string' ? document.createTextNode(kid) : kid);
			}
		});
		return node;
	}

	/* ── Picker ─────────────────────────────────────────────────────────── */

	function wire(input, cfg, t) {
		if (!input || input.fflaWired) {
			return;
		}
		input.fflaWired = true;
		input.fflaReady = Promise.resolve();
		cfg = cfg || {};
		t = t || {};
		if (!canSwap || !input.parentNode) {
			return; // Plain input; the server still resizes photos.
		}

		var items = [];
		var queue = Promise.resolve(); // One line of work: every choice waits for the ones before it.
		var list = el('ul', { class: 'ffla-pick__list' });
		var status = el('p', { class: 'ffla-pick__count', 'aria-live': 'polite' });
		var choose = el('button', { type: 'button', class: 'button ffla-pick__choose', text: t.choose || 'Choose files' });
		var drop = el('div', { class: 'ffla-pick__drop' }, [
			el('span', { class: 'ffla-pick__icon', 'aria-hidden': 'true', text: '⇪' }),
			el('span', { class: 'ffla-pick__hint', text: t.drop || 'Drag files here, paste a screenshot, or' }),
			choose
		]);
		var box = el('div', { class: 'ffla-pick', 'data-state': 'empty' }, [drop, list, status]);
		input.parentNode.insertBefore(box, input.nextSibling);
		box.insertBefore(input, drop); // Keep the input inside, so it still posts with the form.
		input.classList.add('ffla-pick__input');
		input.setAttribute('tabindex', '-1');
		input.setAttribute('aria-hidden', 'true');
		input.fflaPicker = box;

		function sync() {
			var dt = new DataTransfer();
			items.forEach(function (it) {
				if (!it.error) {
					dt.items.add(it.file);
				}
			});
			input.files = dt.files;
			var busy = items.some(function (it) {
				return it.busy;
			});
			var good = items.filter(function (it) {
				return !it.error;
			}).length;
			box.setAttribute('data-state', items.length ? 'filled' : 'empty');
			status.textContent = good ? (good === 1 ? (t.oneFile || '1 file') : fmt(t.files || '%d files', good)) + (busy ? ' · ' + (t.preparing || 'Preparing…') : '') : '';
			if (busy) {
				input.setAttribute('data-ffla-busy', '1');
			} else {
				input.removeAttribute('data-ffla-busy');
			}
		}

		function thumb(it) {
			var kind = typeOf(it.file);
			if (kind === 'image' || (kind === 'heic' && it.file.type === 'image/jpeg')) {
				it.url = it.url || URL.createObjectURL(it.file);
				return el('img', { src: it.url, alt: '' });
			}
			if (kind === 'video') {
				it.url = it.url || URL.createObjectURL(it.file);
				return el('video', { src: it.url + '#t=0.1', muted: true, preload: 'metadata', playsinline: true });
			}
			return el('span', { class: 'ffla-pick__doc', text: kind === 'pdf' ? 'PDF' : (kind === 'heic' ? 'HEIC' : ext(it.file.name).toUpperCase() || '?') });
		}

		function render() {
			list.textContent = '';
			items.forEach(function (it) {
				var remove = el('button', { type: 'button', class: 'ffla-pick__remove', 'aria-label': fmt(t.remove || 'Remove %s', it.file.name), text: '×' });
				remove.addEventListener('click', function () {
					if (it.url) {
						URL.revokeObjectURL(it.url);
					}
					items.splice(items.indexOf(it), 1);
					render();
					sync();
					changed();
				});
				list.appendChild(el('li', { class: 'ffla-pick__item' + (it.busy ? ' is-busy' : '') + (it.error ? ' is-error' : '') }, [
					thumb(it),
					el('span', { class: 'ffla-pick__name', text: it.file.name }),
					el('span', { class: 'ffla-pick__meta', text: it.error ? it.error : (it.busy ? (t.preparing || 'Preparing…') : sizeLabel(it.file.size)) }),
					remove
				]));
			});
		}

		function changed() {
			input.dispatchEvent(new CustomEvent(items.some(function (it) { return it.busy; }) ? 'ffla-files-busy' : 'ffla-files-ready', { bubbles: true }));
		}

		function add(files) {
			files = [].slice.call(files || []);
			if (!files.length) {
				sync(); // A cancelled dialog empties the input: put our list back.
				return;
			}
			var known = {};
			items.forEach(function (it) {
				known[it.key] = true;
			});
			var fresh = [];
			files.forEach(function (f) {
				var key = f.name + '|' + f.size + '|' + (f.lastModified || 0);
				if (!known[key]) {
					known[key] = true;
					fresh.push({ key: key, file: f, busy: true, error: '' });
				}
			});
			if (!fresh.length) {
				sync();
				return;
			}
			items = items.concat(fresh);
			render();
			sync();
			changed();
			// One at a time, after any earlier choice: a dozen 12-megapixel photos
			// decoded at once can exhaust a phone's memory.
			queue = fresh.reduce(function (p, it) {
				return p.then(function () {
					if (items.indexOf(it) < 0) {
						return null; // Removed before its turn.
					}
					return prepareOne(it.file, cfg, t).then(function (res) {
						it.busy = false;
						if (res.error) {
							it.error = res.error;
						} else if (res.file !== it.file) {
							if (it.url) {
								URL.revokeObjectURL(it.url);
								it.url = '';
							}
							it.file = res.file;
						}
						if (items.indexOf(it) >= 0) {
							render();
							sync();
						}
					}, function () {
						it.busy = false;
						sync();
					});
				});
			}, queue).then(function () {
				if (!items.some(function (it) { return it.busy; })) {
					changed();
				}
			});
		}

		// Resolves once nothing is being prepared, including work added meanwhile.
		function idle() {
			var tail = queue;
			return tail.then(function () {
				return tail === queue ? null : idle();
			});
		}
		input.fflaReady = { then: function (ok, fail) { return idle().then(ok, fail); } };

		choose.addEventListener('click', function () {
			input.click();
		});
		input.addEventListener('change', function () {
			// The browser just replaced the input's list with the new choice: add it to ours.
			var picked = [].slice.call(input.files || []);
			var ours = items.filter(function (it) { return !it.error; }).map(function (it) { return it.file; });
			var same = picked.length === ours.length && picked.every(function (f, i) { return f === ours[i]; });
			if (!same) {
				add(picked);
			}
		});
		['dragenter', 'dragover'].forEach(function (type) {
			box.addEventListener(type, function (e) {
				if (e.dataTransfer && [].indexOf.call(e.dataTransfer.types || [], 'Files') >= 0) {
					e.preventDefault();
					box.classList.add('is-over');
				}
			});
		});
		['dragleave', 'drop'].forEach(function (type) {
			box.addEventListener(type, function (e) {
				if (type === 'dragleave' && box.contains(e.relatedTarget)) {
					return;
				}
				box.classList.remove('is-over');
			});
		});
		box.addEventListener('drop', function (e) {
			if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
				e.preventDefault();
				add(e.dataTransfer.files);
			}
		});
		// Paste a screenshot anywhere in the form (text still pastes normally).
		document.addEventListener('paste', function (e) {
			var data = e.clipboardData;
			var form = input.form;
			if (!data || !document.contains(input) || !(form ? form.contains(e.target) : box.contains(e.target))) {
				return;
			}
			var files = [].slice.call(data.files || []);
			if (!files.length && data.items) {
				[].forEach.call(data.items, function (item) {
					if (item.kind === 'file') {
						var f = item.getAsFile();
						if (f) {
							files.push(f);
						}
					}
				});
			}
			if (!files.length) {
				return;
			}
			e.preventDefault();
			var stamp = new Date().toISOString().replace(/[-:]/g, '').replace('T', '-').slice(0, 15);
			add(files.map(function (f, i) {
				if (/^image\//.test(f.type) && (!f.name || /^image\.\w+$/i.test(f.name))) {
					var e2 = f.type === 'image/jpeg' ? 'jpg' : 'png';
					return new File([f], 'screenshot-' + stamp + (i ? '-' + i : '') + '.' + e2, { type: f.type === 'image/jpeg' ? 'image/jpeg' : 'image/png', lastModified: Date.now() + i });
				}
				return f;
			}));
		});

		input.fflaClear = function () {
			items.forEach(function (it) {
				if (it.url) {
					URL.revokeObjectURL(it.url);
				}
			});
			items = [];
			render();
			sync();
		};
	}

	function check(files, cfg) {
		var list = [].slice.call(files || []);
		if (cfg.perUpload && list.length > cfg.perUpload) {
			return 'count';
		}
		if (list.some(function (f) {
			var video = typeOf(f) === 'video';
			return f.size > (video ? (cfg.maxVideoBytes || 0) : cfg.maxFileBytes) && !(typeOf(f) === 'image' && canSwap && canDraw);
		})) {
			return 'size';
		}
		var total = list.reduce(function (sum, f) { return sum + f.size; }, 0);
		// Keep room for the message and the rest of the form.
		return cfg.postLimit && total > cfg.postLimit - 256 * 1024 ? 'total' : '';
	}

	/* ── Viewer ─────────────────────────────────────────────────────────── */

	var viewer = null;

	function openViewer(links, index, t) {
		t = t || window.fflaReqViewer || {};
		if (viewer) {
			viewer.close();
		}
		var opener = document.activeElement;
		var stage = el('figure', { class: 'ffla-lb__stage' });
		var count = el('span', { class: 'ffla-lb__count' });
		var name = el('span', { class: 'ffla-lb__name' });
		var dl = el('a', { class: 'ffla-lb__dl', text: t.download || 'Download' });
		var closeBtn = el('button', { type: 'button', class: 'ffla-lb__close', 'aria-label': t.close || 'Close', text: '×' });
		var prev = el('button', { type: 'button', class: 'ffla-lb__nav ffla-lb__prev', 'aria-label': t.prev || 'Previous', text: '‹' });
		var next = el('button', { type: 'button', class: 'ffla-lb__nav ffla-lb__next', 'aria-label': t.next || 'Next', text: '›' });
		var root = el('div', { class: 'ffla-lb', role: 'dialog', 'aria-modal': 'true', 'aria-label': t.viewer || 'Photo viewer' }, [
			el('div', { class: 'ffla-lb__bar' }, [count, name, dl, closeBtn]),
			prev, stage, next
		]);
		var current = index;

		function show(i) {
			current = (i + links.length) % links.length;
			var a = links[current];
			var url = a.getAttribute('href');
			stage.textContent = '';
			if (a.getAttribute('data-ffla-view') === 'video') {
				stage.appendChild(el('video', { src: url, controls: true, autoplay: true, playsinline: true }));
			} else {
				stage.appendChild(el('img', { src: url, alt: a.getAttribute('data-name') || '' }));
			}
			count.textContent = fmt(t.of || '%1$d of %2$d', current + 1, links.length);
			name.textContent = a.getAttribute('data-name') || '';
			dl.setAttribute('href', url + (url.indexOf('?') >= 0 ? '&' : '?') + 'download=1');
			prev.hidden = next.hidden = links.length < 2;
		}
		function onKey(e) {
			if (e.key === 'Escape') {
				close();
			} else if (e.key === 'ArrowLeft') {
				show(current - 1);
			} else if (e.key === 'ArrowRight') {
				show(current + 1);
			} else if (e.key === 'Tab') {
				// Keep focus inside the viewer.
				var focusable = [].slice.call(root.querySelectorAll('button:not([hidden]), a[href], video'));
				var first = focusable[0];
				var last = focusable[focusable.length - 1];
				if (e.shiftKey && document.activeElement === first) {
					e.preventDefault();
					last.focus();
				} else if (!e.shiftKey && document.activeElement === last) {
					e.preventDefault();
					first.focus();
				}
			}
		}
		function close() {
			document.removeEventListener('keydown', onKey, true);
			root.remove();
			document.documentElement.classList.remove('ffla-lb-open');
			viewer = null;
			if (opener && opener.focus) {
				opener.focus();
			}
		}
		var startX = null;
		stage.addEventListener('touchstart', function (e) {
			startX = e.touches.length === 1 ? e.touches[0].clientX : null;
		}, { passive: true });
		stage.addEventListener('touchend', function (e) {
			if (startX !== null && e.changedTouches.length) {
				var dx = e.changedTouches[0].clientX - startX;
				if (Math.abs(dx) > 50) {
					show(current + (dx < 0 ? 1 : -1));
				}
			}
			startX = null;
		});
		root.addEventListener('click', function (e) {
			if (e.target === root || e.target === stage) {
				close();
			}
		});
		prev.addEventListener('click', function () { show(current - 1); });
		next.addEventListener('click', function () { show(current + 1); });
		closeBtn.addEventListener('click', close);
		document.addEventListener('keydown', onKey, true);
		document.body.appendChild(root);
		document.documentElement.classList.add('ffla-lb-open');
		show(index);
		closeBtn.focus();
		viewer = { close: close };
	}

	document.addEventListener('click', function (e) {
		var a = e.target.closest ? e.target.closest('a[data-ffla-view]') : null;
		if (!a || e.defaultPrevented || e.button || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
			return;
		}
		e.preventDefault();
		var scope = a.closest('[data-ffla-gallery]') || document;
		var links = [].slice.call(scope.querySelectorAll('a[data-ffla-view]'));
		openViewer(links, Math.max(0, links.indexOf(a)));
	});

	window.fflaReqFiles = {
		wire: wire,
		ready: function (input) {
			return (input && input.fflaReady) || Promise.resolve();
		},
		busy: function (input) {
			return !!input && input.hasAttribute('data-ffla-busy');
		},
		clear: function (input) {
			if (input && input.fflaClear) {
				input.fflaClear();
			}
		},
		check: check,
		open: openViewer,
		typeOf: typeOf
	};
})();
