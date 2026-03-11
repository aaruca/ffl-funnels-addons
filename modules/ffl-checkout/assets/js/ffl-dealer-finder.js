/**
 * FFL Dealer Finder — Bricks Widget JS.
 *
 * Full-parity port of the g-ffl-checkout widget adapted for Bricks Builder.
 * All API calls go through the WordPress AJAX proxy — no API key in the browser.
 *
 * Config object `fflDealerFinderConfig` is injected via wp_localize_script:
 *   ajaxUrl, nonce, includeMap, isBuilder, cartHasFflItems,
 *   localPickupLicense, candrEnabled, blacklist
 *
 * @package FFL_Funnels_Addons
 */

(function () {
    'use strict';

    /* ── Config ────────────────────────────────────────────────────────── */

    var CFG = (typeof fflDealerFinderConfig !== 'undefined') ? fflDealerFinderConfig : {};

    /* ── State ─────────────────────────────────────────────────────────── */

    var mapInstance   = null;
    var markers       = {};
    var markersList   = [];
    var isLocalPickup = false;

    /* ── Boot ──────────────────────────────────────────────────────────── */

    function boot() {
        var container = document.getElementById('ffl_container');
        if (!container || container.dataset.fflInited === '1') return;
        container.dataset.fflInited = '1';

        // Builder preview or empty cart — nothing to do.
        if (CFG.isBuilder === '1' || container.dataset.fflEmptyCart === '1') return;

        initSearchFunctionality();
        initSpecialButtons();
        initCandrFunctionality();

        if (shouldShowMap()) {
            initMapbox();
        } else {
            hideMap();
        }

        // Restore previous selection.
        restoreSelection();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // Bricks AJAX re-render hook.
    document.addEventListener('bricks/ajax/query_result/attached', boot);

    /* ── AJAX helpers ──────────────────────────────────────────────────── */

    function ajaxPost(action, data) {
        return new Promise(function (resolve, reject) {
            var params = 'action=' + encodeURIComponent(action) +
                         '&security=' + encodeURIComponent(CFG.nonce);

            if (data) {
                Object.keys(data).forEach(function (k) {
                    params += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
                });
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', CFG.ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                if (xhr.status === 200) {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        res.success ? resolve(res.data) : reject(new Error(res.data || 'Request failed'));
                    } catch (e) {
                        reject(new Error('Invalid response'));
                    }
                } else {
                    reject(new Error('HTTP ' + xhr.status));
                }
            };
            xhr.send(params);
        });
    }

    function ajaxFormData(action, formData) {
        return new Promise(function (resolve, reject) {
            formData.append('action', action);
            formData.append('security', CFG.nonce);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', CFG.ajaxUrl, true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                if (xhr.status === 200) {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        res.success ? resolve(res.data) : reject(new Error((res.data && res.data.message) || 'Upload failed'));
                    } catch (e) {
                        reject(new Error('Invalid response'));
                    }
                } else {
                    reject(new Error('HTTP ' + xhr.status));
                }
            };
            xhr.send(formData);
        });
    }

    /* ── Map helpers ───────────────────────────────────────────────────── */

    function shouldShowMap() {
        return CFG.includeMap === '1';
    }

    function hideMap() {
        var el = document.getElementById('ffl-map');
        if (el) el.style.display = 'none';
        var attr = document.getElementById('mapbox-attribution-line');
        if (attr) attr.style.display = 'none';
    }

    function initMapbox() {
        var el = document.getElementById('ffl-map');
        if (!el) return;

        // If mapboxgl is already loaded and has a token, create map directly.
        if (typeof mapboxgl !== 'undefined' && mapboxgl.accessToken) {
            createMap();
            return;
        }

        // Fetch token via AJAX, then init.
        ajaxPost('ffl_get_mapbox_token', {})
            .then(function (token) {
                if (typeof mapboxgl !== 'undefined') {
                    mapboxgl.accessToken = token;
                    createMap();
                } else {
                    // Mapbox GL JS should already be enqueued by PHP.
                    // Wait briefly in case it's still loading.
                    var tries = 0;
                    var iv = setInterval(function () {
                        tries++;
                        if (typeof mapboxgl !== 'undefined') {
                            clearInterval(iv);
                            mapboxgl.accessToken = token;
                            createMap();
                        } else if (tries > 30) {
                            clearInterval(iv);
                            console.error('[FFL] Mapbox GL JS not loaded');
                        }
                    }, 200);
                }
            })
            .catch(function (err) {
                console.error('[FFL] Failed to get Mapbox token:', err);
            });
    }

    function createMap() {
        if (mapInstance) return;
        try {
            mapInstance = new mapboxgl.Map({
                container: 'ffl-map',
                style: 'mapbox://styles/garidium/clds8orfo000q01udg0o23pp5',
                center: [-78.16847, 38.21885],
                zoom: 4
            });
            mapInstance.addControl(new mapboxgl.FullscreenControl());

            // Resize when container becomes visible (Bricks accordion, tabs, etc.)
            if ('IntersectionObserver' in window) {
                var obs = new IntersectionObserver(function (entries) {
                    entries.forEach(function (e) {
                        if (e.isIntersecting && mapInstance) mapInstance.resize();
                    });
                }, { threshold: 0.1 });
                var c = document.getElementById('ffl_container');
                if (c) obs.observe(c);
            }
        } catch (err) {
            console.error('[FFL] Map init error:', err);
        }
    }

    function clearMarkers() {
        markersList.forEach(function (m) { if (m && m.remove) m.remove(); });
        markersList = [];
        markers = {};
    }

    function addMarkersAndFitBounds(ffls) {
        if (!shouldShowMap() || !mapInstance) return;
        clearMarkers();

        var bounds = null;
        var count  = 0;

        ffls.forEach(function (ffl) {
            var lat = parseFloat(ffl.lat);
            var lng = parseFloat(ffl.lng);
            if (!(lat > 0 && lng < 0)) return; // US coords only

            var phone = formatPhone(ffl.voice_phone);
            var name  = getDisplayName(ffl);
            var color = ffl.ffl_on_file ? '#28e51f' : '#cfd4ce';

            var popup = '<div>' +
                '<h4 style="font-weight:bold;margin:0 0 4px;">' + escHtml(name) + '</h4>' +
                (ffl.ffl_on_file
                    ? '<span style="color:#09bb00"><b>Preferred Dealer:</b> FFL on-file.</span>'
                    : '<span style="color:red"><b>Dealer Contact Required</b> for Transfer Details.</span>') +
                '<br>' + escHtml(ffl.premise_street) +
                '<br>' + escHtml(ffl.premise_city) + ', ' + escHtml(ffl.premise_state) + ' ' + escHtml(ffl.premise_zip_code) +
                '<br>' + escHtml(phone) +
                (ffl.email ? '<br><a target="_blank" href="mailto:' + escHtml(ffl.email) + '">' + escHtml(ffl.email) + '</a>' : '') +
                '</div>';

            var marker = new mapboxgl.Marker({ color: color })
                .setLngLat([lng, lat])
                .setPopup(new mapboxgl.Popup({ maxWidth: '325px' }).setHTML(popup))
                .addTo(mapInstance);

            marker.getElement().addEventListener('click', function () {
                selectFFL(ffl);
                scrollToFFL(ffl.license_number);
            });

            markers[ffl.license_number] = marker;
            markersList.push(marker);

            var coord = new mapboxgl.LngLat(lng, lat);
            if (count === 0) {
                bounds = new mapboxgl.LngLatBounds(coord, coord);
            } else {
                bounds.extend(coord);
            }
            count++;
        });

        if (count > 0 && bounds) {
            mapInstance.fitBounds(bounds, { padding: 50, maxZoom: 17 });
        }
    }

    function centerMapOnFFL(licenseNumber) {
        if (!shouldShowMap() || !mapInstance || !markers[licenseNumber]) return;
        var ll = markers[licenseNumber].getLngLat();
        mapInstance.flyTo({ center: [ll.lng, ll.lat], zoom: 15, speed: 3.0, essential: true });
    }

    /* ── Search ────────────────────────────────────────────────────────── */

    function initSearchFunctionality() {
        var searchBtn = document.getElementById('ffl-search');
        var zipInput  = document.getElementById('ffl-zip-code');
        var nameInput = document.getElementById('ffl-name-search');
        var radiusEl  = document.getElementById('ffl-radius');

        if (!searchBtn) return;

        function onEnter(e) {
            if (e.key === 'Enter') { e.preventDefault(); searchBtn.click(); }
        }

        if (zipInput)  zipInput.addEventListener('keypress', onEnter);
        if (nameInput) nameInput.addEventListener('keypress', onEnter);
        if (radiusEl)  radiusEl.addEventListener('keypress', onEnter);

        searchBtn.addEventListener('click', function (e) {
            e.preventDefault();
            performSearch();
        });
    }

    function performSearch() {
        var zipInput  = document.getElementById('ffl-zip-code');
        var radiusEl  = document.getElementById('ffl-radius');
        var nameInput = document.getElementById('ffl-name-search');
        var searchBtn = document.getElementById('ffl-search');

        if (!zipInput || !radiusEl) return;

        var zip    = (zipInput.value || '').replace(/\D/g, '').substring(0, 5);
        var radius = radiusEl.value || '5';
        var name   = (nameInput ? nameInput.value : '') || '';

        if (zip.length !== 5) {
            alert('Enter valid zip code!');
            return;
        }

        showLoading();
        if (searchBtn) searchBtn.classList.add('dsbSearch');

        ajaxPost('ffl_search_dealers', {
            search_type: 'location',
            zipcode: zip,
            radius: radius,
            ffl_name: name
        })
        .then(function (response) {
            hideLoading();
            if (searchBtn) searchBtn.classList.remove('dsbSearch');

            var ffls = normalizeResults(response);
            ffls = filterBlacklist(ffls);

            if (ffls.length > 0) {
                displayFFLResults(ffls);
            } else {
                alert('No FFLs found. Please check your inputs and try again.');
            }
        })
        .catch(function (err) {
            hideLoading();
            if (searchBtn) searchBtn.classList.remove('dsbSearch');
            console.error('[FFL] Search error:', err);
            alert('Search failed: ' + err.message);
        });
    }

    function performLicenseSearch(license, notFoundMsg) {
        showLoading();

        ajaxPost('ffl_search_dealers', {
            search_type: 'license',
            license_number: license
        })
        .then(function (response) {
            hideLoading();
            var ffls = normalizeResults(response);
            if (ffls.length > 0) {
                displayFFLResults(ffls);
            } else {
                alert(notFoundMsg);
            }
        })
        .catch(function (err) {
            hideLoading();
            console.error('[FFL] License search error:', err);
            alert('Search failed: ' + err.message);
        });
    }

    function normalizeResults(response) {
        if (response && response.data && Array.isArray(response.data)) return response.data;
        if (response && response.ffls && Array.isArray(response.ffls)) return response.ffls;
        if (Array.isArray(response)) return response;
        return [];
    }

    function filterBlacklist(ffls) {
        var bl = CFG.blacklist;
        if (!Array.isArray(bl) || bl.length === 0) return ffls;
        return ffls.filter(function (f) { return bl.indexOf(f.license_number) === -1; });
    }

    /* ── Display Results ───────────────────────────────────────────────── */

    function displayFFLResults(ffls) {
        var list = document.getElementById('ffl-list');
        if (!list) return;

        list.innerHTML = '';
        list.classList.remove('ffl-hide');

        ffls.forEach(function (ffl) {
            var phone = formatPhone(ffl.voice_phone);
            var name  = getDisplayName(ffl);

            var html = '<div id="' + escAttr(ffl.license_number) + '">' +
                '<button type="button" class="ffl-list-div" data-marker-id="' + escAttr(ffl.license_number) + '">' +
                '<b>' + escHtml(name) + '</b>' +
                (ffl.ffl_on_file
                    ? ' <span style="color:#09bb00;font-size:0.85em;">[FFL On File]</span>'
                    : '') +
                '<br>' + escHtml(ffl.premise_street) + ', ' + escHtml(ffl.premise_city) +
                '<br>' + escHtml(phone) +
                (ffl.email ? ' | <a target="_blank" href="mailto:' + escAttr(ffl.email) + '">' + escHtml(ffl.email) + '</a>' : '') +
                '</button></div>';

            list.insertAdjacentHTML('beforeend', html);

            var btn = list.lastElementChild.querySelector('.ffl-list-div');
            if (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    selectFFL(ffl);
                    if (shouldShowMap()) centerMapOnFFL(ffl.license_number);
                });
            }
        });

        // Map markers.
        addMarkersAndFitBounds(ffls);

        // Click instructions.
        var instr = document.getElementById('ffl-click-instructions');
        if (instr) {
            instr.textContent = 'Click on an FFL dealer below to select them for your order';
            instr.classList.remove('ffl-hide');
            instr.style.background = '#f8d7da';
            instr.style.borderLeft = '4px solid #dc3545';
            instr.style.color = '#721c24';
        }
    }

    /* ── Select FFL ────────────────────────────────────────────────────── */

    function selectFFL(ffl) {
        var name = getDisplayName(ffl);
        var expiration = ffl.expiration_date || ffl.license_expiration_date || ffl.license_expiration || ffl.expiration || '';

        // FFL-specific hidden fields.
        setField('shipping_fflno',       ffl.license_number);
        setField('shipping_fflexp',      expiration);
        setField('shipping_ffl_onfile',  ffl.ffl_on_file ? 'Yes' : 'No');
        setField('shipping_ffl_name',    name);
        setField('shipping_ffl_phone',   ffl.voice_phone);

        // Our custom hidden fields for order meta.
        var dealerData = JSON.stringify({
            ffl_id:           ffl.license_number,
            business_name:    name,
            license_number:   ffl.license_number,
            premise_street:   ffl.premise_street,
            premise_city:     ffl.premise_city,
            premise_state:    ffl.premise_state,
            premise_zip_code: ffl.premise_zip_code,
            phone:            ffl.voice_phone
        });
        setField('ffl_selected_dealer',      dealerData);
        setField('ffl_selected_dealer_name', name);

        // Backup fields for validation.
        ensureHiddenField('ffl_id',            ffl.license_number);
        ensureHiddenField('backup_fflno',      ffl.license_number);
        ensureHiddenField('ffl_license_backup', ffl.license_number);
        ensureHiddenField('backup_fflexp',     expiration);

        // FFL premise address fields (for mixed-cart scenarios).
        setField('shipping_ffl_premise_street', ffl.premise_street);
        setField('shipping_ffl_premise_city',   ffl.premise_city);
        setField('shipping_ffl_premise_state',  ffl.premise_state);
        setField('shipping_ffl_premise_zip',    ffl.premise_zip_code);

        // Populate WooCommerce shipping address fields.
        setField('shipping_address_1', ffl.premise_street);
        setField('shipping_city',      ffl.premise_city);
        setField('shipping_state',     ffl.premise_state);
        setField('shipping_postcode',  ffl.premise_zip_code);
        setField('shipping_country',   'US');
        setField('shipping_phone',     ffl.voice_phone);
        setField('shipping_company',   name);
        setField('shipping_email',     ffl.email);

        // Store in localStorage for persistence across WooCommerce AJAX refreshes.
        try {
            localStorage.setItem('selectedFFL', String(ffl.license_number || ''));
            localStorage.setItem('selectedFFL_data', JSON.stringify(ffl));
        } catch (_) {}

        // Also store in sessionStorage for our own restore.
        try {
            sessionStorage.setItem('ffl_selected_dealer', dealerData);
            sessionStorage.setItem('ffl_selected_dealer_name', name);
        } catch (_) {}

        // Set favorite cookie (7 days).
        setCookie('g_ffl_checkout_favorite_ffl', ffl.license_number, 7);

        // Highlight selected.
        highlightSelectedFFL(ffl.license_number);

        // Trigger WooCommerce checkout update.
        if (typeof jQuery !== 'undefined') {
            jQuery('body').trigger('update_checkout');
        }

        // If local pickup, auto-select shipping method.
        if (isLocalPickup) {
            selectLocalPickupShipping();
            isLocalPickup = false;
        }

        // Enable Place Order button.
        enablePlaceOrder();

        // Update required notice.
        var notice = document.getElementById('ffl-required-notice');
        if (notice) {
            notice.style.background = '#d4edda';
            notice.style.borderColor = '#28a745';
            notice.style.color = '#155724';
            notice.textContent = 'FFL dealer selected - you may now complete your order';
        }

        console.log('[FFL] Selected:', name, 'License:', ffl.license_number);
    }

    function highlightSelectedFFL(license) {
        var all = document.querySelectorAll('.ffl-list-div');
        all.forEach(function (b) { b.classList.remove('selectedFFLDivButton'); });

        var sel = document.querySelector('[data-marker-id="' + license + '"]');
        if (sel) sel.classList.add('selectedFFLDivButton');

        var instr = document.getElementById('ffl-click-instructions');
        if (instr) instr.classList.add('ffl-hide');
    }

    function scrollToFFL(license) {
        var el = document.getElementById(license);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    /* ── Special Buttons ───────────────────────────────────────────────── */

    function initSpecialButtons() {
        // Local Pickup.
        var pickupSection = document.getElementById('ffl-local-pickup-section');
        var pickupBtn     = document.getElementById('ffl-local-pickup-search');
        var pickupLicense = CFG.localPickupLicense || '';

        if (pickupSection && pickupLicense.length === 20) {
            pickupSection.style.display = 'block';
            pickupSection.style.marginBottom = '10px';
        }

        if (pickupBtn) {
            pickupBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (!pickupLicense || pickupLicense.length !== 20) return;
                isLocalPickup = true;
                performLicenseSearch(pickupLicense, 'Local pickup FFL not found.');
            });
        }

        // Favorites.
        var favSection = document.getElementById('ffl-favorite-section');
        var favBtn     = document.getElementById('ffl-favorite-search');
        var favLicense = getCookie('g_ffl_checkout_favorite_ffl') || '';

        if (favSection && favLicense.length === 20) {
            favSection.style.display = 'block';
            favSection.style.marginBottom = '10px';
        }

        if (favBtn) {
            favBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var lic = getCookie('g_ffl_checkout_favorite_ffl') || '';
                if (!lic || lic.length !== 20) { alert('No favorite FFL found.'); return; }
                performLicenseSearch(lic, 'Favorite FFL not found. It may have been removed or updated.');
            });
        }

        // C&R section visibility.
        var candrSection = document.getElementById('ffl-candr-section');
        if (candrSection) {
            candrSection.style.display = (CFG.candrEnabled === '1') ? 'block' : 'none';
        }

        // If C&R already selected via cookie, hide entire widget.
        if (getCookie('g_ffl_checkout_candr_override') === 'Yes') {
            var c = document.getElementById('ffl_container');
            if (c) c.style.display = 'none';
        }
    }

    /* ── C&R ───────────────────────────────────────────────────────────── */

    function initCandrFunctionality() {
        var uploadBtn   = document.getElementById('ffl-candr-override');
        var licenseIn   = document.getElementById('candr_license_number');
        var fileIn      = document.getElementById('candr_upload_filename');
        var uploading   = false;

        // Restore license from cookie.
        var saved = getCookie('candr_license');
        if (saved && licenseIn) {
            licenseIn.value = decodeURIComponent(saved);
            licenseIn.readOnly = true;
        }

        // File input label update.
        if (fileIn) {
            fileIn.addEventListener('change', function () {
                var label = document.getElementById('candrUploadLable');
                if (label && this.files && this.files[0]) {
                    label.textContent = this.files[0].name.substring(0, 15);
                }
            });
        }

        if (!uploadBtn) return;

        uploadBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (uploading) return;

            var license = licenseIn ? licenseIn.value.trim() : '';
            var hasFile = fileIn && fileIn.files && fileIn.files.length > 0;

            if (!license) { alert('Please enter your C&R License Number'); return; }

            // Validate format: X-XX-XXX-03-XX-XXXXX
            var pattern = /^\d{1}-\d{2}-\d{3}-03-[0-9]{1}[A-Z]{1}-[0-9A-Z]{5}$/;
            if (!pattern.test(license)) {
                alert('The C&R License Number must be properly formatted: X-XX-XXX-03-XX-XXXXX');
                return;
            }

            if (!hasFile) { alert('Please select your C&R License file'); return; }

            var file = fileIn.files[0];

            // Validate file type.
            var allowed = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (allowed.indexOf(file.type) === -1) {
                alert('Invalid file type. Please select a PDF, JPEG, PNG, or GIF file.');
                return;
            }

            // Max 2 MB.
            if (file.size > 2 * 1024 * 1024) {
                alert('File too large. Maximum size is 2 MB.');
                return;
            }

            uploading = true;
            uploadBtn.disabled = true;
            uploadBtn.value = 'UPLOADING...';

            var fd = new FormData();
            fd.append('document', file);
            fd.append('license_number', license);

            ajaxFormData('ffl_upload_candr', fd)
                .then(function () {
                    setCookie('g_ffl_checkout_candr_override', 'Yes', 7);
                    setCookie('candr_license', encodeURIComponent(license), 7);

                    alert('C&R Upload Successful. The window will refresh, removing the FFL Selector.');
                    uploading = false;
                    window.location.reload();
                })
                .catch(function (err) {
                    console.error('[FFL] C&R upload failed:', err);
                    alert('There was an error uploading the C&R, please try again.');
                    uploading = false;
                    uploadBtn.disabled = false;
                    uploadBtn.value = 'UPLOAD';
                });
        });
    }

    /* ── Restore Selection ─────────────────────────────────────────────── */

    function restoreSelection() {
        try {
            var saved     = sessionStorage.getItem('ffl_selected_dealer');
            var savedName = sessionStorage.getItem('ffl_selected_dealer_name');

            if (!saved) return;

            var hidden     = document.getElementById('ffl_selected_dealer');
            var hiddenName = document.getElementById('ffl_selected_dealer_name');

            if (hidden)     hidden.value     = saved;
            if (hiddenName) hiddenName.value  = savedName || '';

            // Also restore localStorage key used by cockpit flows.
            var parsed = JSON.parse(saved);
            if (parsed && parsed.ffl_id) {
                localStorage.setItem('selectedFFL', String(parsed.ffl_id));
                localStorage.setItem('selectedFFL_data', saved);
            }
        } catch (_) {}
    }

    /* ── Loading ───────────────────────────────────────────────────────── */

    function showLoading() {
        var el = document.getElementById('floatingBarsG');
        if (el) el.style.display = '';

        var list = document.getElementById('ffl-list');
        if (list) { list.classList.add('ffl-hide'); list.innerHTML = ''; }

        var instr = document.getElementById('ffl-click-instructions');
        if (instr) instr.classList.add('ffl-hide');

        clearMarkers();
    }

    function hideLoading() {
        var el = document.getElementById('floatingBarsG');
        if (el) el.style.display = 'none';
    }

    /* ── Local Pickup Shipping ─────────────────────────────────────────── */

    function selectLocalPickupShipping() {
        var radios = document.querySelectorAll('input[type="radio"][name^="shipping_method"]');
        for (var i = 0; i < radios.length; i++) {
            var r = radios[i];
            var val = r.value.toLowerCase();
            var label = document.querySelector('label[for="' + r.id + '"]');
            var txt = label ? label.textContent.toLowerCase() : '';

            if (val.indexOf('local_pickup') !== -1 || val.indexOf('pickup') !== -1 ||
                txt.indexOf('pickup') !== -1 || txt.indexOf('local') !== -1) {
                r.checked = true;
                if (typeof jQuery !== 'undefined') {
                    jQuery(r).prop('checked', true).trigger('change');
                    jQuery('body').trigger('update_checkout');
                } else {
                    r.dispatchEvent(new Event('change', { bubbles: true }));
                }
                return;
            }
        }
    }

    /* ── Place Order ───────────────────────────────────────────────────── */

    function enablePlaceOrder() {
        if (typeof removeFFLRequiredMessage === 'function') {
            removeFFLRequiredMessage();
        }

        var btn = document.getElementById('place_order');
        if (btn) {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';

            var orig = btn.getAttribute('data-original-text');
            if (orig && btn.textContent.indexOf('Select FFL') !== -1) {
                btn.textContent = orig;
            }
        }
    }

    /* ── Utility ───────────────────────────────────────────────────────── */

    function setField(id, value) {
        var el = document.getElementById(id);
        if (!el) return;
        el.value = value || '';
        el.dispatchEvent(new Event('change', { bubbles: true }));
        el.dispatchEvent(new Event('input',  { bubbles: true }));
    }

    function ensureHiddenField(name, value) {
        var form = document.querySelector('form.checkout') || document.querySelector('form.woocommerce-checkout');
        if (!form) return;
        var input = form.querySelector('input[name="' + name + '"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.appendChild(input);
        }
        input.value = value || '';
    }

    function getDisplayName(ffl) {
        return (ffl.list_name && ffl.list_name.trim()) ||
               (ffl.company_name && ffl.company_name.trim()) ||
               (ffl.business_name && ffl.business_name.trim()) ||
               (ffl.name && ffl.name.trim()) ||
               (ffl.trading_name && ffl.trading_name.trim()) ||
               (ffl.license_name && ffl.license_name.trim()) ||
               ((ffl.first_name && ffl.last_name) ? (ffl.first_name + ' ' + ffl.last_name).trim() : '') ||
               (ffl.license_number ? 'FFL #' + ffl.license_number : 'FFL Dealer');
    }

    function formatPhone(phone) {
        if (!phone) return '';
        var s = String(phone);
        if (s.length === 10) {
            var m = s.match(/^(\d{3})(\d{3})(\d{4})$/);
            if (m) return m[1] + '-' + m[2] + '-' + m[3];
        }
        return s;
    }

    function escHtml(str) {
        if (!str) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    function escAttr(str) {
        return escHtml(str).replace(/"/g, '&quot;');
    }

    function getCookie(name) {
        var eq = name + '=';
        var parts = document.cookie.split(';');
        for (var i = 0; i < parts.length; i++) {
            var c = parts[i].trim();
            if (c.indexOf(eq) === 0) return c.substring(eq.length);
        }
        return '';
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = name + '=' + value + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
    }

    /* ── Bricks script registration ───────────────────────────────────── */
    // Bricks calls window.fflDealerFinder() when the element re-renders in the builder.
    window.fflDealerFinder = boot;

})();
