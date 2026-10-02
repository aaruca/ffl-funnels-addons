/**
 * Smart Coupons — "Which products" AND / OR rules: browser checks against the
 * local test site.
 *
 * Creates its own categories, tags and coupons, then checks the rule builder on
 * the coupon screen: Any / All per side, the AND / OR join, "Never for", the
 * live summary (including WooCommerce's own Usage restriction and the firearm
 * guardrail), saving, options saved by 1.55.1 and a 390px layout. Everything it
 * created is deleted at the end.
 *
 * NODE_PATH=$(npm root -g) FFLA_TEST_BROWSER_CHANNEL=chromium \
 *   node tests/smoke/smart-coupons-rules-ui-smoke.js [screenshot-dir]
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
const PREFIX = 'Zqcr';

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
    return JSON.parse(out.slice(out.indexOf('{')));
}

const CLEANUP_PHP = `
foreach (['zqcr-ui', 'zqcr-legacy'] as $code) { $id = wc_get_coupon_id_by_code($code); if ($id) { wp_delete_post($id, true); } }
foreach (['product_cat', 'product_tag'] as $tax) {
    $ids = get_terms(['taxonomy' => $tax, 'name__like' => '${PREFIX}', 'hide_empty' => false, 'fields' => 'ids']);
    if (is_array($ids)) { foreach ($ids as $id) { wp_delete_term($id, $tax); } }
}
echo 'ok';`;

const SETUP_PHP = `
${CLEANUP_PHP.replace("echo 'ok';", '')}
$cat = function ($name, $parent = 0) { $t = wp_insert_term($name, 'product_cat', ['parent' => $parent]); return (int) $t['term_id']; };
$rifles = $cat('${PREFIX} Rifles');
$used_r = $cat('${PREFIX} Used', $rifles);
$pistols = $cat('${PREFIX} Pistols');
$used_p = $cat('${PREFIX} Used', $pistols);
$nfa = $cat('${PREFIX} NFA');
$sale = (int) wp_insert_term('${PREFIX} Sale', 'product_tag')['term_id'];
$clear = (int) wp_insert_term('${PREFIX} Clearance', 'product_tag')['term_id'];
$c = new WC_Coupon(); $c->set_code('zqcr-ui'); $c->set_discount_type('percent'); $c->set_amount(10); $ui = $c->save();
$c = new WC_Coupon(); $c->set_code('zqcr-legacy'); $c->set_discount_type('percent'); $c->set_amount(10); $legacy = $c->save();
// Options as 1.55.1 saved them: "In all of these categories" only.
update_post_meta($legacy, '_ffla_coupon', ['allow_protected' => false, 'all_cats' => [$rifles, $used_r], 'tags' => [], 'tags_match' => 'any', 'exclude_tags' => []]);
echo wp_json_encode(compact('rifles', 'used_r', 'pistols', 'used_p', 'nfa', 'sale', 'clear', 'ui', 'legacy'));`;

async function shot(page, name, locator) {
    if (!SHOTS) {
        return;
    }
    fs.mkdirSync(SHOTS, {recursive: true});
    await (locator || page).screenshot({path: path.join(SHOTS, name + '.png')});
}

async function openTab(page, id) {
    await page.goto(`${SITE}/wp-admin/post.php?post=${id}&action=edit`);
    await page.click('li.ffla_smart_options a');
    await page.waitForSelector('[data-ffla-rules]', {state: 'visible'});
}

const summary = (page) => page.locator('[data-ffla-summary]').innerText();

async function pickTag(page, selectId, text) {
    await page.locator(`#${selectId} + .select2-container .select2-search__field`).click();
    await page.keyboard.type(text, {delay: 20});
    const option = page.locator('.select2-results__option', {hasText: text}).first();
    await option.waitFor({state: 'visible'});
    await option.click();
}

(async () => {
    const ids = json(SETUP_PHP);
    const browser = await chromium.launch({channel: process.env.FFLA_TEST_BROWSER_CHANNEL || undefined});
    const page = await browser.newPage({viewport: {width: 1400, height: 1100}});
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    try {
        await page.goto(`${SITE}/wp-login.php`);
        await page.fill('#user_login', USER);
        await page.fill('#user_pass', PASS);
        await page.click('#wp-submit');
        await page.waitForURL(/wp-admin/, {waitUntil: 'commit'});

        /* Empty rule */
        await openTab(page, ids.ui);
        const rules = page.locator('[data-ffla-rules]');
        check(await rules.locator('.ffla-rule').count() === 3, 'categories, tags and never-for blocks');
        check(/Every product/.test(await summary(page)), 'empty rule reads "Every product"');
        check(await page.locator('[data-ffla-join]').evaluate((el) => el.classList.contains('is-idle')), 'AND / OR is idle until both sides are set');
        check(/Used when both/.test(await page.locator('[data-ffla-join-hint]').innerText()), 'idle hint explains when AND / OR is used');
        check(await page.locator('input[name="ffla[cats_match]"][value="any"]').isChecked(), 'categories default to Any');
        check(await page.locator('input[name="ffla[join]"][value="and"]').isChecked(), 'join defaults to AND');

        /* Categories: path labels, Any → All */
        const labels = await page.locator('#ffla_cats option').allInnerTexts();
        check(labels.includes(`${PREFIX} Rifles › ${PREFIX} Used`) && labels.includes(`${PREFIX} Pistols › ${PREFIX} Used`), 'same-named subcategories show their parent');
        await page.evaluate((v) => jQuery('#ffla_cats').val(v).trigger('change'), [String(ids.rifles), String(ids.pistols)]);
        let text = await summary(page);
        check(/in\s+Zqcr Pistols\s+OR\s+Zqcr Rifles/.test(text), 'Any reads as OR inside the category group: ' + text);
        check(await page.locator('[data-ffla-side="cats"]').evaluate((el) => el.classList.contains('is-set')), 'category block marked as set');
        await page.locator('[data-ffla-side="cats"] .ffla-seg__opt', {hasText: 'All'}).click();
        text = await summary(page);
        check(/Zqcr Pistols\s+AND\s+Zqcr Rifles/.test(text), 'All reads as AND inside the group: ' + text);
        await page.locator('[data-ffla-side="cats"] .ffla-seg__opt', {hasText: 'Any'}).click();

        /* Tags by AJAX search, then AND / OR */
        await pickTag(page, 'ffla_tags', `${PREFIX} Sale`);
        await page.waitForFunction(() => !document.querySelector('[data-ffla-join]').classList.contains('is-idle'));
        text = await summary(page);
        check(/OR\s+Zqcr Rifles\s+AND\s+tagged\s+Zqcr Sale/.test(text), 'categories AND tags: ' + text);
        check(/Both must match/.test(await page.locator('[data-ffla-join-hint]').innerText()), 'AND hint');
        await shot(page, 'coupon-rules-and', rules);
        await page.locator('[data-ffla-join] .ffla-seg__opt', {hasText: 'OR'}).click();
        text = await summary(page);
        check(/Zqcr Rifles\s+OR\s+tagged\s+Zqcr Sale/.test(text), 'categories OR tags: ' + text);
        check(/Either one is enough/.test(await page.locator('[data-ffla-join-hint]').innerText()), 'OR hint');
        check(await page.locator('[data-ffla-join]').evaluate((el) => el.classList.contains('is-or')), 'OR has its own colour');

        /* Never for */
        await page.evaluate((v) => jQuery('#ffla_exclude_cats').val(v).trigger('change'), [String(ids.nfa)]);
        await pickTag(page, 'ffla_exclude_tags', `${PREFIX} Clearance`);
        text = await summary(page);
        check(/Never\s+in\s+Zqcr NFA\s+OR\s+tagged\s+Zqcr Clearance/i.test(text), 'never-for line: ' + text);

        /* WooCommerce's own restriction and the guardrail in the summary */
        check(/Firearms and protected items are left out/.test(text), 'guardrail note while firearms are not allowed');
        await page.click('li.usage_restriction_options a');
        await page.evaluate((v) => jQuery('#product_categories').val(v).trigger('change'), [String(ids.used_p)]);
        await page.check('#exclude_sale_items');
        await page.click('li.ffla_smart_options a');
        text = await summary(page);
        check(/Also required \(Usage restriction tab\)\s+Zqcr Pistols › Zqcr Used|Also required \(Usage restriction tab\)\s+Zqcr Used/.test(text), 'Usage restriction categories shown: ' + text);
        check(/Also never \(Usage restriction tab\)\s+items on sale/.test(text), 'Exclude sale items shown');
        await page.check('input[name="ffla[allow_protected]"]');
        check(!/Firearms and protected/.test(await summary(page)), 'guardrail note gone when the coupon allows firearms');
        await shot(page, 'coupon-rules-or', rules);

        /* Save and reload */
        await page.click('li.usage_restriction_options a');
        await page.evaluate(() => jQuery('#product_categories').val([]).trigger('change'));
        await page.uncheck('#exclude_sale_items');
        await Promise.all([page.waitForNavigation(), page.click('#publish')]);
        const saved = json(`echo wp_json_encode(FFLA_Coupon_Settings::coupon(${ids.ui}));`);
        check(saved.cats.slice().sort().join() === [ids.rifles, ids.pistols].sort().join() && saved.cats_match === 'any', 'categories saved with Any');
        check(saved.tags.join() === String(ids.sale) && saved.join === 'or', 'tag and OR saved');
        check(saved.exclude_cats.join() === String(ids.nfa) && saved.exclude_tags.join() === String(ids.clear), 'never-for saved');
        check(!('all_cats' in saved), 'old key not written');
        await page.click('li.ffla_smart_options a');
        check(await page.locator('input[name="ffla[join]"][value="or"]').isChecked(), 'OR still selected after reload');
        check(/Zqcr Rifles\s+OR\s+tagged\s+Zqcr Sale/.test(await summary(page)), 'summary rebuilt on load');

        /* 1.55.1 options */
        await openTab(page, ids.legacy);
        check(await page.locator('input[name="ffla[cats_match]"][value="all"]').isChecked(), '"In all of these categories" opens as All');
        const legacySel = await page.locator('#ffla_cats').evaluate((el) => Array.from(el.selectedOptions).map((o) => o.value));
        check(legacySel.join() === [ids.rifles, ids.used_r].join(), 'and keeps its categories');
        check(/Zqcr Rifles\s+AND\s+Zqcr Rifles › Zqcr Used/.test(await summary(page)), 'legacy summary reads AND');

        /* Phone width */
        await page.setViewportSize({width: 390, height: 900});
        await openTab(page, ids.ui);
        const overflow = await page.evaluate(() => {
            const box = document.querySelector('[data-ffla-rules]').getBoundingClientRect();
            return document.documentElement.scrollWidth > window.innerWidth + 1 || box.right > window.innerWidth + 1;
        });
        check(!overflow, 'no horizontal overflow at 390px');
        await shot(page, 'coupon-rules-390', page.locator('[data-ffla-rules]'));

        check(errors.length === 0, 'no JavaScript errors: ' + errors.join(' | '));
        console.log(`\n${checks} checks passed (coupon rules UI).`);
    } finally {
        await browser.close();
        wp(CLEANUP_PHP);
    }
})().catch((e) => {
    console.error('FAIL:', e.message);
    try { wp(CLEANUP_PHP); } catch (x) { /* ignore */ }
    process.exit(1);
});
