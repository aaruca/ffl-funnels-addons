/* Tax Address Resolver - Admin JS */
(function ($) {
    'use strict';

    /**
     * Resolve a localized string with an English fallback.
     */
    function t(key, fallback) {
        var i18n = (window.FflaTaxResolver && FflaTaxResolver.i18n) || {};
        if (typeof i18n[key] === 'string' && i18n[key] !== '') {
            return i18n[key];
        }
        return fallback;
    }

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
                alert(t('enterStateCode', 'Please enter a state code.'));
                return;
            }

            $lookupBtn.prop('disabled', true).text(t('lookingUp', 'Looking up…'));
            $resultCard.show();
            $resultBody.html('<div class="wb-ai-loading-message"><span>' +
                escHtml(t('resolvingAddress', 'Resolving address')) +
                '</span><span class="wb-ai-dots"><span></span><span></span><span></span></span></div>');

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
                        $resultBody.html('<div class="ffla-tax-error">' + escHtml(res.data || t('requestFailed', 'Request failed.')) + '</div>');
                    }
                })
                .fail(function () {
                    $resultBody.html('<div class="ffla-tax-error">' +
                        escHtml(t('requestFailedConsole', 'Request failed. Check console for details.')) +
                        '</div>');
                })
                .always(function () {
                    $lookupBtn.prop('disabled', false).text(t('lookUpTaxRate', 'Look Up Tax Rate'));
                });
        });

        $('#ffla-tax-lookup-form input').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $lookupBtn.click();
            }
        });

        // "Test key" button next to the USGeocoder auth key field. Validates
        // the pending key against the USGeocoder sample address without
        // needing to save the settings form first — so admins can confirm the
        // key is valid before committing to reconcile + cache flush.
        var $testBtn    = $('#ffla-tax-test-key');
        var $testStatus = $('#ffla-tax-test-key-status');
        var $keyInput   = $('input[name="usgeocoder_auth_key"]');

        if ($testBtn.length && $keyInput.length) {
            $testBtn.on('click', function () {
                var key = String($keyInput.val() || '').trim();

                $testStatus
                    .removeClass('ffla-tax-test-key__status--ok ffla-tax-test-key__status--error')
                    .text('');

                if (!key) {
                    $testStatus
                        .addClass('ffla-tax-test-key__status--error')
                        .text(t('testKeyEmpty', 'Enter a USGeocoder key first.'));
                    return;
                }

                $testBtn.prop('disabled', true);
                var originalLabel = $testBtn.text();
                $testBtn.text(t('testKeyTesting', 'Testing…'));
                $testStatus
                    .removeClass('ffla-tax-test-key__status--error')
                    .text(t('testKeyTesting', 'Testing…'));

                $.post(FflaTaxResolver.ajaxUrl, {
                    action: 'ffla_tax_test_usgeocoder',
                    security: FflaTaxResolver.nonce,
                    key: key
                })
                    .done(function (res) {
                        var payload = (res && res.data) ? res.data : {};
                        if (res && res.success) {
                            $testStatus
                                .removeClass('ffla-tax-test-key__status--error')
                                .addClass('ffla-tax-test-key__status--ok')
                                .text(String(payload.message || t('testKeyOk', 'Key works. Sample lookup succeeded.')));
                        } else {
                            $testStatus
                                .removeClass('ffla-tax-test-key__status--ok')
                                .addClass('ffla-tax-test-key__status--error')
                                .text(String(payload.message || t('testKeyFailed', 'Key test failed.')));
                        }
                    })
                    .fail(function () {
                        $testStatus
                            .removeClass('ffla-tax-test-key__status--ok')
                            .addClass('ffla-tax-test-key__status--error')
                            .text(t('testKeyRequestFailed', 'Test request failed. Check your network and try again.'));
                    })
                    .always(function () {
                        $testBtn.prop('disabled', false).text(originalLabel || t('testKey', 'Test key'));
                    });
            });
        }

        function renderQuoteResult(data) {
            var html = '';
            var isSuccess = (data.outcomeCode === 'SUCCESS' || data.outcomeCode === 'NO_SALES_TAX');
            var statusClass = isSuccess ? 'ffla-tax-result--success' : 'ffla-tax-result--error';

            html += '<div class="ffla-tax-result ' + statusClass + '">';

            if (isSuccess) {
                var ratePct = data.totalRate !== null ? (data.totalRate * 100).toFixed(2) + '%' : '0.00%';

                html += '<div class="ffla-tax-result__total">';
                html += '<span class="ffla-tax-result__rate">' + ratePct + '</span>';
                html += '<span class="ffla-tax-result__label">' + escHtml(t('totalSalesTaxRate', 'Total Sales Tax Rate')) + '</span>';
                html += '</div>';

                if (data.matchedAddress) {
                    html += '<div class="ffla-tax-result__matched">';
                    html += '<strong>' + escHtml(t('matched', 'Matched:')) + '</strong> ' + escHtml(data.matchedAddress);
                    html += '</div>';
                }

                if (data.breakdown && data.breakdown.length > 0) {
                    html += '<table class="wb-table ffla-tax-breakdown-table">';
                    html += '<thead><tr>' +
                        '<th>' + escHtml(t('jurisdiction', 'Jurisdiction')) + '</th>' +
                        '<th>' + escHtml(t('type', 'Type')) + '</th>' +
                        '<th>' + escHtml(t('rate', 'Rate')) + '</th>' +
                        '</tr></thead>';
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
                html += metaItem(t('coverage', 'Coverage'), data.coverageStatus);
                html += metaItem(t('source', 'Source'), data.source || '-');
                html += metaItem(t('version', 'Version'), data.sourceVersion || '-');
                html += metaItem(t('confidence', 'Confidence'), data.confidence || '-');
                html += metaItem(t('scope', 'Scope'), data.determinationScope || '-');
                html += metaItem(t('mode', 'Mode'), data.resolutionMode || '-');
                html += '</div>';

                if (data.trace) {
                    html += '<div class="ffla-tax-trace">';
                    html += escHtml(t('resolver', 'Resolver:')) + ' ' + escHtml(data.trace.resolver || '-');
                    html += ' | ' + escHtml(t('geocode', 'Geocode:')) + ' ' + (data.trace.geocodeUsed ? escHtml(t('yes', 'Yes')) : escHtml(t('no', 'No')));
                    html += ' | ' + escHtml(t('cache', 'Cache:')) + ' ' + (data.trace.cacheHit ? escHtml(t('hit', 'Hit')) : escHtml(t('miss', 'Miss')));
                    html += ' | ' + (data.trace.durationMs || 0) + 'ms';
                    html += '</div>';
                }

                if (data.limitations && data.limitations.length > 0) {
                    html += '<div class="ffla-tax-limitations">';
                    html += '<strong>' + escHtml(t('limitations', 'Limitations:')) + '</strong>';
                    html += '<ul>';
                    for (var j = 0; j < data.limitations.length; j++) {
                        html += '<li>' + escHtml(data.limitations[j]) + '</li>';
                    }
                    html += '</ul></div>';
                }
            } else {
                html += '<div class="ffla-tax-error-detail">';
                html += '<span class="ffla-tax-error-code">' + escHtml(data.outcomeCode) + '</span>';
                html += '<p>' + escHtml(data.error || t('unknownError', 'Unknown error.')) + '</p>';
                if (data.state) {
                    html += '<p>' + escHtml(t('state', 'State:')) + ' <strong>' + escHtml(data.state) + '</strong></p>';
                }
                html += '</div>';
            }

            html += '</div>';
            $resultBody.html(html);
        }

        function metaItem(label, value) {
            return '<span class="ffla-tax-meta__item"><span class="ffla-tax-meta__label">' + escHtml(label) + '</span><span class="ffla-tax-meta__value">' + escHtml(value) + '</span></span>';
        }

        $('#ffla-sync-btn').on('click', function () {
            var $btn = $(this);
            var $status = $('#ffla-upload-status');

            $btn.prop('disabled', true).text(t('syncingSheetData', 'Syncing sheet data…'));
            $status
                .show()
                .html('<div class="wb-ai-loading-message"><span>' +
                    escHtml(t('syncingCsvDescription', 'Downloading the shared CSV and rebuilding local state datasets. This can take a minute.')) +
                    '</span><span class="wb-ai-dots"><span></span><span></span><span></span></span></div>');

            $.post(FflaTaxResolver.ajaxUrl, {
                action: 'ffla_tax_run_sync',
                security: FflaTaxResolver.nonce
            })
                .done(function (res) {
                    var message = (res && res.data && res.data.message) ? res.data.message : (res.data || t('syncFailed', 'Sync failed.'));
                    if (res && res.data && Array.isArray(res.data.errors) && res.data.errors.length) {
                        message += '\n\n' + res.data.errors.join('\n');
                    }
                    $status.html('<strong>' + escHtml(t('syncFinished', 'Sync finished.')) + '</strong> ' + escHtml(message));
                    alert(message);
                    location.reload();
                })
                .fail(function () {
                    $status.html('<span class="ffla-tax-error">' + escHtml(t('sheetSyncFailed', 'Sheet sync request failed.')) + '</span>');
                    alert(t('requestFailed', 'Request failed.'));
                })
                .always(function () {
                    $btn.prop('disabled', false).text(t('syncSheetData', 'Sync Sheet Data'));
                });
        });

        $('#ffla-purge-legacy-btn').on('click', function () {
            var $btn = $(this);
            var $status = $('#ffla-purge-legacy-status');

            if (!window.confirm(t('confirmPurgeLegacy', 'This will permanently delete old local tax datasets, quote cache, and audit logs. Continue?'))) {
                return;
            }

            $btn.prop('disabled', true).text(t('deletingOldDatabase', 'Deleting old database…'));
            $status
                .show()
                .html('<div class="wb-ai-loading-message"><span>' +
                    escHtml(t('deletingLegacyData', 'Deleting legacy local tax data.')) +
                    '</span><span class="wb-ai-dots"><span></span><span></span><span></span></span></div>');

            $.post(FflaTaxResolver.ajaxUrl, {
                action: 'ffla_tax_purge_legacy_data',
                security: FflaTaxResolver.nonce
            })
                .done(function (res) {
                    var message = (res && res.data && res.data.message) ? res.data.message : t('cleanupCompleted', 'Cleanup completed.');
                    $status.html('<strong>' + escHtml(t('cleanupFinished', 'Cleanup finished.')) + '</strong> ' + escHtml(message));
                    alert(message);
                })
                .fail(function () {
                    $status.html('<span class="ffla-tax-error">' + escHtml(t('cleanupRequestFailed', 'Cleanup request failed.')) + '</span>');
                    alert(t('requestFailed', 'Request failed.'));
                })
                .always(function () {
                    $btn.prop('disabled', false).text(t('deleteOldTaxDb', 'Delete Old Tax Database'));
                });
        });

        $('#ffla-clear-cache-btn').on('click', function () {
            var $btn = $(this);
            var $status = $('#ffla-clear-cache-status');

            if (!window.confirm(t('confirmClearCache', 'This empties the address cache. Every address will be looked up again on its next checkout, and USGeocoder bills per call. Continue?'))) {
                return;
            }

            $btn.prop('disabled', true).text(t('clearingCache', 'Clearing cache…'));
            $status
                .show()
                .html('<div class="wb-ai-loading-message"><span>' +
                    escHtml(t('clearingCacheDescription', 'Removing cached addresses.')) +
                    '</span><span class="wb-ai-dots"><span></span><span></span><span></span></span></div>');

            $.post(FflaTaxResolver.ajaxUrl, {
                action: 'ffla_tax_clear_cache',
                security: FflaTaxResolver.nonce
            })
                .done(function (res) {
                    if (res && res.success) {
                        var message = (res.data && res.data.message) ? res.data.message : t('cacheCleared', 'Cache cleared.');
                        $status.html('<strong>' + escHtml(message) + '</strong>');
                    } else {
                        var err = (res && res.data && res.data.message) ? res.data.message : (res && res.data) || t('clearCacheFailed', 'Could not clear the cache.');
                        $status.html('<span class="ffla-tax-error">' + escHtml(err) + '</span>');
                    }
                })
                .fail(function () {
                    $status.html('<span class="ffla-tax-error">' + escHtml(t('clearCacheRequestFailed', 'Clear cache request failed.')) + '</span>');
                })
                .always(function () {
                    $btn.prop('disabled', false).text(t('clearCacheNow', 'Clear cache now'));
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

        /* Conditional product tax exemption rules. */
        var exemptionRuleIndex = 0;

        $('#ffla-tax-exemption-rules .ffla-tax-exemption-rule').each(function () {
            var name = $(this).find('[name^="tax_exemption_rules["]').first().attr('name') || '';
            var match = name.match(/tax_exemption_rules\[(\d+)\]/);
            if (match) {
                exemptionRuleIndex = Math.max(exemptionRuleIndex, parseInt(match[1], 10) + 1);
            }
        });

        function initTaxTermSearch($scope) {
            var method = $.fn.selectWoo ? 'selectWoo' : ($.fn.select2 ? 'select2' : '');
            if (!method) {
                return;
            }

            $scope.find('.ffla-tax-term-search').each(function () {
                var $select = $(this);
                if ($select.hasClass('select2-hidden-accessible')) {
                    return;
                }

                $select[method]({
                    width: '100%',
                    multiple: true,
                    allowClear: true,
                    placeholder: $select.data('placeholder') || '',
                    minimumInputLength: 0,
                    ajax: {
                        url: FflaTaxResolver.ajaxUrl,
                        dataType: 'json',
                        delay: 250,
                        cache: true,
                        data: function (params) {
                            return {
                                action: 'ffla_tax_search_terms',
                                security: FflaTaxResolver.nonce,
                                taxonomy: $select.data('taxonomy'),
                                term: params.term || '',
                                page: params.page || 1
                            };
                        },
                        processResults: function (response) {
                            if (!response || !Array.isArray(response.results)) {
                                return { results: [] };
                            }
                            return response;
                        }
                    }
                });
            });
        }

        function refreshExemptionRuleState($rule) {
            var customerCount = $rule.find('.ffla-tax-rule-customers option:selected').length;
            var roleCount = $rule.find('.ffla-tax-rule-roles option:selected').length;
            var categoryCount = $rule.find('[data-taxonomy="product_cat"] option:selected').length;
            var tagCount = $rule.find('[data-taxonomy="product_tag"] option:selected').length;
            var complete = (customerCount + roleCount > 0) && (categoryCount + tagCount > 0);

            $rule.toggleClass('ffla-tax-exemption-rule--incomplete', !complete);
            $rule.find('.ffla-tax-exemption-rule__status').text(
                complete ? t('ruleReady', 'Ready') : t('ruleNeedsSelections', 'Needs selections')
            );
        }

        function initExemptionRule($rule) {
            $(document.body).trigger('wc-enhanced_select_init');
            initTaxTermSearch($rule);
            refreshExemptionRuleState($rule);
        }

        initTaxTermSearch($(document));

        $('#ffla-add-tax-exemption-rule').on('click', function () {
            var template = $('#tmpl-ffla-tax-exemption-rule').html() || '';
            if (!template) {
                return;
            }

            var html = template.replace(/__INDEX__/g, String(exemptionRuleIndex++));
            var $rule = $(html);
            $('#ffla-tax-exemption-rules')
                .find('.ffla-tax-exemption-rules__empty')
                .remove();
            $('#ffla-tax-exemption-rules').append($rule);
            initExemptionRule($rule);
            $rule.find('.ffla-tax-exemption-rule__name').trigger('focus').select();
        });

        $(document).on('click', '.ffla-remove-tax-exemption-rule', function () {
            if (!window.confirm(t('confirmRemoveRule', 'Remove this conditional tax exemption rule?'))) {
                return;
            }
            $(this).closest('.ffla-tax-exemption-rule').remove();
            if (!$('#ffla-tax-exemption-rules .ffla-tax-exemption-rule').length) {
                $('#ffla-tax-exemption-rules').html(
                    '<div class="ffla-tax-exemption-rules__empty"><strong>' +
                    escHtml(t('noConditionalRules', 'No conditional rules yet.')) +
                    '</strong></div>'
                );
            }
        });

        $(document).on('change', '.ffla-tax-exemption-rule select, .ffla-tax-exemption-rule input[type="checkbox"]', function () {
            refreshExemptionRuleState($(this).closest('.ffla-tax-exemption-rule'));
        });

        /* Merchant-defined tax holidays. */
        var holidayRuleIndex = 0;
        $('#ffla-tax-holiday-rules .ffla-tax-holiday-rule').each(function () {
            var name = $(this).find('[name^="tax_holiday_rules["]').first().attr('name') || '';
            var match = name.match(/tax_holiday_rules\[(\d+)\]/);
            if (match) {
                holidayRuleIndex = Math.max(holidayRuleIndex, parseInt(match[1], 10) + 1);
            }
        });

        function refreshHolidayState($rule) {
            var enabled = $rule.find('.ffla-tax-exemption-rule__enabled input').is(':checked');
            var startValue = $rule.find('[name$="[start_at]"]').val();
            var endValue = $rule.find('[name$="[end_at]"]').val();
            var start = startValue ? new Date(startValue).getTime() : NaN;
            var end = endValue ? new Date(endValue).getTime() : NaN;
            var scope = $rule.find('.ffla-tax-holiday-scope').val();
            var hasProducts = $rule.find('.ffla-tax-holiday-products option:selected, .ffla-tax-term-search option:selected').length > 0;
            var complete = Number.isFinite(start) && Number.isFinite(end) && end >= start && (scope === 'all' || hasProducts);
            var status = 'incomplete';
            var label = t('holidayIncomplete', 'Needs dates or products');
            var now = Date.now();

            if (!enabled) {
                status = 'disabled';
                label = t('holidayDisabled', 'Disabled');
            } else if (complete && now < start) {
                status = 'scheduled';
                label = t('holidayScheduled', 'Scheduled');
            } else if (complete && now > end) {
                status = 'expired';
                label = t('holidayExpired', 'Expired');
            } else if (complete) {
                status = 'active';
                label = t('holidayActive', 'Active now');
            }

            $rule.attr('data-holiday-status', status);
            $rule.find('.ffla-tax-holiday-rule__status')
                .attr('class', 'ffla-tax-holiday-rule__status ffla-tax-holiday-rule__status--' + status)
                .text(label);
            $rule.find('.ffla-tax-holiday-rule__scope-fields').toggle(scope !== 'all');
        }

        function prepareClonedSelects($rule) {
            $rule.find('.select2-container').remove();
            $rule.find('select').each(function () {
                $(this).removeClass('select2-hidden-accessible enhanced').removeAttr('data-select2-id aria-hidden tabindex');
                $(this).find('option').removeAttr('data-select2-id');
            });
        }

        function initHolidayRule($rule) {
            prepareClonedSelects($rule);
            $(document.body).trigger('wc-enhanced_select_init');
            initTaxTermSearch($rule);
            refreshHolidayState($rule);
        }

        $('#ffla-tax-holiday-rules .ffla-tax-holiday-rule').each(function () {
            refreshHolidayState($(this));
        });

        $('#ffla-add-tax-holiday').on('click', function () {
            var template = $('#tmpl-ffla-tax-holiday-rule').html() || '';
            if (!template) {
                return;
            }
            var $rule = $(template.replace(/__INDEX__/g, String(holidayRuleIndex++)));
            $('#ffla-tax-holiday-rules').find('.ffla-tax-exemption-rules__empty').remove();
            $('#ffla-tax-holiday-rules').append($rule);
            initHolidayRule($rule);
            $rule.find('.ffla-tax-exemption-rule__name').trigger('focus').select();
        });

        $(document).on('click', '.ffla-duplicate-tax-holiday', function () {
            var $clone = $(this).closest('.ffla-tax-holiday-rule').clone(false, false);
            prepareClonedSelects($clone);
            $clone.find('[name]').each(function () {
                var $field = $(this);
                $field.attr('name', ($field.attr('name') || '').replace(/tax_holiday_rules\[\d+\]/, 'tax_holiday_rules[' + holidayRuleIndex + ']'));
            });
            $clone.find('[name$="[id]"]').val('');
            $clone.find('.ffla-tax-exemption-rule__name').val(($clone.find('.ffla-tax-exemption-rule__name').val() || '') + ' ' + t('copy', 'Copy'));
            holidayRuleIndex++;
            $(this).closest('.ffla-tax-holiday-rule').after($clone);
            initHolidayRule($clone);
        });

        $(document).on('click', '.ffla-remove-tax-holiday', function () {
            if (!window.confirm(t('confirmRemoveHoliday', 'Remove this tax holiday rule?'))) {
                return;
            }
            $(this).closest('.ffla-tax-holiday-rule').remove();
            if (!$('#ffla-tax-holiday-rules .ffla-tax-holiday-rule').length) {
                $('#ffla-tax-holiday-rules').html('<div class="ffla-tax-exemption-rules__empty"><strong>' + escHtml(t('noHolidayRules', 'No tax holidays yet.')) + '</strong></div>');
            }
        });

        $(document).on('change input', '.ffla-tax-holiday-rule input, .ffla-tax-holiday-rule select', function () {
            refreshHolidayState($(this).closest('.ffla-tax-holiday-rule'));
        });

        $('form[action*="admin-post.php"]').on('submit', function (event) {
            var $form = $(this);
            if (!$form.find('input[name="tax_holidays_enabled"]').is(':checked')) {
                return;
            }
            var $invalid = $form.find('.ffla-tax-holiday-rule[data-holiday-status="incomplete"]').filter(function () {
                return $(this).find('.ffla-tax-exemption-rule__enabled input').is(':checked');
            }).first();
            if ($invalid.length) {
                event.preventDefault();
                window.alert(t('holidayIncomplete', 'Needs dates or products'));
                $('html, body').animate({ scrollTop: Math.max(0, $invalid.offset().top - 80) }, 200);
            }
        });

        $('form[action*="admin-post.php"]').on('submit', function (event) {
            var $master = $(this).find('input[name="tax_role_restrict"]');
            if (!$master.is(':checked')) {
                return;
            }

            var message = '';
            var $invalidRule = $();
            $(this).find('.ffla-tax-exemption-rule').each(function () {
                var $rule = $(this);
                if (!$rule.find('.ffla-tax-exemption-rule__enabled input').is(':checked')) {
                    return;
                }
                var hasAudience = $rule.find('.ffla-tax-rule-customers option:selected, .ffla-tax-rule-roles option:selected').length > 0;
                var hasScope = $rule.find('.ffla-tax-term-search option:selected').length > 0;
                if (!hasAudience) {
                    message = t('ruleNeedsAudience', 'Select at least one customer or role for every enabled rule.');
                    $invalidRule = $rule;
                    return false;
                }
                if (!hasScope) {
                    message = t('ruleNeedsScope', 'Select at least one category or tag for every enabled rule.');
                    $invalidRule = $rule;
                    return false;
                }
            });

            if (message) {
                event.preventDefault();
                window.alert(message);
                $('html, body').animate({ scrollTop: Math.max(0, $invalidRule.offset().top - 80) }, 200);
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
