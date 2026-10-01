/* Offline browser regression: real PHP markup, local JS/CSS, synthetic orders. No site requests. */
'use strict';
const {chromium} = require('playwright');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const fixture = JSON.parse(execFileSync('php', [path.join(__dirname, 'customer-operations-smoke.php'), 'fixture'], {encoding: 'utf8'}));
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; }
(async function () {
    const browser = await chromium.launch({headless:true, channel:process.env.FFLA_TEST_BROWSER_CHANNEL || 'chrome'});
    const errors = [];
    try {
        for (const width of [1440,390]) {
            for (const kind of ['settings','order']) {
                const page = await browser.newPage({viewport:{width,height:1000}});
                page.on('pageerror', e => errors.push(e.message));
                await page.route('**/*', r => r.abort());
                await page.setContent('<html lang="en"><meta charset="utf-8"><body><main class="ffla-admin">' + fixture[kind] + '</main></body></html>');
                await page.addStyleTag({path:path.join(root,'admin/css/ffla-admin.css')});
                await page.addStyleTag({content:'body{font:14px system-ui;margin:0;background:#f5f6f8}.ffla-admin{padding:20px;margin:0}input,select,textarea{font:inherit;padding:8px;border:1px solid #aaa;border-radius:4px}input[type=checkbox]{padding:0}button{padding:8px 12px;cursor:pointer}.button-primary{background:#2271b1;color:white;border:0;border-radius:4px}'});
                await page.addStyleTag({path:path.join(root,'modules/customer-notes/assets/operations.css')});
                await page.evaluate(() => {
                    window.fflaOps={ajax:'https://fixture.invalid/admin-ajax.php'};
                    window.calls=[];
                    window.fetch=async (url, options) => {
                        window.calls.push(Array.from(options.body.entries()).map(([k,v])=>[k,typeof v==='string'?v:'FILE']));
                        return {json:async()=>({success:true,data:{message:'Fixture saved',reload:false}})};
                    };
                });
                await page.addScriptTag({path:path.join(root,'modules/customer-notes/assets/operations.js')});
                check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'no horizontal overflow '+kind+' '+width);
                check(await page.locator('.ffla-ops input[type=checkbox]:not(.ffla-set-switch):visible').evaluateAll(items=>items.every(x=>x.getBoundingClientRect().width===16)),'checkbox not stretched '+kind+' '+width);
                if (kind==='settings') {
                    check(await page.locator('input[role=switch]').count()===35,'all independent switches rendered (21 + 14 customer request switches)');
                    check(await page.locator('input.ffla-set-switch:visible').evaluateAll(items=>items.length>0&&items.every(x=>x.getBoundingClientRect().width===38)),'switches render as 38px toggles '+width);
                    check(await page.locator('form:has(input[name=action][value=ffla_ops_settings])').count()===1&&await page.locator('form:has(input[name=action][value=ffla_ops_settings]) section.ffla-set-panel').count()===8,'one settings form keeps every section');
                    check(await page.locator('section.ffla-set-panel:visible').count()===1&&await page.locator('#general').isVisible(),'one section at a time, General first '+width);
                    await page.locator('[data-section-link=pickup]').click();
                    check(await page.locator('#pickup').isVisible()&&!(await page.locator('#general').isVisible())&&await page.locator('[data-section-field]').inputValue()==='pickup','section switch remembers where to return after saving '+width);
                    const pickupOn=await page.locator('#ffla-ops-pickup').isChecked();
                    if (pickupOn) { await page.locator('label[for=ffla-ops-pickup]').click(); }
                    check(await page.locator('[data-key=partial_pickup]').evaluate(r=>r.classList.contains('is-waiting'))&&await page.locator('[data-key=pickup] [data-main-hint]').isVisible(),'settings that need the main switch are dimmed with one hint '+width);
                    await page.locator('label[for=ffla-ops-pickup]').click();
                    check(!(await page.locator('[data-key=partial_pickup]').evaluate(r=>r.classList.contains('is-waiting')))&&(await page.locator('[data-state-for=pickup]').textContent())!=='Off','turning the main switch on updates the section status '+width);
                    if (!pickupOn) { await page.locator('label[for=ffla-ops-pickup]').click(); } // Back to the saved value.
                    check(await page.locator('[data-savebar]').getAttribute('data-state')!=='dirty','back to saved values is clean '+width);
                    const name=await page.locator('#ffla-ops-store_name').inputValue();
                    await page.locator('#ffla-ops-store_name').fill(name+' X');
                    check(await page.locator('[data-savebar]').getAttribute('data-state')==='dirty'&&(await page.locator('[data-save-status]').textContent()).toLowerCase().includes('unsaved'),'unsaved changes are announced '+width);
                    await page.locator('#ffla-ops-store_name').fill(name);
                    await page.locator('[data-section-link="email-preview"]').click();
                    await page.locator('[data-template-action=preview]').click();
                    await page.waitForFunction(()=>document.querySelector('.ffla-ops-result').textContent==='Fixture saved');
                    check(await page.evaluate(()=>window.calls[0].some(([k,v])=>k==='operation'&&v==='preview')),'preview payload '+width);
                } else {
                    check(await page.locator('form').count()===0,'no nested form in native order editor');
                    await page.locator('[name="ops[items][11][serials]"]').fill('SN-ONE\nSN-TWO');
                    await page.locator('[name="ops[items][11][serials]"]').dispatchEvent('change');
                    await page.locator('[data-ops-action=upload]').click();
                    check(await page.evaluate(()=>window.calls.length===0),'dirty changes protected before separate action');
                    await page.locator('[data-ops-action=save]').first().click();
                    await page.waitForFunction(()=>document.querySelector('.ffla-ops-result').textContent==='Fixture saved');
                    check(await page.evaluate(()=>window.calls[0].some(([k,v])=>k==='ops[items][11][serials]'&&v==='SN-ONE\nSN-TWO')),'serial payload '+width);
                    check(await page.evaluate(()=>window.calls[0].some(([k,v])=>k==='nonce'&&v==='fixture-nonce')),'nonce bound payload '+width);
                    await page.evaluate(()=> { window.fetch=async()=>({json:async()=>({success:false,data:{message:'Session expired'}})}); });
                    await page.locator('[data-ops-action=save]').first().click();
                    await page.waitForFunction(()=>document.querySelector('.ffla-ops-result').textContent==='Session expired');
                    check(await page.locator('[data-ops-action=save]').first().isEnabled(),'error restores actionable controls '+width);
                }
                if(process.argv[2]) {
                    const out=path.resolve(process.argv[2]);fs.mkdirSync(out,{recursive:true});
                    await page.screenshot({path:path.join(out,kind+'-'+width+'.png'),fullPage:true});
                }
                await page.close();
            }
        }
        check(errors.length===0,'no browser exceptions: '+errors.join(', '));
        console.log(checks+' Customer operations UI checks passed.');
    } finally { await browser.close(); }
}()).catch(e=>{console.error(e);process.exitCode=1;});
