/* global loadoutAdmin */
(function ($) {
    'use strict';

    $(document).ready(function () {
        initImagePickers();
        initProductSearch();
        initTierRepeater();
        initCrossSellRepeater();
        initRowRemoval();
    });

    function initImagePickers() {
        $(document).on('click', '.loadout-image-select', function (e) {
            e.preventDefault();
            var $button = $(this);
            var $picker = $button.closest('.loadout-image-picker');
            var $input = $picker.find('input[type="hidden"]');
            var $preview = $picker.find('.loadout-image-preview');
            var $remove = $picker.find('.loadout-image-remove');

            var frame = wp.media({
                title: loadoutAdmin.strings.selectImage,
                button: { text: loadoutAdmin.strings.useImage },
                multiple: false,
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $input.val(attachment.id);
                var thumbUrl = attachment.sizes && attachment.sizes.thumbnail
                    ? attachment.sizes.thumbnail.url
                    : attachment.url;
                $preview.attr('src', thumbUrl).show();
                $remove.show();
            });

            frame.open();
        });

        $(document).on('click', '.loadout-image-remove', function (e) {
            e.preventDefault();
            var $picker = $(this).closest('.loadout-image-picker');
            $picker.find('input[type="hidden"]').val('');
            $picker.find('.loadout-image-preview').hide().attr('src', '');
            $(this).hide();
        });
    }

    function initProductSearch() {
        var searchTimeout;

        $(document).on('input', '.loadout-product-search', function () {
            var $input = $(this);
            var query = $input.val().trim();
            var $row = $input.closest('.loadout-tier-row, .loadout-item-row, td, .loadout-cs-fields, .form-table tr');
            var $results = $row.find('.loadout-search-results').first();

            if (!$results.length) {
                $results = $input.siblings('.loadout-search-results');
            }

            clearTimeout(searchTimeout);

            if (query.length < 2) {
                $results.empty().hide();
                return;
            }

            searchTimeout = setTimeout(function () {
                $results.html('<p>' + loadoutAdmin.strings.searching + '</p>').show();

                $.ajax({
                    url: loadoutAdmin.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'loadout_search_products',
                        nonce: loadoutAdmin.nonce,
                        search: query,
                    },
                    success: function (response) {
                        if (!response.success || !response.data.products.length) {
                            $results.html('<p>' + loadoutAdmin.strings.noResults + '</p>');
                            return;
                        }

                        var html = '<ul class="loadout-search-list">';
                        response.data.products.forEach(function (product) {
                            var priceAttr = product.price ? ' data-price="' + escapeHtml(product.price) + '"' : '';
                            var stockAttr = product.stock_html ? ' data-stock="' + escapeHtml(product.stock_html) + '"' : '';
                            html += '<li data-id="' + product.id + '" data-name="' + escapeHtml(product.name) + '"' + priceAttr + stockAttr + '>';
                            html += '<strong>' + escapeHtml(product.name) + '</strong>';
                            if (product.sku) {
                                html += ' <span style="color:#666;">(SKU: ' + escapeHtml(product.sku) + ')</span>';
                            }
                            html += '<br>';
                            if (product.price) {
                                html += '<span style="color:#2271b1;font-size:11px;margin-right:6px;">' + product.price + '</span>';
                            }
                            if (product.stock_html) {
                                html += product.stock_html;
                            }
                            html += '</li>';
                        });
                        html += '</ul>';

                        $results.html(html);
                    },
                });
            }, 300);
        });

        $(document).on('click', '.loadout-search-list li', function () {
            var $li = $(this);
            var productId = $li.data('id');
            var productName = $li.data('name');
            var productPrice = $li.attr('data-price') || '';
            var productStock = $li.attr('data-stock') || '';
            var $results = $li.closest('.loadout-search-results');
            var $input = $results.siblings('.loadout-product-search').last();
            if (!$input.length) {
                $input = $results.parent().find('.loadout-product-search').first();
            }

            var targetSel = $input.data('target');
            var displaySel = $input.data('display');
            var scope = $input.data('scope');

            var $target, $display;
            if (scope === 'row') {
                var $row = $input.closest('tr, .loadout-item-row, .loadout-tier-row');
                $target = $row.find(targetSel).first();
                $display = $row.find(displaySel).first();
            } else {
                $target = $(targetSel);
                $display = $(displaySel);
            }

            $target.val(productId);
            var html = '<span class="loadout-product-name">' + escapeHtml(productName) + ' (#' + productId + ')</span>';
            if (productPrice) {
                html += ' <span class="loadout-product-price">' + productPrice + '</span>';
            }
            if (productStock) {
                html += ' ' + productStock;
            }
            html += ' <button type="button" class="button-link loadout-product-remove" data-target="' + targetSel + '" data-display="' + displaySel + '"' + (scope === 'row' ? ' data-scope="row"' : '') + '>Remove</button>';
            $display.html(html);

            $input.val('');
            $results.empty().hide();
        });

        $(document).on('click', '.loadout-product-remove', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var targetSel = $btn.data('target');
            var displaySel = $btn.data('display');
            var scope = $btn.data('scope');

            var $target, $display;
            if (scope === 'row') {
                var $row = $btn.closest('tr, .loadout-item-row, .loadout-tier-row');
                $target = $row.find(targetSel).first();
                $display = $row.find(displaySel).first();
            } else {
                $target = $(targetSel);
                $display = $(displaySel);
            }
            $target.val('');
            $display.empty();
        });
    }

    /**
     * Next free row index for a repeater container. A counter instead of the
     * row count, so removing a row and adding another never reuses an index
     * (two rows with the same index would overwrite each other on save).
     */
    function nextIndex($container, rowSelector) {
        var next = $container.data('nextIndex');
        if (typeof next !== 'number') {
            next = $container.find(rowSelector).length;
        }
        $container.data('nextIndex', next + 1);
        return next;
    }

    function initTierRepeater() {
        $('#add-tier').on('click', function () {
            var $container = $('#loadout-tiers');
            var index = nextIndex($container, '.loadout-tier-row');
            var template = $('#tmpl-loadout-tier').html();
            if (template) {
                // Rewrite the "+ Add Item" button's data-tier-index too, or items
                // added to this new tier would be posted under tier 0.
                var html = template.replace(/data-index="0"/g, 'data-index="' + index + '"')
                                   .replace(/data-tier-index="0"/g, 'data-tier-index="' + index + '"')
                                   .replace(/tiers\[0\]/g, 'tiers[' + index + ']');
                $container.append(html);
            }
        });

        $(document).on('click', '.loadout-tier-remove', function () {
            if (confirm('Remove this tier and all its items?')) {
                $(this).closest('.loadout-tier-row').remove();
            }
        });

        $(document).on('input', '.loadout-tier-name-input', function () {
            var $row = $(this).closest('.loadout-tier-row');
            $row.find('.loadout-tier-name').text($(this).val() || 'New Tier');
        });

        $(document).on('click', '.loadout-add-item', function () {
            var $btn = $(this);
            var tierIndex = $btn.data('tier-index');
            var $row = $btn.closest('.loadout-tier-row');
            var $items = $row.find('.loadout-tier-items');
            var itemIndex = nextIndex($items, '.loadout-item-row');

            var template = $('#tmpl-loadout-item').html();
            if (template) {
                var html = template.replace(/tiers\[0\]\[items\]\[0\]/g, 'tiers[' + tierIndex + '][items][' + itemIndex + ']');
                $items.append(html);
            }
        });
    }

    function initCrossSellRepeater() {
        $('#add-cross-sell').on('click', function () {
            var $container = $('#loadout-cross-sells');
            var index = nextIndex($container, '.loadout-cross-sell-row');
            var template = $('#tmpl-loadout-cross-sell').html();
            if (template) {
                var html = template.replace(/cross_sells\[0\]/g, 'cross_sells[' + index + ']');
                $container.append(html);
            }
        });
    }

    function initRowRemoval() {
        $(document).on('click', '.loadout-item-remove', function () {
            $(this).closest('.loadout-item-row').remove();
        });
        $(document).on('click', '.loadout-cs-remove', function () {
            $(this).closest('.loadout-cross-sell-row').remove();
        });
    }

    window.loadoutNextIndex = nextIndex;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
        });
    }

})(jQuery);
