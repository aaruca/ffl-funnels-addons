/**
 * Offline browser checks for the Wishlist front-end script (algenib-wishlist.js).
 * Requires Playwright + installed Chromium. All data is synthetic; the AJAX
 * endpoint is stubbed and every other request is blocked.
 *
 * NODE_PATH=/path/to/node_modules node tests/smoke/wishlist-ui-smoke.js
 *
 * Covers: state updates only touch wishlist buttons (not other elements that
 * happen to carry data-product-id), per-button label texts, fixed shortcode
 * text, "hide badge at 0" opt-out, correcting a page served from a cache for
 * another visitor (state cookie), and retrying with a fresh token after 403.
 */
/* eslint-env node */
'use strict';
const { chromium } = require('playwright');
const path = require('node:path');
const fs = require('node:fs');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '../..');
const script = fs.readFileSync(path.join(root, 'modules/wishlist/assets/js/algenib-wishlist.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'modules/wishlist/assets/css/algenib-wishlist.css'), 'utf8');
const ORIGIN = 'https://shop.test';

let checks = 0;
function check(condition, label) {
    assert.ok(condition, label);
    checks++;
}

const body = `
<button id="bricks" class="alg-add-to-wishlist" data-product-id="5" data-text-add="Save it" data-text-remove="Saved"><svg viewBox="0 0 24 24"><path d="M1 1"/></svg><span class="ffla-wishlist-label">Save it</span></button>
<button id="fixed" class="alg-add-to-wishlist" data-product-id="6"><svg viewBox="0 0 24 24"><path d="M1 1"/></svg><span class="alg-btn-text">Keep</span></button>
<a id="aws" href="#" class="aws-wishlist--trigger single" data-product-id="7" data-type="ADD"><span>Add to wishlist</span></a>
<button id="other" class="ffla-loadout__add-btn" data-product-id="5">ADD</button>
<div class="alg-wishlist-grid">
  <div class="alg-wishlist-card" id="card9" data-product-id="9"><button class="alg-remove-btn" data-product-id="9">x</button></div>
  <div class="alg-wishlist-card" id="card5" data-product-id="5"><button class="alg-remove-btn" data-product-id="5">x</button></div>
</div>
<span id="badge" class="alg-wishlist-count">0</span>
<span id="badge-keep" class="alg-wishlist-count" data-hide-zero="0">0</span>`;

function html(settings) {
    return `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head><body>${body}
<script>window.AlgWishlistSettings = ${JSON.stringify(settings)};</script>
<script>${script}</script></body></html>`;
}

function baseSettings(extra) {
    return Object.assign({
        ajax_url: ORIGIN + '/wp-admin/admin-ajax.php',
        nonce: 'n1',
        initial_items: [],
        state: '0',
        state_cookie: 'alg_wishlist_state',
        shop_url: ORIGIN + '/shop/',
        i18n: {
            added: 'Added to Wishlist', removed: 'Removed from Wishlist',
            text_add: 'Add to wishlist', text_remove: 'Remove from wishlist',
            empty_wishlist: 'Your wishlist is currently empty.', return_to_shop: 'Return to Shop',
        },
    }, extra || {});
}

async function openPage(browser, settings, cookie, handler) {
    const context = await browser.newContext();
    if (cookie !== null) {
        await context.addCookies([{ name: 'alg_wishlist_state', value: cookie, url: ORIGIN }]);
    }
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const requests = [];
    await context.route('**/*', async route => {
        const url = route.request().url();
        if (url === ORIGIN + '/') {
            return route.fulfill({ status: 200, contentType: 'text/html', body: html(settings) });
        }
        if (url.endsWith('/wp-admin/admin-ajax.php')) {
            const form = {};
            const raw = route.request().postDataBuffer() || Buffer.from('');
            // multipart/form-data from FormData: pull name/value pairs.
            const text = raw.toString('utf8');
            const re = /name="([^"]+)"\r\n\r\n([^\r]*)\r\n/g;
            let m;
            while ((m = re.exec(text))) form[m[1]] = m[2];
            requests.push(form);
            const reply = handler(form, requests.length);
            return route.fulfill({ status: reply.status || 200, contentType: 'application/json', body: JSON.stringify(reply.body) });
        }
        return route.abort();
    });
    await page.goto(ORIGIN + '/');
    await page.waitForLoadState('domcontentloaded');
    return { page, context, requests, errors };
}

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.FFLA_TEST_BROWSER_CHANNEL ? { channel: process.env.FFLA_TEST_BROWSER_CHANNEL } : {}) });
    try {
        /* 1. Toggling: scoped state, labels, badges. */
        {
            let saved = [];
            const { page, context, requests, errors } = await openPage(browser, baseSettings(), null, form => {
                if (form.action === 'alg_add_to_wishlist') {
                    const id = form.product_id;
                    const add = form.todo ? form.todo === 'add' : saved.indexOf(id) === -1;
                    saved = add ? saved.concat([id]) : saved.filter(x => x !== id);
                    return { body: { success: true, data: { status: add ? 'added' : 'removed', count: saved.length, state: saved.length ? 'h' + saved.join('') : '0' } } };
                }
                return { body: { success: false } };
            });

            check(requests.length === 0, 'no request on load when the cookie and the page agree (no list)');
            check(await page.$eval('#badge', el => el.classList.contains('hidden')), 'badge hidden at 0');
            check(!(await page.$eval('#badge-keep', el => el.classList.contains('hidden'))), 'badge with data-hide-zero="0" stays visible at 0');

            await page.click('#bricks');
            await page.waitForFunction(() => document.getElementById('bricks').classList.contains('active') && !document.getElementById('bricks').classList.contains('loading'));
            check(await page.$eval('#bricks .ffla-wishlist-label', el => el.textContent) === 'Saved', 'Bricks button shows its own Remove text');
            check(!(await page.$eval('#other', el => el.classList.contains('active') || el.hasAttribute('title'))), 'non-wishlist element with the same data-product-id is untouched');
            check(!(await page.$eval('#card5', el => el.classList.contains('active') || el.hasAttribute('title'))), 'wishlist page card is not treated as a button');
            check(await page.$eval('#badge', el => el.textContent === '1' && !el.classList.contains('hidden')), 'badge shows 1');
            check(await page.evaluate(() => AlgWishlistSettings.initial_items.indexOf('5') !== -1), 'saved IDs kept current for other scripts (SnapFind)');

            await page.click('#bricks');
            await page.waitForFunction(() => !document.getElementById('bricks').classList.contains('active') && !document.getElementById('bricks').classList.contains('loading'));
            check(await page.$eval('#bricks .ffla-wishlist-label', el => el.textContent) === 'Save it', 'Bricks button shows its own Add text again');
            check(await page.$eval('#badge-keep', el => el.textContent === '0' && !el.classList.contains('hidden')), 'kept badge visible at 0 after an update');
            check(await page.$eval('#badge', el => el.classList.contains('hidden')), 'default badge hidden at 0 after an update');

            await page.click('#fixed');
            await page.waitForFunction(() => document.getElementById('fixed').classList.contains('active'));
            check(await page.$eval('#fixed .alg-btn-text', el => el.textContent) === 'Keep', 'fixed shortcode text is not replaced');

            await page.click('#aws');
            await page.waitForFunction(() => document.getElementById('aws').classList.contains('active'));
            check(await page.$eval('#aws', el => el.querySelector('span').textContent === 'Remove from wishlist' && el.getAttribute('data-type') === 'REMOVE'), 'link-style button switches text and data-type');

            const toastBg = await page.$eval('#alg-wishlist-toast', el => getComputedStyle(el).backgroundColor);
            check(toastBg === 'rgb(255, 67, 67)', 'toast is styled from the stylesheet (overridable by Custom CSS)');
            check(errors.length === 0, 'no page errors (toggle): ' + errors.join('; '));
            await context.close();
        }

        /* 2. Cached page from another visitor, this visitor has no list (cookie 0). */
        {
            const { page, context, requests, errors } = await openPage(browser, baseSettings({ initial_items: ['5', '9'], state: 'abc' }), '0', () => ({ body: { success: false } }));
            await page.waitForFunction(() => document.querySelector('.alg-wishlist-empty'));
            check(requests.length === 0, 'cookie "0" fixes the page without a request');
            check(!(await page.$eval('#bricks', el => el.classList.contains('active'))), 'other visitor\'s heart cleared');
            check(await page.$$eval('.alg-wishlist-card', els => els.length) === 0, 'other visitor\'s wishlist cards removed');
            check(await page.$eval('.alg-wishlist-empty a', el => el.textContent === 'Return to Shop' && el.href === 'https://shop.test/shop/'), 'empty state has Return to Shop');
            check(errors.length === 0, 'no page errors (cookie 0)');
            await context.close();
        }

        /* 3. Cookie disagrees and is not empty: ask the server. */
        {
            const { page, context, requests, errors } = await openPage(browser, baseSettings({ initial_items: ['9'], state: 'h9' }), 'h6', form => {
                if (form.action === 'alg_wishlist_state') {
                    return { body: { success: true, data: { items: [6], count: 1, state: 'h6', nonce: 'n2' } } };
                }
                return { body: { success: false } };
            });
            await page.waitForFunction(() => document.getElementById('fixed').classList.contains('active'));
            check(requests.length === 1 && requests[0].action === 'alg_wishlist_state', 'one state request');
            check(await page.$$eval('.alg-wishlist-card', els => els.length) === 0, 'card not in the real list removed');
            check(await page.evaluate(() => AlgWishlistSettings.nonce) === 'n2', 'fresh token stored');
            check(await page.$eval('#badge', el => el.textContent) === '1', 'count corrected');
            check(errors.length === 0, 'no page errors (state fetch)');
            await context.close();
        }

        /* 4. Expired token on a cached page: get a new one and retry once. */
        {
            const { page, context, requests, errors } = await openPage(browser, baseSettings(), null, form => {
                if (form.action === 'alg_wishlist_state') {
                    return { body: { success: true, data: { items: [], count: 0, state: '0', nonce: 'fresh' } } };
                }
                if (form.nonce !== 'fresh') {
                    return { status: 403, body: -1 };
                }
                return { body: { success: true, data: { status: 'added', count: 1, state: 'h5' } } };
            });
            await page.click('#bricks');
            await page.waitForFunction(() => document.getElementById('bricks').classList.contains('active') && !document.getElementById('bricks').classList.contains('loading'));
            check(requests.map(r => r.action).join(',') === 'alg_add_to_wishlist,alg_wishlist_state,alg_add_to_wishlist', 'retried after fetching a fresh token');
            check(requests[2].nonce === 'fresh', 'retry used the fresh token');
            check(errors.length === 0, 'no page errors (retry)');
            await context.close();
        }
    } finally {
        await browser.close();
    }
    console.log(`wishlist-ui-smoke: ${checks} checks passed`);
})().catch(error => {
    console.error(error);
    process.exit(1);
});
