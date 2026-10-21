(function () {
    if (typeof window.fflaProductReviews === 'undefined') {
        return;
    }

    /**
     * Both buttons for one review live in the same group and settle together.
     * Falls back to the button itself so markup cached before the group wrapper
     * existed still updates its own count.
     */
    function scopeFor(button) {
        var group = button.closest ? button.closest('.ffla-review-helpful-group') : null;
        return group || button;
    }

    function paintCounts(scope, data) {
        if (!scope || !data) {
            return;
        }
        var yes = scope.querySelector('.ffla-review-helpful__count');
        if (yes && typeof data.count !== 'undefined') {
            yes.textContent = String(data.count);
        }
        var no = scope.querySelector('.ffla-review-helpful__count-no');
        if (no && typeof data.countNo !== 'undefined') {
            no.textContent = String(data.countNo);
        }
    }

    function settle(scope, button) {
        var buttons = scope === button ? [button] : scope.querySelectorAll('.ffla-review-helpful');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].setAttribute('aria-disabled', 'true');
        }
        button.classList.add('is-done');
    }

    function i18n(key) {
        var strings = window.fflaProductReviews.i18n || {};
        return strings[key] || '';
    }

    function showMessage(scope, button, text, isError) {
        var host = scope === button ? button.parentNode : scope;
        if (!host || !text) {
            return;
        }
        var el = host.querySelector('.ffla-review-helpful__message');
        if (!el) {
            el = document.createElement('span');
            el.className = 'ffla-review-helpful__message';
            el.setAttribute('role', 'status');
            host.appendChild(el);
        }
        el.textContent = text;
        el.classList.toggle('is-error', !!isError);
    }

    function sendVote(button, scope, retried) {
        var formData = new FormData();
        formData.append('action', 'ffla_vote_review_helpful');
        formData.append('nonce', window.fflaProductReviews.nonce);
        formData.append('comment_id', button.getAttribute('data-comment-id'));
        formData.append('vote', button.getAttribute('data-vote') || 'yes');

        function fail(text) {
            button.removeAttribute('data-ffla-pending');
            showMessage(scope, button, text || i18n('voteError'), true);
        }

        fetch(window.fflaProductReviews.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return null;
                });
            })
            .then(function (json) {
                var data = json && typeof json === 'object' && json.data ? json.data : {};

                if (json && json.success) {
                    button.removeAttribute('data-ffla-pending');
                    paintCounts(scope, data);
                    settle(scope, button);
                    showMessage(scope, button, data.throttled ? data.message : i18n('voteSaved'), false);
                    return;
                }

                // A cached page can carry an expired nonce; the server sends a
                // fresh one with the refusal, so try once more with it.
                if (!retried && data.code === 'invalid_nonce' && data.nonce) {
                    window.fflaProductReviews.nonce = data.nonce;
                    sendVote(button, scope, true);
                    return;
                }

                fail(data.message);
            })
            .catch(function () {
                fail('');
            });
    }

    function onVoteClick(event) {
        var button = event.currentTarget;
        var scope = scopeFor(button);

        // One vote per review: once either direction lands, both are spent.
        if (button.getAttribute('aria-disabled') === 'true' || button.getAttribute('data-ffla-pending') === '1') {
            return;
        }

        if (!button.getAttribute('data-comment-id')) {
            return;
        }

        button.setAttribute('data-ffla-pending', '1');
        sendVote(button, scope, false);
    }

    function syncStarDisplay(root) {
        var hover = root.getAttribute('data-hover');
        var checked = root.querySelector('input[type="radio"]:checked');
        var v = '';
        if (hover !== null && hover !== '') {
            v = hover;
        } else if (checked) {
            v = checked.value;
        }
        root.setAttribute('data-display', v);
    }

    function initStarRatingInputs() {
        var roots = document.querySelectorAll('[data-ffla-stars]');
        roots.forEach(function (root) {
            if (root.getAttribute('data-ffla-stars-init') === '1') {
                return;
            }
            root.setAttribute('data-ffla-stars-init', '1');
            syncStarDisplay(root);
            root.addEventListener('change', function () {
                syncStarDisplay(root);
            });
            root.addEventListener('mouseleave', function () {
                root.removeAttribute('data-hover');
                syncStarDisplay(root);
            });
            root.querySelectorAll('.ffla-star-rating-input__label').forEach(function (label) {
                label.addEventListener('mouseenter', function (ev) {
                    var el = ev.currentTarget;
                    var fid = el.getAttribute('for');
                    var input = fid ? document.getElementById(fid) : null;
                    if (!input || !input.value) {
                        return;
                    }
                    root.setAttribute('data-hover', input.value);
                    syncStarDisplay(root);
                });
            });
        });
    }

    function initMediaUploadZones() {
        var zones = document.querySelectorAll('[data-ffla-media-upload]');
        var fu = (typeof window.fflaProductReviews.fileUpload === 'object' && window.fflaProductReviews.fileUpload) || {};
        var maxFiles = 3;
        var maxBytes = 5 * 1024 * 1024;

        zones.forEach(function (zone) {
            if (zone.getAttribute('data-ffla-media-init') === '1') {
                return;
            }
            zone.setAttribute('data-ffla-media-init', '1');

            var input = zone.querySelector('.ffla-review-form__file-input');
            var btn = zone.querySelector('.ffla-review-form__file-add');
            var list = zone.querySelector('.ffla-review-form__file-list');
            if (!input || !list) {
                return;
            }

            /** After the browser opens the file dialog, `input.files` is only the new pick — merge with prior. */
            var existingFiles = [];

            function applyFiles(files) {
                var dt = new DataTransfer();
                files.forEach(function (f) {
                    dt.items.add(f);
                });
                input.files = dt.files;
                existingFiles = files.slice();
                renderList();
            }

            function renderList() {
                var files = existingFiles;
                list.innerHTML = '';
                if (!files.length) {
                    list.hidden = true;
                    return;
                }
                list.hidden = false;
                files.forEach(function (file, index) {
                    var li = document.createElement('li');
                    li.className = 'ffla-review-form__file-item';

                    var nameSpan = document.createElement('span');
                    nameSpan.className = 'ffla-review-form__file-name';
                    nameSpan.textContent = file.name;

                    var rm = document.createElement('button');
                    rm.type = 'button';
                    rm.className = 'ffla-review-form__file-remove';
                    rm.setAttribute('aria-label', fu.removeLabel || 'Remove');
                    rm.setAttribute('title', fu.removeLabel || 'Remove');
                    rm.textContent = '\u00d7';

                    rm.addEventListener('click', function () {
                        var next = existingFiles.slice();
                        next.splice(index, 1);
                        applyFiles(next);
                    });

                    li.appendChild(nameSpan);
                    li.appendChild(rm);
                    list.appendChild(li);
                });
            }

            input.addEventListener('change', function () {
                var picked = Array.prototype.slice.call(input.files || []);
                input.value = '';
                var merged = existingFiles.concat(picked);
                var hadTooMany = merged.length > maxFiles;
                if (hadTooMany) {
                    merged = merged.slice(0, maxFiles);
                    if (fu.maxFiles) {
                        window.alert(fu.maxFiles);
                    }
                }
                var skippedLarge = false;
                merged = merged.filter(function (f) {
                    if (f.size > maxBytes) {
                        skippedLarge = true;
                        return false;
                    }
                    return true;
                });
                if (skippedLarge && fu.fileTooLarge) {
                    window.alert(fu.fileTooLarge);
                }
                if (merged.length > maxFiles) {
                    merged = merged.slice(0, maxFiles);
                }
                applyFiles(merged);
            });

            if (btn) {
                btn.addEventListener('click', function () {
                    input.click();
                });
            }

            renderList();
        });
    }

    function init() {
        initStarRatingInputs();
        initMediaUploadZones();
        var buttons = document.querySelectorAll('.ffla-review-helpful');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', onVoteClick);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
