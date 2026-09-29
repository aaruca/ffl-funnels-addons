<?php
/** Read-only usage guidance, aligned with the engine and reconciler behavior. */
defined('ABSPATH') || exit;
?>
<section class="wb-card ffla-gmp-help" aria-labelledby="ffla-gmp-help-title">
    <div class="wb-card__header">
        <h3 id="ffla-gmp-help-title"><?php esc_html_e('How to use Google Merchant Policy', 'ffl-funnels-addons'); ?></h3>
    </div>
    <div class="wb-card__body">
        <p><?php esc_html_e('In Enforce, this module decides which products Google for WooCommerce sends to Google, and every change syncs automatically in both directions. It does not create a separate feed, delete products from your store, or resolve a Merchant Center account suspension. Google decides approval.', 'ffl-funnels-addons'); ?></p>
        <ol class="ffla-gmp-help__steps">
            <?php foreach ([
                __('Prepare the connection. Google for WooCommerce must be active and connected for uploads and removals. You can prepare category rules and run an audit without it, but this addon does not connect your Google account.', 'ffl-funnels-addons'),
                __('Start with Audit only. Keep Restricted-content safety scan checked and begin with 50 products per batch. Audit records local decisions without changing Google visibility or sending upload or removal requests.', 'ffl-funnels-addons'),
                __('Review every relevant category below. Choose Allow only after reviewing the products, Block for categories to exclude, Pending when not yet reviewed, and Inherit parent for children that should follow an ancestor. Check all categories assigned to a product: one Block overrides Allow, and one Pending prevents an Allowed result.', 'ffl-funnels-addons'),
                __('Click Save policies & start catalog scan. This saves the settings and every category rule, then starts a NEW scan from the beginning. It resets the scan counters. In Enforce, the scan uploads or removes products to match the rules. Allow background processing to run. Reload this page after saving to see current progress; the counters do not refresh automatically.', 'ffl-funnels-addons'),
                __('Review the local totals and sample products before enforcing. Check categories, firearm/ammunition flags, titles and descriptions. The table shows category rules, not a product-by-product approval report. A category marked Allow does not guarantee that every product in it passes the safety checks.', 'ffl-funnels-addons'),
                __('When the rules are ready, select Enforce and save again. Confirm the warning. From then on this addon decides what reaches Google: Allowed products are uploaded, Blocked and Pending products are removed, and later changes to category rules, products or per-product decisions sync automatically through Google for WooCommerce. Products that were excluded in Google for WooCommerce before are kept excluded as Always exclude. Do not assume Google listings change immediately.', 'ffl-funnels-addons'),
                __('Verify the result outside this page. Review Google for WooCommerce synchronization/removal jobs, then check the actual products and issues in Merchant Center. Request an account review there only after verifying the affected listings and any other issues. Complete here means the local scan finished, not that Google approved or removed anything.', 'ffl-funnels-addons'),
            ] as $step): ?>
                <li><?php echo esc_html($step); ?></li>
            <?php endforeach; ?>
        </ol>
        <details>
            <summary><?php esc_html_e('Category rules, inheritance and safety checks', 'ffl-funnels-addons'); ?></summary>
            <dl>
                <dt><?php esc_html_e('Allow', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Makes the category eligible. Product safety checks, other assigned category rules and per-product decisions still apply. In Enforce, eligible products are uploaded through Google for WooCommerce, which only syncs published, visible products of supported types. It does not guarantee Google approval.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Block', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Excludes products with this effective category rule when Enforce is active. If a product has multiple categories, any effective Block wins over Pending or Allow.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Pending — not eligible in Enforce', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Means unreviewed, not approved. With no Block present, any effective Pending makes the product Pending even if another category is Allow. Products with no category also remain Pending.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Inherit parent', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Uses the first explicit Allow, Block or Pending found up the parent chain. If none is found, the result is Pending. An explicit child rule replaces inheritance for that child, but a separately assigned parent category is still evaluated. New root categories start Pending; new children start Inherit parent.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Example: a product belongs to two categories', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Allow + Pending = Pending. Allow + Block = Blocked. All effective rules must be Allow and the safety checks must pass for an Allowed result. Variations use their parent product categories; safety signals on either the parent or variation can block them. Google for WooCommerce applies channel visibility per parent product, so this addon keeps a variation blocked on its own out of queued uploads and Google pull requests while its allowed siblings stay eligible.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Restricted-content safety scan', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Checks product names, short and full descriptions, and category names for restricted-content patterns. These checks can override Allow and are not a compliance guarantee. Turning the checkbox off disables these text checks only: explicit firearm/ammunition product flags still block. In Enforce, products that only failed these checks are uploaded after the next scan.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Always include / Always exclude (per product)', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Set from the Google Merchant Policy box on the product page or from the bulk actions in Products. Always exclude keeps the product out of Google whatever its categories. Always include skips category rules and text checks, but products marked as firearm or ammunition stay excluded. Follow policy rules returns the product to the category rules. In Enforce, the change reaches Google as soon as the product is saved.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Channel visibility in Google for WooCommerce', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('In Enforce, this addon writes Channel visibility for you. The Channel visibility box on the product page is replaced by the Google Merchant Policy box, and bulk visibility edits on the Google for WooCommerce Product Feed page are refused with a message pointing here. In Audit only, those controls work as usual.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Saved effective category rule', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('The badge reflects the saved category rules when this page loaded. Changing a dropdown does not update that badge until you save and the page reloads. Search only hides rows; it does not change rules, and saving also includes rows hidden by search.', 'ffl-funnels-addons'); ?></dd>
            </dl>
        </details>
        <details>
            <summary><?php esc_html_e('Exactly what each scan button does', 'ffl-funnels-addons'); ?></summary>
            <dl>
                <dt><?php esc_html_e('Save policies & start catalog scan', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Saves the current form and restarts the scan from the beginning with new counters. Use this after changing rules, mode or batch size. Do not repeatedly press Save just to advance an existing scan.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Resume / run next batch', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Uses SAVED settings; it does not save edits in the form above. For Running, it attempts the next batch. For Paused or Failed, it resumes from the saved checkpoint and attempts a batch. For Idle or Complete, it starts a new scan. If another worker holds the lock, work may continue in the background rather than immediately.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Pause scan', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Pauses this addon’s catalog scan and retains its progress. It does not change the saved operating mode, turn off Enforce filtering or product-save syncing, or stop Google jobs already delegated. It does not save unsaved form edits.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Products per batch', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Maximum records attempted per batch, from 10 to 250. A batch can stop earlier for its time budget. Start at 50; lower the number if processing strains the site. Saving a new batch size starts a new scan; this is not a limit on the total catalog size.', 'ffl-funnels-addons'); ?></dd>
            </dl>
        </details>
        <details>
            <summary><?php esc_html_e('Understand the counters and recover a stalled scan', 'ffl-funnels-addons'); ?></summary>
            <dl>
                <dt><?php esc_html_e('Processed / Allowed / Blocked / Pending', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Local results for the current scan, not live Google totals. The scan visits published products and variations, so these counts need not match Merchant Center or the number of parent products. Newly created records after a scan starts may require another scan. Unavailable records are counted separately as skipped.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Last progress (UTC)', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Time of the latest saved product checkpoint, shown in UTC, not your browser’s local time. Save any pending edits before reloading to check whether this time and Processed are advancing.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Google uploads / removals requested', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Requests this scan handed to Google for WooCommerce, not confirmed Google changes. Uploads count products the scan moved back into Google; removals count previously synced products it asked Google to remove. Listings can stay unchanged while its jobs are pending or failing. Both are zero in Audit only, and uploads are zero while Merchant Center is not connected: Google for WooCommerce then syncs every eligible product once it is.', 'ffl-funnels-addons'); ?></dd>
                <dt><?php esc_html_e('Running without progress / Failed', 'ffl-funnels-addons'); ?></dt>
                <dd><?php esc_html_e('Read Last scan error below, check the Google for WooCommerce connection and WordPress scheduled-task processing, correct the reported problem, then use Resume / run next batch. If it fails again, give support the exact error, last UTC progress time and current counters. Do not repeatedly restart the whole scan or treat a Running label as proof that Google received changes.', 'ffl-funnels-addons'); ?></dd>
            </dl>
        </details>
        <details>
            <summary><?php esc_html_e('A product is Allowed but is not in Google', 'ffl-funnels-addons'); ?></summary>
            <p><?php esc_html_e('In Enforce, an Allowed product is uploaded automatically when it is saved or when the scan reaches it. Open its Google Merchant Policy box: Always exclude wins over the rules, including an exclusion kept from Google for WooCommerce. Then check the Google for WooCommerce status shown there: it only uploads published, visible products of supported types, and its jobs run in the background. In Audit only, nothing is uploaded or removed.', 'ffl-funnels-addons'); ?></p>
        </details>
    </div>
</section>
