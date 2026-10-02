<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Loadout price math, shared by the tier panels, the cart and the tier bundle.
 *
 * Pure functions on purpose: no database, no WooCommerce state, so the rules
 * can be regression-tested offline (tests/smoke/loadout-cart-smoke.php).
 *
 * Rules:
 *  - Discount percentages add up and are capped at 100%.
 *  - A discount is taken off the regular price (the current price when the
 *    product has no regular price).
 *  - The result never exceeds the product's current price, so a deeper sale
 *    price is kept instead of being replaced by a smaller loadout discount.
 *  - Applying the rule to an already discounted price gives the same result,
 *    so recalculating the cart several times in one request is safe.
 */
class Loadout_Pricing
{
    /**
     * Add discount percentages together, ignoring negatives, capped at 100.
     */
    public static function combine(...$percentages): float
    {
        $total = 0.0;
        foreach ($percentages as $pct) {
            $pct = (float) $pct;
            if ($pct > 0) {
                $total += $pct;
            }
        }
        return min(100.0, $total);
    }

    /**
     * Regular and current price of a product, null when the product has none.
     *
     * @param object $product WC_Product (anything with get_regular_price()/get_price()).
     * @return array{regular: float|null, current: float|null}
     */
    public static function product_prices($product): array
    {
        $regular = $product->get_regular_price();
        $current = $product->get_price();

        return [
            'regular' => ($regular === '' || $regular === null) ? null : (float) $regular,
            'current' => ($current === '' || $current === null) ? null : (float) $current,
        ];
    }

    /**
     * Price the savings are measured against: the regular price, or the
     * current price when there is no regular price.
     */
    public static function reference_price(?float $regular, ?float $current): float
    {
        if ($regular !== null && $regular > 0) {
            return $regular;
        }
        return $current !== null ? max(0.0, $current) : 0.0;
    }

    /**
     * Unit price after a loadout discount.
     */
    public static function unit_price(?float $regular, ?float $current, float $pct): float
    {
        $pct = max(0.0, min(100.0, $pct));
        $base = self::reference_price($regular, $current);

        if ($pct <= 0) {
            return $current !== null ? max(0.0, $current) : $base;
        }

        $final = $base * (1 - $pct / 100);
        if ($current !== null && $current < $final) {
            $final = $current;
        }

        return max(0.0, $final);
    }

    /**
     * Whole-number percentage saved against the reference price (for badges).
     */
    public static function saving_percent(float $reference, float $final): int
    {
        if ($reference <= 0 || $final >= $reference) {
            return 0;
        }
        return (int) round(($reference - $final) / $reference * 100);
    }
}
