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
        form.addEventListener('submit', function (event) {
            if (mode.value === 'enforce' && !window.confirm('Enforce excludes Blocked AND Pending products from eligible Google sync requests. Saving starts a NEW scan and resets its counters; existing exclusions remain. Google removals are not immediate. Save and start?')) {
                event.preventDefault();
            }
        });
    }
}());
