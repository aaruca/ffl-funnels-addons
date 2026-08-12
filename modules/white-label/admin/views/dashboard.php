<?php
/**
 * White Label — custom dashboard (view).
 *
 * @var array{woo: ?array, search: ?array} $data
 * @var array<string, string>              $links
 * @var WP_User                            $user
 * @var string                             $from
 * @var string                             $to
 * @var string                             $refresh_url
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

$woo    = is_array($data['woo'] ?? null) ? $data['woo'] : null;
$search = is_array($data['search'] ?? null) ? $data['search'] : null;

// ── Helpers ────────────────────────────────────────────────────────────────
$abbrev = static function ($n): string {
    $n = (float) $n;
    if ($n >= 1000000) {
        return round($n / 1000000, 1) . 'M';
    }
    if ($n >= 1000) {
        return round($n / 1000, 1) . 'K';
    }
    return number_format_i18n((int) $n);
};

$money = static function ($n): string {
    $symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
    return $symbol . number_format((float) $n, 0);
};

$delta_badge = static function ($delta): string {
    if (null === $delta) {
        return '';
    }
    $up  = (float) $delta >= 0;
    $cls = $up ? 'is-up' : 'is-down';
    $arrow = $up ? '&#8599;' : '&#8600;';
    return '<span class="ffla-dash-delta ' . $cls . '">' . $arrow . ' ' . esc_html(($up ? '+' : '') . $delta . '%') . '</span>';
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

// Quick-link card definitions; rendered only when a URL is set. External links
// (support, knowledge base, command center) open in a new tab.
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

// Stat-tile icons.
$tile_icons = [
    'sales'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2v20M17 6.5A4 4 0 0 0 13 4h-2a3.5 3.5 0 0 0 0 7h2a3.5 3.5 0 0 1 0 7h-2a4 4 0 0 1-4-2.5" stroke-linecap="round"/></svg>',
    'orders'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M6 6L5 3H2" stroke-linecap="round"/></svg>',
    'traffic' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 17l6-6 4 4 7-8" stroke-linecap="round" stroke-linejoin="round"/><path d="M17 7h4v4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'search'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3" stroke-linecap="round"/></svg>',
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

    <?php // ── Quick-link cards ────────────────────────────────────────── ?>
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

    <?php // ── Stat tiles ──────────────────────────────────────────────── ?>
    <span class="ffla-dash-section"><?php esc_html_e('Performance', 'ffl-funnels-addons'); ?></span>
    <h3 class="ffla-dash-h3"><?php esc_html_e('Business at a glance', 'ffl-funnels-addons'); ?></h3>

    <div class="ffla-dash-tiles">
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
                <span class="ffla-dash-tile__icon"><?php echo $tile_icons['traffic']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            </span>
            <span class="ffla-dash-tile__label"><?php esc_html_e('Website traffic', 'ffl-funnels-addons'); ?></span>
            <span class="ffla-dash-tile__value"><?php echo $search ? esc_html($abbrev($search['traffic'])) : '&mdash;'; ?></span>
            <span class="ffla-dash-tile__sub"><?php esc_html_e('sessions this period', 'ffl-funnels-addons'); ?></span>
        </div>
        <div class="ffla-dash-tile">
            <span class="ffla-dash-tile__top">
                <span class="ffla-dash-tile__icon"><?php echo $tile_icons['search']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php echo $delta_badge($search['conversion_delta'] ?? null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </span>
            <span class="ffla-dash-tile__label"><?php esc_html_e('Search conversion', 'ffl-funnels-addons'); ?></span>
            <span class="ffla-dash-tile__value"><?php echo $search ? esc_html($search['conversion'] . '%') : '&mdash;'; ?></span>
            <span class="ffla-dash-tile__sub"><?php esc_html_e('searches resulting in sale', 'ffl-funnels-addons'); ?></span>
        </div>
    </div>

    <?php // ── Sales chart + search funnel ─────────────────────────────── ?>
    <div class="ffla-dash-split">
        <div class="ffla-dash-panel">
            <span class="ffla-dash-panel__label"><?php esc_html_e('Sales overview', 'ffl-funnels-addons'); ?></span>
            <?php if ($woo && !empty($woo['series'])) : ?>
                <div class="ffla-dash-panel__value"><?php echo esc_html($money($woo['sales'])); ?></div>
                <?php
                $chart = array_map(static function ($p) {
                    return ['l' => date_i18n('M j', strtotime($p['date'])), 'v' => (float) $p['value']];
                }, $woo['series']);
                ?>
                <div class="ffla-dash-chart-wrap">
                    <canvas class="ffla-dash-chart" data-series="<?php echo esc_attr(wp_json_encode($chart)); ?>" data-currency="<?php echo esc_attr(function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$'); ?>"></canvas>
                </div>
            <?php else : ?>
                <p class="ffla-dash-empty"><?php esc_html_e('Sales data is unavailable.', 'ffl-funnels-addons'); ?></p>
            <?php endif; ?>
        </div>

        <div class="ffla-dash-panel">
            <span class="ffla-dash-panel__label"><?php esc_html_e('On-site search funnel', 'ffl-funnels-addons'); ?></span>
            <?php if ($search && !empty($search['funnel'])) : ?>
                <?php $funnel_max = max(1, (int) $search['funnel'][0]['value']); ?>
                <ul class="ffla-dash-funnel">
                    <?php foreach ($search['funnel'] as $stage) : ?>
                        <?php $pct = round(((int) $stage['value'] / $funnel_max) * 100); ?>
                        <li class="ffla-dash-funnel__row">
                            <span class="ffla-dash-funnel__bar" style="width:<?php echo esc_attr(max(6, $pct)); ?>%">
                                <strong><?php echo esc_html(number_format_i18n((int) $stage['value'])); ?></strong>
                            </span>
                            <span class="ffla-dash-funnel__label"><?php echo esc_html($stage['label']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="ffla-dash-funnel__foot">
                    <span><strong><?php echo esc_html($search['ctr'] . '%'); ?></strong><?php esc_html_e('Click-through rate', 'ffl-funnels-addons'); ?></span>
                    <span><strong><?php echo esc_html($search['conversion'] . '%'); ?></strong><?php esc_html_e('Search conversion', 'ffl-funnels-addons'); ?></span>
                </div>
            <?php else : ?>
                <p class="ffla-dash-empty"><?php esc_html_e('Search analytics are unavailable.', 'ffl-funnels-addons'); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php // ── Top search terms ────────────────────────────────────────── ?>
    <div class="ffla-dash-panel ffla-dash-panel--full">
        <span class="ffla-dash-panel__label"><?php esc_html_e('Customer intent', 'ffl-funnels-addons'); ?></span>
        <h4 class="ffla-dash-panel__title"><?php esc_html_e('Top search terms', 'ffl-funnels-addons'); ?></h4>
        <?php if ($search && !empty($search['top_terms'])) : ?>
            <table class="ffla-dash-table" data-ffla-sortable>
                <thead>
                    <tr>
                        <th data-type="text"><?php esc_html_e('Search term', 'ffl-funnels-addons'); ?><span class="ffla-dash-sort"></span></th>
                        <th data-type="num"><?php esc_html_e('Searches', 'ffl-funnels-addons'); ?><span class="ffla-dash-sort"></span></th>
                        <th data-type="num"><?php esc_html_e('Product clicks', 'ffl-funnels-addons'); ?><span class="ffla-dash-sort"></span></th>
                        <th data-type="num"><?php esc_html_e('CTR', 'ffl-funnels-addons'); ?><span class="ffla-dash-sort"></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($search['top_terms'] as $term) : ?>
                        <tr>
                            <td><?php echo esc_html($term['term']); ?></td>
                            <td data-v="<?php echo esc_attr((string) (int) $term['searches']); ?>"><?php echo esc_html(number_format_i18n((int) $term['searches'])); ?></td>
                            <td data-v="<?php echo esc_attr((string) (int) $term['clicks']); ?>"><?php echo esc_html(number_format_i18n((int) $term['clicks'])); ?></td>
                            <td data-v="<?php echo esc_attr((string) (float) $term['ctr']); ?>"><span class="ffla-dash-pill"><?php echo esc_html($term['ctr'] . '%'); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="ffla-dash-empty"><?php esc_html_e('No search terms to show yet.', 'ffl-funnels-addons'); ?></p>
        <?php endif; ?>
    </div>

</div>
