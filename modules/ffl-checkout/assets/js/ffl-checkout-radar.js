/**
 * FFL Checkout — Radar Address Autocomplete.
 *
 * Initializes Radar SDK and mounts autocomplete UI on
 * WooCommerce billing_address_1 and shipping_address_1 fields.
 *
 * @package FFL_Funnels_Addons
 */
(function () {
    'use strict';

    /* ── Guard ───────────────────────────────────────────────────── */

    if (typeof Radar === 'undefined' || typeof fflCheckoutRadar === 'undefined') {
        return;
    }

    var publishableKey = fflCheckoutRadar.publishableKey;
    if (!publishableKey) {
        return;
    }

    /* ── Init SDK ─────────────────────────────────────────────────── */

    Radar.initialize(publishableKey);

    /* ── Field mapping config ─────────────────────────────────────── */

    /**
     * Each entry maps a WooCommerce address group to its field selectors.
     * Both classic checkout and block-based checkout selectors are supported.
     */
    var ADDRESS_GROUPS = [
        {
            prefix: 'billing',
            address1: '#billing_address_1',
            address2: '#billing_address_2',
            city:     '#billing_city',
            state:    '#billing_state',
            postcode: '#billing_postcode',
            country:  '#billing_country',
        },
        {
            prefix: 'shipping',
            address1: '#shipping_address_1',
            address2: '#shipping_address_2',
            city:     '#shipping_city',
            state:    '#shipping_state',
            postcode: '#shipping_postcode',
            country:  '#shipping_country',
        },
    ];

    /* ── Helper: set a field value and trigger WooCommerce updates ── */

    function setFieldValue(selector, value) {
        var el = document.querySelector(selector);
        if (!el || typeof value === 'undefined' || value === null) {
            return;
        }

        // For <select> elements (state / country), try to match the option value.
        if (el.tagName === 'SELECT') {
            var opts = el.options;
            for (var i = 0; i < opts.length; i++) {
                if (
                    opts[i].value === value ||
                    opts[i].value.toUpperCase() === value.toUpperCase()
                ) {
                    el.value = opts[i].value;
                    break;
                }
            }
        } else {
            el.value = value;
        }

        // Fire change + input events so WooCommerce & Select2 pick up the value.
        el.dispatchEvent(new Event('change', { bubbles: true }));
        el.dispatchEvent(new Event('input', { bubbles: true }));

        // If Select2 is wrapping this element, trigger its change too.
        if (typeof jQuery !== 'undefined') {
            jQuery(el).trigger('change');
        }
    }

    /* ── Mount autocomplete on each address group ─────────────────── */

    function mountAutocomplete(group) {
        var input = document.querySelector(group.address1);
        if (!input) {
            return;
        }

        // Use Radar Autocomplete UI if available.
        if (Radar.ui && typeof Radar.ui.autocomplete === 'function') {
            Radar.ui.autocomplete({
                container: input.parentElement,
                near: 'ip',
                debounceMS: 200,
                responsive: true,
                onSelection: function (address) {
                    if (!address) return;
                    handleAddressSelection(group, address);
                },
            });
            return;
        }

        // Fallback: use the Radar.autocomplete API directly with a simple listener.
        var debounceTimer;
        input.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            var query = input.value;
            if (query.length < 3) return;

            debounceTimer = setTimeout(function () {
                Radar.autocomplete({ query: query, near: 'ip' }, function (err, result) {
                    if (err || !result || !result.addresses || !result.addresses.length) {
                        return;
                    }
                    showFallbackSuggestions(input, group, result.addresses);
                });
            }, 200);
        });
    }

    /* ── Handle selected address (common for both paths) ──────────── */

    function handleAddressSelection(group, address) {
        // Radar address objects provide these fields:
        // addressLabel, formattedAddress, number, street, city, state, stateCode,
        // postalCode, countryCode, countryFlag, county, neighborhood, layer, unit
        var streetLine = '';
        if (address.number) streetLine += address.number;
        if (address.street) streetLine += (streetLine ? ' ' : '') + address.street;

        if (streetLine) {
            setFieldValue(group.address1, streetLine);
        } else if (address.formattedAddress) {
            setFieldValue(group.address1, address.formattedAddress);
        }

        if (address.unit) {
            setFieldValue(group.address2, address.unit);
        }

        if (address.city) {
            setFieldValue(group.city, address.city);
        }

        // State — try state abbreviation first, then full name.
        if (address.stateCode) {
            setFieldValue(group.state, address.stateCode);
        } else if (address.state) {
            setFieldValue(group.state, address.state);
        }

        if (address.postalCode) {
            setFieldValue(group.postcode, address.postalCode);
        }

        // Country — use ISO 2-letter code.
        if (address.countryCode) {
            setFieldValue(group.country, address.countryCode);
        }

        // Trigger WooCommerce checkout update after populating all fields.
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).trigger('update_checkout');
        }
    }

    /* ── Fallback: simple dropdown suggestions (no Radar UI plugin) ─ */

    function showFallbackSuggestions(input, group, addresses) {
        removeFallbackDropdown(input);

        var dropdown = document.createElement('ul');
        dropdown.className = 'ffl-checkout-radar-suggestions';
        dropdown.style.cssText =
            'position:absolute;z-index:9999;background:#fff;border:1px solid #ddd;' +
            'border-radius:4px;max-height:220px;overflow-y:auto;padding:0;margin:4px 0 0;' +
            'list-style:none;width:100%;box-shadow:0 4px 12px rgba(0,0,0,.1);';

        addresses.forEach(function (addr) {
            var li = document.createElement('li');
            li.textContent = addr.formattedAddress || addr.addressLabel || '';
            li.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:14px;';
            li.addEventListener('mouseenter', function () {
                li.style.background = '#f0f0f0';
            });
            li.addEventListener('mouseleave', function () {
                li.style.background = '#fff';
            });
            li.addEventListener('mousedown', function (e) {
                e.preventDefault();
                handleAddressSelection(group, addr);
                removeFallbackDropdown(input);
            });
            dropdown.appendChild(li);
        });

        // Position relative to input.
        var wrapper = input.parentElement;
        if (wrapper) {
            wrapper.style.position = 'relative';
            wrapper.appendChild(dropdown);
        }

        // Close on outside click.
        function closeHandler(e) {
            if (!dropdown.contains(e.target) && e.target !== input) {
                removeFallbackDropdown(input);
                document.removeEventListener('click', closeHandler);
            }
        }
        setTimeout(function () {
            document.addEventListener('click', closeHandler);
        }, 0);
    }

    function removeFallbackDropdown(input) {
        var wrapper = input.parentElement;
        if (!wrapper) return;
        var existing = wrapper.querySelector('.ffl-checkout-radar-suggestions');
        if (existing) {
            existing.remove();
        }
    }

    /* ── Bootstrap ─────────────────────────────────────────────────── */

    /**
     * Wait for DOM + WooCommerce checkout to be ready, then mount.
     */
    function bootstrap() {
        ADDRESS_GROUPS.forEach(mountAutocomplete);
    }

    // WooCommerce may load checkout fragments via AJAX, so re-mount after updates.
    if (typeof jQuery !== 'undefined') {
        jQuery(function ($) {
            bootstrap();
            $(document.body).on('updated_checkout', bootstrap);
        });
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
