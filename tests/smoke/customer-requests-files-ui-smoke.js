/**
 * Customer requests — many photos in one message: browser checks against the
 * local test site.
 *
 * Creates an order, a request and a page with the request form, then sends 15
 * full-size phone photos (about 4 MB each, more than the server would accept in
 * one upload) as a staff internal note and as a customer reply. Checks that the
 * photos are shrunk in the browser, that all 15 are stored, and that more files
 * than the server accepts at once get a clear message. Everything it creates is
 * deleted at the end.
 *
 * NODE_PATH=$(npm root -g) FFLA_TEST_BROWSER_CHANNEL=chromium \
 *   node tests/smoke/customer-requests-files-ui-smoke.js
 *
 * Env: FFLA_TEST_SITE (default http://127.0.0.1:8899), FFLA_TEST_WPCLI
 * (default /tmp/claude-0/wpc), FFLA_TEST_USER / FFLA_TEST_PASS (admin / admin).
 */
'use strict';

const {chromium} = require('playwright');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');

const SITE = (process.env.FFLA_TEST_SITE || 'http://127.0.0.1:8899').replace(/\/$/, '');
const WPCLI = process.env.FFLA_TEST_WPCLI || '/tmp/claude-0/wpc';
const USER = process.env.FFLA_TEST_USER || 'admin';
const PASS = process.env.FFLA_TEST_PASS || 'admin';
const PHOTOS = 15;

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

const SETUP_PHP = `
$p = new WC_Product_Simple(); $p->set_name('Zqrf photo test'); $p->set_regular_price('20'); $pid = $p->save();
$o = wc_create_order(); $o->add_product(wc_get_product($pid), 1); $o->set_billing_email('zqrf@example.com'); $o->set_billing_first_name('Pat'); $o->set_status('completed'); $o->save();
$r = FFLA_Requests::create($o, ['type' => 'issue', 'reason' => 'damaged', 'message' => 'Box arrived crushed.', 'items' => []], 'staff', ['type' => 'staff', 'id' => 1]);
$page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Zqrf requests', 'post_content' => '[ffla_order_requests]']);
echo wp_json_encode(['pid' => $pid, 'order' => $o->get_id(), 'id' => (int) $r->id, 'number' => $r->number, 'key' => FFLA_Requests::access_key($r), 'page' => get_permalink($page), 'page_id' => $page]);`;

const cleanup = (ids) => `
global $wpdb; $t = FFLA_Requests::tables();
foreach ($t as $table) { if ($wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'request_id'))) { $wpdb->delete($table, ['request_id' => ${ids.id}]); } }
$wpdb->delete($t['requests'], ['id' => ${ids.id}]);
$o = wc_get_order(${ids.order}); if ($o) { $o->delete(true); }
wp_delete_post(${ids.pid}, true); wp_delete_post(${ids.page_id}, true);
echo 'ok';`;

const stored = (ids, like) => Number(wp(`global $wpdb; $t = FFLA_Requests::tables(); echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['files']} WHERE request_id = %d AND name LIKE %s", ${ids.id}, '${like}%'));`));

/** Full-size "phone photos": 4032 × 3024 JPEGs with noise so they stay big. */
async function makePhotos(page, dir, prefix, count) {
    const files = [];
    for (let i = 0; i < count; i++) {
        const b64 = await page.evaluate(async (seed) => {
            const c = document.createElement('canvas');
            c.width = 4032;
            c.height = 3024;
            const g = c.getContext('2d');
            const grad = g.createLinearGradient(0, 0, c.width, c.height);
            grad.addColorStop(0, `hsl(${seed * 23 % 360},60%,40%)`);
            grad.addColorStop(1, `hsl(${(seed * 23 + 120) % 360},60%,60%)`);
            g.fillStyle = grad;
            g.fillRect(0, 0, c.width, c.height);
            const img = g.getImageData(0, 0, c.width, c.height);
            for (let p = 0; p < img.data.length; p += 4) {
                const n = (Math.random() - 0.5) * 50;
                img.data[p] += n;
                img.data[p + 1] += n;
                img.data[p + 2] += n;
            }
            g.putImageData(img, 0, 0);
            const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.92));
            const buf = new Uint8Array(await blob.arrayBuffer());
            let s = '';
            for (let k = 0; k < buf.length; k += 0x8000) {
                s += String.fromCharCode.apply(null, buf.subarray(k, k + 0x8000));
            }
            return btoa(s);
        }, i + 1);
        const file = path.join(dir, `${prefix}${String(i + 1).padStart(2, '0')}.jpg`);
        fs.writeFileSync(file, Buffer.from(b64, 'base64'));
        files.push(file);
    }
    return files;
}

const sizes = (locator) => locator.evaluate((el) => Array.from(el.files).map((f) => f.size));

(async () => {
    const ids = json(SETUP_PHP);
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ffla-req-photos-'));
    const browser = await chromium.launch({channel: process.env.FFLA_TEST_BROWSER_CHANNEL || undefined});
    const page = await browser.newPage({viewport: {width: 1300, height: 1000}});
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    try {
        await page.goto(`${SITE}/wp-login.php`);
        await page.fill('#user_login', USER);
        await page.fill('#user_pass', PASS);
        await page.click('#wp-submit');
        await page.waitForURL(/wp-admin/, {waitUntil: 'commit'});

        const staffPhotos = await makePhotos(page, tmp, 'staff-', PHOTOS);
        const total = staffPhotos.reduce((sum, f) => sum + fs.statSync(f).size, 0);
        check(total > 40 * 1048576, `the ${PHOTOS} photos weigh more than the server's 40 MB upload limit (${Math.round(total / 1048576)} MB)`);

        /* Staff: internal note with 15 photos */
        await page.goto(`${SITE}/wp-admin/admin.php?page=ffla-requests&request=${ids.id}`);
        await page.click('#ffla-req-tab-note');
        const hint = await page.locator('#ffla-req-note .description').first().innerText();
        check(/as many as you need/i.test(hint) && !/Up to 3/.test(hint), 'staff hint has no file count: ' + hint);
        const noteInput = page.locator('#ffla-req-files-note');
        await page.fill('#ffla-req-note textarea[name=message]', 'Fifteen photos of the damage.');
        await noteInput.setInputFiles(staffPhotos);
        await page.waitForFunction(() => !document.querySelector('#ffla-req-files-note').hasAttribute('data-ffla-busy'), null, {timeout: 120000});
        const shrunk = await sizes(noteInput);
        check(shrunk.length === PHOTOS, 'all photos still selected after shrinking');
        check(Math.max(...shrunk) < 2 * 1048576, 'each photo shrunk in the browser (largest ' + Math.round(Math.max(...shrunk) / 1024) + ' KB)');
        check(shrunk.reduce((a, b) => a + b, 0) < 40 * 1048576, 'all 15 fit in one upload now');
        const dims = await noteInput.evaluate(async (el) => {
            const bmp = await createImageBitmap(el.files[0]);
            return [bmp.width, bmp.height];
        });
        check(dims[0] === 2000 && dims[1] === 1500, 'longest side 2000 px, proportions kept: ' + dims.join('×'));
        await Promise.all([page.waitForNavigation(), page.click('#ffla-req-note [type=submit]')]);
        check(/Internal note added/.test(await page.content()), 'staff note saved');
        check(stored(ids, 'staff-') === PHOTOS, 'all 15 staff photos stored');

        /* Staff: submit right away, while photos are still being prepared */
        await page.click('#ffla-req-tab-note');
        await page.fill('#ffla-req-note textarea[name=message]', 'Three more, sent at once.');
        await noteInput.setInputFiles(staffPhotos.slice(0, 3).map((f) => {
            const copy = path.join(tmp, 'quick-' + path.basename(f));
            fs.copyFileSync(f, copy);
            return copy;
        }));
        await Promise.all([page.waitForNavigation({timeout: 120000}), page.click('#ffla-req-note [type=submit]')]);
        check(stored(ids, 'quick-') === 3, 'a submit during preparation waits, then sends all 3');

        /* Customer: reply with 15 photos on the request page */
        const customerPhotos = staffPhotos.map((f) => {
            const copy = path.join(tmp, 'cust-' + path.basename(f));
            fs.copyFileSync(f, copy);
            return copy;
        });
        const customer = await browser.newPage({viewport: {width: 390, height: 900}});
        customer.on('pageerror', (e) => errors.push(e.message));
        await customer.goto(`${ids.page}${ids.page.includes('?') ? '&' : '?'}ffla_request=${encodeURIComponent(ids.number)}&key=${ids.key}#ffla-requests`);
        const reply = customer.locator('form.ffla-req-reply');
        await reply.waitFor({timeout: 30000});
        const customerHint = await reply.locator('.ffla-req-muted').last().innerText();
        check(/as many as you need/i.test(customerHint), 'customer hint has no file count: ' + customerHint);
        await reply.locator('textarea[name=message]').fill('Here are all the photos.');
        const custInput = reply.locator('input[type=file]');
        await custInput.setInputFiles(customerPhotos);
        await reply.locator('[type=submit]').click(); // Straight away: the form waits for the photos.
        await customer.locator('.ffla-req-notice--success').first().waitFor({timeout: 120000});
        check(stored(ids, 'cust-') === PHOTOS, 'all 15 customer photos stored');

        /* More files than PHP accepts at once (max_file_uploads) */
        const perUpload = Number(wp('echo FFLA_Requests_Files::per_upload();'));
        await customer.reload();
        const reply2 = customer.locator('form.ffla-req-reply');
        await reply2.waitFor();
        const tiny = Array.from({length: perUpload + 1}, (_, i) => ({name: `t${i}.png`, mimeType: 'image/png', buffer: fs.readFileSync(path.join(__dirname, '..', '..', 'modules', 'customer-notes', 'assets', 'requests.css')).subarray(0, 10)}));
        await reply2.locator('textarea[name=message]').fill('Too many.');
        await reply2.locator('input[type=file]').setInputFiles(tiny);
        await reply2.locator('[type=submit]').click();
        const err = await reply2.locator('.ffla-req-error').first().innerText();
        check(new RegExp(`send ${perUpload} files at a time`).test(err), 'over the server limit: clear message instead of lost files: ' + err);

        check(errors.length === 0, 'no JavaScript errors: ' + errors.join(' | '));
        console.log(`\n${checks} checks passed (request photos UI).`);
    } finally {
        await browser.close();
        fs.rmSync(tmp, {recursive: true, force: true});
        wp(cleanup(ids));
    }
})().catch((e) => {
    console.error('FAIL:', e.message);
    process.exit(1);
});
