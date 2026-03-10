/**
 * FFL Checkout — Mapbox Search Box Autocomplete.
 *
 * Initialises a Mapbox SearchBox on the WooCommerce billing and shipping
 * address_1 fields. When the customer picks a suggestion the city, state,
 * postcode, and country fields are filled automatically.
 *
 * Requires: Mapbox Search Box JS (CDN), injected token via wp_localize_script.
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

    /* ── Helper: set a WooCommerce field value and fire change events ─ */

    function setFieldValue(selector, value) {
        var el = document.querySelector(selector);
        if (!el || value == null) return;

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

    /* ── Parse a Mapbox feature into WC fields ────────────────────── */

    /**
     * Mapbox Search Box returns a GeoJSON Feature with a `properties` object.
     * The relevant properties are:
     *   full_address, place_formatted, name,
     *   address_line1, address_line2,
     *   context.place.name        → city
     *   context.region.region_code → state abbreviation
     *   context.region.name       → state full name
     *   context.postcode.name     → postcode
     *   context.country.country_code → ISO 2-letter country
     */
    function fillFieldsFromFeature(group, feature) {
        var p   = feature.properties || {};
        var ctx = p.context || {};

        // ── Street address ────────────────────────────────────────────
        var street = p.address_line1 || p.name || '';
        if (street) {
            setFieldValue(group.address1, street);
        }

        // ── Address line 2 ────────────────────────────────────────────
        if (p.address_line2) {
            setFieldValue(group.address2, p.address_line2);
        }

        // ── City ──────────────────────────────────────────────────────
        var city = (ctx.place && ctx.place.name) || '';
        if (city) setFieldValue(group.city, city);

        // ── State ─────────────────────────────────────────────────────
        var state = (ctx.region && (ctx.region.region_code || ctx.region.name)) || '';
        if (state) setFieldValue(group.state, state);

        // ── Postcode ──────────────────────────────────────────────────
        var postcode = (ctx.postcode && ctx.postcode.name) || '';
        if (postcode) setFieldValue(group.postcode, postcode);

        // ── Country ───────────────────────────────────────────────────
        var country = (ctx.country && ctx.country.country_code) || '';
        if (country) setFieldValue(group.country, country.toUpperCase());

        // Trigger WooCommerce totals recalculation.
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).trigger('update_checkout');
        }
    }

    /* ── Mount a SearchBox on one address group ───────────────────── */

    function mountSearchBox(group) {
        var input = document.querySelector(group.address1);
        if (!input || input.dataset.mbxInited) return;
        input.dataset.mbxInited = '1';

        // Mapbox Search Box web component (available on window after the CDN script loads).
        var SearchBox = (window.mapboxsearch && window.mapboxsearch.SearchBox) ||
                        (window.MapboxSearchBox)                               ||
                        null;

        if (SearchBox) {
            mountViaSearchBoxComponent(group, input, SearchBox);
        } else {
            mountViaGeocoderFetch(group, input);
        }
    }

    /* ── Path A: Mapbox SearchBox web component ───────────────────── */

    function mountViaSearchBoxComponent(group, input, SearchBox) {
        var sb = new SearchBox();
        sb.accessToken = ACCESS_TOKEN;
        sb.options = {
            language: 'en',
            country:  'US',
            types:    'address',
        };

        // Replace the plain input with the SearchBox component.
        // The SearchBox component wraps itself around the input it is given.
        sb.bindTo(input);

        sb.addEventListener('retrieve', function (e) {
            var feature = e.detail &&
                          e.detail.features &&
                          e.detail.features[0];
            if (feature) fillFieldsFromFeature(group, feature);
        });
    }

    /* ── Path B: Geocoder Fetch API fallback (no web component) ───── */

    function mountViaGeocoderFetch(group, input) {
        var debounceTimer;
        var dropdownId = 'ffl-mbx-suggestions-' + group.prefix;

        input.setAttribute('autocomplete', 'off');

        input.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            var q = input.value.trim();

            if (q.length < 3) {
                removeSuggestions(dropdownId);
                return;
            }

            debounceTimer = setTimeout(function () {
                fetch(
                    'https://api.mapbox.com/search/searchbox/v1/suggest' +
                    '?q=' + encodeURIComponent(q) +
                    '&access_token=' + encodeURIComponent(ACCESS_TOKEN) +
                    '&session_token=' + getSessionToken() +
                    '&types=address' +
                    '&country=US' +
                    '&language=en'
                )
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var suggestions = (data && data.suggestions) || [];
                    showSuggestions(input, group, suggestions, dropdownId);
                })
                .catch(function () { /* silent */ });
            }, 220);
        });

        input.addEventListener('blur', function () {
            setTimeout(function () { removeSuggestions(dropdownId); }, 200);
        });
    }

    /* Retrieve a full feature from the Searchbox retrieve endpoint. */
    function retrieveFeature(mapboxId, callback) {
        fetch(
            'https://api.mapbox.com/search/searchbox/v1/retrieve/' +
            encodeURIComponent(mapboxId) +
            '?access_token=' + encodeURIComponent(ACCESS_TOKEN) +
            '&session_token=' + getSessionToken()
        )
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var feature = data && data.features && data.features[0];
            if (feature) callback(feature);
        })
        .catch(function () { /* silent */ });
    }

    function showSuggestions(input, group, suggestions, dropdownId) {
        removeSuggestions(dropdownId);
        if (!suggestions.length) return;

        var ul = document.createElement('ul');
        ul.id = dropdownId;
        ul.className = 'ffl-checkout-mbx-suggestions';
        ul.style.cssText =
            'position:absolute;z-index:9999;background:#fff;border:1px solid #ddd;' +
            'border-radius:6px;max-height:240px;overflow-y:auto;padding:0;margin:4px 0 0;' +
            'list-style:none;width:100%;box-shadow:0 4px 16px rgba(0,0,0,.12);';

        suggestions.forEach(function (s) {
            var li = document.createElement('li');
            li.style.cssText = 'padding:9px 14px;cursor:pointer;font-size:14px;line-height:1.4;border-bottom:1px solid #f0f0f0;';

            var nameEl = document.createElement('span');
            nameEl.textContent = s.name || s.full_address || '';
            nameEl.style.fontWeight = '500';

            var placeEl = document.createElement('span');
            placeEl.textContent = s.place_formatted ? ' — ' + s.place_formatted : '';
            placeEl.style.cssText = 'color:#888;font-size:12px;';

            li.appendChild(nameEl);
            li.appendChild(placeEl);

            li.addEventListener('mousedown', function (e) {
                e.preventDefault();
                input.value = s.name || '';
                removeSuggestions(dropdownId);

                // Retrieve full feature to fill remaining fields.
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
    }

    function removeSuggestions(dropdownId) {
        var el = document.getElementById(dropdownId);
        if (el) el.remove();
    }

    /* ── Session token (required by Mapbox Searchbox API billing) ─── */

    var _sessionToken = null;
    function getSessionToken() {
        if (!_sessionToken) {
            _sessionToken = 'xxxx-xxxx-4xxx-yxxx'.replace(/[xy]/g, function (c) {
                var r = (Math.random() * 16) | 0;
                return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
            });
        }
        return _sessionToken;
    }

    /* ── Bootstrap ─────────────────────────────────────────────────── */

    function bootstrap() {
        ADDRESS_GROUPS.forEach(mountSearchBox);
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
