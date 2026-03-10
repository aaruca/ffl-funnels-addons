/**
 * FFL Dealer Finder — widget JS.
 *
 * Powered by the Garidium API (ffl-api.garidium.com).
 * The API key is injected server-side via wp_localize_script — never stored in JS.
 *
 * Flow:
 *  1. User types a ZIP code and clicks "Find FFL Dealers".
 *  2. POST to ffl-api.garidium.com { action: "get_nearby_ffl_dealers", data: { zip, api_key, radius_miles } }
 *  3. Results rendered as a scrollable list.
 *  4. User clicks "Select This Dealer" → hidden checkout fields updated + banner shown.
 *  5. WooCommerce saves the hidden fields on order submission.
 *  6. On page load, restore any previously selected dealer from sessionStorage.
 *
 * @package FFL_Funnels_Addons
 */

(function () {
    'use strict';

    /* ── Config (injected by wp_localize_script) ─────────────────────────── */

    const CFG = (typeof fflDealerFinderConfig !== 'undefined') ? fflDealerFinderConfig : {};
    const API_URL  = CFG.apiUrl  || 'https://ffl-api.garidium.com';
    const API_KEY  = CFG.apiKey  || '';
    const IS_BUILDER = CFG.isBuilder === '1';

    /* ── Bootstrap ───────────────────────────────────────────────────────── */

    function boot() {
        const roots = document.querySelectorAll('.ffl-dealer-finder');
        roots.forEach(initWidget);
    }

    // Run after Bricks re-renders the frontend (AJAX Page Transitions).
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // Bricks infinite scroll / query re-fetch hook.
    document.addEventListener('bricks/ajax/query_result/attached', boot);

    /* ── Per-widget initialisation ───────────────────────────────────────── */

    function initWidget(root) {
        // Avoid double-init.
        if (root.dataset.fflInited === '1') return;
        root.dataset.fflInited = '1';

        // Not an FFL cart — already hidden server-side, bail.
        if (root.dataset.fflEmptyCart === '1') return;

        // Builder preview — controls are disabled server-side, nothing extra needed.
        if (root.classList.contains('ffl-dealer-finder--preview')) return;

        const strings = getStrings(root);

        const zipInput     = root.querySelector('#ffl-zip-input');
        const searchBtn    = root.querySelector('#ffl-search-btn');
        const resultsDiv   = root.querySelector('.ffl-dealer-finder__results');
        const loadingDiv   = root.querySelector('.ffl-dealer-finder__loading');
        const noResultsDiv = root.querySelector('.ffl-dealer-finder__no-results');
        const banner       = root.querySelector('.ffl-dealer-finder__selected-banner');
        const bannerName   = banner ? banner.querySelector('.ffl-dealer-finder__selected-name') : null;
        const changeBtn    = banner ? banner.querySelector('.ffl-dealer-finder__change-btn') : null;

        const resultsPerPage = parseInt(root.dataset.resultsPerPage || '10', 10);
        const radiusMiles    = parseInt(root.dataset.radiusMiles   || '50',  10);

        // Hidden checkout fields (in the same Bricks page DOM or standard checkout form).
        const hiddenDealer     = document.getElementById('ffl_selected_dealer');
        const hiddenDealerName = document.getElementById('ffl_selected_dealer_name');

        // Restore previously selected dealer from sessionStorage.
        restoreSelection(hiddenDealer, hiddenDealerName, banner, bannerName);

        /* ── Event: Search ──────────────────────────────────────────────── */

        if (searchBtn) {
            searchBtn.addEventListener('click', handleSearch);
        }

        if (zipInput) {
            zipInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') handleSearch();
            });
        }

        if (changeBtn) {
            changeBtn.addEventListener('click', function () {
                clearSelection(hiddenDealer, hiddenDealerName, banner);
                if (resultsDiv) resultsDiv.innerHTML = '';
                if (zipInput)   zipInput.value = '';
            });
        }

        /* ── Search handler ─────────────────────────────────────────────── */

        function handleSearch() {
            if (!zipInput) return;

            const zip = zipInput.value.trim().replace(/\D/g, '').substring(0, 10);

            if (!zip) {
                zipInput.focus();
                zipInput.classList.add('ffl-dealer-finder__input--error');
                setTimeout(() => zipInput.classList.remove('ffl-dealer-finder__input--error'), 1500);
                return;
            }

            setLoading(true);
            if (noResultsDiv) noResultsDiv.style.display = 'none';
            if (resultsDiv)   resultsDiv.innerHTML = '';

            fetchDealers(zip, radiusMiles)
                .then(function (dealers) {
                    setLoading(false);
                    renderResults(dealers.slice(0, resultsPerPage));
                })
                .catch(function (err) {
                    setLoading(false);
                    console.error('[FFL Dealer Finder]', err);
                    if (noResultsDiv) noResultsDiv.style.display = '';
                });
        }

        /* ── API call ───────────────────────────────────────────────────── */

        function fetchDealers(zip, radius) {
            return fetch(API_URL, {
                method: 'POST',
                headers: {
                    'Accept':       'application/json',
                    'Content-Type': 'application/json',
                    'x-api-key':    API_KEY,
                },
                body: JSON.stringify({
                    action: 'get_nearby_ffl_dealers',
                    data: {
                        api_key:      API_KEY,
                        zip_code:     zip,
                        radius_miles: radius,
                    },
                }),
            })
            .then(function (res) {
                if (!res.ok) throw new Error('API responded with status ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.dealers || !Array.isArray(data.dealers)) {
                    return [];
                }
                return data.dealers;
            });
        }

        /* ── Result rendering ───────────────────────────────────────────── */

        function renderResults(dealers) {
            if (!resultsDiv) return;

            if (!dealers.length) {
                if (noResultsDiv) noResultsDiv.style.display = '';
                return;
            }

            // Determine the currently selected dealer ID (if any).
            const currentId = hiddenDealer ? hiddenDealer.value : '';

            dealers.forEach(function (dealer) {
                const isDealerSelected = currentId && String(dealer.ffl_id) === String(currentId);

                const card = document.createElement('div');
                card.className = 'ffl-dealer-finder__result' + (isDealerSelected ? ' ffl-dealer-finder__result--selected' : '');
                card.setAttribute('role', 'listitem');

                const header = document.createElement('div');
                header.className = 'ffl-dealer-finder__result-header';

                const name = document.createElement('span');
                name.className = 'ffl-dealer-finder__result-name';
                name.textContent = dealer.business_name || dealer.name || '—';

                const distance = document.createElement('span');
                distance.className = 'ffl-dealer-finder__result-distance';
                if (dealer.distance_miles != null) {
                    distance.textContent = parseFloat(dealer.distance_miles).toFixed(1) + ' ' + strings.miles;
                }

                header.appendChild(name);
                header.appendChild(distance);
                card.appendChild(header);

                // Address.
                const addr = [
                    dealer.premise_street,
                    dealer.premise_city,
                    dealer.premise_state,
                    dealer.premise_zip_code,
                ].filter(Boolean).join(', ');

                if (addr) {
                    const addrEl = document.createElement('div');
                    addrEl.className = 'ffl-dealer-finder__result-address';
                    addrEl.textContent = addr;
                    card.appendChild(addrEl);
                }

                // License.
                if (dealer.license_number) {
                    const licEl = document.createElement('div');
                    licEl.className = 'ffl-dealer-finder__result-meta';
                    licEl.textContent = strings.license + ' ' + dealer.license_number;
                    card.appendChild(licEl);
                }

                // Phone.
                if (dealer.phone) {
                    const phEl = document.createElement('div');
                    phEl.className = 'ffl-dealer-finder__result-meta';
                    phEl.textContent = strings.phone + ' ' + dealer.phone;
                    card.appendChild(phEl);
                }

                // Select button.
                const selectBtn = document.createElement('button');
                selectBtn.type      = 'button';
                selectBtn.className = 'ffl-dealer-finder__select-btn' + (isDealerSelected ? ' ffl-dealer-finder__select-btn--selected' : '');
                selectBtn.textContent = isDealerSelected
                    ? '✓ ' + strings.selectBtn
                    : strings.selectBtn;

                selectBtn.addEventListener('click', function () {
                    selectDealer(dealer, dealers, hiddenDealer, hiddenDealerName, banner, bannerName);
                });

                card.appendChild(selectBtn);
                resultsDiv.appendChild(card);
            });
        }

        /* ── Helpers ────────────────────────────────────────────────────── */

        function setLoading(active) {
            if (loadingDiv) loadingDiv.style.display = active ? '' : 'none';
            if (searchBtn)  searchBtn.disabled = active;
        }
    }

    /* ── Select dealer (shared) ──────────────────────────────────────────── */

    function selectDealer(dealer, dealers, hiddenDealer, hiddenDealerName, banner, bannerName) {
        const dealerId   = String(dealer.ffl_id || '');
        const dealerName = dealer.business_name || dealer.name || '';
        const dealerData = JSON.stringify({
            ffl_id:          dealer.ffl_id,
            business_name:   dealerName,
            license_number:  dealer.license_number,
            premise_street:  dealer.premise_street,
            premise_city:    dealer.premise_city,
            premise_state:   dealer.premise_state,
            premise_zip_code: dealer.premise_zip_code,
            phone:           dealer.phone,
        });

        // Update hidden fields.
        if (hiddenDealer)     hiddenDealer.value     = dealerData;
        if (hiddenDealerName) hiddenDealerName.value = dealerName;

        // Persist across page reload within the checkout session.
        try {
            sessionStorage.setItem('ffl_selected_dealer',      dealerData);
            sessionStorage.setItem('ffl_selected_dealer_name', dealerName);
        } catch (_) {}

        // Show banner.
        if (banner && bannerName) {
            bannerName.textContent = dealerName;
            banner.style.display = '';
        }

        // Update all select buttons on the page to reflect selection.
        document.querySelectorAll('.ffl-dealer-finder__result').forEach(function (card) {
            card.classList.remove('ffl-dealer-finder__result--selected');
        });
        document.querySelectorAll('.ffl-dealer-finder__select-btn').forEach(function (btn) {
            btn.classList.remove('ffl-dealer-finder__select-btn--selected');
        });

        // Find and highlight the selected card.
        if (dealers) {
            const cards = document.querySelectorAll('.ffl-dealer-finder__result');
            cards.forEach(function (card, idx) {
                if (dealers[idx] && String(dealers[idx].ffl_id) === dealerId) {
                    card.classList.add('ffl-dealer-finder__result--selected');
                    const btn = card.querySelector('.ffl-dealer-finder__select-btn');
                    if (btn) {
                        btn.classList.add('ffl-dealer-finder__select-btn--selected');
                        btn.textContent = '✓ Selected';
                    }
                }
            });
        }
    }

    /* ── Restore from sessionStorage ─────────────────────────────────────── */

    function restoreSelection(hiddenDealer, hiddenDealerName, banner, bannerName) {
        try {
            const saved     = sessionStorage.getItem('ffl_selected_dealer');
            const savedName = sessionStorage.getItem('ffl_selected_dealer_name');

            if (saved && hiddenDealer) {
                hiddenDealer.value = saved;
                if (hiddenDealerName && savedName) hiddenDealerName.value = savedName;

                if (banner && bannerName && savedName) {
                    bannerName.textContent = savedName;
                    banner.style.display   = '';
                }
            }
        } catch (_) {}
    }

    /* ── Clear selection ─────────────────────────────────────────────────── */

    function clearSelection(hiddenDealer, hiddenDealerName, banner) {
        if (hiddenDealer)     hiddenDealer.value     = '';
        if (hiddenDealerName) hiddenDealerName.value = '';
        if (banner)           banner.style.display   = 'none';

        try {
            sessionStorage.removeItem('ffl_selected_dealer');
            sessionStorage.removeItem('ffl_selected_dealer_name');
        } catch (_) {}
    }

    /* ── Read runtime strings from the embedded JSON block ──────────────── */

    function getStrings(root) {
        const defaults = {
            selectBtn: 'Select This Dealer',
            noResults: 'No FFL dealers found near that ZIP code.',
            miles:     'mi',
            license:   'License:',
            phone:     'Phone:',
            email:     'Email:',
        };

        const el = root.querySelector('#ffl-dealer-finder-strings');
        if (!el) return defaults;

        try {
            return Object.assign(defaults, JSON.parse(el.textContent));
        } catch (_) {
            return defaults;
        }
    }
})();
