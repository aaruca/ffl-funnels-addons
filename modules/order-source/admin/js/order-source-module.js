/**
 * Order Badges — settings page.
 *
 * Enhances the two tag multi-selects (selectWoo/select2 when available) and the
 * colour inputs (WP colour picker), and keeps a colour row in sync with each
 * selected "badge tag".
 */
(function ($) {
    'use strict';

    // Default colours handed to newly-selected tags; mirrors the PHP palette.
    var PALETTE = ['#2271b1', '#e02424', '#0f766e', '#7c3aed', '#b45309', '#be185d', '#0891b2', '#4d7c0f'];

    function initColorPicker($input) {
        if ($.fn.wpColorPicker && !$input.hasClass('wp-color-picker')) {
            $input.wpColorPicker();
        }
    }

    $(function () {
        var $badge = $('#ffla-os-badge-tags');
        if (!$badge.length) {
            return;
        }
        var $rows = $('#ffla-os-color-rows');

        // Searchable multi-selects when WooCommerce's selectWoo (or select2) is present.
        if ($.fn.selectWoo) {
            $('.ffla-os-tags').selectWoo({ width: '100%' });
        } else if ($.fn.select2) {
            $('.ffla-os-tags').select2({ width: '100%' });
        }

        // Colour pickers already on the page (saved rows + Online Only colour).
        $('.ffla-os-settings .ffla-os-color').each(function () {
            initColorPicker($(this));
        });

        function addColorRow(id, name) {
            var color = PALETTE[$rows.children().length % PALETTE.length];
            var $row = $('<div class="ffla-os-color-row"></div>').attr('data-id', id);
            $('<span class="ffla-os-color-row__name"></span>').text(name).appendTo($row);
            var $input = $('<input type="text" class="ffla-os-color">')
                .attr('name', 'ffla_os[colors][' + id + ']')
                .val(color);
            $row.append($input);
            $rows.append($row);
            initColorPicker($input);
        }

        // Keep colour rows in step with the badge-tags selection.
        $badge.on('change', function () {
            var selected = {};
            $badge.find('option:selected').each(function () {
                var id = $(this).val();
                selected[id] = true;
                if (!$rows.find('.ffla-os-color-row[data-id="' + id + '"]').length) {
                    addColorRow(id, $(this).text());
                }
            });
            $rows.find('.ffla-os-color-row').each(function () {
                if (!selected[String($(this).data('id'))]) {
                    $(this).remove();
                }
            });
        });
    });
})(jQuery);
