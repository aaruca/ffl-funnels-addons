/**
 * Customer requests — photos, videos and the viewer: browser checks against
 * the local test site.
 *
 * Creates an order, a request and a page with the request form, then checks
 * the file picker on the staff screen and the customer form: choosing photos
 * twice adds to the list, removing one, pasting a screenshot, dropping a file,
 * an iPhone HEIC photo the browser cannot convert, full-size phone photos
 * (more than the server would take in one upload) shrunk in the browser, a
 * submit made while photos are still being prepared, a video, the full-size
 * viewer with arrows and Esc, the Photos & files box, and the per-upload limit
 * message. Everything it creates is deleted at the end.
 *
 * NODE_PATH=$(npm root -g) FFLA_TEST_BROWSER_CHANNEL=chromium \
 *   node tests/smoke/customer-requests-files-ui-smoke.js [screenshot-dir]
 *
 * Env: FFLA_TEST_SITE (default http://127.0.0.1:8899), FFLA_TEST_WPCLI
 * (default /tmp/claude-0/wpc), FFLA_TEST_USER / FFLA_TEST_PASS (admin / admin).
 * Needs ffmpeg for the test video (skipped without it).
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
const SHOTS = process.argv[2] ? path.resolve(process.argv[2]) : '';
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

async function shot(target, name) {
    if (SHOTS) {
        fs.mkdirSync(SHOTS, {recursive: true});
        await target.screenshot({path: path.join(SHOTS, name + '.png')});
    }
}

const SETUP_PHP = `
$p = new WC_Product_Simple(); $p->set_name('Zqrf photo test'); $p->set_regular_price('20'); $pid = $p->save();
$o = wc_create_order(); $o->add_product(wc_get_product($pid), 1); $o->set_billing_email('zqrf@example.com'); $o->set_billing_first_name('Pat'); $o->set_status('completed'); $o->save();
$r = FFLA_Requests::create($o, ['type' => 'issue', 'reason' => 'damaged', 'message' => 'Box arrived crushed.', 'items' => []], 'staff', ['type' => 'staff', 'id' => 1]);
$page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Zqrf requests', 'post_content' => '[ffla_order_requests]']);
echo wp_json_encode(['pid' => $pid, 'order' => $o->get_id(), 'id' => (int) $r->id, 'number' => $r->number, 'key' => FFLA_Requests::access_key($r), 'page' => get_permalink($page), 'page_id' => $page]);`;

const cleanup = (ids) => `
$r = FFLA_Requests::get(${ids.id}); if ($r) { FFLA_Requests::delete($r); }
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

/** Paste or drop a small PNG made in the page, as a user would. */
function sendImage(page, selector, how) {
    return page.evaluate(async ({selector, how}) => {
        const c = document.createElement('canvas');
        c.width = 300;
        c.height = 200;
        c.getContext('2d').fillRect(0, 0, 300, 200);
        const blob = await new Promise((r) => c.toBlob(r, 'image/png'));
        const dt = new DataTransfer();
        dt.items.add(new File([blob], how === 'paste' ? 'image.png' : 'dropped.png', {type: 'image/png'}));
        const target = document.querySelector(selector);
        if (how === 'paste') {
            target.focus();
            target.dispatchEvent(new ClipboardEvent('paste', {clipboardData: dt, bubbles: true, cancelable: true}));
        } else {
            const box = target.closest('.ffla-pick');
            box.dispatchEvent(new DragEvent('dragover', {dataTransfer: dt, bubbles: true, cancelable: true}));
            box.dispatchEvent(new DragEvent('drop', {dataTransfer: dt, bubbles: true, cancelable: true}));
        }
    }, {selector, how});
}

const items = (picker) => picker.locator('.ffla-pick__item');
const notBusy = (page, sel) => page.waitForFunction((s) => !document.querySelector(s).hasAttribute('data-ffla-busy'), sel, {timeout: 120000});

(async () => {
    const ids = json(SETUP_PHP);
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ffla-req-photos-'));
    const browser = await chromium.launch({channel: process.env.FFLA_TEST_BROWSER_CHANNEL || undefined});
    const page = await browser.newPage({viewport: {width: 1300, height: 1000}});
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    let clip = '';
    try {
        clip = path.join(tmp, 'unboxing.mp4');
        execFileSync('ffmpeg', ['-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=red:s=64x64:d=1', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', clip]);
    } catch (e) {
        clip = '';
    }
    const heic = path.join(tmp, 'IMG_9001.HEIC');
    fs.writeFileSync(heic, Buffer.concat([Buffer.from('\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic', 'latin1'), Buffer.alloc(800)]));

    try {
        await page.goto(`${SITE}/wp-login.php`);
        await page.fill('#user_login', USER);
        await page.fill('#user_pass', PASS);
        await page.click('#wp-submit');
        await page.waitForURL(/wp-admin/, {waitUntil: 'commit'});

        const photos = await makePhotos(page, tmp, 'staff-', PHOTOS);
        const total = photos.reduce((sum, f) => sum + fs.statSync(f).size, 0);
        check(total > 40 * 1048576, `the ${PHOTOS} photos weigh more than the server's 40 MB upload limit (${Math.round(total / 1048576)} MB)`);

        /* Staff note: pick twice, remove, paste, drop, HEIC, then send */
        await page.goto(`${SITE}/wp-admin/admin.php?page=ffla-requests&request=${ids.id}`);
        await page.click('#ffla-req-tab-note');
        const input = page.locator('#ffla-req-files-note');
        const picker = page.locator('#ffla-req-note .ffla-pick');
        check(await picker.locator('.ffla-pick__drop').isVisible(), 'drop zone with Choose files');
        await page.fill('#ffla-req-note textarea[name=message]', 'Damage photos.');
        await input.setInputFiles(photos.slice(0, 8));
        await input.setInputFiles(photos.slice(8));
        check(await items(picker).count() === PHOTOS, 'choosing again adds to the list (15 thumbnails)');
        await notBusy(page, '#ffla-req-files-note');
        await shot(picker, 'picker-staff');
        await picker.locator('.ffla-pick__remove').first().click();
        check(await items(picker).count() === PHOTOS - 1, 'one photo removed');
        await sendImage(page, '#ffla-req-note textarea[name=message]', 'paste');
        await sendImage(page, '#ffla-req-files-note', 'drop');
        await notBusy(page, '#ffla-req-files-note');
        const names = await picker.locator('.ffla-pick__name').allInnerTexts();
        check(names.some((n) => /^screenshot-\d{8}-\d{6}\.png$/.test(n)) && names.includes('dropped.png'), 'pasted screenshot and dropped file added: ' + names.slice(-2).join(', '));
        check(await page.inputValue('#ffla-req-note textarea[name=message]') === 'Damage photos.', 'pasting an image leaves the message text alone');
        await input.setInputFiles([heic]);
        await notBusy(page, '#ffla-req-files-note');
        const heicItem = picker.locator('.ffla-pick__item.is-error');
        check(await heicItem.count() === 1 && /HEIC/.test(await heicItem.innerText()), 'HEIC the browser cannot convert is flagged with a fix');
        const sizes = await input.evaluate((el) => Array.from(el.files).map((f) => f.size));
        check(sizes.length === PHOTOS + 1, 'the input sends exactly the good files (' + sizes.length + ')');
        check(Math.max(...sizes) < 2 * 1048576, 'photos shrunk in the browser (largest ' + Math.round(Math.max(...sizes) / 1024) + ' KB)');
        check(/16 files ready/.test(await picker.locator('.ffla-pick__count').innerText()), 'count of files ready');
        await shot(picker, 'picker-staff-final');
        await Promise.all([page.waitForNavigation(), page.click('#ffla-req-note [type=submit]')]);
        check(/Internal note added/.test(await page.content()), 'staff note saved');
        check(stored(ids, 'staff-') === PHOTOS - 1 && stored(ids, 'screenshot-') === 1 && stored(ids, 'dropped') === 1 && stored(ids, 'IMG_9001') === 0, 'all good files stored, the HEIC not');

        /* Video, and a submit while photos are still being prepared */
        await page.click('#ffla-req-tab-note');
        await page.fill('#ffla-req-note textarea[name=message]', 'Video and three more, sent at once.');
        await input.setInputFiles((clip ? [clip] : []).concat(photos.slice(0, 3).map((f) => {
            const copy = path.join(tmp, 'quick-' + path.basename(f));
            fs.copyFileSync(f, copy);
            return copy;
        })));
        await Promise.all([page.waitForNavigation({timeout: 120000}), page.click('#ffla-req-note [type=submit]')]);
        check(stored(ids, 'quick-') === 3, 'a submit during preparation waits, then sends the photos');
        if (clip) {
            check(stored(ids, 'unboxing') === 1, 'video stored');
            check(await page.locator('.ffla-req-timeline a[data-ffla-view=video] video').count() === 1, 'video shows in the history');
        }

        /* Photos & files box and the viewer */
        const box = page.locator('#ffla-req-files-box');
        check(/19 photos/.test(await box.innerText()) && await box.locator('a', {hasText: 'Download all (ZIP)'}).count() === 1, 'Photos & files box with count and ZIP');
        check(await box.locator('details.ffla-req-claim[open]').count() === 1, 'claim packet open for a damage issue');
        await shot(box, 'files-box');
        const thumbs = page.locator('.ffla-req-timeline a[data-ffla-view]');
        const count = await thumbs.count();
        await thumbs.first().click();
        const viewer = page.locator('.ffla-lb');
        await viewer.waitFor();
        check(await viewer.locator('.ffla-lb__count').innerText() === `1 of ${count}`, 'viewer opens on the clicked photo');
        await page.keyboard.press('ArrowRight');
        check(await viewer.locator('.ffla-lb__count').innerText() === `2 of ${count}`, 'arrow key moves to the next');
        await page.keyboard.press('ArrowLeft');
        await page.keyboard.press('ArrowLeft');
        check(await viewer.locator('.ffla-lb__count').innerText() === `${count} of ${count}`, 'wraps around');
        check(/download=1/.test(await viewer.locator('.ffla-lb__dl').getAttribute('href')), 'Download link in the viewer');
        await page.keyboard.press('ArrowLeft');
        await viewer.locator('img').evaluate((img) => img.complete || new Promise((r) => { img.onload = r; }));
        await shot(page, 'viewer');
        await page.keyboard.press('Escape');
        check(await viewer.count() === 0, 'Esc closes the viewer');
        if (clip) {
            await page.locator('.ffla-req-timeline a[data-ffla-view=video]').click();
            await viewer.waitFor();
            check(await viewer.locator('video[controls]').count() === 1, 'the video plays in the viewer');
            await page.keyboard.press('Escape');
        }

        /* Customer: 15 photos and a video on the request page, viewer, limit */
        const customer = await browser.newPage({viewport: {width: 390, height: 900}});
        customer.on('pageerror', (e) => errors.push(e.message));
        await customer.goto(`${ids.page}${ids.page.includes('?') ? '&' : '?'}ffla_request=${encodeURIComponent(ids.number)}&key=${ids.key}#ffla-requests`);
        const reply = customer.locator('form.ffla-req-reply');
        await reply.waitFor({timeout: 30000});
        check(/as many as you need/i.test(await reply.locator('.ffla-req-muted').last().innerText()), 'customer hint has no file count');
        await reply.locator('textarea[name=message]').fill('Here are all the photos.');
        const custInput = reply.locator('input[type=file]');
        const custFiles = photos.map((f) => {
            const copy = path.join(tmp, 'cust-' + path.basename(f));
            fs.copyFileSync(f, copy);
            return copy;
        });
        await custInput.setInputFiles(custFiles.slice(0, 10));
        await custInput.setInputFiles(custFiles.slice(10).concat(clip ? [clip] : []));
        check(await items(reply.locator('.ffla-pick')).count() === PHOTOS + (clip ? 1 : 0), 'customer can add photos in several goes');
        await shot(reply, 'picker-customer');
        await reply.locator('[type=submit]').click(); // Straight away: the form waits for the photos.
        try {
            await customer.locator('.ffla-req-notice--success').first().waitFor({timeout: 120000});
        } catch (e) {
            throw new Error('customer reply not sent: ' + (await reply.locator('.ffla-req-error').allInnerTexts()).join(' | '));
        }
        check(stored(ids, 'cust-') === PHOTOS, 'all 15 customer photos stored');
        const custThumbs = customer.locator('.ffla-req-timeline a[data-ffla-view]');
        await custThumbs.first().click();
        await customer.locator('.ffla-lb').waitFor();
        check(/^1 of \d+$/.test(await customer.locator('.ffla-lb__count').innerText()), 'customer viewer opens');
        await shot(customer, 'viewer-customer');
        await customer.locator('.ffla-lb__close').click();

        const perUpload = Number(wp('echo FFLA_Requests_Files::per_upload();'));
        await customer.reload();
        const reply2 = customer.locator('form.ffla-req-reply');
        await reply2.waitFor();
        const tiny = Array.from({length: perUpload + 1}, (_, i) => {
            const f = path.join(tmp, `t${i}.pdf`);
            fs.writeFileSync(f, '%PDF-1.4\n%%EOF\n' + i);
            return f;
        });
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
