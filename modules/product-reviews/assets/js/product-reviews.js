(function () {
    if (typeof window.fflaProductReviews === 'undefined') {
        return;
    }

    /**
     * Both buttons for one review live in the same group and settle together.
     * Falls back to the button itself so markup cached before the group wrapper
     * existed still updates its own count.
     */
    function scopeFor(button) {
        var group = button.closest ? button.closest('.ffla-review-helpful-group') : null;
        return group || button;
    }

    function paintCounts(scope, data) {
        if (!scope || !data) {
            return;
        }
        var yes = scope.querySelector('.ffla-review-helpful__count');
        if (yes && typeof data.count !== 'undefined') {
            yes.textContent = String(data.count);
        }
        var no = scope.querySelector('.ffla-review-helpful__count-no');
        if (no && typeof data.countNo !== 'undefined') {
            no.textContent = String(data.countNo);
        }
    }

    function settle(scope, button) {
        var buttons = scope === button ? [button] : scope.querySelectorAll('.ffla-review-helpful');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].setAttribute('aria-disabled', 'true');
        }
        button.classList.add('is-done');
    }

    function i18n(key) {
        var strings = window.fflaProductReviews.i18n || {};
        return strings[key] || '';
    }

    function showMessage(scope, button, text, isError) {
        var host = scope === button ? button.parentNode : scope;
        if (!host || !text) {
            return;
        }
        var el = host.querySelector('.ffla-review-helpful__message');
        if (!el) {
            el = document.createElement('span');
            el.className = 'ffla-review-helpful__message';
            el.setAttribute('role', 'status');
            host.appendChild(el);
        }
        el.textContent = text;
        el.classList.toggle('is-error', !!isError);
    }

    function sendVote(button, scope, retried) {
        var formData = new FormData();
        formData.append('action', 'ffla_vote_review_helpful');
        formData.append('nonce', window.fflaProductReviews.nonce);
        formData.append('comment_id', button.getAttribute('data-comment-id'));
        formData.append('vote', button.getAttribute('data-vote') || 'yes');

        function fail(text) {
            button.removeAttribute('data-ffla-pending');
            showMessage(scope, button, text || i18n('voteError'), true);
        }

        fetch(window.fflaProductReviews.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return null;
                });
            })
            .then(function (json) {
                var data = json && typeof json === 'object' && json.data ? json.data : {};

                if (json && json.success) {
                    button.removeAttribute('data-ffla-pending');
                    paintCounts(scope, data);
                    settle(scope, button);
                    showMessage(scope, button, data.throttled ? data.message : i18n('voteSaved'), false);
                    return;
                }

                // A cached page can carry an expired nonce; the server sends a
                // fresh one with the refusal, so try once more with it.
                if (!retried && data.code === 'invalid_nonce' && data.nonce) {
                    window.fflaProductReviews.nonce = data.nonce;
                    sendVote(button, scope, true);
                    return;
                }

                fail(data.message);
            })
            .catch(function () {
                fail('');
            });
    }

    function onVoteClick(button) {
        var scope = scopeFor(button);

        // One vote per review: once either direction lands, both are spent.
        if (button.getAttribute('aria-disabled') === 'true' || button.getAttribute('data-ffla-pending') === '1') {
            return;
        }

        if (!button.getAttribute('data-comment-id')) {
            return;
        }

        button.setAttribute('data-ffla-pending', '1');
        sendVote(button, scope, false);
    }

    function syncStarDisplay(root) {
        var hover = root.getAttribute('data-hover');
        var checked = root.querySelector('input[type="radio"]:checked');
        var v = '';
        if (hover !== null && hover !== '') {
            v = hover;
        } else if (checked) {
            v = checked.value;
        }
        root.setAttribute('data-display', v);
    }

    function initStarRatingInputs() {
        var roots = document.querySelectorAll('[data-ffla-stars]');
        roots.forEach(function (root) {
            if (root.getAttribute('data-ffla-stars-init') === '1') {
                return;
            }
            root.setAttribute('data-ffla-stars-init', '1');
            syncStarDisplay(root);
            root.addEventListener('change', function () {
                syncStarDisplay(root);
            });
            root.addEventListener('mouseleave', function () {
                root.removeAttribute('data-hover');
                syncStarDisplay(root);
            });
            root.querySelectorAll('.ffla-star-rating-input__label').forEach(function (label) {
                label.addEventListener('mouseenter', function (ev) {
                    var el = ev.currentTarget;
                    var fid = el.getAttribute('for');
                    var input = fid ? document.getElementById(fid) : null;
                    if (!input || !input.value) {
                        return;
                    }
                    root.setAttribute('data-hover', input.value);
                    syncStarDisplay(root);
                });
            });
        });
    }

    function initMediaUploadZones() {
        var zones = document.querySelectorAll('[data-ffla-media-upload]');
        var fu = (typeof window.fflaProductReviews.fileUpload === 'object' && window.fflaProductReviews.fileUpload) || {};
        var maxFiles = parseInt(fu.maxCount, 10) || 3;
        var maxBytes = parseInt(fu.maxBytes, 10) || 5 * 1024 * 1024;

        zones.forEach(function (zone) {
            if (zone.getAttribute('data-ffla-media-init') === '1') {
                return;
            }
            zone.setAttribute('data-ffla-media-init', '1');

            var input = zone.querySelector('.ffla-review-form__file-input');
            var btn = zone.querySelector('.ffla-review-form__file-add');
            var list = zone.querySelector('.ffla-review-form__file-list');
            if (!input || !list) {
                return;
            }

            /** After the browser opens the file dialog, `input.files` is only the new pick — merge with prior. */
            var existingFiles = [];

            function applyFiles(files) {
                var dt = new DataTransfer();
                files.forEach(function (f) {
                    dt.items.add(f);
                });
                input.files = dt.files;
                existingFiles = files.slice();
                renderList();
            }

            function renderList() {
                var files = existingFiles;
                list.innerHTML = '';
                if (!files.length) {
                    list.hidden = true;
                    return;
                }
                list.hidden = false;
                files.forEach(function (file, index) {
                    var li = document.createElement('li');
                    li.className = 'ffla-review-form__file-item';

                    var nameSpan = document.createElement('span');
                    nameSpan.className = 'ffla-review-form__file-name';
                    nameSpan.textContent = file.name;

                    var rm = document.createElement('button');
                    rm.type = 'button';
                    rm.className = 'ffla-review-form__file-remove';
                    rm.setAttribute('aria-label', fu.removeLabel || 'Remove');
                    rm.setAttribute('title', fu.removeLabel || 'Remove');
                    rm.textContent = '\u00d7';

                    rm.addEventListener('click', function () {
                        var next = existingFiles.slice();
                        next.splice(index, 1);
                        applyFiles(next);
                    });

                    li.appendChild(nameSpan);
                    li.appendChild(rm);
                    list.appendChild(li);
                });
            }

            input.addEventListener('change', function () {
                var picked = Array.prototype.slice.call(input.files || []);
                input.value = '';
                var merged = existingFiles.concat(picked);
                var hadTooMany = merged.length > maxFiles;
                if (hadTooMany) {
                    merged = merged.slice(0, maxFiles);
                    if (fu.maxFiles) {
                        window.alert(fu.maxFiles);
                    }
                }
                var skippedLarge = false;
                merged = merged.filter(function (f) {
                    if (f.size > maxBytes) {
                        skippedLarge = true;
                        return false;
                    }
                    return true;
                });
                if (skippedLarge && fu.fileTooLarge) {
                    window.alert(fu.fileTooLarge);
                }
                if (merged.length > maxFiles) {
                    merged = merged.slice(0, maxFiles);
                }
                applyFiles(merged);
            });

            if (btn) {
                btn.addEventListener('click', function () {
                    input.click();
                });
            }

            renderList();
        });
    }

    /* ------------------------------------------------------------------
     * Review lists: star filter, photos filter, sort, search, Show more
     * ---------------------------------------------------------------- */

    /**
     * CAPTCHA widgets in HTML added after the page loaded (answer forms from
     * "Show more" or a search): the providers only draw the widgets that
     * were on the page at load, and inline scripts in inserted HTML do not
     * run.
     */
    function renderCaptchas(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        var providers = [
            ['.cf-turnstile', window.turnstile],
            ['.h-captcha', window.hcaptcha],
            ['.g-recaptcha', window.grecaptcha]
        ];
        providers.forEach(function (provider) {
            var api = provider[1];
            if (!api || typeof api.render !== 'function') {
                return;
            }
            Array.prototype.forEach.call(root.querySelectorAll(provider[0]), function (el) {
                if (el.childElementCount > 0 || el.getAttribute('data-ffla-rendered') === '1') {
                    return;
                }
                el.setAttribute('data-ffla-rendered', '1');
                var params = { sitekey: el.getAttribute('data-sitekey') || '' };
                if (el.getAttribute('data-action')) {
                    params.action = el.getAttribute('data-action');
                }
                try {
                    api.render(el, params);
                } catch (e) {
                    // The provider refused (already drawn, bad key): leave it.
                }
            });
        });
    }

    function post(params) {
        var formData = new FormData();
        Object.keys(params).forEach(function (key) {
            var value = params[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    formData.append(key + '[]', String(item));
                });
            } else if (typeof value === 'boolean') {
                formData.append(key, value ? '1' : '0');
            } else if (value !== null && typeof value !== 'undefined') {
                formData.append(key, String(value));
            }
        });

        return fetch(window.fflaProductReviews.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (response) {
            return response.json();
        });
    }

    function initReviewList(body) {
        if (body.getAttribute('data-ffla-reviews-init') === '1') {
            return;
        }
        body.setAttribute('data-ffla-reviews-init', '1');

        var config;
        try {
            config = JSON.parse(body.getAttribute('data-ffla-reviews') || '{}');
        } catch (e) {
            return;
        }

        var root = (body.closest && body.closest('.ffla-reviews-list')) || body;
        var items = body.querySelector('[data-ffla-items]');
        var toolbar = body.querySelector('[data-ffla-toolbar]');
        var more = body.querySelector('[data-ffla-more]');
        var status = body.querySelector('[data-ffla-status]');
        var sort = body.querySelector('[data-ffla-sort]');
        var search = body.querySelector('[data-ffla-search]');
        var mediaChip = body.querySelector('[data-ffla-media-filter]');
        var bars = root.querySelectorAll('[data-ffla-filter-rating]');

        if (!items) {
            return;
        }

        var state = {
            rating: 0,
            withMedia: !!config.withMedia,
            orderBy: config.orderBy || 'recent',
            search: '',
            page: 1,
            busy: false,
            queued: false
        };

        if (toolbar) {
            toolbar.hidden = false;
        }

        function setStatus(text) {
            if (!status) {
                status = document.createElement('p');
                status.className = 'ffla-reviews-list__status';
                status.setAttribute('data-ffla-status', '');
                status.setAttribute('aria-live', 'polite');
                items.parentNode.insertBefore(status, more || null);
            }
            status.textContent = text || '';
        }

        function filtered() {
            return state.rating > 0 || state.search !== '' || state.withMedia !== !!config.withMedia;
        }

        function paintFilters() {
            body.querySelectorAll('[data-ffla-rating]').forEach(function (chip) {
                var on = parseInt(chip.getAttribute('data-ffla-rating'), 10) === state.rating;
                chip.classList.toggle('is-active', on);
                chip.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            bars.forEach(function (bar) {
                var on = parseInt(bar.getAttribute('data-ffla-filter-rating'), 10) === state.rating;
                bar.classList.toggle('is-active', on);
                bar.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            if (mediaChip) {
                mediaChip.classList.toggle('is-active', state.withMedia);
                mediaChip.setAttribute('aria-pressed', state.withMedia ? 'true' : 'false');
            }
        }

        function showEmpty(text) {
            items.innerHTML = '';
            var empty = document.createElement('div');
            empty.className = 'ffla-reviews-list__empty';
            var p = document.createElement('p');
            p.textContent = text;
            empty.appendChild(p);
            if (filtered()) {
                var clear = document.createElement('button');
                clear.type = 'button';
                clear.className = 'ffla-reviews-list__clear';
                clear.textContent = i18n('clearFilters');
                clear.addEventListener('click', function () {
                    state.rating = 0;
                    state.search = '';
                    state.withMedia = !!config.withMedia;
                    if (search) {
                        var input = search.querySelector('input');
                        if (input) {
                            input.value = '';
                        }
                    }
                    load(false);
                });
                empty.appendChild(clear);
            }
            items.appendChild(empty);
        }

        function load(append) {
            if (state.busy) {
                // A filter, sort or search changed while a request is out:
                // load again with the new state once it is back.
                if (!append) {
                    state.queued = true;
                }
                return;
            }
            state.busy = true;
            body.setAttribute('aria-busy', 'true');
            if (more) {
                more.disabled = true;
            }
            if (!append) {
                state.page = 1;
                setStatus(i18n('loading'));
            }

            post({
                action: 'ffla_load_reviews',
                products: config.products || [],
                categories: config.categories || [],
                perPage: config.perPage || 5,
                minRating: config.minRating || 0,
                showProduct: !!config.showProduct,
                rating: state.rating,
                withMedia: state.withMedia,
                orderBy: state.orderBy,
                search: state.search,
                page: append ? state.page + 1 : 1
            })
                .then(function (json) {
                    if (state.queued) {
                        return; // Answers the old filters; the new load follows.
                    }
                    if (!json || !json.success || !json.data) {
                        throw new Error('bad response');
                    }
                    var data = json.data;
                    if (append) {
                        state.page += 1;
                        items.insertAdjacentHTML('beforeend', data.html || '');
                    } else if (data.html) {
                        items.innerHTML = data.html;
                    } else {
                        showEmpty(data.status || '');
                    }
                    if (more) {
                        more.hidden = !data.hasMore;
                    }
                    setStatus((data.html || append) ? data.status : '');
                    paintFilters();
                })
                .catch(function () {
                    if (!state.queued) {
                        setStatus(i18n('loadError'));
                    }
                })
                .then(function () {
                    state.busy = false;
                    body.removeAttribute('aria-busy');
                    if (more) {
                        more.disabled = false;
                    }
                    if (state.queued) {
                        state.queued = false;
                        load(false);
                    }
                });
        }

        function pickRating(stars) {
            state.rating = state.rating === stars ? 0 : stars;
            paintFilters();
            load(false);
        }

        body.querySelectorAll('[data-ffla-rating]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var stars = parseInt(chip.getAttribute('data-ffla-rating'), 10) || 0;
                state.rating = stars;
                paintFilters();
                load(false);
            });
        });

        // The summary bars filter too, but only when the toolbar is there.
        if (toolbar && body.querySelector('[data-ffla-rating]')) {
            bars.forEach(function (bar) {
                var stars = parseInt(bar.getAttribute('data-ffla-filter-rating'), 10) || 0;
                bar.classList.add('is-interactive');
                bar.addEventListener('click', function () {
                    pickRating(stars);
                });
                bar.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        pickRating(stars);
                    }
                });
            });
        } else {
            bars.forEach(function (bar) {
                bar.removeAttribute('role');
                bar.removeAttribute('tabindex');
                bar.removeAttribute('aria-pressed');
                bar.removeAttribute('aria-label');
            });
        }

        if (mediaChip) {
            mediaChip.addEventListener('click', function () {
                state.withMedia = !state.withMedia;
                paintFilters();
                load(false);
            });
        }

        if (sort) {
            sort.addEventListener('change', function () {
                state.orderBy = sort.value;
                load(false);
            });
        }

        if (search) {
            search.addEventListener('submit', function (event) {
                event.preventDefault();
                var input = search.querySelector('input');
                state.search = input ? input.value.trim() : '';
                load(false);
            });
        }

        if (more) {
            more.addEventListener('click', function () {
                load(true);
            });
        }
    }

    function initReviewLists() {
        document.querySelectorAll('[data-ffla-reviews]').forEach(initReviewList);
    }

    /* ------------------------------------------------------------------
     * Product Q&A: search and Show more
     * ---------------------------------------------------------------- */

    function initQna(body) {
        if (body.getAttribute('data-ffla-qna-init') === '1') {
            return;
        }
        body.setAttribute('data-ffla-qna-init', '1');

        var config;
        try {
            config = JSON.parse(body.getAttribute('data-ffla-qna') || '{}');
        } catch (e) {
            return;
        }

        var items = body.querySelector('[data-ffla-qna-items]');
        var more = body.querySelector('[data-ffla-qna-more]');
        var search = body.querySelector('[data-ffla-qna-search]');
        var state = { page: 1, search: '', busy: false, queued: false };

        if (!items) {
            return;
        }
        if (search) {
            search.hidden = false;
        }

        function load(append) {
            if (state.busy) {
                if (!append) {
                    state.queued = true;
                }
                return;
            }
            state.busy = true;
            body.setAttribute('aria-busy', 'true');

            post({
                action: 'ffla_load_questions',
                product: config.product,
                perPage: config.perPage || 5,
                page: append ? state.page + 1 : 1,
                search: state.search
            })
                .then(function (json) {
                    if (state.queued) {
                        return;
                    }
                    if (!json || !json.success || !json.data) {
                        throw new Error('bad response');
                    }
                    var data = json.data;
                    if (append) {
                        state.page += 1;
                        items.insertAdjacentHTML('beforeend', data.html || '');
                        renderCaptchas(items);
                    } else {
                        state.page = 1;
                        if (data.html) {
                            items.innerHTML = data.html;
                            renderCaptchas(items);
                        } else {
                            items.innerHTML = '';
                            var p = document.createElement('p');
                            p.className = 'ffla-qna__empty';
                            p.textContent = data.status || '';
                            items.appendChild(p);
                        }
                    }
                    if (more) {
                        more.hidden = !data.hasMore;
                    }
                })
                .catch(function () {
                    if (state.queued) {
                        return;
                    }
                    var p = document.createElement('p');
                    p.className = 'ffla-qna__empty';
                    p.textContent = i18n('loadError');
                    items.appendChild(p);
                })
                .then(function () {
                    state.busy = false;
                    body.removeAttribute('aria-busy');
                    if (state.queued) {
                        state.queued = false;
                        load(false);
                    }
                });
        }

        if (more) {
            more.addEventListener('click', function () {
                load(true);
            });
        }

        if (search) {
            search.addEventListener('submit', function (event) {
                event.preventDefault();
                var input = search.querySelector('input');
                state.search = input ? input.value.trim() : '';
                load(false);
            });
        }
    }

    function initQnas() {
        document.querySelectorAll('[data-ffla-qna]').forEach(initQna);
    }

    /* ------------------------------------------------------------------
     * Photo lightbox
     * ---------------------------------------------------------------- */

    var lightbox = null;

    function buildLightbox() {
        var dialog = document.createElement('dialog');
        dialog.className = 'ffla-lightbox';
        dialog.setAttribute('aria-label', i18n('lightboxLabel'));
        dialog.innerHTML = '<div class="ffla-lightbox__frame">'
            + '<img class="ffla-lightbox__image" alt="">'
            + '<p class="ffla-lightbox__counter" aria-live="polite"></p>'
            + '<button type="button" class="ffla-lightbox__close"></button>'
            + '<button type="button" class="ffla-lightbox__nav ffla-lightbox__nav--prev">&#8249;</button>'
            + '<button type="button" class="ffla-lightbox__nav ffla-lightbox__nav--next">&#8250;</button>'
            + '</div>';
        document.body.appendChild(dialog);

        var state = { links: [], index: 0, opener: null };
        var image = dialog.querySelector('.ffla-lightbox__image');
        var counter = dialog.querySelector('.ffla-lightbox__counter');
        var close = dialog.querySelector('.ffla-lightbox__close');
        var prev = dialog.querySelector('.ffla-lightbox__nav--prev');
        var next = dialog.querySelector('.ffla-lightbox__nav--next');

        close.setAttribute('aria-label', i18n('close'));
        close.innerHTML = '&times;';
        prev.setAttribute('aria-label', i18n('previous'));
        next.setAttribute('aria-label', i18n('next'));

        function show(index) {
            var count = state.links.length;
            state.index = (index + count) % count;
            var link = state.links[state.index];
            var thumb = link.querySelector('img');
            image.src = link.getAttribute('href');
            image.alt = (thumb && thumb.getAttribute('alt')) || i18n('lightboxLabel');
            counter.textContent = count > 1
                ? (i18n('photoOf') || '%1$s / %2$s').replace('%1$s', String(state.index + 1)).replace('%2$s', String(count))
                : '';
            prev.hidden = count < 2;
            next.hidden = count < 2;
        }

        function shut() {
            if (typeof dialog.close === 'function' && dialog.open) {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
                dialog.classList.remove('is-fallback');
            }
        }

        dialog.addEventListener('close', function () {
            image.removeAttribute('src');
            if (state.opener && typeof state.opener.focus === 'function') {
                state.opener.focus();
            }
        });
        // A click on the backdrop lands on the dialog itself.
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                shut();
            }
        });
        dialog.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') {
                show(state.index - 1);
            } else if (event.key === 'ArrowRight') {
                show(state.index + 1);
            } else if (event.key === 'Escape' && !dialog.showModal) {
                shut();
            }
        });
        close.addEventListener('click', shut);
        prev.addEventListener('click', function () {
            show(state.index - 1);
        });
        next.addEventListener('click', function () {
            show(state.index + 1);
        });

        return {
            open: function (links, index, opener) {
                state.links = links;
                state.opener = opener;
                show(index);
                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                } else {
                    dialog.setAttribute('open', '');
                    dialog.classList.add('is-fallback');
                }
                close.focus();
            }
        };
    }

    function onLightboxClick(event, link) {
        // Let the browser open the photo in a new tab when asked to.
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();

        var group = link.getAttribute('data-ffla-lightbox');
        var card = (link.closest && link.closest('.ffla-review-card')) || document;
        var links = Array.prototype.slice.call(card.querySelectorAll('[data-ffla-lightbox]')).filter(function (el) {
            return el.getAttribute('data-ffla-lightbox') === group;
        });

        if (!lightbox) {
            lightbox = buildLightbox();
        }
        lightbox.open(links, Math.max(0, links.indexOf(link)), link);
    }

    /* ------------------------------------------------------------------
     * Slider
     * ---------------------------------------------------------------- */

    function initSliders() {
        document.querySelectorAll('[data-ffla-slider]').forEach(function (slider) {
            if (slider.getAttribute('data-ffla-slider-init') === '1') {
                return;
            }
            slider.setAttribute('data-ffla-slider-init', '1');

            var track = slider.querySelector('[data-ffla-items]');
            var prev = slider.querySelector('[data-ffla-slider-prev]');
            var next = slider.querySelector('[data-ffla-slider-next]');
            if (!track || !prev || !next) {
                return;
            }

            function step() {
                var card = track.querySelector('.ffla-review-card');
                var gap = parseFloat(window.getComputedStyle(track).columnGap) || 0;
                return card ? card.getBoundingClientRect().width + gap : track.clientWidth;
            }

            function update() {
                prev.hidden = track.scrollLeft <= 2;
                next.hidden = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
            }

            prev.addEventListener('click', function () {
                track.scrollBy({ left: -step(), behavior: 'smooth' });
            });
            next.addEventListener('click', function () {
                track.scrollBy({ left: step(), behavior: 'smooth' });
            });

            var ticking = false;
            track.addEventListener('scroll', function () {
                if (ticking) {
                    return;
                }
                ticking = true;
                window.requestAnimationFrame(function () {
                    ticking = false;
                    update();
                });
            });
            window.addEventListener('resize', update);
            update();
        });
    }

    /* ------------------------------------------------------------------
     * reCAPTCHA v3: a token is fetched when the form is sent
     * ---------------------------------------------------------------- */

    // Every send gets a fresh token: a token works once, and a page restored
    // from the back/forward cache still holds the one already used.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var field = form && form.querySelector ? form.querySelector('[data-ffla-recaptcha-v3]') : null;
        if (!field || form.getAttribute('data-ffla-recaptcha-busy') === '1') {
            return;
        }
        if (!window.grecaptcha || typeof window.grecaptcha.ready !== 'function') {
            return;
        }

        event.preventDefault();
        form.setAttribute('data-ffla-recaptcha-busy', '1');
        window.grecaptcha.ready(function () {
            window.grecaptcha.execute(field.getAttribute('data-ffla-recaptcha-v3'), { action: field.getAttribute('data-action') || 'submit' })
                .then(function (token) {
                    field.value = token;
                    form.submit();
                }, function () {
                    form.removeAttribute('data-ffla-recaptcha-busy');
                });
        });
    }, true);

    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) {
            return;
        }
        Array.prototype.forEach.call(document.querySelectorAll('[data-ffla-recaptcha-busy]'), function (form) {
            form.removeAttribute('data-ffla-recaptcha-busy');
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-ffla-recaptcha-v3]'), function (field) {
            field.value = '';
        });
    });

    /* ------------------------------------------------------------------
     * Boot
     * ---------------------------------------------------------------- */

    // Delegated, so cards added by "Show more" or a filter work too.
    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) {
            return;
        }

        var vote = target.closest('.ffla-review-helpful');
        if (vote) {
            onVoteClick(vote);
            return;
        }

        var photo = target.closest('[data-ffla-lightbox]');
        if (photo) {
            onLightboxClick(event, photo);
        }
    });

    function init() {
        initStarRatingInputs();
        initMediaUploadZones();
        initReviewLists();
        initQnas();
        initSliders();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
