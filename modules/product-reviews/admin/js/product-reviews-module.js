/**
 * Product Reviews settings page: send a test request, reminder or coupon email.
 */
(function () {
    'use strict';

    function initTest(box) {

        var button = box.querySelector('[data-ffla-pr-test-send]');
        var input = box.querySelector('input[type="email"]');
        var kind = box.querySelector('[data-ffla-pr-test-kind]');
        var result = box.querySelector('[data-ffla-pr-test-result]');

        button.addEventListener('click', function () {
            var data = new FormData();
            data.append('action', 'ffla_review_send_test');
            data.append('nonce', box.getAttribute('data-nonce'));
            data.append('to', input.value);
            data.append('kind', kind ? kind.value : 'request');

            button.disabled = true;
            result.className = 'ffla-pr-result';
            result.textContent = '…';

            fetch(window.fflaAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
                .then(function (response) {
                    return response.json();
                })
                .then(function (json) {
                    var message = json && json.data && json.data.message ? json.data.message : '';
                    result.textContent = message;
                    result.className = 'ffla-pr-result ' + (json && json.success ? 'is-success' : 'is-error');
                })
                .catch(function () {
                    result.textContent = (window.fflaAdmin.i18n && window.fflaAdmin.i18n.networkError) || 'Error';
                    result.className = 'ffla-pr-result is-error';
                })
                .then(function () {
                    button.disabled = false;
                });
        });
    }

    function boot() {
        if (typeof window.fflaAdmin === 'undefined') {
            return;
        }
        document.querySelectorAll('[data-ffla-pr-test]').forEach(initTest);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
