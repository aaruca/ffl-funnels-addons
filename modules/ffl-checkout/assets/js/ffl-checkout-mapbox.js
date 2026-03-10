/**
 * FFL Checkout — Mapbox Address Autocomplete.
 *
 * Uses the Mapbox Searchbox REST API (/suggest + /retrieve) to provide
 * address autocomplete on WooCommerce billing and shipping address fields.
 * No web component dependency — works with any WooCommerce checkout theme.
 *
 * @package FFL_Funnels_Addons
 */
(function () {
    'use strict';

    /* ── Guard ─────────────────────────────────────────────────────── */

    if (typeof fflCheckoutMapbox === 'undefined' || !fflCheckoutMapbox.accessToken) {
        return;
    }

    var ACCESS_TOKEN = fflCheckoutMapbox.accessToken;

    var SUGGEST_URL  = 'https://api.mapbox.com/search/searchbox/v1/suggest';
    var RETRIEVE_URL = 'https://api.mapbox.com/search/searchbox/v1/retrieve/';

    /* ── Field mapping config ─────────────────────────────────────── */

    var ADDRESS_GROUPS = [
        {
            prefix:   'billing',
            address1: '#billing_address_1',
            address2: '#billing_address_2',
            city:     '#billing_city',
            state:    '#billing_state',
            postcode: '#billing_postcode',
            country:  '#billing_country',
        },
        {
            prefix:   'shipping',
            address1: '#shipping_address_1',
            address2: '#shipping_address_2',
            city:     '#shipping_city',
            state:    '#shipping_state',
            postcode: '#shipping_postcode',
            country:  '#shipping_country',
        },
    ];

    /* ── Session token (required for Mapbox Searchbox billing) ─────── */

    var _sessionToken = null;
    function getSessionToken() {
        if (!_sessionToken) {
            _sessionToken = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                var r = (Math.random() * 16) | 0;
                return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
            });
        }
        return _sessionToken;
    }

    /* ── Helper: set a WooCommerce field and fire change events ──────- */

    function setFieldValue(selector, value) {
        var el = document.querySelector(selector);
        if (!el || value == null || value === '') return;

        if (el.tagName === 'SELECT') {
            var opts = el.options;
            for (var i = 0; i < opts.length; i++) {
                if (
                    opts[i].value === value ||
                    opts[i].value.toUpperCase() === (value + '').toUpperCase()
                ) {
                    el.value = opts[i].value;
                    break;
                }
            }
        } else {
            el.value = value;
        }

        el.dispatchEvent(new Event('change', { bubbles: true }));
        el.dispatchEvent(new Event('input',  { bubbles: true }));

        if (typeof jQuery !== 'undefined') {
            jQuery(el).trigger('change');
        }
    }

    /* ── Fill WC fields from a Mapbox feature ─────────────────────── */

    /**
     * Mapbox Searchbox retrieve response has a GeoJSON Feature with `properties`:
     *   name, full_address, address_line1, address_line2,
     *   context.place.name       → city
     *   context.region.region_code → state abbreviation
     *   context.postcode.name    → postcode
     *   context.country.country_code → ISO 2-letter country
     */
    function fillFieldsFromFeature(group, feature) {
        var p   = feature.properties || {};
        var ctx = p.context           || {};

        // Street.
        var street = p.address_line1 || p.name || '';
        if (street) setFieldValue(group.address1, street);

        // Unit / apt.
        if (p.address_line2) setFieldValue(group.address2, p.address_line2);

        // City.
        var city = (ctx.place   && ctx.place.name)                        || '';
        if (city) setFieldValue(group.city, city);

        // State — prefer abbreviation.
        var state = (ctx.region && (ctx.region.region_code || ctx.region.name)) || '';
        if (state) setFieldValue(group.state, state);

        // Postcode.
        var postcode = (ctx.postcode && ctx.postcode.name) || '';
        if (postcode) setFieldValue(group.postcode, postcode);

        // Country — ISO 2-letter.
        var country = (ctx.country && ctx.country.country_code) || '';
        if (country) setFieldValue(group.country, country.toUpperCase());

        // Tell WooCommerce to recalculate totals.
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).trigger('update_checkout');
        }
    }

    /* ── Retrieve full feature from a suggestion's mapbox_id ─────── */

    function retrieveFeature(mapboxId, callback) {
        fetch(
            RETRIEVE_URL + encodeURIComponent(mapboxId) +
            '?access_token=' + encodeURIComponent(ACCESS_TOKEN) +
            '&session_token=' + getSessionToken()
        )
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var feature = data && data.features && data.features[0];
            if (feature) callback(feature);
        })
        .catch(function (err) {
            console.warn('[FFL Mapbox] retrieve error:', err);
        });
    }

    /* ── Suggestion dropdown ──────────────────────────────────────── */

    function showSuggestions(input, group, suggestions, dropdownId) {
        removeSuggestions(dropdownId);
        if (!suggestions.length) return;

        var ul = document.createElement('ul');
        ul.id            = dropdownId;
        ul.className     = 'ffl-checkout-mbx-suggestions';
        ul.style.cssText =
            'position:absolute;z-index:99999;background:#fff;border:1px solid #ddd;' +
            'border-radius:6px;max-height:240px;overflow-y:auto;padding:0;margin:4px 0 0;' +
            'list-style:none;width:100%;box-shadow:0 4px 16px rgba(0,0,0,.12);';

        suggestions.forEach(function (s) {
            var li = document.createElement('li');
            li.style.cssText =
                'padding:9px 14px;cursor:pointer;font-size:14px;line-height:1.4;' +
                'border-bottom:1px solid #f4f4f4;';

            var nameEl = document.createElement('span');
            nameEl.textContent  = s.name || '';
            nameEl.style.fontWeight = '500';

            var descEl = document.createElement('span');
            descEl.textContent = s.place_formatted ? '  ' + s.place_formatted : '';
            descEl.style.cssText = 'color:#888;font-size:12px;';

            li.appendChild(nameEl);
            li.appendChild(descEl);

            li.addEventListener('mouseover', function () { li.style.background = '#f5f7ff'; });
            li.addEventListener('mouseout',  function () { li.style.background = '#fff';    });

            li.addEventListener('mousedown', function (e) {
                e.preventDefault();

                // Pre-fill address line 1 immediately so the user sees feedback.
                setFieldValue(group.address1, s.name || '');
                removeSuggestions(dropdownId);

                // Retrieve full feature to fill the rest of the fields.
                if (s.mapbox_id) {
                    retrieveFeature(s.mapbox_id, function (feature) {
                        fillFieldsFromFeature(group, feature);
                    });
                }
            });

            ul.appendChild(li);
        });

        var wrapper = input.parentElement;
        if (wrapper) {
            wrapper.style.position = 'relative';
            wrapper.appendChild(ul);
        }

        // Close on outside click.
        setTimeout(function () {
            document.addEventListener('click', function handler(e) {
                if (!ul.contains(e.target) && e.target !== input) {
                    removeSuggestions(dropdownId);
                    document.removeEventListener('click', handler);
                }
            });
        }, 0);
    }

    function removeSuggestions(dropdownId) {
        var el = document.getElementById(dropdownId);
        if (el) el.remove();
    }

    /* ── Mount on a single address group ─────────────────────────── */

    function mountAutocomplete(group) {
        var input = document.querySelector(group.address1);
        if (!input || input.dataset.mbxInited) return;
        input.dataset.mbxInited = '1';
        input.setAttribute('autocomplete', 'off');

        var debounceTimer;
        var dropdownId = 'ffl-mbx-' + group.prefix;

        input.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            var q = input.value.trim();

            if (q.length < 3) {
                removeSuggestions(dropdownId);
                return;
            }

            debounceTimer = setTimeout(function () {
                fetch(
                    SUGGEST_URL +
                    '?q='             + encodeURIComponent(q) +
                    '&access_token='  + encodeURIComponent(ACCESS_TOKEN) +
                    '&session_token=' + getSessionToken() +
                    '&types=address' +
                    '&country=US' +
                    '&language=en' +
                    '&limit=6'
                )
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var suggestions = (data && data.suggestions) || [];
                    showSuggestions(input, group, suggestions, dropdownId);
                })
                .catch(function (err) {
                    console.warn('[FFL Mapbox] suggest error:', err);
                });
            }, 220);
        });

        input.addEventListener('blur', function () {
            setTimeout(function () { removeSuggestions(dropdownId); }, 200);
        });
    }

    /* ── Bootstrap ─────────────────────────────────────────────────── */

    function bootstrap() {
        ADDRESS_GROUPS.forEach(mountAutocomplete);
    }

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
