<?php
/**
 * White Label — custom dashboard (view).
 *
 * @var array{woo: ?array}       $data
 * @var array<string, string>    $links
 * @var WP_User                  $user
 * @var string                   $from
 * @var string                   $to
 * @var string                   $analytics_source
 * @var int                      $analytics_range
 * @var string                   $refresh_url
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

$woo = is_array($data['woo'] ?? null) ? $data['woo'] : null;

$money = static function ($number): string {
    $symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
    return $symbol . number_format_i18n((float) $number, 0);
};

$delta_badge = static function ($delta): string {
    if (null === $delta) {
        return '';
    }

    $up    = (float) $delta >= 0;
    $class = $up ? 'is-up' : 'is-down';
    $arrow = $up ? '&#8599;' : '&#8600;';

    return '<span class="ffla-dash-delta ' . $class . '">' . $arrow . ' ' . esc_html(($up ? '+' : '') . $delta . '%') . '</span>';
};

$greeting = static function (): string {
    $hour = (int) current_time('G');
    if ($hour < 12) {
        return __('Good morning', 'ffl-funnels-addons');
    }
    if ($hour < 18) {
        return __('Good afternoon', 'ffl-funnels-addons');
    }
    return __('Good evening', 'ffl-funnels-addons');
};

$display_name = $user->display_name ?: ($user->first_name ?: __('there', 'ffl-funnels-addons'));

$icons = [
    'support' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 1 1 4.5 2.6c-.9.6-1.6 1-1.6 2.1" stroke-linecap="round"/><circle cx="12" cy="17" r=".6" fill="currentColor"/></svg>',
    'kb'      => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5a2 2 0 0 1 2-2h5v18H6a2 2 0 0 1-2-2z"/><path d="M20 5a2 2 0 0 0-2-2h-5v18h5a2 2 0 0 0 2-2z"/></svg>',
    'cockpit' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l8 4.5v9L12 20l-8-4.5v-9z"/><path d="M12 11l8-4.5M12 11v9M12 11L4 6.5"/></svg>',
    'command' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M4 7l8 6 8-6"/></svg>',
];

$cards = [
    ['url' => $links['support'], 'target' => '_blank', 'icon' => $icons['support'], 'eyebrow' => __('Help & Support', 'ffl-funnels-addons'), 'title' => __('Get help from our team', 'ffl-funnels-addons'), 'desc' => __('Submit a request and track support for your website.', 'ffl-funnels-addons'), 'cta' => __('Open support', 'ffl-funnels-addons')],
    ['url' => $links['knowledge_base'], 'target' => '_blank', 'icon' => $icons['kb'], 'eyebrow' => __('Knowledge Base', 'ffl-funnels-addons'), 'title' => __('Guides and training', 'ffl-funnels-addons'), 'desc' => __('Find walkthroughs, answers, and training resources.', 'ffl-funnels-addons'), 'cta' => __('Browse knowledge base', 'ffl-funnels-addons')],
    ['url' => $links['cockpit'], 'target' => '', 'icon' => $icons['cockpit'], 'eyebrow' => __('Cockpit', 'ffl-funnels-addons'), 'title' => __('Dropshipping & products', 'ffl-funnels-addons'), 'desc' => __('Manage distributor feeds, inventory, pricing, and products.', 'ffl-funnels-addons'), 'cta' => __('Open Cockpit', 'ffl-funnels-addons')],
    ['url' => $links['command_center'], 'target' => '_blank', 'icon' => $icons['command'], 'eyebrow' => __('Command Center', 'ffl-funnels-addons'), 'title' => __('Email marketing', 'ffl-funnels-addons'), 'desc' => __('Build campaigns, manage contacts, and review performance.', 'ffl-funnels-addons'), 'cta' => __('Launch Command Center', 'ffl-funnels-addons')],
];

$tile_icons = [
    'sales'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2v20M17 6.5A4 4 0 0 0 13 4h-2a3.5 3.5 0 0 0 0 7h2a3.5 3.5 0 0 1 0 7h-2a4 4 0 0 1-4-2.5" stroke-linecap="round"/></svg>',
    'orders' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M6 6L5 3H2" stroke-linecap="round"/></svg>',
    'aov'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V9m6 10V5m6 14v-7m4 7H2" stroke-linecap="round"/><path d="M4 6l6-3 6 6 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
];
?>

<div class="ffla-wl-dash">

    <div class="ffla-dash-hero">
        <span class="ffla-dash-eyebrow"><?php esc_html_e('Dashboard', 'ffl-funnels-addons'); ?></span>
        <h2 class="ffla-dash-hello"><?php echo esc_html($greeting() . ', ' . $display_name . '.'); ?></h2>
        <p class="ffla-dash-sub"><?php esc_html_e('Here’s what’s happening with your website.', 'ffl-funnels-addons'); ?></p>
        <span class="ffla-dash-rangebar">
            <span class="ffla-dash-range"><?php esc_html_e('Last 30 days', 'ffl-funnels-addons'); ?> &middot; <?php echo esc_html(date_i18n('M j', strtotime($from)) . ' – ' . date_i18n('M j', strtotime($to))); ?></span>
            <a class="ffla-dash-refresh" href="<?php echo esc_url($refresh_url); ?>" title="<?php esc_attr_e('Refresh data', 'ffl-funnels-addons'); ?>" aria-label="<?php esc_attr_e('Refresh data', 'ffl-funnels-addons'); ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v5h-5"/></svg>
            </a>
        </span>
    </div>

    <div class="ffla-dash-cards">
        <?php foreach ($cards as $card) : ?>
            <?php if (empty($card['url'])) { continue; } ?>
            <a class="ffla-dash-card" href="<?php echo esc_url($card['url']); ?>"<?php echo '_blank' === $card['target'] ? ' target="_blank" rel="noopener"' : ''; ?>>
                <span class="ffla-dash-card__icon"><?php echo $card['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <span class="ffla-dash-card__eyebrow"><?php echo esc_html($card['eyebrow']); ?></span>
                <span class="ffla-dash-card__title"><?php echo esc_html($card['title']); ?></span>
                <span class="ffla-dash-card__desc"><?php echo esc_html($card['desc']); ?></span>
                <span class="ffla-dash-card__cta"><?php echo esc_html($card['cta']); ?> &rarr;</span>
            </a>
        <?php endforeach; ?>
    </div>

    <span class="ffla-dash-section"><?php esc_html_e('Performance', 'ffl-funnels-addons'); ?></span>
    <h3 class="ffla-dash-h3"><?php esc_html_e('Business at a glance', 'ffl-funnels-addons'); ?></h3>

    <div class="ffla-dash-tiles ffla-dash-tiles--business">
        <div class="ffla-dash-tile">
            <span class="ffla-dash-tile__top">
                <span class="ffla-dash-tile__icon"><?php echo $tile_icons['sales']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php echo $delta_badge($woo['sales_delta'] ?? null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </span>
            <span class="ffla-dash-tile__label"><?php esc_html_e('Sales', 'ffl-funnels-addons'); ?></span>
            <span class="ffla-dash-tile__value"><?php echo $woo ? esc_html($money($woo['sales'])) : '&mdash;'; ?></span>
            <span class="ffla-dash-tile__sub"><?php esc_html_e('vs. previous 30 days', 'ffl-funnels-addons'); ?></span>
        </div>
        <div class="ffla-dash-tile">
            <span class="ffla-dash-tile__top">
                <span class="ffla-dash-tile__icon"><?php echo $tile_icons['orders']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php echo $delta_badge($woo['orders_delta'] ?? null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </span>
            <span class="ffla-dash-tile__label"><?php esc_html_e('Orders', 'ffl-funnels-addons'); ?></span>
            <span class="ffla-dash-tile__value"><?php echo $woo ? esc_html(number_format_i18n((int) $woo['orders'])) : '&mdash;'; ?></span>
            <span class="ffla-dash-tile__sub"><?php esc_html_e('vs. previous 30 days', 'ffl-funnels-addons'); ?></span>
        </div>
        <div class="ffla-dash-tile">
            <span class="ffla-dash-tile__top">
                <span class="ffla-dash-tile__icon"><?php echo $tile_icons['aov']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            </span>
            <span class="ffla-dash-tile__label"><?php esc_html_e('Average order value', 'ffl-funnels-addons'); ?></span>
            <span class="ffla-dash-tile__value"><?php echo $woo ? esc_html($money($woo['average_order_value'])) : '&mdash;'; ?></span>
            <span class="ffla-dash-tile__sub"><?php esc_html_e('sales divided by paid orders', 'ffl-funnels-addons'); ?></span>
        </div>
    </div>

    <div class="ffla-dash-panel ffla-dash-panel--full ffla-dash-sales-panel">
        <span class="ffla-dash-panel__label"><?php esc_html_e('Sales overview', 'ffl-funnels-addons'); ?></span>
        <?php if ($woo && !empty($woo['series'])) : ?>
            <div class="ffla-dash-panel__value"><?php echo esc_html($money($woo['sales'])); ?></div>
            <?php
            $chart = array_map(static function ($point) {
                return ['l' => date_i18n('M j', strtotime($point['date'])), 'v' => (float) $point['value']];
            }, $woo['series']);
            ?>
            <div class="ffla-dash-chart-wrap ffla-dash-chart-wrap--sales">
                <canvas class="ffla-dash-chart" data-series="<?php echo esc_attr(wp_json_encode($chart)); ?>" data-currency="<?php echo esc_attr(function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$'); ?>"></canvas>
            </div>
        <?php else : ?>
            <p class="ffla-dash-empty"><?php esc_html_e('Sales data is unavailable.', 'ffl-funnels-addons'); ?></p>
        <?php endif; ?>
    </div>

    <section class="ffla-dash-analytics" data-ffla-analytics>
        <div class="ffla-dash-analytics__heading">
            <div>
                <span class="ffla-dash-section"><?php esc_html_e('Analytics', 'ffl-funnels-addons'); ?></span>
                <h3 class="ffla-dash-h3"><?php esc_html_e('Understand how customers find and use your store', 'ffl-funnels-addons'); ?></h3>
            </div>
            <label class="ffla-dash-period">
                <span class="screen-reader-text"><?php esc_html_e('Analytics date range', 'ffl-funnels-addons'); ?></span>
                <select data-ffla-analytics-range>
                    <?php foreach ([7, 30, 90] as $days) : ?>
                        <option value="<?php echo esc_attr((string) $days); ?>" <?php selected($analytics_range, $days); ?>>
                            <?php echo esc_html(sprintf(_n('Last %d day', 'Last %d days', $days, 'ffl-funnels-addons'), $days)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="ffla-dash-tabs" role="tablist" aria-label="<?php esc_attr_e('Analytics source', 'ffl-funnels-addons'); ?>">
            <button id="ffla-dashboard-tab-google" type="button" class="ffla-dash-tab" role="tab" data-ffla-source="google" aria-controls="ffla-dashboard-analytics-panel" aria-selected="<?php echo 'google' === $analytics_source ? 'true' : 'false'; ?>" tabindex="<?php echo 'google' === $analytics_source ? '0' : '-1'; ?>">
                <?php esc_html_e('Google Analytics', 'ffl-funnels-addons'); ?>
            </button>
            <button id="ffla-dashboard-tab-snapfind" type="button" class="ffla-dash-tab" role="tab" data-ffla-source="snapfind" aria-controls="ffla-dashboard-analytics-panel" aria-selected="<?php echo 'snapfind' === $analytics_source ? 'true' : 'false'; ?>" tabindex="<?php echo 'snapfind' === $analytics_source ? '0' : '-1'; ?>">
                <?php esc_html_e('SnapFind', 'ffl-funnels-addons'); ?>
            </button>
        </div>

        <div id="ffla-dashboard-analytics-panel" class="ffla-dash-analytics__panel" role="tabpanel" aria-labelledby="ffla-dashboard-tab-<?php echo esc_attr($analytics_source); ?>" aria-live="polite" aria-busy="true" data-ffla-analytics-panel>
            <div class="ffla-dash-loading"><span class="spinner is-active"></span><?php esc_html_e('Loading analytics…', 'ffl-funnels-addons'); ?></div>
        </div>
        <noscript><p class="ffla-dash-empty"><?php esc_html_e('JavaScript is required to load analytics.', 'ffl-funnels-addons'); ?></p></noscript>
    </section>

</div>
