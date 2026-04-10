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
        for (var r = 0; r < roots.length; r++) {
            var root = roots[r];
            if (root.getAttribute('data-ffla-stars-init') === '1') {
                continue;
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
            var labels = root.querySelectorAll('.ffla-star-rating-input__label');
            for (var j = 0; j < labels.length; j++) {
                labels[j].addEventListener('mouseenter', function (ev) {
                    var label = ev.currentTarget;
                    var fid = label.getAttribute('for');
                    var input = fid ? document.getElementById(fid) : null;
                    if (!input || !input.value) {
                        return;
                    }
                    root.setAttribute('data-hover', input.value);
                    syncStarDisplay(root);
                });
            }
        }
    }

    function init() {
        initStarRatingInputs();
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
