<?php
/**
 * Smart Coupons — settings and per-coupon options.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Coupon_Settings
{
    const OPTION = 'ffla_coupons_settings';
    const META = '_ffla_coupon';

    /** Module settings with defaults. */
    public static function defaults(): array
    {
        return [
            'protect_firearms' => true,   // Firearms are never discounted unless a coupon allows it.
            'protected_terms'  => [],     // "product_cat:ID" / "product_tag:ID" also never discounted.
            'enforce_map'      => true,   // No coupon pushes a product below its MAP price.
            'links'            => true,   // ?coupon=CODE applies a coupon.
            'link_param'       => 'coupon',
            'best_wins'        => false,  // When coupons conflict keep the bigger discount.
            'throttle'         => true,   // Block coupon guessing.
            'throttle_max'     => 10,     // Failed codes per visitor per 10 minutes.
            'credit_prefix'    => 'CREDIT',
            'credit_days'      => 365,
            'credit_notice'    => true,   // Remind signed-in customers of their store credit in cart / checkout.
        ];
    }

    public static function get(): array
    {
        $saved = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    public static function value(string $key)
    {
        $all = self::get();
        return $all[$key] ?? null;
    }

    public static function sanitize(array $input): array
    {
        $d = self::defaults();
        $terms = [];
        foreach ((array) ($input['protected_terms'] ?? []) as $term) {
            if (preg_match('/^(product_cat|product_tag):\d+$/', (string) $term)) {
                $terms[] = (string) $term;
            }
        }
        $param = sanitize_key((string) ($input['link_param'] ?? 'coupon'));
        return [
            'protect_firearms' => !empty($input['protect_firearms']),
            'protected_terms'  => array_values(array_unique($terms)),
            'enforce_map'      => !empty($input['enforce_map']),
            'links'            => !empty($input['links']),
            'link_param'       => '' !== $param ? $param : $d['link_param'],
            'best_wins'        => !empty($input['best_wins']),
            'throttle'         => !empty($input['throttle']),
            'throttle_max'     => max(3, min(100, (int) ($input['throttle_max'] ?? $d['throttle_max']))),
            'credit_prefix'    => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['credit_prefix'] ?? $d['credit_prefix'])), 0, 12)) ?: $d['credit_prefix'],
            'credit_days'      => max(1, min(3650, (int) ($input['credit_days'] ?? $d['credit_days']))),
            'credit_notice'    => !empty($input['credit_notice']),
        ];
    }

    /* ── Per-coupon options (one meta array) ───────────────────────────── */

    public static function coupon_defaults(): array
    {
        return [
            'allow_protected' => false,
            // Which products: categories and tags, each matched "any" (OR) or "all"
            // (AND), joined by `join` when both are set. Exclusions always win.
            'cats'            => [],      // Product categories (subcategories count).
            'cats_match'      => 'any',   // any, all
            'join'            => 'and',   // and, or — between categories and tags.
            'tags'            => [],      // Product tags.
            'tags_match'      => 'any',   // any, all
            'exclude_cats'    => [],      // Products in any of these categories are left out.
            'exclude_tags'    => [],      // Products with any of these tags are left out.
            'starts'          => '',      // Y-m-d, store time.
            'first_order'     => false,
            'roles'           => [],      // WP roles, plus "guest".
            'per_customer'    => 0,       // Max uses per person (email / phone / address); 0 = no limit.
            'min_qty'         => 0,
            'qty_cats'        => [],
            'delivery'        => '',      // '', pickup, shipping
            'states'          => [],      // e.g. FL, GA
            'payments'        => [],      // Gateway IDs.
            'stack'           => 'any',   // any, none, only
            'stack_codes'     => [],
            'max_discount'    => 0.0,
            'gift_product'    => 0,
            'gift_qty'        => 1,
            'tiers'           => [],      // [['min' => 500, 'amount' => 25, 'percent' => false], ...]
            'buy_cats'        => [],
            'buy_products'    => [],
            'buy_qty'         => 1,
            'get_cats'        => [],
            'get_products'    => [],
            'get_qty'         => 1,
            'get_pct'         => 100.0,
            'repeat'          => true,
        ];
    }

    /** @param WC_Coupon|int $coupon */
    public static function coupon($coupon): array
    {
        $coupon = $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon((int) $coupon);
        $saved = $coupon->get_meta(self::META, true);
        return self::upgrade(array_merge(self::coupon_defaults(), is_array($saved) ? $saved : []));
    }

    /**
     * Options saved before 1.55.2 had only "In all of these categories"
     * (`all_cats`): read them as categories matched with "All".
     */
    private static function upgrade(array $o): array
    {
        if (!empty($o['all_cats']) && empty($o['cats'])) {
            $o['cats'] = array_values(array_filter(array_map('absint', (array) $o['all_cats'])));
            $o['cats_match'] = 'all';
        }
        unset($o['all_cats']);
        return $o;
    }

    public static function sanitize_coupon(array $in): array
    {
        $ids = static function ($value): array {
            return array_values(array_filter(array_map('absint', (array) $value)));
        };
        $codes = static function ($value): array {
            $list = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
            return array_values(array_filter(array_map('wc_format_coupon_code', array_map('strval', (array) $list))));
        };
        $roles = array_values(array_intersect((array) ($in['roles'] ?? []), array_merge(['guest'], array_keys(wp_roles()->get_names()))));
        $states = array_values(array_filter(array_map(static function ($s) {
            return strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $s));
        }, is_array($in['states'] ?? '') ? $in['states'] : preg_split('/[\s,]+/', (string) ($in['states'] ?? '')))));
        $starts = (string) ($in['starts'] ?? '');
        $in = self::upgrade($in);

        return [
            'allow_protected' => !empty($in['allow_protected']),
            'cats'            => $ids($in['cats'] ?? []),
            'cats_match'      => 'all' === ($in['cats_match'] ?? '') ? 'all' : 'any',
            'join'            => 'or' === ($in['join'] ?? '') ? 'or' : 'and',
            'tags'            => $ids($in['tags'] ?? []),
            'tags_match'      => 'all' === ($in['tags_match'] ?? '') ? 'all' : 'any',
            'exclude_cats'    => $ids($in['exclude_cats'] ?? []),
            'exclude_tags'    => $ids($in['exclude_tags'] ?? []),
            'starts'          => preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts) ? $starts : '',
            'first_order'     => !empty($in['first_order']),
            'roles'           => $roles,
            'per_customer'    => max(0, (int) ($in['per_customer'] ?? 0)),
            'min_qty'         => max(0, (int) ($in['min_qty'] ?? 0)),
            'qty_cats'        => $ids($in['qty_cats'] ?? []),
            'delivery'        => in_array($in['delivery'] ?? '', ['pickup', 'shipping'], true) ? $in['delivery'] : '',
            'states'          => $states,
            'payments'        => array_values(array_map('sanitize_key', (array) ($in['payments'] ?? []))),
            'stack'           => in_array($in['stack'] ?? '', ['none', 'only'], true) ? $in['stack'] : 'any',
            'stack_codes'     => $codes($in['stack_codes'] ?? ''),
            'max_discount'    => max(0.0, (float) ($in['max_discount'] ?? 0)),
            'gift_product'    => absint($in['gift_product'] ?? 0),
            'gift_qty'        => max(1, min(10, (int) ($in['gift_qty'] ?? 1))),
            'tiers'           => self::parse_tiers($in['tiers'] ?? ''),
            'buy_cats'        => $ids($in['buy_cats'] ?? []),
            'buy_products'    => $ids($in['buy_products'] ?? []),
            'buy_qty'         => max(1, (int) ($in['buy_qty'] ?? 1)),
            'get_cats'        => $ids($in['get_cats'] ?? []),
            'get_products'    => $ids($in['get_products'] ?? []),
            'get_qty'         => max(1, (int) ($in['get_qty'] ?? 1)),
            'get_pct'         => max(0.0, min(100.0, (float) ($in['get_pct'] ?? 100))),
            'repeat'          => !empty($in['repeat']),
        ];
    }

    /**
     * Tiers from lines like "500=25" (spend $500 → $25 off) or "1000=10%".
     *
     * @param array|string $value
     */
    public static function parse_tiers($value): array
    {
        if (is_array($value) && isset($value[0]['min'])) {
            return $value;
        }
        $tiers = [];
        foreach (preg_split('/[\r\n,;]+/', (string) $value) as $line) {
            if (preg_match('/^\s*\$?\s*([\d.]+)\s*[=:>]\s*\$?\s*([\d.]+)\s*(%?)\s*$/', $line, $m)) {
                $tiers[] = ['min' => (float) $m[1], 'amount' => (float) $m[2], 'percent' => '%' === $m[3]];
            }
        }
        usort($tiers, static function ($a, $b) {
            return $a['min'] <=> $b['min'];
        });
        return array_slice($tiers, 0, 10);
    }

    public static function tiers_text(array $tiers): string
    {
        return implode("\n", array_map(static function ($t) {
            return wc_format_decimal($t['min'], '', true) . '=' . wc_format_decimal($t['amount'], '', true) . ($t['percent'] ? '%' : '');
        }, $tiers));
    }

    /* ── Product helpers ───────────────────────────────────────────────── */

    public static function product_is_firearm($product): bool
    {
        if (!$product instanceof WC_Product) {
            return false;
        }
        if (function_exists('product_is_firearm') && product_is_firearm($product)) {
            return true;
        }
        $source = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
        return $source && 'yes' === strtolower((string) $source->get_meta('_firearm_product'));
    }

    /** Category IDs including ancestors, plus tag IDs, for the product (parent for variations). */
    public static function product_terms($product): array
    {
        static $cache = [];
        $id = $product instanceof WC_Product ? ($product->get_parent_id() ?: $product->get_id()) : (int) $product;
        if (isset($cache[$id])) {
            return $cache[$id];
        }
        $cats = [];
        foreach ((array) wp_get_post_terms($id, 'product_cat', ['fields' => 'ids']) as $cat) {
            $cats[(int) $cat] = true;
            foreach (get_ancestors((int) $cat, 'product_cat', 'taxonomy') as $parent) {
                $cats[(int) $parent] = true;
            }
        }
        $tags = array_map('intval', (array) wp_get_post_terms($id, 'product_tag', ['fields' => 'ids']));
        return $cache[$id] = ['product_cat' => array_keys($cats), 'product_tag' => $tags];
    }

    public static function in_categories($product, array $cat_ids): bool
    {
        return (bool) array_intersect(self::product_terms($product)['product_cat'], array_map('intval', $cat_ids));
    }

    /** Whether the coupon limits products by category or tag. */
    public static function has_product_filters(array $o): bool
    {
        return (bool) (($o['cats'] ?? []) || ($o['tags'] ?? []) || ($o['exclude_cats'] ?? []) || ($o['exclude_tags'] ?? []));
    }

    /**
     * The coupon's "Which products" rule:
     *   (categories: any / all)  AND / OR  (tags: any / all), then exclusions.
     * Categories include subcategories. A side left empty is not part of the
     * rule. These narrow WooCommerce's own Usage restriction, which still applies.
     */
    public static function product_matches(array $o, $product): bool
    {
        if (!$product instanceof WC_Product) {
            return false;
        }
        $terms = self::product_terms($product);
        $test = static function (array $want, array $has, string $match): ?bool {
            $want = array_values(array_unique(array_map('intval', $want)));
            if (!$want) {
                return null; // Not part of the rule.
            }
            $hit = count(array_intersect($want, $has));
            return 'all' === $match ? $hit === count($want) : $hit > 0;
        };
        $by_cat = $test((array) ($o['cats'] ?? []), $terms['product_cat'], (string) ($o['cats_match'] ?? 'any'));
        $by_tag = $test((array) ($o['tags'] ?? []), $terms['product_tag'], (string) ($o['tags_match'] ?? 'any'));
        if (null !== $by_cat && null !== $by_tag) {
            $ok = 'or' === ($o['join'] ?? 'and') ? ($by_cat || $by_tag) : ($by_cat && $by_tag);
        } else {
            $ok = $by_cat ?? $by_tag ?? true;
        }
        if (!$ok) {
            return false;
        }
        if (array_intersect(array_map('intval', (array) ($o['exclude_cats'] ?? [])), $terms['product_cat'])) {
            return false;
        }
        return !array_intersect(array_map('intval', (array) ($o['exclude_tags'] ?? [])), $terms['product_tag']);
    }

    /**
     * Shown to customers when nothing in the cart fits, e.g.
     * "This coupon is only for products in Rifles or Shotguns and tagged Sale."
     */
    public static function product_filter_message(array $o): string
    {
        $names = static function (array $ids, string $taxonomy): array {
            return array_values(array_filter(array_map(static function ($id) use ($taxonomy) {
                $term = get_term((int) $id, $taxonomy);
                return $term && !is_wp_error($term) ? $term->name : '';
            }, $ids)));
        };
        $list = static function (array $items, bool $all): string {
            if (count($items) < 2) {
                return implode('', $items);
            }
            $last = array_pop($items);
            $head = implode(', ', $items);
            if (!$all) {
                /* translators: 1: items, 2: last item, e.g. "Rifles, Shotguns or Pistols" */
                return sprintf(__('%1$s or %2$s', 'ffl-funnels-addons'), $head, $last);
            }
            return 1 === count($items)
                /* translators: 1: item, 2: item, e.g. "both Rifles and Used Guns" */
                ? sprintf(__('both %1$s and %2$s', 'ffl-funnels-addons'), $head, $last)
                /* translators: 1: items, 2: last item, e.g. "all of Rifles, Used Guns and Sale" */
                : sprintf(__('all of %1$s and %2$s', 'ffl-funnels-addons'), $head, $last);
        };
        $o = self::upgrade($o);
        $cats = $names((array) $o['cats'], 'product_cat');
        $tags = $names((array) $o['tags'], 'product_tag');
        $no_cats = $names((array) $o['exclude_cats'], 'product_cat');
        $no_tags = $names((array) $o['exclude_tags'], 'product_tag');

        $want = [];
        if ($cats) {
            /* translators: %s: categories, e.g. "Rifles or Shotguns" */
            $want[] = sprintf(__('in %s', 'ffl-funnels-addons'), $list($cats, 'all' === $o['cats_match']));
        }
        if ($tags) {
            /* translators: %s: tags, e.g. "Sale or Clearance" */
            $want[] = sprintf(__('tagged %s', 'ffl-funnels-addons'), $list($tags, 'all' === $o['tags_match']));
        }
        $not = [];
        if ($no_cats) {
            /* translators: %s: categories */
            $not[] = sprintf(__('in %s', 'ffl-funnels-addons'), $list($no_cats, false));
        }
        if ($no_tags) {
            /* translators: %s: tags */
            $not[] = sprintf(__('tagged %s', 'ffl-funnels-addons'), $list($no_tags, false));
        }
        $not = implode(__(' or ', 'ffl-funnels-addons'), $not);

        if (!$want && !$not) {
            return __('This coupon does not apply to the items in your cart.', 'ffl-funnels-addons');
        }
        if (!$want) {
            /* translators: %s: e.g. "in Consignment or tagged No discount" */
            return sprintf(__('This coupon cannot be used on products %s.', 'ffl-funnels-addons'), $not);
        }
        $want = implode('or' === $o['join'] ? __(', or ', 'ffl-funnels-addons') : __(' and ', 'ffl-funnels-addons'), $want);
        return '' === $not
            /* translators: %s: e.g. "in Rifles or Shotguns and tagged Sale" */
            ? sprintf(__('This coupon is only for products %s.', 'ffl-funnels-addons'), $want)
            /* translators: 1: e.g. "in Rifles and tagged Sale", 2: e.g. "tagged Consignment" */
            : sprintf(__('This coupon is only for products %1$s, and not for products %2$s.', 'ffl-funnels-addons'), $want, $not);
    }

    /** Firearms and protected categories / tags. */
    public static function is_protected($product): bool
    {
        $s = self::get();
        if ($s['protect_firearms'] && self::product_is_firearm($product)) {
            return true;
        }
        if ($s['protected_terms']) {
            $terms = self::product_terms($product);
            foreach ($s['protected_terms'] as $key) {
                [$taxonomy, $id] = explode(':', $key);
                if (in_array((int) $id, $terms[$taxonomy] ?? [], true)) {
                    return true;
                }
            }
        }
        return (bool) apply_filters('ffla_coupons_is_protected', false, $product);
    }

    /** Minimum advertised / sale price (variation first, then parent). */
    public static function map_price($product): float
    {
        if (!$product instanceof WC_Product) {
            return 0.0;
        }
        $map = (float) $product->get_meta('_ffla_map_price');
        if ($map <= 0 && $product->get_parent_id()) {
            $parent = wc_get_product($product->get_parent_id());
            $map = $parent ? (float) $parent->get_meta('_ffla_map_price') : 0.0;
        }
        return (float) apply_filters('ffla_coupons_map_price', max(0.0, $map), $product);
    }

    /** Store credit coupons skip guardrails (credit is money owed, not a price cut). */
    public static function is_credit($coupon): bool
    {
        return $coupon instanceof WC_Coupon && 'yes' === $coupon->get_meta('_ffla_credit');
    }
}
