(function () {
    'use strict';

    var search = document.getElementById('ffla-gmp-category-search');
    if (search) {
        search.addEventListener('input', function () {
            var query = search.value.trim().toLowerCase();
            document.querySelectorAll('.ffla-gmp-category-table tbody tr[data-category-name]').forEach(function (row) {
                row.hidden = query !== '' && (row.getAttribute('data-category-name') || '').indexOf(query) === -1;
            });
        });
    }

    var mode = document.getElementById('ffla-gmp-mode');
    if (mode) {
        var form = mode.closest('form');
        var unsaved = document.getElementById('ffla-gmp-unsaved');
        form.addEventListener('change', function (event) {
            if (unsaved && event.target.name && event.target.type !== 'hidden') {
                unsaved.hidden = false;
            }
        });
        // Coming back with the browser's Back button must not leave rules disabled.
        window.addEventListener('pageshow', function () {
            form.querySelectorAll('select[name^="category_policy["][disabled]').forEach(function (select) {
                select.disabled = false;
            });
        });
        form.addEventListener('submit', function (event) {
            // Send only the category rules that changed. A store with a
            // thousand categories would otherwise exceed PHP's max_input_vars
            // and silently lose the rest; unchanged rules need no saving.
            if (!event.defaultPrevented) {
                form.querySelectorAll('select[name^="category_policy["][data-initial]').forEach(function (select) {
                    select.disabled = select.value === select.getAttribute('data-initial');
                });
            }
            if (mode.value === 'enforce' && !window.confirm('In Enforce this addon decides what reaches Google: Allowed products are uploaded and Blocked AND Pending products are removed through Google for WooCommerce. Products excluded in Google for WooCommerce before stay excluded. Saving starts a NEW scan and resets its counters. Google changes are not immediate. Save and start?')) {
                event.preventDefault();
                form.querySelectorAll('select[name^="category_policy["][data-initial]').forEach(function (select) {
                    select.disabled = false;
                });
            }
        });
    }
}());
