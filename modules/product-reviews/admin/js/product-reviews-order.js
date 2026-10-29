/**
 * Review request box on the order screen: copy the review link, show it as
 * a QR code (drawn here, no outside service), download or print the code.
 */
(function () {
    'use strict';

    var strings = window.fflaReviewOrder || {};

    function qrSvg(text) {
        if (typeof window.qrcode !== 'function') {
            return '';
        }
        var qr = window.qrcode(0, 'M');
        qr.addData(text);
        qr.make();
        return qr.createSvgTag({ cellSize: 6, margin: 4, scalable: true });
    }

    function copy(text, done) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                done(true);
            }, function () {
                done(false);
            });
            return;
        }
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '-1000px';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(area);
        done(ok);
    }

    function init(box) {
        var link = box.getAttribute('data-ffla-review-link');
        var note = box.querySelector('[data-ffla-copied]');
        var panel = box.querySelector('[data-ffla-qr]');
        var image = box.querySelector('[data-ffla-qr-image]');

        var copyButton = box.querySelector('[data-ffla-copy-link]');
        if (copyButton) {
            copyButton.addEventListener('click', function () {
                copy(link, function (ok) {
                    if (note) {
                        note.textContent = ok ? (strings.copied || '') : (strings.copyFailed || '');
                    }
                    if (!ok) {
                        window.prompt(strings.copyFailed || '', link);
                    }
                });
            });
        }

        var show = box.querySelector('[data-ffla-qr-show]');
        if (show && panel && image) {
            show.addEventListener('click', function () {
                if (!image.innerHTML) {
                    image.innerHTML = qrSvg(link);
                }
                panel.hidden = !panel.hidden;
            });
        }

        var download = box.querySelector('[data-ffla-qr-download]');
        if (download) {
            download.addEventListener('click', function () {
                var svg = qrSvg(link);
                var a = document.createElement('a');
                a.href = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
                a.download = 'review-qr.svg';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            });
        }

        var print = box.querySelector('[data-ffla-qr-print]');
        if (print) {
            print.addEventListener('click', function () {
                var win = window.open('', '_blank', 'width=480,height=600');
                if (!win) {
                    return;
                }
                var title = document.createElement('h1');
                title.textContent = strings.qrTitle || '';
                title.style.font = '600 22px system-ui, sans-serif';
                win.document.body.style.textAlign = 'center';
                win.document.body.appendChild(title);
                var holder = win.document.createElement('div');
                holder.style.width = '320px';
                holder.style.margin = '0 auto';
                holder.innerHTML = qrSvg(link);
                win.document.body.appendChild(holder);
                win.focus();
                win.print();
            });
        }
    }

    function boot() {
        document.querySelectorAll('[data-ffla-review-link]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
