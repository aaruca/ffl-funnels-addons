<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers WooBooster_Bundle::apply_discount_to_prices
 */
class BundleDiscountMathTest extends TestCase
{
    public function test_none_returns_originals_unchanged()
    {
        $out = WooBooster_Bundle::apply_discount_to_prices(
            array(1 => 10.0, 2 => 20.0),
            'none',
            50,
            2
        );

        $this->assertSame(10.0, $out[1]['original']);
        $this->assertSame(10.0, $out[1]['discounted']);
        $this->assertSame(20.0, $out[2]['original']);
        $this->assertSame(20.0, $out[2]['discounted']);
    }

    public function test_percentage_applies_proportionally_per_item()
    {
        $out = WooBooster_Bundle::apply_discount_to_prices(
            array(1 => 100.0, 2 => 50.0),
            'percentage',
            20,
            2
        );

        $this->assertSame(80.0, $out[1]['discounted']);
        $this->assertSame(40.0, $out[2]['discounted']);
    }

    public function test_percentage_zero_value_is_noop()
    {
        $out = WooBooster_Bundle::apply_discount_to_prices(
            array(1 => 9.99),
            'percentage',
            0,
            2
        );
        $this->assertSame(9.99, $out[1]['discounted']);
    }

    public function test_percentage_over_one_hundred_floors_at_zero()
    {
        $out = WooBooster_Bundle::apply_discount_to_prices(
            array(1 => 50.0),
            'percentage',
            150,
            2
        );
        $this->assertSame(0.0, $out[1]['discounted']);
    }

    public function test_fixed_splits_pro_rata_across_items()
    {
        // $30 off a $60 + $40 = $100 cart → 30% effective on each item.
        $out = WooBooster_Bundle::apply_discount_to_prices(
            array(10 => 60.0, 20 => 40.0),
            'fixed',
            30,
            2
        );
        $this->assertSame(42.0, $out[10]['discounted']);
        $this->assertSame(28.0, $out[20]['discounted']);
    }

    public function test_fixed_caps_at_subtotal()
    {
        $out = WooBooster_Bundle::apply_discount_to_prices(
            array(1 => 5.0, 2 => 5.0),
            'fixed',
            999,
            2
        );
        $this->assertSame(0.0, $out[1]['discounted']);
        $this->assertSame(0.0, $out[2]['discounted']);
    }

    public function test_empty_input_returns_empty()
    {
        $this->assertSame(array(), WooBooster_Bundle::apply_discount_to_prices(array(), 'percentage', 25));
    }

    public function test_rounding_honors_decimals_argument()
    {
        $out = WooBooster_Bundle::apply_discount_to_prices(
            array(1 => 9.99),
            'percentage',
            33.333,
            2
        );
        // 9.99 * (1 - 0.33333) = 6.6600067 → 6.66 at 2 decimals.
        $this->assertSame(6.66, $out[1]['discounted']);
    }
}
