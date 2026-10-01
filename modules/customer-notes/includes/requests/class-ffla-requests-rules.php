<?php
/**
 * Customer requests — return rules.
 *
 * Rules attach to a product category (including its subcategories) or a
 * product tag and can make products non-returnable, change the return window
 * or set a restocking fee. When several rules match one product the strictest
 * wins: non-returnable beats returnable, the shortest window and the highest
 * fee apply. Products without a matching rule use the defaults from settings.
 *
 * The restocking fee is waived for reasons that are the store's fault
 * (damaged, defective, wrong item, not as described).
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Rules
{
    const OPTION = 'ffla_requests_rules';
    const MAX_RULES = 100;

    /** @var array<int, array> */
    private static $cache = [];

    /**
     * @return array<int, array{taxonomy:string, term:int, returnable:bool, days:?int, fee:?float, note:string}>
     */
    public static function all(): array
    {
        $rules = get_option(self::OPTION, []);
        return is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [];
    }

    /** Validate and store rules from the setup form. */
    public static function save(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($out) >= self::MAX_RULES) {
                continue;
            }
            $target = explode(':', (string) ($row['target'] ?? ''), 2);
            if (2 !== count($target) || !in_array($target[0], ['product_cat', 'product_tag'], true) || !absint($target[1])) {
                continue;
            }
            $days = trim((string) ($row['days'] ?? ''));
            $fee = trim((string) ($row['fee'] ?? ''));
            $out[] = [
                'taxonomy'   => $target[0],
                'term'       => absint($target[1]),
                'returnable' => 'no' !== ($row['returnable'] ?? 'yes'),
                'days'       => '' === $days ? null : max(1, min(365, (int) $days)),
                'fee'        => '' === $fee ? null : max(0.0, min(100.0, round((float) $fee, 2))),
                'note'       => substr(sanitize_text_field((string) ($row['note'] ?? '')), 0, 300),
            ];
        }
        update_option(self::OPTION, $out, false);
        self::$cache = [];
        return $out;
    }

    public static function default_fee(): float
    {
        return max(0.0, min(100.0, (float) FFLA_Requests::setting('requests_restocking_fee')));
    }

    public static function default_days(): int
    {
        return max(1, (int) FFLA_Requests::setting('requests_return_days'));
    }

    /** Reasons where the restocking fee does not apply. */
    public static function fee_waived_reasons(): array
    {
        return (array) apply_filters('ffla_requests_fee_waived_reasons', ['damaged', 'defective', 'wrong_item', 'not_as_described']);
    }

    /** Reasons that need at least one photo from the customer. */
    public static function photo_reasons(): array
    {
        if (!FFLA_Customer_Operations_Settings::enabled('requests_photo_required') || !FFLA_Customer_Operations_Settings::enabled('requests_uploads')) {
            return [];
        }
        return (array) apply_filters('ffla_requests_photo_reasons', ['damaged', 'wrong_item', 'defective']);
    }

    public static function photo_required(string $reason): bool
    {
        return in_array($reason, self::photo_reasons(), true);
    }

    /** Restocking fee for a line given the request reason. */
    public static function fee_for(float $fee, string $reason): float
    {
        return in_array($reason, self::fee_waived_reasons(), true) ? 0.0 : $fee;
    }

    /**
     * Policy for one product.
     *
     * @return array{returnable:bool, days:int, fee:float, note:string}
     */
    public static function for_product(int $product_id): array
    {
        if (isset(self::$cache[$product_id])) {
            return self::$cache[$product_id];
        }

        $policy = ['returnable' => true, 'days' => self::default_days(), 'fee' => self::default_fee(), 'note' => ''];
        $rules = self::all();
        if ($product_id && $rules) {
            $terms = ['product_cat' => [], 'product_tag' => []];
            foreach (array_keys($terms) as $taxonomy) {
                $ids = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'ids']);
                if (is_wp_error($ids)) {
                    continue;
                }
                foreach ((array) $ids as $id) {
                    $terms[$taxonomy][(int) $id] = true;
                    if ('product_cat' === $taxonomy) {
                        foreach (get_ancestors((int) $id, 'product_cat', 'taxonomy') as $parent) {
                            $terms[$taxonomy][(int) $parent] = true;
                        }
                    }
                }
            }

            $days = null;
            $fee = null;
            foreach ($rules as $rule) {
                if (empty($terms[$rule['taxonomy']][(int) $rule['term']])) {
                    continue;
                }
                if (empty($rule['returnable'])) {
                    $policy['returnable'] = false;
                    $policy['note'] = '' !== $rule['note'] ? $rule['note'] : __('This item cannot be returned.', 'ffl-funnels-addons');
                }
                if (null !== $rule['days']) {
                    $days = null === $days ? (int) $rule['days'] : min($days, (int) $rule['days']);
                }
                if (null !== $rule['fee']) {
                    $fee = null === $fee ? (float) $rule['fee'] : max($fee, (float) $rule['fee']);
                }
                if ($policy['returnable'] && '' === $policy['note'] && '' !== $rule['note']) {
                    $policy['note'] = $rule['note'];
                }
            }
            if (null !== $days) {
                $policy['days'] = $days;
            }
            if (null !== $fee) {
                $policy['fee'] = $fee;
            }
        }

        return self::$cache[$product_id] = $policy;
    }

    /** Human label for a rule target, e.g. "Category: Ammunition". */
    public static function target_label(array $rule): string
    {
        $term = get_term((int) $rule['term'], $rule['taxonomy']);
        $name = $term && !is_wp_error($term) ? $term->name : '#' . (int) $rule['term'];
        return ('product_cat' === $rule['taxonomy'] ? __('Category', 'ffl-funnels-addons') : __('Tag', 'ffl-funnels-addons')) . ': ' . $name;
    }

    public static function flush_cache(): void
    {
        self::$cache = [];
    }
}
