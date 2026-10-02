/**
 * White Label — category & tag search: browser checks against the local test site.
 *
 * Creates its own data (nested categories plus 1,000 extra ones, brands, tags,
 * a product), checks the Edit Product boxes, Quick Edit and Bulk Edit, the
 * AJAX fallback for large tag lists, saving, the White Label setting and a
 * 390px layout, then deletes everything it created and restores the White
 * Label option exactly as it was.
 *
 * NODE_PATH=$(npm root -g) FFLA_TEST_BROWSER_CHANNEL=chromium \
 *   node tests/smoke/white-label-term-search-ui-smoke.js [screenshot-dir]
 *
 * Env: FFLA_TEST_SITE (default http://127.0.0.1:8899), FFLA_TEST_WPCLI
 * (default /tmp/claude-0/wpc), FFLA_TEST_USER / FFLA_TEST_PASS (admin / admin).
 */
'use strict';

const {chromium} = require('playwright');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const SITE = (process.env.FFLA_TEST_SITE || 'http://127.0.0.1:8899').replace(/\/$/, '');
const WPCLI = process.env.FFLA_TEST_WPCLI || '/tmp/claude-0/wpc';
const USER = process.env.FFLA_TEST_USER || 'admin';
const PASS = process.env.FFLA_TEST_PASS || 'admin';
const SHOTS = process.argv[2] ? path.resolve(process.argv[2]) : '';
const PREFIX = 'Zqts';

let checks = 0;
function check(condition, label) {
    assert.ok(condition, label);
    checks++;
}

function wp(php) {
    return execFileSync(WPCLI, ['eval', php], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe']}).trim();
}

function json(php) {
    const out = wp(php);
    const start = out.indexOf('{');
    return JSON.parse(out.slice(start));
}

/* ── Test data ───────────────────────────────────────────────────────── */

const CLEANUP_PHP = `
foreach (get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 's' => '${PREFIX} Term Search', 'fields' => 'ids']) as $id) { wp_delete_post($id, true); }
foreach (['product_cat', 'product_brand', 'product_tag'] as $tax) {
    if (!taxonomy_exists($tax)) { continue; }
    $ids = get_terms(['taxonomy' => $tax, 'name__like' => '${PREFIX}', 'hide_empty' => false, 'fields' => 'ids']);
    if (is_array($ids)) { foreach ($ids as $id) { wp_delete_term($id, $tax); } }
}
echo 'ok';`;

const SETUP_PHP = `
${CLEANUP_PHP.replace("echo 'ok';", '')}
wp_defer_term_counting(true);
$mk = function ($name, $tax, $parent = 0) {
    $r = wp_insert_term($name, $tax, ['parent' => $parent]);
    return is_wp_error($r) ? 0 : (int) $r['term_id'];
};
$c = [];
$c['shotguns'] = $mk('${PREFIX} Shotguns', 'product_cat');
$c['bolt_sg']  = $mk('${PREFIX} Bolt Action Shotguns', 'product_cat', $c['shotguns']);
$c['lever_sg'] = $mk('${PREFIX} Lever Action Shotguns', 'product_cat', $c['shotguns']);
$c['rifles']   = $mk('${PREFIX} Rifles', 'product_cat');
$c['bolt_rf']  = $mk('${PREFIX} Bolt Action Rifles', 'product_cat', $c['rifles']);
$c['senal']    = $mk('${PREFIX} Señales Ópticas', 'product_cat');
$c['bulk']     = $mk('${PREFIX} Bulk', 'product_cat');
for ($i = 1; $i <= 1000; $i++) { $mk(sprintf('${PREFIX} Bulk Item %04d', $i), 'product_cat', $c['bulk']); }
$brand = taxonomy_exists('product_brand') ? $mk('${PREFIX} Brand Ruger', 'product_brand') : 0;
if ($brand) { $mk('${PREFIX} Brand Señor', 'product_brand'); }
foreach (['Used', 'Optics', 'Scope', 'Señal Tag', 'Ammo & Gear'] as $tag) { $mk('${PREFIX} ' . $tag, 'product_tag'); }
wp_defer_term_counting(false);
$p = new WC_Product_Simple();
$p->set_name('${PREFIX} Term Search Product');
$p->set_status('publish');
$p->set_regular_price('10');
$id = $p->save();
wp_set_object_terms($id, [$c['bolt_rf']], 'product_cat');
wp_set_object_terms($id, ['${PREFIX} Used'], 'product_tag');
echo wp_json_encode(['product' => $id, 'cats' => $c, 'brand' => $brand]);`;

const MU_PLUGIN = path.join(execFileSync(WPCLI, ['eval', 'echo WPMU_PLUGIN_DIR;'], {encoding: 'utf8'}).trim(), 'ffla-term-search-ui-smoke.php');

function productTerms(id) {
    return json(`
$out = [];
foreach (['product_cat', 'product_tag', 'product_brand'] as $tax) {
    $names = taxonomy_exists($tax) ? wp_get_object_terms(${Number(id)}, $tax, ['fields' => 'names']) : [];
    $names = array_map(function ($n) { return html_entity_decode($n, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }, is_array($names) ? $names : []);
    sort($names);
    $out[$tax] = $names;
}
echo wp_json_encode($out);`);
}

/* ── Page helpers ────────────────────────────────────────────────────── */

async function login(page) {
    await page.goto(SITE + '/wp-login.php');
    await page.fill('#user_login', USER);
    await page.fill('#user_pass', PASS);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
}

/** Names of the items in a checklist that are not hidden by the filter. */
function visibleNames(page, listSelector) {
    return page.$$eval(listSelector + ' li', items => items
        .filter(li => !li.closest('.ffla-ts-hidden'))
        .map(li => {
            const name = li.querySelector(':scope > label .ffla-ts-name');
            return name ? name.textContent.trim() : '';
        })
        .filter(Boolean));
}

function ancestorNames(page, listSelector) {
    return page.$$eval(listSelector + ' li.ffla-ts-ancestor', items => items.map(li => li.querySelector(':scope > label .ffla-ts-name').textContent.trim()));
}

async function search(page, inputSelector, text) {
    await page.fill(inputSelector, text);
    await page.waitForTimeout(150); // Past the 60ms debounce.
}

function ours(names) {
    return names.filter(name => name.startsWith(PREFIX));
}

async function chips(page) {
    return page.$$eval('#product_tag ul.tagchecklist li', items => items.map(li => {
        const clone = li.cloneNode(true);
        clone.querySelectorAll('button').forEach(b => b.remove());
        return clone.textContent.replace(/ /g, ' ').trim();
    }));
}

async function tagChecked(page, name) {
    return page.$eval('#product_tag .ffla-ts-list', (list, wanted) => {
        const input = Array.from(list.querySelectorAll('input[type=checkbox]')).find(i => i.value === wanted);
        return input ? input.checked : null;
    }, name);
}

async function clickTag(page, name) {
    const box = page.locator('#product_tag .ffla-ts-list label', {hasText: name}).locator('input');
    await box.click();
}

async function shot(target, name) {
    if (!SHOTS) {
        return;
    }
    fs.mkdirSync(SHOTS, {recursive: true});
    await target.screenshot({path: path.join(SHOTS, 'term-search-' + name + '.png')});
}

/* ── Run ─────────────────────────────────────────────────────────────── */

(async () => {
    const originalOption = wp("echo base64_encode(serialize(get_option('ffla_white_label_settings', '__FFLA_ABSENT__')));");
    const restoreOption = () => wp(`$v = unserialize(base64_decode('${originalOption}'));
if ('__FFLA_ABSENT__' === $v) { delete_option('ffla_white_label_settings'); } else { update_option('ffla_white_label_settings', $v, false); }
echo 'restored';`);

    let data = null;
    const browser = await chromium.launch({headless: true, channel: process.env.FFLA_TEST_BROWSER_CHANNEL || 'chromium'});
    const errors = [];

    try {
        data = json(SETUP_PHP);
        check(data.product > 0 && data.cats.bolt_rf > 0, 'test data created');
        // Lets the remote (AJAX) tag search be checked without 2,000+ tags.
        fs.writeFileSync(MU_PLUGIN, "<?php\n// Temporary: written and removed by tests/smoke/white-label-term-search-ui-smoke.js.\nadd_filter('ffla_term_search_inline_limit', function ($limit) { return isset($_GET['ffla_ts_test_remote']) ? 2 : $limit; });\n");

        const page = await browser.newPage({viewport: {width: 1440, height: 1100}});
        page.on('pageerror', e => errors.push(e.message));
        page.on('dialog', d => d.accept());
        await login(page);

        const editUrl = SITE + '/wp-admin/post.php?post=' + data.product + '&action=edit';
        await page.goto(editUrl);

        /* Categories: field, labels, accessibility */
        const catInput = '#taxonomy-product_cat .ffla-ts-input';
        const catList = '#product_catchecklist';
        check(await page.getAttribute(catInput, 'placeholder') === 'Search categories…', 'category placeholder uses the taxonomy label');
        check(await page.getAttribute(catInput, 'aria-controls') === 'product_catchecklist', 'search field controls the checklist');
        const catLabel = await page.$eval(catInput, input => document.querySelector('label[for="' + input.id + '"]').textContent);
        check(catLabel === 'Search categories', 'search field has a screen-reader label');
        check(await page.getAttribute('#taxonomy-product_cat .ffla-ts-status', 'aria-live') === 'polite', 'counts are announced politely');
        check(await page.$eval('#taxonomy-product_cat', div => div.firstElementChild.classList.contains('ffla-ts')), 'search sits above the All / Most Used tabs');
        if (data.brand) {
            check(await page.getAttribute('#taxonomy-product_brand .ffla-ts-input', 'placeholder') === 'Search Brands…', 'brand box gets its own placeholder');
        }
        check(await page.$eval('#product_cat-all', p => p.classList.contains('ffla-ts-resizable') && getComputedStyle(p).resize === 'vertical'), 'long category panel can be resized taller');

        /* Filtering, ancestors, highlight */
        await search(page, catInput, 'zqts bolt');
        check(JSON.stringify(ours(await visibleNames(page, catList)).sort()) === JSON.stringify([PREFIX + ' Bolt Action Rifles', PREFIX + ' Bolt Action Shotguns', PREFIX + ' Rifles', PREFIX + ' Shotguns']), 'matches plus their parents are visible');
        check(JSON.stringify((await ancestorNames(page, catList)).sort()) === JSON.stringify([PREFIX + ' Rifles', PREFIX + ' Shotguns']), 'parents of matches are shown muted');
        check((await page.textContent('#taxonomy-product_cat .ffla-ts-status')) === '2 matches', 'result count');
        const marks = await page.$$eval(catList + ' mark.ffla-ts-mark', m => m.map(x => x.textContent));
        check(marks.includes('Bolt') && marks.includes(PREFIX), 'every typed word is highlighted');
        await shot(page.locator('#product_catdiv'), 'desktop-categories');

        await search(page, catInput, 'SHOTGUNS lever ZQTS');
        check(JSON.stringify(ours(await visibleNames(page, catList))) === JSON.stringify([PREFIX + ' Shotguns', PREFIX + ' Lever Action Shotguns']), 'case-insensitive, any word order');

        await search(page, catInput, 'senales opticas');
        check(JSON.stringify(await visibleNames(page, catList)) === JSON.stringify([PREFIX + ' Señales Ópticas']), 'accent-insensitive');
        check(JSON.stringify(await page.$$eval(catList + ' mark.ffla-ts-mark', m => m.map(x => x.textContent))) === JSON.stringify(['Señales', 'Ópticas']), 'highlight keeps the original accents');

        await search(page, catInput, 'zqts nothing here');
        check(await page.isVisible('#taxonomy-product_cat .ffla-ts-empty') && (await page.textContent('#taxonomy-product_cat .ffla-ts-empty')) === 'No matches', 'no-match line');

        await page.focus(catInput);
        await page.keyboard.press('Escape');
        await page.waitForTimeout(100);
        check((await page.inputValue(catInput)) === '' && (await page.locator(catList + ' li.ffla-ts-hidden').count()) === 0 && (await page.locator(catList + ' mark').count()) === 0, 'Esc clears the search');

        // Enter never submits the product form.
        await page.evaluate(() => {
            window.__fflaSubmitted = false;
            window.__fflaSubmitGuard = e => { window.__fflaSubmitted = true; e.preventDefault(); };
            document.getElementById('post').addEventListener('submit', window.__fflaSubmitGuard);
        });
        await page.fill(catInput, 'zqts');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(300);
        check(!(await page.evaluate(() => window.__fflaSubmitted)) && page.url().startsWith(editUrl), 'Enter does not submit the product');
        await page.evaluate(() => document.getElementById('post').removeEventListener('submit', window.__fflaSubmitGuard));

        // Searching switches to the All tab.
        await page.fill(catInput, '');
        await page.click('#product_cat-tabs a[href="#product_cat-pop"]');
        check(await page.isHidden('#product_cat-all'), 'Most Used tab open');
        await search(page, catInput, 'zqts rifles');
        check(await page.isVisible('#product_cat-all'), 'searching switches to the All tab');

        // 1,000+ terms stay fast.
        const elapsed = await page.evaluate(async () => {
            const input = document.querySelector('#taxonomy-product_cat .ffla-ts-input');
            const status = document.querySelector('#taxonomy-product_cat .ffla-ts-status');
            const start = performance.now();
            input.value = 'zqts bulk item 0999';
            input.dispatchEvent(new Event('input', {bubbles: true}));
            while (status.textContent !== '1 match') {
                await new Promise(r => setTimeout(r, 5));
                if (performance.now() - start > 5000) { break; }
            }
            return performance.now() - start;
        });
        check(await page.locator(catList + ' li').count() > 1000 && elapsed < 600, 'filtering 1,000+ terms is fast (' + Math.round(elapsed) + 'ms incl. 60ms debounce)');

        /* Selected only */
        await search(page, catInput, '');
        check((await page.textContent('#taxonomy-product_cat .ffla-ts-count')) === '1 selected', 'selected count');
        await page.check('#taxonomy-product_cat .ffla-ts-only input');
        await page.waitForTimeout(100);
        check(JSON.stringify(await visibleNames(page, catList)) === JSON.stringify([PREFIX + ' Rifles', PREFIX + ' Bolt Action Rifles']), 'Selected only shows ticked terms and their parents');
        await page.uncheck('#taxonomy-product_cat .ffla-ts-only input');
        await search(page, catInput, 'zqts lever');
        await page.locator(catList + ' label', {hasText: PREFIX + ' Lever Action Shotguns'}).locator('input').check();
        await page.waitForTimeout(50);
        check((await page.textContent('#taxonomy-product_cat .ffla-ts-count')) === '2 selected', 'selected count follows ticks');

        // "+ Add new category" keeps working and the new term is searchable.
        await search(page, catInput, '');
        await page.click('#product_cat-add-toggle');
        await page.fill('#newproduct_cat', PREFIX + ' Added Category');
        await page.click('#product_cat-add-submit');
        await page.waitForFunction(prefix => Array.from(document.querySelectorAll('#product_catchecklist li label')).some(l => l.textContent.includes(prefix + ' Added Category')), PREFIX);
        await search(page, catInput, 'zqts added');
        await page.waitForTimeout(150);
        check(JSON.stringify(await visibleNames(page, catList)) === JSON.stringify([PREFIX + ' Added Category']), 'a newly added category is searchable');
        check((await page.textContent('#taxonomy-product_cat .ffla-ts-count')) === '3 selected', 'new category (ticked by core) is counted');
        await search(page, catInput, '');

        /* Brands */
        if (data.brand) {
            await search(page, '#taxonomy-product_brand .ffla-ts-input', 'ruger');
            check(JSON.stringify(await visibleNames(page, '#product_brandchecklist')) === JSON.stringify([PREFIX + ' Brand Ruger']), 'brand search');
            await page.locator('#product_brandchecklist label', {hasText: PREFIX + ' Brand Ruger'}).locator('input').check();
            await search(page, '#taxonomy-product_brand .ffla-ts-input', '');
        }

        /* Tags: checklist ⇄ core textarea and chips */
        const tagInput = '#product_tag .ffla-ts-input';
        check(await page.getAttribute(tagInput, 'placeholder') === 'Search tags…', 'tag placeholder');
        check(ours(await visibleNames(page, '#product_tag .ffla-ts-list')).length === 5, 'existing tags listed');
        check(await tagChecked(page, PREFIX + ' Used') === true && (await chips(page)).includes(PREFIX + ' Used'), 'assigned tag starts checked');

        await clickTag(page, PREFIX + ' Optics');
        check((await chips(page)).includes(PREFIX + ' Optics') && (await page.inputValue('#tax-input-product_tag')).includes(PREFIX + ' Optics'), 'checking a tag adds the chip and the value');
        await clickTag(page, PREFIX + ' Used');
        check(!(await chips(page)).includes(PREFIX + ' Used'), 'unchecking a tag removes its chip');

        await page.locator('#product_tag ul.tagchecklist li', {hasText: PREFIX + ' Optics'}).locator('button.ntdelbutton').click();
        await page.waitForTimeout(50);
        check(await tagChecked(page, PREFIX + ' Optics') === false, 'removing a chip with core X unchecks the tag');

        await page.fill('#new-tag-product_tag', PREFIX + ' Scope');
        await page.click('#product_tag .tagadd');
        await page.waitForTimeout(50);
        check(await tagChecked(page, PREFIX + ' Scope') === true, 'adding a tag in core field checks it');

        await search(page, tagInput, 'senal');
        check(JSON.stringify(await visibleNames(page, '#product_tag .ffla-ts-list')) === JSON.stringify([PREFIX + ' Señal Tag']), 'tag search is accent-insensitive');
        await page.focus(tagInput);
        await page.keyboard.press('Escape');
        await page.waitForTimeout(100);
        check((await page.inputValue(tagInput)) === '', 'Esc clears the tag search');

        await clickTag(page, PREFIX + ' Ammo & Gear');
        await clickTag(page, PREFIX + ' Optics');
        check((await chips(page)).includes(PREFIX + ' Ammo & Gear'), 'names with & round-trip');
        await shot(page.locator('#tagsdiv-product_tag'), 'tags');

        // AJAX endpoint: permissions and minimum length.
        const ajax = await page.evaluate(async () => {
            const call = async (body) => {
                const r = await fetch(window.fflaTermSearch.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams(body)});
                return {status: r.status, body: await r.json().catch(() => null)};
            };
            const base = {action: window.fflaTermSearch.action, nonce: window.fflaTermSearch.nonce};
            return {
                ok: await call(Object.assign({}, base, {taxonomy: 'product_tag', q: 'zqts op'})),
                short: await call(Object.assign({}, base, {taxonomy: 'product_tag', q: 'z'})),
                other: await call(Object.assign({}, base, {taxonomy: 'category', q: 'zqts'})),
                nonce: await call(Object.assign({}, base, {nonce: 'bad', taxonomy: 'product_tag', q: 'zqts'}))
            };
        });
        check(ajax.ok.status === 200 && JSON.stringify(ajax.ok.body.data.terms) === JSON.stringify([PREFIX + ' Optics']), 'AJAX search returns matching names');
        check(ajax.short.body.success && ajax.short.body.data.terms.length === 0, 'AJAX search needs 2 characters');
        check(ajax.other.status === 403 && ajax.nonce.status === 403, 'AJAX search refuses other taxonomies and bad nonces');

        /* Save keeps the right terms */
        await Promise.all([page.waitForNavigation(), page.click('#publish')]);
        const saved = productTerms(data.product);
        check(JSON.stringify(saved.product_cat) === JSON.stringify([PREFIX + ' Added Category', PREFIX + ' Bolt Action Rifles', PREFIX + ' Lever Action Shotguns']), 'saved categories: ' + saved.product_cat.join(', '));
        check(JSON.stringify(saved.product_tag) === JSON.stringify([PREFIX + ' Ammo & Gear', PREFIX + ' Optics', PREFIX + ' Scope']), 'saved tags: ' + saved.product_tag.join(', '));
        if (data.brand) {
            check(JSON.stringify(saved.product_brand) === JSON.stringify([PREFIX + ' Brand Ruger']), 'saved brand');
        }
        check(await tagChecked(page, PREFIX + ' Scope') === true && await tagChecked(page, PREFIX + ' Used') === false, 'reloaded tag checklist matches the saved tags');

        /* Large tag lists: AJAX fallback */
        await page.goto(editUrl + '&ffla_ts_test_remote=1');
        check(JSON.stringify((await visibleNames(page, '#product_tag .ffla-ts-list')).sort()) === JSON.stringify([PREFIX + ' Ammo & Gear', PREFIX + ' Optics', PREFIX + ' Scope']), 'large lists start with the assigned tags');
        await page.fill(tagInput, 'z');
        await page.waitForTimeout(400);
        check((await page.textContent('#product_tag .ffla-ts-status')).includes('at least 2 characters'), 'large lists ask for 2 characters');
        await page.fill(tagInput, 'zqts used');
        await page.waitForFunction(() => document.querySelector('#product_tag .ffla-ts-status').textContent === '1 match');
        check(JSON.stringify(await visibleNames(page, '#product_tag .ffla-ts-list')) === JSON.stringify([PREFIX + ' Used']), 'server results filtered by every word');
        await clickTag(page, PREFIX + ' Used');
        check((await chips(page)).includes(PREFIX + ' Used'), 'a server result can be assigned');

        /* Quick Edit and Bulk Edit */
        await page.goto(SITE + '/wp-admin/edit.php?post_type=product&s=' + encodeURIComponent(PREFIX + ' Term Search'));
        await page.hover('#post-' + data.product);
        await page.click('#post-' + data.product + ' button.editinline');
        const qe = 'tr.inline-editor:not(#bulk-edit)';
        // One filter per taxonomy checklist (Brands, Product categories…).
        const catFilter = ' .ffla-ts--inline:has(+ ul.product_cat-checklist) .ffla-ts-input';
        await page.waitForSelector(qe + catFilter);
        const qeInput = qe + ' ul.product_cat-checklist';
        await search(page, qe + catFilter, 'zqts lever');
        check(JSON.stringify(await visibleNames(page, qeInput)) === JSON.stringify([PREFIX + ' Shotguns', PREFIX + ' Lever Action Shotguns']), 'Quick Edit checklist filters');
        await shot(page.locator(qe), 'quick-edit');
        await page.focus(qe + catFilter);
        await page.keyboard.press('Enter');
        await page.waitForTimeout(300);
        check(await page.isVisible(qe), 'Enter in the search does not save Quick Edit');
        await page.keyboard.press('Escape');
        await page.waitForTimeout(100);
        check(await page.isVisible(qe) && (await page.inputValue(qe + catFilter)) === '', 'Esc clears the search, Quick Edit stays open');
        await page.keyboard.press('Escape');
        await page.waitForTimeout(300);
        check((await page.locator(qe).count()) === 0, 'Esc on an empty search still closes Quick Edit');

        // Opening Quick Edit again gives a fresh, working filter.
        await page.hover('#post-' + data.product);
        await page.click('#post-' + data.product + ' button.editinline');
        await page.waitForSelector(qe + catFilter);
        await search(page, qe + catFilter, 'zqts rifles');
        check(ours(await visibleNames(page, qeInput)).includes(PREFIX + ' Rifles'), 'second Quick Edit filters too');
        await page.click(qe + ' button.cancel');

        await page.check('#cb-select-' + data.product);
        await page.selectOption('#bulk-action-selector-top', 'edit');
        await page.click('#doaction');
        await page.waitForSelector('#bulk-edit' + catFilter);
        await search(page, '#bulk-edit' + catFilter, 'zqts bolt rifles');
        check(JSON.stringify(await visibleNames(page, '#bulk-edit ul.product_cat-checklist')) === JSON.stringify([PREFIX + ' Rifles', PREFIX + ' Bolt Action Rifles']), 'Bulk Edit checklist filters');
        await page.click('#bulk-edit button.cancel');

        /* Mobile */
        const mobile = await browser.newPage({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true});
        mobile.on('pageerror', e => errors.push(e.message));
        mobile.on('dialog', d => d.accept());
        await login(mobile);
        await mobile.goto(editUrl);
        await search(mobile, catInput, 'zqts bolt');
        const fit = await mobile.$eval('#taxonomy-product_cat .ffla-ts', el => el.scrollWidth <= el.clientWidth + 1 && el.getBoundingClientRect().right <= window.innerWidth + 1);
        check(fit, 'fits a 390px screen');
        await mobile.locator('#product_catdiv').scrollIntoViewIfNeeded();
        await shot(mobile.locator('#product_catdiv'), 'mobile-390');
        await mobile.close();

        /* Folding tree and tree order */
        const setTools = tools => wp(`$s = get_option('ffla_white_label_settings', []); $s = is_array($s) ? $s : []; $s['term_search'] = json_decode('${JSON.stringify(tools)}', true); update_option('ffla_white_label_settings', $s, false); echo 'set';`);
        await page.goto(editUrl);
        await page.waitForSelector('#product_catchecklist .ffla-ts-twisty');
        const cb = id => `#product_catchecklist input[value="${id}"]`;
        const liOf = id => `#product_catchecklist li:has(> label input[value="${id}"])`;
        const tree = await page.evaluate(cats => {
            const input = id => document.querySelector('#product_catchecklist input[value="' + id + '"]');
            const li = id => input(id).closest('li');
            return {
                bulkFolded: li(cats.bulk).classList.contains('ffla-ts-collapsed'),
                riflesOpen: !li(cats.rifles).classList.contains('ffla-ts-collapsed'),
                inPlace: !!input(cats.bolt_rf).closest('ul.children'),
                label: li(cats.bulk).querySelector(':scope > .ffla-ts-twisty').getAttribute('aria-label'),
            };
        }, data.cats);
        check(tree.bulkFolded, 'a long tree starts folded');
        check(tree.riflesOpen, 'the branch holding a ticked category starts open');
        check(tree.inPlace, 'a ticked category stays in its place in the tree');
        check(tree.label === 'Show subcategories of ' + PREFIX + ' Bulk', 'fold arrow has an accessible name');
        await page.click(liOf(data.cats.bulk) + ' > .ffla-ts-twisty');
        check(await page.getAttribute(liOf(data.cats.bulk) + ' > .ffla-ts-twisty', 'aria-expanded') === 'true', 'arrow unfolds a branch');
        await page.click('#taxonomy-product_cat .ffla-ts-tree-action >> nth=1');
        check(await page.$eval(liOf(data.cats.shotguns), li => li.classList.contains('ffla-ts-collapsed')), 'Collapse all folds every branch');
        await search(page, catInput, 'bulk item 0007');
        check(await page.$eval(cb(data.cats.bulk), () => {
            const item = Array.from(document.querySelectorAll('#product_catchecklist .ffla-ts-name')).find(n => n.textContent.includes('Bulk Item 0007'));
            return !!item && item.offsetParent !== null;
        }), 'search shows a match inside a folded branch');
        await search(page, catInput, '');
        await page.click('#taxonomy-product_cat .ffla-ts-tree-action >> nth=0');
        check(!(await page.$eval(liOf(data.cats.bulk), li => li.classList.contains('ffla-ts-collapsed'))), 'Expand all unfolds every branch');

        /* Ticking parents (off by default) */
        await page.uncheck(cb(data.cats.lever_sg));
        await page.uncheck(cb(data.cats.shotguns));
        await page.check(cb(data.cats.lever_sg));
        check(!(await page.isChecked(cb(data.cats.shotguns))), 'parents are not ticked while the option is off');
        await page.uncheck(cb(data.cats.lever_sg));
        setTools({auto_parents: true});
        await page.goto(editUrl);
        check(await page.evaluate(() => !!(window.fflaTermSearch && window.fflaTermSearch.features && window.fflaTermSearch.features.autoParents)), 'parent ticking switched on for the test');
        await page.click('#taxonomy-product_cat .ffla-ts-tree-action >> nth=0');
        await page.uncheck(cb(data.cats.lever_sg));
        await page.uncheck(cb(data.cats.shotguns));
        await page.check(cb(data.cats.lever_sg));
        check(await page.isChecked(cb(data.cats.shotguns)), 'ticking a subcategory ticks its parent');
        await page.uncheck(cb(data.cats.lever_sg));
        check(await page.isChecked(cb(data.cats.shotguns)), 'unticking leaves the parent alone');
        restoreOption();

        /* Products list filters */
        // Earlier steps may have changed the product's tags: give it one again.
        const tagSlug = wp(`wp_set_object_terms(${Number(data.product)}, ['${PREFIX} Used'], 'product_tag', true); $t = get_term_by('name', '${PREFIX} Used', 'product_tag'); echo $t ? $t->slug : '';`);
        await page.goto(SITE + '/wp-admin/edit.php?post_type=product');
        check(await page.locator(`select#ffla-ts-tag-filter option[value="${tagSlug}"]`).count() === 1, 'Products list has a tag filter');
        check(await page.$eval('select[name="product_cat"]', el => el.classList.contains('select2-hidden-accessible')), 'category filter is searchable');
        check(await page.$eval('select#ffla-ts-tag-filter', el => el.classList.contains('select2-hidden-accessible')), 'tag filter is searchable');
        await page.goto(SITE + '/wp-admin/edit.php?post_type=product&product_tag=' + tagSlug);
        check(await page.locator('#post-' + data.product).count() === 1, 'filtering by tag lists the tagged product');
        await shot(page.locator('.tablenav.top'), 'list-filters');

        /* White Label settings */
        await page.goto(SITE + '/wp-admin/admin.php?page=ffla-white-label&tab=products');
        check(await page.isChecked('input[type=checkbox][name="ffla_wl[term_search][enabled]"]'), 'search shows on by default');
        check(await page.isChecked('input[type=checkbox][name="ffla_wl[term_search][collapse_tree]"]') && await page.isChecked('input[type=checkbox][name="ffla_wl[term_search][keep_order]"]') && await page.isChecked('input[type=checkbox][name="ffla_wl[term_search][list_filters]"]'), 'tree, order and list tools show on by default');
        check(!(await page.isChecked('input[type=checkbox][name="ffla_wl[term_search][auto_parents]"]')), 'parent ticking shows off by default');
        await shot(page.locator('.wb-card', {hasText: 'Product editor tools'}), 'settings');
        setTools({enabled: false});
        await page.goto(editUrl);
        check((await page.locator('.ffla-ts-input').count()) === 0, 'switching search off removes the search fields');
        check((await page.locator('#product_catchecklist .ffla-ts-twisty').count()) > 0, 'the folding tree works without search');
        await page.goto(SITE + '/wp-admin/admin.php?page=ffla-white-label&tab=products');
        check(!(await page.isChecked('input[type=checkbox][name="ffla_wl[term_search][enabled]"]')), 'search setting shows off');
        wp(`wp_set_object_terms(${Number(data.product)}, [${Number(data.cats.bolt_rf)}], 'product_cat'); echo 'ok';`);
        setTools({enabled: false, keep_order: false, collapse_tree: false, auto_parents: false, list_filters: false});
        await page.goto(editUrl);
        check((await page.locator('.ffla-ts, .ffla-ts-twisty').count()) === 0, 'all tools off leaves the boxes untouched');
        // WooCommerce already keeps product categories in place; the tool
        // extends that to brands and every other hierarchical product taxonomy.
        check(wp(`require_once WP_PLUGIN_DIR . '/ffl-funnels-addons/modules/white-label/includes/class-white-label-term-search.php'; $a = (new White_Label_Term_Search())->keep_tree_order(['taxonomy' => 'product_brand'], ${Number(data.product)}); echo isset($a['checked_ontop']) && false === $a['checked_ontop'] ? 'kept' : 'top';`) === 'kept', 'ticked brands stay in place in their tree');
        await page.goto(SITE + '/wp-admin/edit.php?post_type=product');
        check(await page.locator('select#ffla-ts-tag-filter').count() === 0, 'list filters off removes the tag filter');
        restoreOption();

        check(errors.length === 0, 'no browser errors: ' + errors.join('; '));
        console.log(checks + ' term search browser checks passed.');
    } finally {
        await browser.close();
        try { fs.unlinkSync(MU_PLUGIN); } catch (e) { /* Not written. */ }
        try { restoreOption(); } catch (e) { console.error('Could not restore ffla_white_label_settings: ' + e.message); }
        try { wp(CLEANUP_PHP); } catch (e) { console.error('Cleanup failed: ' + e.message); }
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
