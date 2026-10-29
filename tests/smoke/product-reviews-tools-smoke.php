<?php
/**
 * Offline check of CSV export and import (reviews and Q&A) and the copy of
 * photos and votes from Customer Reviews for WooCommerce.
 *
 * Run: php tests/smoke/product-reviews-tools-smoke.php
 *
 * Export: columns, what is left out, replies, criteria, media URLs, and
 * cells a spreadsheet would run as formulas. Import: background batches of
 * 100 rows without gaps or repeats, product by ID or SKU, the other plugin's
 * column names, replies and answers under their parent, media downloaded and
 * cleaned of location data, duplicates skipped (also an export of this very
 * store), pending mode, no emails or coupons, WooCommerce recounts, a file
 * without the needed columns refused.
 */

require __DIR__ . '/product-reviews-stubs.php';

$GLOBALS['is_admin'] = true;

load_module([
    'includes/class-product-reviews-core.php',
    'includes/class-product-reviews-query.php',
    'includes/class-product-reviews-criteria.php',
    'includes/class-product-reviews-frontend-render.php',
    'includes/class-product-reviews-image-privacy.php',
    'includes/class-product-reviews-assets.php',
    'includes/class-product-reviews-moderation.php',
    'includes/class-product-reviews-notifications.php',
    'includes/class-product-reviews-email-template.php',
    'includes/class-product-reviews-request-rules.php',
    'includes/class-product-reviews-consent.php',
    'includes/class-product-reviews-tracking.php',
    'includes/class-product-reviews-email.php',
    'includes/class-product-reviews-coupons.php',
    'includes/class-product-reviews-qna.php',
    'includes/class-product-reviews-import.php',
    'admin/class-product-reviews-tools-admin.php',
]);
Product_Reviews_Core::init();
Product_Reviews_Notifications::init();
Product_Reviews_Coupons::init();
Product_Reviews_Qna::init();
Product_Reviews_Import::init();

$GLOBALS['options']['ffla_product_reviews_settings'] = ['order_review_page_id' => '500', 'coupon_enabled' => '1', 'qna_enabled' => '1'];
@mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
foreach (['file.php', 'media.php', 'image.php'] as $wp_admin_file) {
    file_put_contents(ABSPATH . 'wp-admin/includes/' . $wp_admin_file, "<?php\n");
}

function csv_rows(string $csv): array
{
    $h    = fopen('php://memory', 'r+');
    fwrite($h, $csv);
    rewind($h);
    $rows = [];
    while (false !== ($row = fgetcsv($h, 0, ',', '"', ''))) {
        $rows[] = $row;
    }
    fclose($h);
    return $rows;
}

function export(string $kind): array
{
    $h = fopen('php://memory', 'r+');
    'qna' === $kind ? Product_Reviews_Import::export_qna($h) : Product_Reviews_Import::export_reviews($h);
    rewind($h);
    $csv = stream_get_contents($h);
    fclose($h);
    $rows   = csv_rows($csv);
    $header = array_shift($rows);
    return [$header, array_map(function ($r) use ($header) {
        return array_combine($header, $r);
    }, $rows), $csv];
}

/** Write rows to a file in the import folder and queue it, as the upload does. */
function import_file(array $rows, string $kind = 'reviews', string $mode = 'keep', string $delimiter = ','): array
{
    $h = fopen('php://memory', 'r+');
    foreach ($rows as $row) {
        fputcsv($h, $row, $delimiter, '"', '');
    }
    rewind($h);
    $csv = stream_get_contents($h);
    fclose($h);
    return import_raw($csv, $kind, $mode);
}

/** Queue a file with exactly these bytes. */
function import_raw(string $csv, string $kind = 'reviews', string $mode = 'keep'): array
{
    $path = Product_Reviews_Import::storage_dir() . '/test-' . count(glob(Product_Reviews_Import::storage_dir() . '/*.csv') ?: []) . '-' . mt_rand() . '.csv';
    file_put_contents($path, $csv);
    return Product_Reviews_Import::queue_file($path, $kind, $mode);
}

function find_comment(string $content): ?WP_Comment
{
    foreach ($GLOBALS['comments'] as $c) {
        if ($content === $c->comment_content) {
            return $c;
        }
    }
    return null;
}

function get_comment_date_gmt_for(string $content): string
{
    $c = find_comment($content);
    return $c ? (string) $c->comment_date_gmt : '';
}

function count_comments(string $content): int
{
    $n = 0;
    foreach ($GLOBALS['comments'] as $c) {
        $n += $content === $c->comment_content ? 1 : 0;
    }
    return $n;
}

/** Run queued batches until the job ends. */
function run_import(): int
{
    $batches = 0;
    while (Product_Reviews_Import::job_running()) {
        run_action('ffla_review_import_batch', []);
        $batches++;
        check($batches < 50, 'import ends');
    }
    return $batches;
}

/* ── Export ────────────────────────────────────────────────────────────── */

$photo = add_test_attachment('https://store.test/uploads/scope-1.jpg');
$a = add_test_comment(['comment_post_ID' => 101, 'comment_author' => 'Ann Buyer', 'comment_author_email' => 'ann@example.com', 'comment_content' => 'Crisp glass.', 'comment_date_gmt' => '2026-02-01 09:00:00', 'comment_karma' => 1, 'meta' => ['rating' => 5, 'ffla_verified_purchase' => 1, 'ffla_review_quality' => 5, 'ffla_review_media_ids' => [$photo], 'ffla_helpful_yes' => 3]]);
$reply = add_test_comment(['comment_post_ID' => 101, 'comment_parent' => $a, 'comment_type' => 'comment', 'comment_author' => 'Store Staff', 'comment_content' => 'Thanks!', 'user_id' => 9, 'meta' => ['ffla_is_store_reply' => 1]]);
$evil = add_test_comment(['comment_post_ID' => 102, 'comment_author' => '=cmd|calc', 'comment_author_email' => 'evil@example.com', 'comment_content' => '=HYPERLINK("http://x.test","click")', 'comment_approved' => '0', 'meta' => ['rating' => 1]]);
add_test_comment(['comment_post_ID' => 102, 'comment_author' => 'Spammer', 'comment_content' => 'buy now', 'comment_approved' => 'spam', 'meta' => ['rating' => 5]]);
$q = add_test_comment(['comment_post_ID' => 101, 'comment_type' => 'ffla_question', 'comment_author' => 'Asker', 'comment_author_email' => 'asker@example.com', 'comment_content' => 'Does it fit?']);
$qa = add_test_comment(['comment_post_ID' => 101, 'comment_type' => 'ffla_answer', 'comment_parent' => $q, 'comment_author' => 'Store Staff', 'comment_content' => 'Yes.', 'user_id' => 9, 'meta' => ['ffla_is_store_answer' => 1]]);

[$header, $rows, $csv] = export('reviews');
check(Product_Reviews_Import::REVIEW_COLUMNS === $header, 'review export header');
check(3 === count($rows), 'approved, pending and the reply; no spam, no Q&A');
$byid = array_column($rows, null, 'id');
check('5' === $byid[$a]['rating'] && '1' === $byid[$a]['verified'] && '1' === $byid[$a]['pinned'] && 'SCOPE-1' === $byid[$a]['product_sku'], 'rating, verified, pinned, SKU');
check('quality=5' === $byid[$a]['criteria'] && 'https://store.test/uploads/scope-1.jpg' === $byid[$a]['media'] && '3' === $byid[$a]['helpful'], 'criteria, media URL, votes');
check((string) $a === $byid[$reply]['parent_id'] && '1' === $byid[$reply]['store_reply'] && '' === $byid[$reply]['rating'], 'the reply points at its review');
check('pending' === $byid[$evil]['status'], 'pending reviews are exported as pending');
check("'=HYPERLINK(\"http://x.test\",\"click\")" === $byid[$evil]['content'] && "'=cmd|calc" === $byid[$evil]['author'], 'formula cells are neutralised');
check("'-5" !== Product_Reviews_Import::cell('-5') && '-5' === Product_Reviews_Import::cell('-5') && "'+1 (555)" === Product_Reviews_Import::cell('+1 (555)'), 'numbers stay numbers; other leading signs are escaped');

[$qheader, $qrows] = export('qna');
check(Product_Reviews_Import::QNA_COLUMNS === $qheader && 2 === count($qrows), 'Q&A export');
check('question' === $qrows[0]['type'] && 'answer' === $qrows[1]['type'] && (string) $q === $qrows[1]['parent_id'] && '1' === $qrows[1]['store_answer'], 'question, then its store answer');

/* ── Importing this store's own export again changes nothing ───────────── */

$before = count($GLOBALS['comments']);
import_file(array_merge([$header], array_map('array_values', $rows)));
run_import();
$job = Product_Reviews_Import::job();
check('done' === $job['status'] && 0 === $job['imported'] && 3 === $job['skipped'], 're-importing the store\'s own export skips every row');
check(count($GLOBALS['comments']) === $before, 'no duplicates');

/* ── A file from Customer Reviews for WooCommerce ──────────────────────── */

$png = stub_png(stub_png_chunk('tEXt', "GPS\x00SECRET-LOCATION"));
$GLOBALS['downloads']['https://old.test/a.png'] = $png;
$GLOBALS['downloads']['https://old.test/missing.jpg'] = new WP_Error('http_404', 'Not Found');
$GLOBALS['options']['timezone_string'] = 'UTC';
$mails_before = count($GLOBALS['mails']);
$result = import_file([
    ['id', 'review_title', 'review_content', 'review_score', 'parent', 'date', 'product_id', 'product_sku', 'display_name', 'email', 'order_id', 'media', 'location'],
    ['77', 'Great bag', 'Lots of pockets.', '4', '', '2025-12-24 10:30:00', '', 'BAG-1', 'Old Customer', 'old@example.com', '', 'https://old.test/a.png,https://old.test/missing.jpg', 'Texas'],
    ['78', '', 'Thank you for the review!', '', '77', '2025-12-25 08:00:00', '102', '', 'Store', 'shop@store.test', '', '', ''],
    ['79', '', 'A store review', '5', '', '2025-12-26', '-1', '', 'Shop Fan', 'fan@example.com', '', '', ''],
    ['80', '', 'Unknown product', '5', '', '2025-12-26', '', 'NOPE', 'X', 'x@example.com', '', '', ''],
    ['81', '', '', '3', '', '', '102', '', 'Empty', 'e@example.com', '', '', ''],
]);
check($result['ok'], 'file with the other plugin\'s columns accepted');
run_import();
$job = Product_Reviews_Import::job();
check(2 === $job['imported'] && 3 === $job['skipped'], 'two rows imported, three skipped: ' . json_encode([$job['imported'], $job['skipped']]));
$notes = implode("\n", $job['messages']);
check(strpos($notes, 'Line 4: store reviews (product_id -1) are not imported.') !== false, 'store reviews noted');
check(strpos($notes, 'Line 5: product not found') !== false && strpos($notes, 'Line 6: the review text is empty.') !== false, 'bad rows noted with their line');
check(strpos($notes, 'Line 2: 1 photo or video could not be downloaded.') !== false, 'a failed download is noted, the review kept');

$imported = null;
$imported_reply = null;
foreach ($GLOBALS['comments'] as $c) {
    if ('Old Customer' === $c->comment_author) {
        $imported = $c;
    }
    if ('Thank you for the review!' === $c->comment_content) {
        $imported_reply = $c;
    }
}
check($imported && 102 === (int) $imported->comment_post_ID && 'review' === $imported->comment_type, 'product found by SKU');
check("Great bag\n\nLots of pockets." === $imported->comment_content, 'title kept above the text');
check('2025-12-24 10:30:00' === $imported->comment_date_gmt && '1' === (string) $imported->comment_approved, 'date and status');
check(4 === (int) get_comment_meta($imported->comment_ID, 'rating', true) && 0 === (int) get_comment_meta($imported->comment_ID, 'ffla_verified_purchase', true), 'rating; not verified without a verified column');
$media = get_comment_meta($imported->comment_ID, 'ffla_review_media_ids', true);
check(is_array($media) && 1 === count($media), 'the photo is in the Media Library');
check((int) $imported->comment_ID === (int) get_post_meta($media[0], '_ffla_review_upload', true), 'and is marked as uploaded for that review');
$side = end($GLOBALS['sideloaded']);
check(strpos($side['bytes'], 'SECRET-LOCATION') === false && strpos($side['bytes'], 'IHDR') !== false, 'the imported photo lost its text chunks (location data)');
check($imported_reply && (int) $imported->comment_ID === (int) $imported_reply->comment_parent, 'the reply sits under the imported review');
check($mails_before === count($GLOBALS['mails']), 'importing sends no email');
check('imported' === (string) get_comment_meta($imported->comment_ID, '_ffla_review_coupon_checked', true), 'and is marked as checked for a coupon, so approving it later earns none');
check(in_array(102, WC_Comments::$cleared, true), 'WooCommerce recounts the product\'s ratings');
check([] === glob(Product_Reviews_Import::storage_dir() . '/*.csv'), 'the uploaded file is deleted when the import ends');

/* ── Batches: 230 rows, no gaps, no repeats; pending mode ──────────────── */

$rows = [['product_id', 'rating', 'author', 'email', 'content', 'date_gmt', 'verified', 'criteria', 'status']];
for ($i = 1; $i <= 230; $i++) {
    $rows[] = ['103', (string) (1 + $i % 5), "Batch $i", "batch$i@example.com", "Batch review number $i", sprintf('2026-01-01 %02d:%02d:00', intdiv($i, 60), $i % 60), $i % 2 ? '1' : '0', 'value=4', 'approved'];
}
import_file($rows, 'reviews', 'pending');
$batches = run_import();
$job     = Product_Reviews_Import::job();
check(3 === $batches && 230 === $job['imported'] && 0 === $job['skipped'], '230 rows in 3 batches of 100: ' . json_encode([$batches, $job['imported'], $job['skipped']]));
$seen = [];
foreach ($GLOBALS['comments'] as $c) {
    if (strpos($c->comment_content, 'Batch review number ') === 0) {
        $seen[] = (int) substr($c->comment_content, 20);
        check('0' === (string) $c->comment_approved, 'pending mode holds every row');
    }
}
sort($seen);
check(range(1, 230) === $seen, 'every row once, none twice');
$one = null;
foreach ($GLOBALS['comments'] as $c) {
    if ('Batch review number 1' === $c->comment_content) {
        $one = $c;
    }
}
check(1 === (int) get_comment_meta($one->comment_ID, 'ffla_verified_purchase', true) && 4 === (int) get_comment_meta($one->comment_ID, 'ffla_review_value', true), 'verified flag and criteria imported');

import_file($rows);
run_import();
$job = Product_Reviews_Import::job();
check(0 === $job['imported'] && 230 === $job['skipped'], 'the same file again: every row skipped');

/* ── Q&A import ────────────────────────────────────────────────────────── */

$mails_before = count($GLOBALS['mails']);
import_file([
    ['id', 'parent_id', 'type', 'product_sku', 'author', 'email', 'content', 'store_answer', 'date_gmt'],
    ['q1', '', 'question', 'KIT-1', 'Curious', 'curious@example.com', 'Is the rod brass?', '', '2025-11-01 10:00:00'],
    ['a1', 'q1', 'answer', 'KIT-1', 'Store', 'shop@store.test', 'Yes, brass.', '1', '2025-11-02 10:00:00'],
    ['a2', 'missing', 'answer', 'KIT-1', 'Lost', 'lost@example.com', 'Orphan', '', ''],
], 'qna');
run_import();
$job = Product_Reviews_Import::job();
check(2 === $job['imported'] && 1 === $job['skipped'], 'question and answer imported; an answer without its question skipped');
$iq = null;
$ia = null;
foreach ($GLOBALS['comments'] as $c) {
    if ('Is the rod brass?' === $c->comment_content) {
        $iq = $c;
    }
    if ('Yes, brass.' === $c->comment_content) {
        $ia = $c;
    }
}
check('ffla_question' === $iq->comment_type && 103 === (int) $iq->comment_post_ID && 'ffla_answer' === $ia->comment_type && (int) $iq->comment_ID === (int) $ia->comment_parent, 'answer under its question');
check(1 === (int) get_comment_meta($ia->comment_ID, 'ffla_is_store_answer', true), 'store answer flag kept');
check($mails_before === count($GLOBALS['mails']), 'no emails for imported questions or answers');

/* ── Refused files ─────────────────────────────────────────────────────── */

$r = import_file([['name', 'stars'], ['Bob', '5']]);
check(!$r['ok'] && strpos($r['message'], 'at least content and product_id or product_sku') !== false, 'a file without the needed columns is refused');
check([] === glob(Product_Reviews_Import::storage_dir() . '/*.csv'), 'and deleted');
$r = Product_Reviews_Import::start_job(['name' => 'x.csv', 'tmp_name' => '/etc/passwd', 'error' => 0, 'size' => 10], 'reviews', 'keep');
check(!$r['ok'], 'a file that was not uploaded through the form is refused');
$r = Product_Reviews_Import::start_job(['name' => 'x.csv', 'tmp_name' => '', 'error' => 4, 'size' => 0], 'reviews', 'keep');
check(!$r['ok'] && 'Choose a CSV file to import.' === $r['message'], 'no file chosen');
check(file_exists(Product_Reviews_Import::storage_dir() . '/.htaccess') && file_exists(Product_Reviews_Import::storage_dir() . '/index.php'), 'the import folder is closed to the web');

// One job at a time.
$first = import_file([['product_id', 'content'], ['101', 'Queued one']]);
$queued_file = Product_Reviews_Import::job()['file'];
$r = import_file([['product_id', 'content'], ['101', 'Second one']]);
check($first['ok'] && !$r['ok'] && strpos($r['message'], 'still running') !== false, 'a second file is refused while one is queued');
check($queued_file === Product_Reviews_Import::job()['file'] && 1 === count(glob(Product_Reviews_Import::storage_dir() . '/*.csv')), 'the queued job is untouched and the second file deleted');
$r2 = Product_Reviews_Import::start_job(['name' => 'y.csv', 'tmp_name' => '', 'error' => 0, 'size' => 1], 'reviews', 'keep');
check(!$r2['ok'] && strpos($r2['message'], 'still running') !== false, 'a new upload is refused while one runs');
Product_Reviews_Import::cancel_job();
check('cancelled' === Product_Reviews_Import::job()['status'] && [] === glob(Product_Reviews_Import::storage_dir() . '/*.csv'), 'cancel stops the job and deletes its file');
check([] === actions_for('ffla_review_import_batch'), 'cancel removes the queued batch');
// A batch that had already started when Cancel was clicked.
Product_Reviews_Import::run_batch();
$queued_one = false;
foreach ($GLOBALS['comments'] as $c) {
    $queued_one = $queued_one || 'Queued one' === $c->comment_content;
}
check(!$queued_one, 'a batch already running when the job was cancelled imports nothing');
Product_Reviews_Import::cancel_job();
check(null === Product_Reviews_Import::job(), 'clearing removes the finished job');
$GLOBALS['as'] = array_values(array_filter($GLOBALS['as'], function ($a) {
    return 'ffla_review_import_batch' !== $a['hook'];
}));

/* ── Review findings: emails, products, people, text, dates ───────────── */

// Imported rows held for approval send nothing and earn nothing when approved.
$mails_before = count($GLOBALS['mails']);
make_order(7001, 'held@example.com', [[101, 1]]);
import_file([
    ['id', 'parent_id', 'product_id', 'email', 'author', 'rating', 'verified', 'order_id', 'content', 'store_reply', 'date_gmt'],
    ['9001', '', '101', 'held@example.com', 'Held Buyer', '5', '1', '7001', 'Held review from the old store', '', '2024-01-02 10:00:00'],
    ['9002', '9001', '101', 'staff@store.test', 'Store', '', '', '', 'Held store reply', '1', '2024-01-03 10:00:00'],
], 'reviews', 'pending');
run_import();
$held       = find_comment('Held review from the old store');
$held_reply = find_comment('Held store reply');
check($held && '0' === (string) $held->comment_approved && $held_reply && '0' === (string) $held_reply->comment_approved, 'held rows imported as pending');
wp_set_comment_status((int) $held->comment_ID, 'approve');
wp_set_comment_status((int) $held_reply->comment_ID, 'approve');
check($mails_before === count($GLOBALS['mails']), 'approving imported rows later sends no email');
check('' === (string) get_comment_meta($held->comment_ID, Product_Reviews_Coupons::REVIEW_CODE_META, true) && empty($GLOBALS['orders'][7001]->meta['_ffla_review_coupon']), 'and creates no coupon');
check(7001 === (int) get_comment_meta($held->comment_ID, 'ffla_review_order_id', true), 'an order of this store with the row\'s email and product is kept');

// A file from another store: the id is a different product here, the SKU decides.
import_file([
    ['product_id', 'product_sku', 'email', 'user_id', 'order_id', 'content', 'date_gmt'],
    ['101', 'BAG-1', 'stranger@example.com', '7', '7001', 'Wrong id, right SKU', '2024-02-01 10:00:00'],
    ['101', 'NOPE-9', 'stranger@example.com', '', '', 'Unknown SKU', '2024-02-01 10:00:00'],
    ['102', '', 'buyer@example.com', '7', '', 'Same store, no SKU', '2024-02-01 10:00:00'],
]);
run_import();
$moved = find_comment('Wrong id, right SKU');
check($moved && 102 === (int) $moved->comment_post_ID, 'a product_id whose product has another SKU is not trusted; the SKU wins');
check(null === find_comment('Unknown SKU'), 'a row whose SKU is not in this store is skipped, not put on the product with that id');
check(0 === (int) $moved->user_id, 'a user_id whose account has another email is dropped');
check('' === (string) get_comment_meta($moved->comment_ID, 'ffla_review_order_id', true), 'an order_id of another customer is dropped');
check(7 === (int) find_comment('Same store, no SKU')->user_id, 'a user_id with the row\'s email is kept');

// Backslashes, quotes and a cell ending in a backslash (standard CSV).
import_raw("product_id,content,date_gmt\n101,\"Works great with C:\\\",2024-03-01 10:00:00\n101,\"Path C:\\temp and \"\"quotes\"\" ¯\\_(ツ)_/¯\",2024-03-02 10:00:00\n");
run_import();
check(null !== find_comment('Works great with C:\\'), 'a cell ending in a backslash does not swallow the next row');
check(null !== find_comment('Path C:\\temp and "quotes" ¯\\_(ツ)_/¯'), 'backslashes and quotes are kept');
list(, $exported) = export('reviews');
$found = false;
foreach ($exported as $row) {
    $found = $found || 'Works great with C:\\' === $row['content'];
}
check($found, 'the export writes it back as standard CSV');

// Dates: none, day first, not understood; importing again adds nothing.
$dated = [
    ['product_id', 'content', 'date'],
    ['103', 'No date at all', ''],
    ['103', 'Day first', '14/03/2024 10:00'],
    ['103', 'Nonsense date', 'sometime soon-ish'],
];
import_file($dated);
run_import();
check('2024-03-14' === substr(get_comment_date_gmt_for('Day first'), 0, 10), 'a day-first date (14/03/2024) is understood');
$notes = implode("\n", Product_Reviews_Import::job()['messages']);
check(strpos($notes, 'Line 4: the date was not understood') !== false && strpos($notes, 'Line 2:') === false, 'a date not understood gets a note; a missing one does not');
sleep(1); // The import time differs on the second import.
import_file($dated);
run_import();
check(1 === count_comments('No date at all') && 1 === count_comments('Nonsense date'), 'rows without a usable date are not added again on a second import');

// Same-site export imported again: text with & and a title column still matches.
$amp = add_test_comment(['comment_post_ID' => 101, 'comment_parent' => 0, 'comment_author' => 'Amp', 'comment_content' => 'Thanks & enjoy <3', 'meta' => ['rating' => 5]]);
import_file([
    ['id', 'review_title', 'review_content', 'product_id', 'date'],
    [(string) $amp, 'A title', 'Thanks &amp; enjoy <3', '101', '2020-01-01'],
]);
run_import();
check(1 === Product_Reviews_Import::job()['skipped'] && 1 === count_comments('Thanks & enjoy <3'), 'this store\'s own review is found again despite entities and a title column');

// Spam and trash rows; a Q&A answer whose question is missing (no type column).
import_file([
    ['product_id', 'status', 'content'],
    ['101', 'spam', 'Spam row'],
    ['101', 'trash', 'Trash row'],
]);
run_import();
check(null === find_comment('Spam row') && null === find_comment('Trash row'), 'rows marked spam or trash are not imported');
import_file([
    ['id', 'parent_id', 'product_id', 'content'],
    ['5', '424242', '101', 'An answer to a missing question'],
], 'qna');
run_import();
check(null === find_comment('An answer to a missing question'), 'an answer whose question is missing is not published as a question');

// Semicolons (European spreadsheets) and old Mac line endings.
import_file([['product_id', 'content', 'date_gmt'], ['101', 'Semicolon, with a comma inside', '2024-04-01 10:00:00']], 'reviews', 'keep', ';');
run_import();
check(null !== find_comment('Semicolon, with a comma inside'), 'a semicolon-separated file imports');
import_raw("product_id,content,date_gmt\r101,Old Mac file,2024-04-02 10:00:00\r");
run_import();
check(null !== find_comment('Old Mac file') && 1 === Product_Reviews_Import::job()['imported'], 'a file with CR-only line endings imports');

// Upload limits.
$GLOBALS['max_upload'] = 2 * 1048576;
$r = Product_Reviews_Import::start_job(['name' => 'big.csv', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0], 'reviews', 'keep');
check(!$r['ok'] && strpos($r['message'], 'larger than this server accepts (2 MB)') !== false, 'a file over the server limit says so');
unset($GLOBALS['max_upload']);

/* ── Review findings: batches the server kills, cancel races ─────────── */

$rows = [['product_id', 'content', 'date_gmt']];
for ($i = 1; $i <= 30; $i++) {
    $rows[] = ['103', 'Stall row ' . $i, '2024-05-01 10:00:00'];
}
import_file($rows);
$GLOBALS['as'] = array_values(array_filter($GLOBALS['as'], function ($a) {
    return 'ffla_review_import_batch' !== $a['hook'];
}));
Product_Reviews_Import::resume_stalled();
check([] === actions_for('ffla_review_import_batch'), 'a job that moved recently is left alone');
$job = Product_Reviews_Import::job();
$job['touched'] = time() - 600;
update_option(Product_Reviews_Import::JOB_OPTION, $job);
Product_Reviews_Import::resume_stalled();
check(1 === count(actions_for('ffla_review_import_batch')), 'a job with no queued batch that stopped moving is queued again');
run_import();
check('done' === Product_Reviews_Import::job()['status'] && 1 === count_comments('Stall row 30'), 'and finishes');
// Three kills at the same place: it stops and says where.
import_file([['product_id', 'content'], ['103', 'Never finishes']]);
for ($i = 0; $i < 3; $i++) {
    $GLOBALS['as'] = array_values(array_filter($GLOBALS['as'], function ($a) {
        return 'ffla_review_import_batch' !== $a['hook'];
    }));
    $job = Product_Reviews_Import::job();
    $job['touched'] = time() - 600;
    update_option(Product_Reviews_Import::JOB_OPTION, $job);
    Product_Reviews_Import::resume_stalled();
}
$job = Product_Reviews_Import::job();
check('failed' === $job['status'] && strpos(end($job['messages']), 'three times at the same place') !== false, 'after three kills at the same place the job stops with a note');
Product_Reviews_Import::cancel_job();
$GLOBALS['as'] = array_values(array_filter($GLOBALS['as'], function ($a) {
    return 'ffla_review_import_batch' !== $a['hook'];
}));

// A batch still running when its job is replaced does not overwrite the new job.
import_file([['product_id', 'content'], ['103', 'Old job row']]);
$old_job = Product_Reviews_Import::job();
Product_Reviews_Import::cancel_job();
Product_Reviews_Import::cancel_job();
import_file([['product_id', 'content'], ['103', 'New job row']]);
$new_token = Product_Reviews_Import::job()['token'];
update_option(Product_Reviews_Import::JOB_OPTION . '_old', $old_job);
$ref = new ReflectionMethod('Product_Reviews_Import', 'save_progress');
$ref->setAccessible(true);
check(false === $ref->invoke(null, $old_job), 'a batch of a replaced job may not save its progress');
check($new_token === Product_Reviews_Import::job()['token'], 'the new job is kept');
run_import();
check(null !== find_comment('New job row') && null === find_comment('Old job row'), 'only the new file is imported');

/* ── Photos and votes from Customer Reviews for WooCommerce ────────────── */

$img1 = add_test_attachment('https://store.test/uploads/cr-1.jpg');
$img2 = add_test_attachment('https://store.test/uploads/cr-2.jpg');
$vid  = add_test_attachment('https://store.test/uploads/cr-3.mp4', 'video/mp4');
$cr = add_test_comment(['comment_post_ID' => 101, 'comment_author' => 'CR Reviewer', 'comment_content' => 'From the old plugin', 'meta' => [
    'rating'                      => 4,
    'ivole_review_image2'         => new StubMulti([$img1, $img2, 999999]),
    'ivole_review_video2'         => new StubMulti([$vid]),
    'ivole_review_reg_upvoters'   => [11, 12],
    'ivole_review_unreg_upvoters' => ['1.1.1.1'],
    'ivole_review_reg_downvoters' => [13],
]]);
$cr2 = add_test_comment(['comment_post_ID' => 101, 'comment_author' => 'CR Two', 'comment_content' => 'Votes only', 'meta' => ['ivole_review_unreg_upvoters' => ['2.2.2.2'], 'ffla_helpful_yes' => 5]]);
check([$cr, $cr2] === Product_Reviews_Import::cusrev_candidates(), 'reviews with the other plugin\'s photos or votes are found');
Product_Reviews_Import::start_migration();
run_action('ffla_review_migrate_cusrev', []);
$state = get_option(Product_Reviews_Import::MIGRATE_OPTION);
check('done' === $state['status'] && 2 === $state['reviews'] && 3 === $state['media'], 'migration copies three files: ' . json_encode($state));
check([$img1, $img2, $vid] === get_comment_meta($cr, 'ffla_review_media_ids', true), 'the same attachments (a missing one is left out)');
check(3 === (int) get_comment_meta($cr, 'ffla_helpful_yes', true) && 1 === (int) get_comment_meta($cr, 'ffla_helpful_no', true), 'upvoters and downvoters become vote counts');
check(5 === (int) get_comment_meta($cr2, 'ffla_helpful_yes', true), 'a higher count already here is kept');
Product_Reviews_Import::start_migration();
run_action('ffla_review_migrate_cusrev', []);
check([$img1, $img2, $vid] === get_comment_meta($cr, 'ffla_review_media_ids', true), 'running it again changes nothing');

// Photos kept on cusrev.com (an array with a url) are downloaded once; downvotes alone count.
$GLOBALS['downloads']['https://cdn.cusrev.test/r/photo-a.png'] = stub_png();
$cloud = add_test_comment(['comment_post_ID' => 102, 'comment_author' => 'Cloud', 'comment_content' => 'Collected on cusrev.com', 'meta' => [
    'ivole_review_image' => new StubMulti([['url' => 'https://cdn.cusrev.test/r/photo-a.png'], ['url' => 'https://cdn.cusrev.test/r/gone.jpg']]),
]]);
$down = add_test_comment(['comment_post_ID' => 102, 'comment_author' => 'Down', 'comment_content' => 'Only downvotes', 'meta' => ['ivole_review_unreg_downvoters' => serialize(['3.3.3.3', '4.4.4.4'])]]);
check(in_array($cloud, Product_Reviews_Import::cusrev_candidates(), true) && in_array($down, Product_Reviews_Import::cusrev_candidates(), true), 'reviews with only cloud photos or only downvotes are found');
$sideloads = count($GLOBALS['sideloaded']);
check(true === Product_Reviews_Import::start_migration(), 'the copy starts');
check(false === Product_Reviews_Import::start_migration(), 'a second click while it runs does not start a second copy');
run_action('ffla_review_migrate_cusrev', []);
$cloud_media = get_comment_meta($cloud, 'ffla_review_media_ids', true);
check(is_array($cloud_media) && 1 === count($cloud_media) && $sideloads + 1 === count($GLOBALS['sideloaded']), 'the cloud photo is downloaded into the Media Library');
check(1 === (int) get_option(Product_Reviews_Import::MIGRATE_OPTION)['failed'], 'a cloud file that cannot be downloaded is counted');
check(2 === (int) get_comment_meta($down, 'ffla_helpful_no', true), 'downvotes alone become a Not helpful count (lists serialized twice too)');
Product_Reviews_Import::start_migration();
run_action('ffla_review_migrate_cusrev', []);
check($sideloads + 1 === count($GLOBALS['sideloaded']) && $cloud_media === get_comment_meta($cloud, 'ffla_review_media_ids', true), 'running it again downloads nothing again');

// A copy that stopped moving can be continued.
$state = get_option(Product_Reviews_Import::MIGRATE_OPTION);
$state['status']  = 'running';
$state['touched'] = time() - 600;
update_option(Product_Reviews_Import::MIGRATE_OPTION, $state);
reset_request(1);
$page = capture(['Product_Reviews_Tools_Admin', 'render']);
check(strpos($page, 'Continue copying') !== false && strpos($page, 'Stopped:') !== false, 'a stalled copy is shown as stopped and can be continued');
check(true === Product_Reviews_Import::start_migration() && (int) get_option(Product_Reviews_Import::MIGRATE_OPTION)['reviews'] === (int) $state['reviews'], 'continuing keeps its place');
run_action('ffla_review_migrate_cusrev', []);

/* ── Admin page ────────────────────────────────────────────────────────── */

reset_request(1);
$page = capture(['Product_Reviews_Tools_Admin', 'render']);
check(strpos($page, 'action=ffla_review_export&amp;kind=reviews') !== false && strpos($page, 'kind=qna') !== false, 'export links');
check(strpos($page, 'enctype="multipart/form-data"') !== false && strpos($page, 'name="file"') !== false, 'import form');
check(strpos($page, 'Copy photos and votes') !== false, 'migration offered when the other plugin\'s data is there');
$tools = new Product_Reviews_Tools_Admin();
reset_request(7);
$_GET = ['kind' => 'reviews', '_wpnonce' => 'nonce-ffla_review_export'];
$_REQUEST = $_GET;
try {
    $tools->handle_export();
    check(false, 'customers cannot export');
} catch (Died $d) {
    check(403 === $d->status, 'customers cannot export');
}
reset_request(1);
$_POST = ['kind' => 'reviews', 'mode' => 'keep', '_wpnonce' => 'nonce-ffla_review_import'];
$_REQUEST = $_POST;
$_FILES = ['file' => ['name' => 'x.csv', 'tmp_name' => '', 'error' => 4, 'size' => 0]];
$back = expect_redirect([$tools, 'handle_import']);
check('error' === $back['tools_type'] && 'Choose a CSV file to import.' === $back['tools_notice'], 'import without a file comes back with the reason');

echo "OK: {$checks} product-reviews import/export checks passed\n";
