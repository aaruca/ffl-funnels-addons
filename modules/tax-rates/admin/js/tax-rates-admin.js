/* Tax Address Resolver - Admin JS */
(function ($) {
    'use strict';

    $(function () {
        var $lookupBtn = $('#ffla-tax-lookup-btn');
        var $resultCard = $('#ffla-tax-result-card');
        var $resultBody = $('#ffla-tax-result-body');

        $lookupBtn.on('click', function () {
            var street = $('#ffla-tax-street').val().trim();
            var city = $('#ffla-tax-city').val().trim();
            var state = $('#ffla-tax-state').val().trim().toUpperCase();
            var zip = $('#ffla-tax-zip').val().trim();

            if (!state) {
                alert('Please enter a state code.');
                return;
            }

            $lookupBtn.prop('disabled', true).text('Looking up...');
            $resultCard.show();
            $resultBody.html('<div class="wb-ai-loading-message"><span>Resolving address</span><span class="wb-ai-dots"><span></span><span></span><span></span></span></div>');

            $.post(FflaTaxResolver.ajaxUrl, {
                action: 'ffla_tax_quote_lookup',
                security: FflaTaxResolver.nonce,
                street: street,
                city: city,
                state: state,
                zip: zip
            })
                .done(function (res) {
                    if (res.success) {
                        renderQuoteResult(res.data);
                    } else {
                        $resultBody.html('<div class="ffla-tax-error">' + escHtml(res.data || 'Request failed.') + '</div>');
                    }
                })
                .fail(function () {
                    $resultBody.html('<div class="ffla-tax-error">Request failed. Check console for details.</div>');
                })
                .always(function () {
                    $lookupBtn.prop('disabled', false).text('Look Up Tax Rate');
                });
        });

        $('#ffla-tax-lookup-form input').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $lookupBtn.click();
            }
        });

        function renderQuoteResult(data) {
            var html = '';
            var isSuccess = (data.outcomeCode === 'SUCCESS' || data.outcomeCode === 'NO_SALES_TAX');
            var statusClass = isSuccess ? 'ffla-tax-result--success' : 'ffla-tax-result--error';

            html += '<div class="ffla-tax-result ' + statusClass + '">';

            if (isSuccess) {
                var ratePct = data.totalRate !== null ? (data.totalRate * 100).toFixed(2) + '%' : '0.00%';

                html += '<div class="ffla-tax-result__total">';
                html += '<span class="ffla-tax-result__rate">' + ratePct + '</span>';
                html += '<span class="ffla-tax-result__label">Total Sales Tax Rate</span>';
                html += '</div>';

                if (data.matchedAddress) {
                    html += '<div class="ffla-tax-result__matched">';
                    html += '<strong>Matched:</strong> ' + escHtml(data.matchedAddress);
                    html += '</div>';
                }

                if (data.breakdown && data.breakdown.length > 0) {
                    html += '<table class="wb-table ffla-tax-breakdown-table">';
                    html += '<thead><tr><th>Jurisdiction</th><th>Type</th><th>Rate</th></tr></thead>';
                    html += '<tbody>';

                    for (var i = 0; i < data.breakdown.length; i++) {
                        var b = data.breakdown[i];
                        var bRate = (b.rate * 100).toFixed(4) + '%';
                        html += '<tr>';
                        html += '<td>' + escHtml(b.jurisdiction) + '</td>';
                        html += '<td><span class="ffla-tax-jtype ffla-tax-jtype--' + b.type + '">' + escHtml(b.type) + '</span></td>';
                        html += '<td class="ffla-tax-rate-cell">' + bRate + '</td>';
                        html += '</tr>';
                    }

                    html += '</tbody></table>';
                }

                html += '<div class="ffla-tax-meta">';
                html += metaItem('Coverage', data.coverageStatus);
                html += metaItem('Source', data.source || '-');
                html += metaItem('Version', data.sourceVersion || '-');
                html += metaItem('Confidence', data.confidence || '-');
                html += metaItem('Scope', data.determinationScope || '-');
                html += metaItem('Mode', data.resolutionMode || '-');
                html += '</div>';

                if (data.trace) {
                    html += '<div class="ffla-tax-trace">';
                    html += 'Resolver: ' + escHtml(data.trace.resolver || '-');
                    html += ' | Geocode: ' + (data.trace.geocodeUsed ? 'Yes' : 'No');
                    html += ' | Cache: ' + (data.trace.cacheHit ? 'Hit' : 'Miss');
                    html += ' | ' + (data.trace.durationMs || 0) + 'ms';
                    html += '</div>';
                }

                if (data.limitations && data.limitations.length > 0) {
                    html += '<div class="ffla-tax-limitations">';
                    html += '<strong>Limitations:</strong>';
                    html += '<ul>';
                    for (var j = 0; j < data.limitations.length; j++) {
                        html += '<li>' + escHtml(data.limitations[j]) + '</li>';
                    }
                    html += '</ul></div>';
                }
            } else {
                html += '<div class="ffla-tax-error-detail">';
                html += '<span class="ffla-tax-error-code">' + escHtml(data.outcomeCode) + '</span>';
                html += '<p>' + escHtml(data.error || 'Unknown error.') + '</p>';
                if (data.state) {
                    html += '<p>State: <strong>' + escHtml(data.state) + '</strong></p>';
                }
                html += '</div>';
            }

            html += '</div>';
            $resultBody.html(html);
        }

        function metaItem(label, value) {
            return '<span class="ffla-tax-meta__item"><span class="ffla-tax-meta__label">' + label + '</span><span class="ffla-tax-meta__value">' + escHtml(value) + '</span></span>';
        }

        $('#ffla-sync-btn').on('click', function () {
            var $btn = $(this);
            var $status = $('#ffla-upload-status');

            $btn.prop('disabled', true).text('Syncing sheet data...');
            $status
                .show()
                .html('<div class="wb-ai-loading-message"><span>Downloading the shared CSV and rebuilding local state datasets. This can take a minute.</span><span class="wb-ai-dots"><span></span><span></span><span></span></span></div>');

            $.post(FflaTaxResolver.ajaxUrl, {
                action: 'ffla_tax_run_sync',
                security: FflaTaxResolver.nonce
            })
                .done(function (res) {
                    var message = (res && res.data && res.data.message) ? res.data.message : (res.data || 'Sync failed.');
                    if (res && res.data && Array.isArray(res.data.errors) && res.data.errors.length) {
                        message += '\n\n' + res.data.errors.join('\n');
                    }
                    $status.html('<strong>Sync finished.</strong> ' + escHtml(message));
                    alert(message);
                    location.reload();
                })
                .fail(function () {
                    $status.html('<span class="ffla-tax-error">Sheet sync request failed.</span>');
                    alert('Request failed.');
                })
                .always(function () {
                    $btn.prop('disabled', false).text('Sync Sheet Data');
                });
        });

        $('#ffla-purge-legacy-btn').on('click', function () {
            var $btn = $(this);
            var $status = $('#ffla-purge-legacy-status');

            if (!window.confirm('This will permanently delete old local tax datasets, quote cache, and audit logs. Continue?')) {
                return;
            }

            $btn.prop('disabled', true).text('Deleting old database...');
            $status
                .show()
                .html('<div class="wb-ai-loading-message"><span>Deleting legacy local tax data.</span><span class="wb-ai-dots"><span></span><span></span><span></span></span></div>');

            $.post(FflaTaxResolver.ajaxUrl, {
                action: 'ffla_tax_purge_legacy_data',
                security: FflaTaxResolver.nonce
            })
                .done(function (res) {
                    var message = (res && res.data && res.data.message) ? res.data.message : 'Cleanup completed.';
                    $status.html('<strong>Cleanup finished.</strong> ' + escHtml(message));
                    alert(message);
                })
                .fail(function () {
                    $status.html('<span class="ffla-tax-error">Cleanup request failed.</span>');
                    alert('Request failed.');
                })
                .always(function () {
                    $btn.prop('disabled', false).text('Delete Old Tax Database');
                });
        });

        var $restrictStates = $('input[name="restrict_states"]');
        var $statePicker = $('#ffla-tax-state-picker');

        function syncStatePickerMode() {
            if (!$statePicker.length || !$restrictStates.length) {
                return;
            }

            $statePicker.toggleClass('ffla-tax-state-picker--inactive', !$restrictStates.is(':checked'));
        }

        $restrictStates.on('change', syncStatePickerMode);
        syncStatePickerMode();

        $(document).on('click', '.ffla-tax-state-picker__action', function () {
            var action = $(this).data('state-picker-action');
            var $checkboxes = $('.ffla-tax-state-picker__checkbox');

            if (action === 'select-all') {
                $checkboxes.prop('checked', true);
                return;
            }

            if (action === 'clear-all') {
                $checkboxes.prop('checked', false);
                return;
            }

            if (action === 'select-covered') {
                $checkboxes.each(function () {
                    var $checkbox = $(this);
                    $checkbox.prop('checked', $checkbox.data('covered') === 1 || $checkbox.data('covered') === '1');
                });
            }
        });

        function escHtml(str) {
            if (!str) {
                return '';
            }

            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    });
}(jQuery));
