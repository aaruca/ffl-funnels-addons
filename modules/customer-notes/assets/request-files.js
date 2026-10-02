/*
 * Customer requests — attachments, shared by the customer form and the staff
 * screens.
 *
 * Phone photos are shrunk in the browser (longest side 2000 px, the same size
 * the server keeps) before they are sent, so many photos fit in one message
 * and stay under the server's upload size. The browser applies the photo's
 * rotation when it reads it. PDFs and small images are sent as they are; if
 * the browser cannot do this, the original files are sent and the server
 * resizes them as before.
 *
 * window.fflaReqFiles:
 *   wire(input)        shrink the input's photos whenever files are chosen
 *   ready(input)       Promise that resolves when the input is done
 *   busy(input)        true while photos are being prepared
 *   check(files, cfg)  '' or 'count' / 'size' / 'total' for what is too much
 */
(function () {
	'use strict';

	var MAX_EDGE = 2000;
	var QUALITY = 0.85;
	var MIN_BYTES = 600 * 1024; // Smaller images are left alone.

	var canSwap = (function () {
		try {
			return typeof DataTransfer === 'function' && !!new DataTransfer().items && typeof File === 'function';
		} catch (e) {
			return false;
		}
	})();
	var canShrink = canSwap && typeof createImageBitmap === 'function' && !!document.createElement('canvas').toBlob;

	function decode(file) {
		// Honour the camera's rotation where the option exists; some browsers reject the options object.
		return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () {
			return createImageBitmap(file);
		});
	}

	function shrink(file) {
		if (!/^image\/(jpeg|png)$/.test(file.type) || file.size < MIN_BYTES) {
			return Promise.resolve(file);
		}
		return decode(file).then(function (bitmap) {
			var scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
			if (scale === 1 && file.type === 'image/png') {
				if (bitmap.close) {
					bitmap.close();
				}
				return file; // A PNG within 2000 px (a screenshot): keep it sharp.
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
					if (!blob || blob.size >= file.size) {
						resolve(file);
						return;
					}
					resolve(new File([blob], file.name, { type: file.type, lastModified: file.lastModified }));
				}, file.type, QUALITY);
			});
		}).catch(function () {
			return file;
		});
	}

	// One at a time: a dozen 12-megapixel photos decoded at once can exhaust a phone's memory.
	function shrinkAll(files) {
		var out = [];
		return files.reduce(function (chain, file) {
			return chain.then(function () {
				return shrink(file);
			}).then(function (result) {
				out.push(result);
			});
		}, Promise.resolve()).then(function () {
			return out;
		});
	}

	function wire(input) {
		if (!input || input.fflaWired) {
			return;
		}
		input.fflaWired = true;
		input.fflaReady = Promise.resolve();
		if (!canShrink) {
			return;
		}
		var run = 0;
		input.addEventListener('change', function () {
			var list = [].slice.call(input.files || []);
			var mine = ++run;
			if (!list.length || !list.some(function (f) { return /^image\/(jpeg|png)$/.test(f.type) && f.size >= MIN_BYTES; })) {
				input.fflaReady = Promise.resolve();
				return;
			}
			input.setAttribute('data-ffla-busy', '1');
			input.dispatchEvent(new CustomEvent('ffla-files-busy', { bubbles: true }));
			input.fflaReady = shrinkAll(list).then(function (result) {
				if (mine !== run) {
					return; // Another choice was made meanwhile; that one wins.
				}
				var dt = new DataTransfer();
				result.forEach(function (f) {
					dt.items.add(f);
				});
				input.files = dt.files;
			}).catch(function () {
				// Keep the original files; the server resizes them.
			}).then(function () {
				if (mine === run) {
					input.removeAttribute('data-ffla-busy');
					input.dispatchEvent(new CustomEvent('ffla-files-ready', { bubbles: true }));
				}
			});
		});
	}

	function check(files, cfg) {
		var list = [].slice.call(files || []);
		if (cfg.perUpload && list.length > cfg.perUpload) {
			return 'count';
		}
		if (list.some(function (f) { return f.size > cfg.maxFileBytes; })) {
			return 'size';
		}
		var total = list.reduce(function (sum, f) { return sum + f.size; }, 0);
		// Keep room for the message and the rest of the form.
		return cfg.postLimit && total > cfg.postLimit - 256 * 1024 ? 'total' : '';
	}

	window.fflaReqFiles = {
		wire: wire,
		ready: function (input) {
			return (input && input.fflaReady) || Promise.resolve();
		},
		busy: function (input) {
			return !!input && input.hasAttribute('data-ffla-busy');
		},
		check: check
	};
})();
