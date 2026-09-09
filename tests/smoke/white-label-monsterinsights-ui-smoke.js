/**
 * Offline browser checks using the actual PHP view, CSS, JS and vendored Chart.js.
 * Requires Playwright + installed Chromium. All data is synthetic; network blocked.
 * NODE_PATH=/path/to/node_modules node tests/smoke/white-label-monsterinsights-ui-smoke.js [screenshot-dir]
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const fixture = JSON.parse(execFileSync('php', [path.join(__dirname, 'white-label-monsterinsights-smoke.php'), 'fixture'], { encoding: 'utf8' }));
let checks = 0;
function check(condition, label) { assert.ok(condition, label); checks++; }
(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.FFLA_TEST_BROWSER_CHANNEL ? { channel: process.env.FFLA_TEST_BROWSER_CHANNEL } : {}) });
    const errors = [];
    try {
        async function pageFixture(width, dark = false, manual = false) {
            const page = await browser.newPage({ viewport: { width, height: 1000 } });
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => route.abort());
            await page.setContent('<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body class="index-php ' + (dark ? 'ffla-theme-dark' : 'ffla-theme-light') + '"><main id="wpcontent">' + fixture.html + '</main></body></html>');
            await page.addStyleTag({ content: 'body{margin:0;font-family:Arial,sans-serif}#wpcontent{padding:20px}*{box-sizing:border-box}.screen-reader-text{position:absolute;width:1px;height:1px;clip-path:inset(50%);overflow:hidden}.button{padding:10px;border-radius:5px}.button-primary{background:#2563eb;color:white}' });
            await page.addStyleTag({ path: path.join(root, 'modules/white-label/admin/css/white-label-dashboard.css') });
            await page.evaluate(({ report, manual }) => {
                window.fflaWhiteLabelDashboard = { ajaxUrl: 'https://fixture.invalid/admin-ajax.php', nonce: 'synthetic-nonce', initialSource: 'google', initialRange: 30 };
                window.__requests = [];
                window.__pending = [];
                window.__report = report;
                window.fetch = (url, options) => {
                    const params = new URLSearchParams(options.body);
                    window.__requests.push(Object.fromEntries(params.entries()));
                    if (manual) { return new Promise(resolve => { window.__pending.push(resolve); }); }
                    const data = params.get('source') === 'snapfind'
                        ? { source: 'snapfind', status: 'ready', metrics: [{ label: 'Searches', value: 17 }], funnel: [], top_terms: [] }
                        : report;
                    return Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true, data }) });
                };
            }, { report: fixture.report, manual });
            await page.addScriptTag({ path: path.join(root, 'modules/woobooster/assets/lib/chart.umd.js') });
            await page.evaluate(() => { window.Chart.defaults.animation = false; });
            await page.addScriptTag({ path: path.join(root, 'modules/white-label/admin/js/white-label-dashboard.js') });
            if (!manual) { await page.locator('.ffla-dash-analytics-metric').first().waitFor(); }
            return page;
        }
        for (const [width, dark, name] of [[1440, false, 'light-desktop'], [1440, true, 'dark-desktop'], [390, false, 'light-mobile'], [390, true, 'dark-mobile'], [768, false, 'tablet']]) {
            const page = await pageFixture(width, dark);
            const content = await page.locator('[data-ffla-analytics-panel]').innerText();
            check(content.includes('Sessions') && content.includes('Top pages') && content.includes('Purchases'), name + ' has MI traffic and commerce');
            check(!content.includes('Rank Math') && !content.includes('Organic search traffic'), name + ' no obsolete provider labels');
            check(await page.locator('.ffla-dash-tiles--business .ffla-dash-tile').count() === 3, name + ' preserves Woo cards');
            check(await page.locator('.ffla-dash-analytics-metric').count() === 8, name + ' traffic and commerce cards');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), name + ' no page-wide horizontal overflow');
            check(await page.locator('.ffla-dash-table-wrap').count() === 3, name + ' tables use overflow regions');
            if (process.argv[2] && name !== 'tablet') {
                const out = path.resolve(process.argv[2]); fs.mkdirSync(out, { recursive: true });
                await page.locator('[data-ffla-analytics]').screenshot({ path: path.join(out, 'wl-mi-' + name + '.png') });
            }
            if (width < 500) {
                check(await page.locator('.ffla-dash-table-wrap').first().evaluate(el => el.scrollWidth > el.clientWidth), name + ' table scrolls within panel');
            }
            const th = page.locator('.ffla-dash-table-wrap').first().locator('th').nth(1);
            await th.focus(); await page.keyboard.press('Enter');
            check(await th.getAttribute('aria-sort') === 'ascending', name + ' keyboard sorting ARIA');
            check(await page.locator('.ffla-dash-table-wrap').first().locator('tbody tr').first().innerText() === '/second/\t50', name + ' numeric ascending order');
            const range = page.locator('[data-ffla-analytics-range]');
            await range.selectOption('7');
            check(await page.evaluate(() => window.__requests.at(-1).range === '7'), name + ' range sent to server');
            await page.locator('#ffla-dashboard-tab-snapfind').click();
            await page.getByText('On-site search funnel', { exact: true }).waitFor();
            check(await page.locator('#ffla-dashboard-tab-snapfind').getAttribute('aria-selected') === 'true', name + ' SnapFind preserved');
            await page.locator('#ffla-dashboard-tab-snapfind').focus(); await page.keyboard.press('ArrowLeft');
            check(await page.locator('#ffla-dashboard-tab-google').getAttribute('aria-selected') === 'true', name + ' keyboard provider switch');
            check(await page.evaluate(() => window.__requests.length === 3), name + ' client cache avoids duplicate requests');
            await page.close();
        }
        const page = await pageFixture(1200, false, true);
        check(await page.locator('[data-ffla-analytics-panel]').getAttribute('aria-busy') === 'true', 'loading state accessible');
        await page.locator('#ffla-dashboard-tab-snapfind').click();
        await page.evaluate(() => {
            window.__pending[1]({ ok: true, json: () => Promise.resolve({ success: true, data: { source:'snapfind', status:'ready', metrics:[{label:'Current tab',value:17}], funnel:[], top_terms:[] } }) });
        });
        await page.getByText('Current tab', { exact: true }).waitFor();
        await page.evaluate(() => window.__pending[0]({ ok: true, json: () => Promise.resolve({ success: true, data: window.__report }) }));
        check(!(await page.locator('[data-ffla-analytics-panel]').innerText()).includes('Top pages'), 'late response cannot replace active tab');
        await page.locator('#ffla-dashboard-tab-google').click();
        await page.evaluate(() => window.__pending[2]({ ok:false, status:503 }));
        await page.getByText('Analytics could not be loaded.', { exact: true }).waitFor();
        check(await page.locator('[data-ffla-analytics-panel]').getAttribute('aria-busy') === 'false', 'HTTP failure clears spinner');
        await page.locator('#ffla-dashboard-tab-google').click();
        await page.evaluate(() => window.__pending[3]({ ok:true, json:() => Promise.resolve({success:true,data:{status:'unavailable',message:'Your role cannot view reports.', action_url:''}}) }));
        await page.getByText('Your role cannot view reports.', { exact: true }).waitFor();
        check(await page.locator('[data-ffla-analytics-panel] a').count() === 0, 'restricted role has no settings link');
        await page.locator('#ffla-dashboard-tab-google').click();
        await page.evaluate(() => {
            const data = structuredClone(window.__report);
            data.tables[0].rows[0][0] = '<img src=x onerror="window.__xss=true">';
            data.metrics[0].value = null;
            window.__pending[4]({ ok:true, json:() => Promise.resolve({ success:true, data }) });
        });
        await page.locator('.ffla-dash-analytics-metric').first().waitFor();
        check(await page.locator('.ffla-dash-analytics-metric__value').first().innerText() === '—', 'missing value is not zero');
        check(await page.locator('[data-ffla-analytics-panel] img').count() === 0 && !await page.evaluate(() => window.__xss), 'table provider strings are text, not executable HTML');
        await page.close();
        check(errors.length === 0, 'no browser runtime errors: ' + errors.join('; '));
        console.log(checks + ' browser checks passed (offline fixtures, 5 layouts).');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
