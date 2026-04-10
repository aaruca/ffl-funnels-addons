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

    function init() {
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
