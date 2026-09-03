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
        mode.closest('form').addEventListener('submit', function (event) {
            if (mode.value === 'enforce' && !window.confirm('Enforce mode can exclude blocked and pending products from Google. Continue and start the catalog scan?')) {
                event.preventDefault();
            }
        });
    }
}());
