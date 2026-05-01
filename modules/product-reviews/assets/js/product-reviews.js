(function () {
    if (typeof window.fflaProductReviews === 'undefined') {
        return;
    }

    function onVoteClick(event) {
        var button = event.currentTarget;
        if (button.classList.contains('is-done')) {
            return;
        }

        var commentId = button.getAttribute('data-comment-id');
        if (!commentId) {
            return;
        }

        var formData = new FormData();
        formData.append('action', 'ffla_vote_review_helpful');
        formData.append('nonce', window.fflaProductReviews.nonce);
        formData.append('comment_id', commentId);

        fetch(window.fflaProductReviews.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (json) {
                if (!json || !json.success) {
                    return;
                }

                var countNode = button.querySelector('.ffla-review-helpful__count');
                if (countNode && json.data && typeof json.data.count !== 'undefined') {
                    countNode.textContent = String(json.data.count);
                }
                button.classList.add('is-done');
                button.setAttribute('aria-disabled', 'true');
            })
            .catch(function () {
                // Intentionally silent to avoid disrupting checkout/product UI.
            });
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
