/**
 * Product Reviews lists in a real browser: toolbar, star / photo filters,
 * sort, search, "Show more", the photo lightbox, votes on cards loaded later,
 * the slider arrows and the grid; and product Q&A (paging, search, votes on
 * answers, the folded ask form).
 *
 * The page markup comes from the module's own renderer and every AJAX call
 * is answered by its own endpoint (product-reviews-list-ui-fixture.php over
 * the in-memory stubs), so no WordPress site is needed.
 *
 * NODE_PATH=$(npm root -g) node tests/smoke/product-reviews-list-ui-smoke.js [screenshot-dir]
 */
/* eslint-env node */
'use strict';

const {chromium} = require('playwright');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const ROOT = path.resolve(__dirname, '../..');
const FIXTURE = path.join(__dirname, 'product-reviews-list-ui-fixture.php');
const STYLES = fs.readFileSync(path.join(ROOT, 'modules/product-reviews/assets/css/product-reviews.css'), 'utf8');
const SCRIPT = fs.readFileSync(path.join(ROOT, 'modules/product-reviews/assets/js/product-reviews.js'), 'utf8');
const SHOTS = process.argv[2] ? path.resolve(process.argv[2]) : '';

let checks = 0;
function check(condition, label) {
    assert.ok(condition, label);
    checks++;
}

function php(mode, args) {
    return execFileSync('php', [FIXTURE, mode, JSON.stringify(args)], {encoding: 'utf8'});
}

function pageHtml(settings, mode) {
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<style>html{font-size:62.5%}body{margin:0;padding:16px;font-family:system-ui,sans-serif}'
        + '.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}' + STYLES + '</style></head><body>'
        + '<main style="max-width:900px;margin:0 auto">' + php(mode || 'page', settings) + '</main>'
        + '<script>' + SCRIPT + '</script></body></html>';
}

const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><rect width="800" height="600" fill="#3b5b92"/></svg>';

async function openList(browser, settings, viewport, mode) {
    const page = await browser.newPage({viewport: viewport || {width: 1000, height: 900}});
    const ajax = [];
    await page.route('https://store.test/**', async (route) => {
        const request = route.request();
        const url = request.url();
        if (url.indexOf('/uploads/') !== -1) {
            return route.fulfill({status: 200, contentType: 'image/svg+xml', body: SVG});
        }
        if (url.indexOf('admin-ajax.php') !== -1) {
            const fields = {};
            const body = request.postDataBuffer() || Buffer.from('');
            const boundary = (request.headers()['content-type'] || '').split('boundary=')[1];
            body.toString('utf8').split('--' + boundary).forEach((part) => {
                const match = part.match(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n$/);
                if (!match) {
                    return;
                }
                const key = match[1];
                if (key.endsWith('[]')) {
                    const k = key.slice(0, -2);
                    fields[k] = (fields[k] || []).concat(match[2]);
                } else {
                    fields[key] = match[2];
                }
            });
            ajax.push(fields);
            const answer = php('ajax', fields);
            if (page.slow) {
                await new Promise((resolve) => setTimeout(resolve, 400));
            }
            return route.fulfill({status: 200, contentType: 'application/json', body: answer});
        }
        return route.fulfill({status: 200, contentType: 'text/html', body: pageHtml(settings, mode)});
    });
    await page.goto('https://store.test/product/scope/');
    page.ajax = ajax;
    return page;
}

async function cards(page) {
    return page.$$eval('.ffla-review-card .ffla-review-card__author', (els) => els.map((el) => el.textContent));
}

async function settle(page) {
    await page.waitForFunction(() => !document.querySelector('[data-ffla-reviews][aria-busy="true"]'));
}

(async () => {
    const browser = await chromium.launch();
    try {
        /* ── List with toolbar and Show more ───────────────────────────── */
        let page = await openList(browser, {perPage: 5});

        check(await page.isVisible('[data-ffla-toolbar]'), 'the script reveals the toolbar');
        check((await cards(page)).length === 5, 'first page: 5 reviews');
        check(await page.isVisible('[data-ffla-more]'), 'Show more is shown');
        check((await page.textContent('[data-ffla-status]')).trim() === 'Showing 5 of 12 reviews', 'status line');

        await page.click('[data-ffla-more]');
        await settle(page);
        check((await cards(page)).length === 10, 'Show more appends the next 5');
        const seen = await cards(page);
        check(new Set(seen).size === 10, 'no review repeated across pages');
        check(page.ajax[0].page === '2' && page.ajax[0].perPage === '5' && page.ajax[0].products[0] === '101', 'Show more asks for page 2 of this product');

        // A vote on a card that arrived with Show more still works (delegated).
        const late = await page.$$('.ffla-review-card');
        const button = await late[8].$('.ffla-review-helpful');
        const before = Number(await button.$eval('.ffla-review-helpful__count', (el) => el.textContent));
        await button.click();
        await page.waitForSelector('.ffla-review-card:nth-child(9) .ffla-review-helpful__message');
        const after = Number(await button.$eval('.ffla-review-helpful__count', (el) => el.textContent));
        check(after === before + 1, 'vote on a card loaded by Show more is counted');
        check(await button.getAttribute('aria-disabled') === 'true', 'both buttons on that review lock after the vote');

        await page.click('[data-ffla-more]');
        await settle(page);
        check((await cards(page)).length === 12, 'last page');
        check(!(await page.isVisible('[data-ffla-more]')), 'Show more hides when everything is shown');
        check((await page.textContent('[data-ffla-status]')).trim() === 'Showing 12 of 12 reviews', 'status after the last page');

        /* ── Filters, sort and search ──────────────────────────────────── */
        await page.click('.ffla-reviews-chip[data-ffla-rating="5"]');
        await settle(page);
        let ratings = await page.$$eval('.ffla-review-card .ffla-stars', (els) => els.map((el) => el.getAttribute('aria-label')));
        check(ratings.length === 5 && ratings.every((r) => r.indexOf('5.0') !== -1), '5-star chip shows only 5-star reviews');
        check(await page.getAttribute('.ffla-reviews-chip[data-ffla-rating="5"]', 'aria-pressed') === 'true', 'active chip is pressed');
        check(await page.getAttribute('.ffla-rating-summary__row[data-ffla-filter-rating="5"]', 'aria-pressed') === 'true', 'matching summary bar shows as active');

        await page.click('.ffla-rating-summary__row[data-ffla-filter-rating="1"]');
        await settle(page);
        check((await cards(page)).length === 2, 'clicking the 1-star bar filters to 1-star reviews');
        await page.focus('.ffla-rating-summary__row[data-ffla-filter-rating="1"]');
        await page.keyboard.press('Enter');
        await settle(page);
        check((await cards(page)).length === 5, 'pressing Enter on the active bar clears the star filter');

        // A second filter picked while the first request is still out is not lost.
        page.slow = true;
        const sentBefore = page.ajax.length;
        await page.click('.ffla-reviews-chip[data-ffla-rating="5"]');
        await page.click('.ffla-rating-summary__row[data-ffla-filter-rating="1"]');
        await settle(page);
        page.slow = false;
        check((await cards(page)).length === 2, 'a filter picked while a request was out is applied when it returns');
        check(page.ajax.length === sentBefore + 2 && page.ajax[page.ajax.length - 1].rating === '1', 'the list loads again with the latest filter');
        check(await page.getAttribute('.ffla-reviews-chip[data-ffla-rating="1"]', 'aria-pressed') === 'true', 'and the pressed chip matches what is shown');
        await page.click('.ffla-rating-summary__row[data-ffla-filter-rating="1"]');
        await settle(page);

        await page.selectOption('[data-ffla-sort]', 'lowest');
        await settle(page);
        ratings = await page.$$eval('.ffla-review-card .ffla-stars', (els) => els.map((el) => el.getAttribute('aria-label')));
        check(ratings[0].indexOf('1.0') !== -1, 'Lowest rating sort puts a 1-star review first');

        await page.fill('[data-ffla-search] input', 'turret');
        await page.press('[data-ffla-search] input', 'Enter');
        await settle(page);
        check(JSON.stringify(await cards(page)) === JSON.stringify(['Fay G.']), 'search finds the review that mentions it');

        await page.fill('[data-ffla-search] input', 'zzz-nothing');
        await page.press('[data-ffla-search] input', 'Enter');
        await settle(page);
        check((await cards(page)).length === 0 && await page.isVisible('.ffla-reviews-list__clear'), 'no match: message with Clear filters');
        await page.click('.ffla-reviews-list__clear');
        await settle(page);
        check((await cards(page)).length === 5, 'Clear filters brings the list back');
        check(await page.inputValue('[data-ffla-search] input') === '', 'Clear filters empties the search box');

        await page.click('[data-ffla-media-filter]');
        await settle(page);
        check(JSON.stringify((await cards(page)).sort()) === JSON.stringify(['Ann B.', 'Eve W.']), 'With photos shows only reviews with photos');
        await page.click('[data-ffla-media-filter]');
        await settle(page);

        /* ── Lightbox ──────────────────────────────────────────────────── */
        await page.selectOption('[data-ffla-sort]', 'recent');
        await settle(page);
        await page.click('[data-ffla-media-filter]');
        await settle(page);
        // Eve has one photo: no counter, no arrows.
        await page.locator('.ffla-review-card', {hasText: 'Eve W.'}).locator('[data-ffla-lightbox]').first().click();
        check(await page.isVisible('dialog.ffla-lightbox[open]'), 'clicking a photo opens the lightbox');
        check((await page.textContent('.ffla-lightbox__counter')) === '' && !(await page.isVisible('.ffla-lightbox__nav--next')), 'a single photo has no counter and no arrows');
        await page.keyboard.press('Escape');

        const firstPhoto = page.locator('.ffla-review-card', {hasText: 'Ann B.'}).locator('[data-ffla-lightbox]').first();
        await firstPhoto.click();
        const src = await page.getAttribute('.ffla-lightbox__image', 'src');
        check(/photo-1\.svg\?size=large$/.test(src), 'lightbox shows the large size of the clicked photo: ' + src);
        check((await page.textContent('.ffla-lightbox__counter')) === 'Photo 1 of 3', 'counter for a review with three photos');
        await page.keyboard.press('ArrowRight');
        check((await page.textContent('.ffla-lightbox__counter')) === 'Photo 2 of 3', 'arrow key goes to the next photo');
        check(/photo-2\.svg/.test(await page.getAttribute('.ffla-lightbox__image', 'src')), 'next photo is shown');
        await page.click('.ffla-lightbox__nav--prev');
        check((await page.textContent('.ffla-lightbox__counter')) === 'Photo 1 of 3', 'previous button goes back');
        await page.keyboard.press('ArrowLeft');
        check((await page.textContent('.ffla-lightbox__counter')) === 'Photo 3 of 3', 'wraps around from the first photo');
        if (SHOTS) {
            fs.mkdirSync(SHOTS, {recursive: true});
            await page.screenshot({path: path.join(SHOTS, 'reviews-lightbox.png')});
        }
        await page.keyboard.press('Escape');
        check(!(await page.isVisible('dialog.ffla-lightbox[open]')), 'Escape closes the lightbox');
        check(await page.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-ffla-lightbox')), 'focus goes back to the photo');
        await firstPhoto.click();
        await page.mouse.click(5, 5);
        check(!(await page.isVisible('dialog.ffla-lightbox[open]')), 'clicking the backdrop closes the lightbox');

        if (SHOTS) {
            await page.click('[data-ffla-media-filter]');
            await settle(page);
            await page.screenshot({path: path.join(SHOTS, 'reviews-list.png'), fullPage: true});
        }
        await page.close();

        /* ── Phone width ───────────────────────────────────────────────── */
        page = await openList(browser, {perPage: 5}, {width: 390, height: 844});
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        check(overflow <= 0, 'no horizontal scroll at 390px (overflow ' + overflow + 'px)');
        if (SHOTS) {
            await page.screenshot({path: path.join(SHOTS, 'reviews-list-390.png'), fullPage: true});
        }
        await page.close();

        /* ── Slider ────────────────────────────────────────────────────── */
        page = await openList(browser, {perPage: 12, layout: 'slider', columns: 3}, {width: 1280, height: 900});
        check(!(await page.isVisible('[data-ffla-toolbar]')), 'slider has no toolbar');
        check(await page.isVisible('[data-ffla-slider-next]') && !(await page.isVisible('[data-ffla-slider-prev]')), 'slider starts with only the next arrow');
        await page.click('[data-ffla-slider-next]');
        await page.waitForFunction(() => document.querySelector('[data-ffla-items]').scrollLeft > 0);
        await page.waitForTimeout(400);
        check(await page.isVisible('[data-ffla-slider-prev]'), 'previous arrow appears after scrolling');
        const perView = await page.evaluate(() => {
            const track = document.querySelector('[data-ffla-items]');
            const card = track.querySelector('.ffla-review-card');
            return Math.round(track.clientWidth / card.getBoundingClientRect().width);
        });
        check(perView === 3, 'three cards per view at desktop width (got ' + perView + ')');
        await page.setViewportSize({width: 800, height: 900});
        const tablet = await page.evaluate(() => {
            const track = document.querySelector('[data-ffla-items]');
            return Math.round(track.clientWidth / track.querySelector('.ffla-review-card').getBoundingClientRect().width);
        });
        check(tablet === 2, 'two cards per view on tablets (got ' + tablet + ')');
        if (SHOTS) {
            await page.screenshot({path: path.join(SHOTS, 'reviews-slider.png')});
        }
        await page.close();

        /* ── Grid ──────────────────────────────────────────────────────── */
        page = await openList(browser, {perPage: 6, layout: 'grid', columns: 3}, {width: 1280, height: 900});
        const columns = await page.evaluate(() => getComputedStyle(document.querySelector('[data-ffla-items]')).gridTemplateColumns.split(' ').length);
        check(columns === 3, 'grid shows three columns at desktop width');
        await page.setViewportSize({width: 390, height: 844});
        const narrow = await page.evaluate(() => getComputedStyle(document.querySelector('[data-ffla-items]')).gridTemplateColumns.split(' ').length);
        check(narrow === 1, 'grid collapses to one column on phones');
        await page.setViewportSize({width: 800, height: 900});
        const mid = await page.evaluate(() => getComputedStyle(document.querySelector('[data-ffla-items]')).gridTemplateColumns.split(' ').length);
        check(mid === 2, 'grid shows two columns on tablets');
        await page.close();

        /* ── Product Q&A ───────────────────────────────────────────────── */
        page = await openList(browser, {}, {width: 1000, height: 900}, 'qna');
        const questions = () => page.$$eval('.ffla-qna-item .ffla-qna-item__text', (els) => els.map((el) => el.textContent));
        check(await page.isVisible('[data-ffla-qna-search]'), 'the script reveals the Q&A search');
        check((await questions()).length === 3, 'first 3 questions');
        check((await questions())[0] === 'Question 6?', 'newest question first');
        await page.click('[data-ffla-qna-more]');
        await settle(page);
        await page.waitForFunction(() => document.querySelectorAll('.ffla-qna-item').length === 6);
        check(new Set(await questions()).size === 6, 'Show more questions appends the next 3');
        await page.click('[data-ffla-qna-more]');
        await page.waitForFunction(() => document.querySelectorAll('.ffla-qna-item').length === 7);
        check(!(await page.isVisible('[data-ffla-qna-more]')), 'Show more questions hides at the end');
        await page.fill('[data-ffla-qna-search] input', 'turret');
        await page.press('[data-ffla-qna-search] input', 'Enter');
        await page.waitForFunction(() => document.querySelectorAll('.ffla-qna-item').length === 1);
        check(JSON.stringify(await questions()) === JSON.stringify(['Question 4 about the turret caps?']), 'Q&A search');
        await page.click('.ffla-qna-answer .ffla-review-helpful');
        await page.waitForSelector('.ffla-qna-answer .ffla-review-helpful__message');
        check((await page.textContent('.ffla-qna-answer .ffla-review-helpful__count')).trim() === '2', 'helpful vote on a store answer');
        check(!(await page.isVisible('.ffla-qna__ask form')), 'the ask form starts folded');
        await page.click('.ffla-qna__ask-summary');
        check(await page.isVisible('.ffla-qna__ask form textarea[name="question"]'), 'Ask a question opens the form');
        if (SHOTS) {
            await page.screenshot({path: path.join(SHOTS, 'qna.png'), fullPage: true});
        }
        await page.close();

        console.log('OK: ' + checks + ' product-reviews list and Q&A browser checks passed');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
