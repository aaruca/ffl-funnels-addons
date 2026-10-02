<?php
/**
 * Product Reviews checks against a real WordPress + WooCommerce site.
 *
 * Usage: wp eval-file tests/smoke/product-reviews-wpcli.php
 * Creates a product and reviews, then deletes them. Settings are restored.
 */
global $wpdb;
$GLOBALS['pr_pass'] = 0;
$GLOBALS['pr_fail'] = 0;
function pr_ck($c, $m) { if ($c) { $GLOBALS['pr_pass']++; echo "  ok  $m\n"; } else { $GLOBALS['pr_fail']++; echo "  FAIL $m\n"; } }

$saved = get_option('ffla_product_reviews_settings', null);
$saved_enable = get_option('woocommerce_enable_reviews');
$saved_verify = get_option('woocommerce_review_rating_verification_required');

$product = new WC_Product_Simple();
$product->set_name('PR test product');
$product->set_regular_price('10');
$product->set_reviews_allowed(true);
$pid = $product->save();

function pr_review($pid, $rating, $yes, $no, $karma = 0, $days_ago = 0) {
    $id = wp_insert_comment([
        'comment_post_ID' => $pid, 'comment_content' => "r$rating y$yes n$no", 'comment_type' => 'review',
        'comment_approved' => 1, 'comment_author' => 'T', 'comment_author_email' => 't@example.com',
        'comment_karma' => $karma, 'comment_date_gmt' => gmdate('Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS),
        'comment_date' => gmdate('Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS),
    ]);
    update_comment_meta($id, 'rating', $rating);
    update_comment_meta($id, 'ffla_helpful_yes', $yes);
    update_comment_meta($id, 'ffla_helpful_no', $no);
    return $id;
}

echo "Most helpful sorts over every review, not the 100 newest\n";
$old_best = pr_review($pid, 5, 50, 0, 0, 400);   // oldest, most helpful
for ($i = 0; $i < 105; $i++) { pr_review($pid, 4, 1, 0, 0, $i); }
$pinned = pr_review($pid, 3, 0, 0, 1, 200);
$mixed  = pr_review($pid, 2, 40, 39, 0, 1);      // net +1
Product_Reviews_Core::flush_product_review_caches($pid);
ob_start();
Product_Reviews_Frontend_Render::render_reviews_list($pid, ['perPage' => 3, 'orderBy' => 'helpful', 'showSummary' => false]);
$html = ob_get_clean();
$p_pin  = strpos($html, 'r3 y0 n0');
$p_best = strpos($html, 'r5 y50 n0');
pr_ck($p_pin !== false && $p_best !== false && $p_pin < $p_best, 'pinned first, then the most helpful review from 400 days ago');
pr_ck(strpos($html, 'r2 y40 n39') === false, 'net score used (40 up / 39 down is not near the top)');

echo "Badge uses the module's own numbers\n";
$dist = Product_Reviews_Core::get_rating_distribution($pid);
pr_ck($dist['total'] === 108, 'distribution counts approved top-level reviews (' . $dist['total'] . ')');
wp_update_comment(['comment_ID' => $mixed, 'comment_approved' => 0]);
$dist2 = Product_Reviews_Core::get_rating_distribution($pid);
pr_ck($dist2['total'] === 107, 'cache cleared when a review is unapproved (' . $dist2['total'] . ')');
update_comment_meta($pinned, 'rating', 5);
$dist3 = Product_Reviews_Core::get_rating_distribution($pid);
pr_ck($dist3['counts'][5] === 2, 'cache cleared when a rating changes');

echo "Uploads setting\n";
update_option('ffla_product_reviews_settings', array_merge(Product_Reviews_Core::get_settings(), ['allow_media_uploads' => '0']));
ob_start(); Product_Reviews_Frontend_Render::render_review_form($pid, []); $form = ob_get_clean();
pr_ck(strpos($form, 'ffla_review_media') === false, 'no upload field when uploads are off');
update_option('ffla_product_reviews_settings', array_merge(Product_Reviews_Core::get_settings(), ['allow_media_uploads' => '1', 'form_title' => 'Tell us more']));
ob_start(); Product_Reviews_Frontend_Render::render_review_form($pid, []); $form = ob_get_clean();
pr_ck(strpos($form, 'ffla_review_media') !== false, 'upload field back when on');
pr_ck(strpos($form, 'Tell us more') !== false, 'form title setting is used');

echo "Admin URL\n";
pr_ck(strpos(Product_Reviews_Core::reviews_admin_url('moderated'), 'page=product-reviews') !== false, 'pending queue link points to Products → Reviews');

echo "Bundle email uses the per-order template when the per-product default is untouched\n";
$GLOBALS['pr_mail'] = [];
add_filter('pre_wp_mail', function ($null, $atts) { $GLOBALS['pr_mail'][] = $atts; return true; }, 10, 2);
update_option('ffla_product_reviews_settings', array_merge(Product_Reviews_Core::get_settings(), ['request_email_mode' => 'bundle', 'email_template' => Product_Reviews_Core::get_default_settings()['email_template']]));
$order = wc_create_order();
$order->add_product(wc_get_product($pid), 1);
$order->set_billing_email('buyer@example.com');
$order->set_billing_first_name('Pat');
$order->set_status('completed');
$order->save();
$GLOBALS['pr_mail'] = []; // ignore WooCommerce's own order emails
Product_Reviews_Email::send_order_review_bundle($order->get_id());
$body = '';
foreach ($GLOBALS['pr_mail'] as $mail) { if (strpos((string) $mail['message'], '{') === false && strpos((string) $mail['message'], 'Pat') !== false) { $body = (string) $mail['message']; } }
pr_ck(strpos($body, 'Leave your reviews here') !== false, 'bundle email body uses the per-order wording');

$order->delete(true);
foreach (get_comments(['post_id' => $pid, 'status' => 'all']) as $c) { wp_delete_comment($c->comment_ID, true); }
wp_delete_post($pid, true);
if ($saved === null) { delete_option('ffla_product_reviews_settings'); } else { update_option('ffla_product_reviews_settings', $saved); }
update_option('woocommerce_enable_reviews', $saved_enable);
update_option('woocommerce_review_rating_verification_required', $saved_verify);

echo "\n{$GLOBALS['pr_pass']} passed, {$GLOBALS['pr_fail']} failed\n";
if ($GLOBALS['pr_fail'] > 0) { exit(1); }
