<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Tier_Item
{
    private $id;
    private $tier_id;
    private $product_id;
    private $quantity;
    private $discount_pct;
    private $is_required;
    private $sort_order;

    public static function create(int $tier_id, array $data): ?self
    {
        global $wpdb;

        if (!$tier_id || empty($data['product_id'])) {
            return null;
        }

        $sanitized = self::sanitize_data($data);
        $sanitized['tier_id'] = $tier_id;

        $table = $wpdb->prefix . 'ffla_loadout_tier_items';
        $inserted = $wpdb->insert(
            $table,
            [
                'tier_id'    => $sanitized['tier_id'],
                'product_id' => $sanitized['product_id'],
                'quantity'   => $sanitized['quantity'],
                'discount_pct' => $sanitized['discount_pct'],
                'is_required' => $sanitized['is_required'],
                'sort_order' => $sanitized['sort_order'],
            ],
            [
                '%d', '%d', '%d', '%f', '%d', '%d'
            ]
        );

        if (!$inserted) {
            return null;
        }

        return self::get($wpdb->insert_id);
    }

    public static function get($id): ?self
    {
        global $wpdb;

        $id = absint($id);
        if (!$id) {
            return null;
        }

        $table = $wpdb->prefix . 'ffla_loadout_tier_items';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

        if (!$row) {
            return null;
        }

        $item = new self();
        $item->id = (int) $row->id;
        $item->tier_id = (int) $row->tier_id;
        $item->product_id = (int) $row->product_id;
        $item->quantity = (int) $row->quantity;
        $item->discount_pct = (float) $row->discount_pct;
        $item->is_required = (int) $row->is_required;
        $item->sort_order = (int) $row->sort_order;

        return $item;
    }

    public static function get_by_tier($tier_id): array
    {
        global $wpdb;

        $tier_id = absint($tier_id);
        if (!$tier_id) {
            return [];
        }

        $table = $wpdb->prefix . 'ffla_loadout_tier_items';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE tier_id = %d ORDER BY sort_order ASC",
            $tier_id
        ));

        $items = [];
        foreach ($ids as $id) {
            $item = self::get($id);
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function save(): bool
    {
        global $wpdb;

        if (!$this->id) {
            return false;
        }

        $table = $wpdb->prefix . 'ffla_loadout_tier_items';
        $updated = $wpdb->update(
            $table,
            [
                'quantity'    => $this->quantity,
                'discount_pct' => $this->discount_pct,
                'is_required' => $this->is_required,
                'sort_order'  => $this->sort_order,
            ],
            ['id' => $this->id],
            [
                '%d', '%f', '%d', '%d'
            ],
            ['%d']
        );

        return $updated !== false;
    }

    public static function delete($id): bool
    {
        global $wpdb;

        $id = absint($id);
        if (!$id) {
            return false;
        }

        $table = $wpdb->prefix . 'ffla_loadout_tier_items';
        return $wpdb->delete($table, ['id' => $id], ['%d']) !== false;
    }

    private static function sanitize_data(array $data): array
    {
        return [
            'product_id'  => isset($data['product_id']) ? absint($data['product_id']) : 0,
            'quantity'    => isset($data['quantity']) ? absint($data['quantity']) : 1,
            'discount_pct' => isset($data['discount_pct']) ? floatval($data['discount_pct']) : 0.0,
            'is_required' => isset($data['is_required']) ? absint($data['is_required']) : 0,
            'sort_order'  => isset($data['sort_order']) ? absint($data['sort_order']) : 0,
        ];
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_tier_id()
    {
        return $this->tier_id;
    }

    public function get_product_id()
    {
        return $this->product_id;
    }

    public function set_product_id($id): void
    {
        $this->product_id = absint($id);
    }

    public function get_quantity()
    {
        return $this->quantity;
    }

    public function set_quantity($qty): void
    {
        $this->quantity = max(1, absint($qty));
    }

    public function get_discount_pct()
    {
        return $this->discount_pct;
    }

    public function set_discount_pct($discount): void
    {
        $this->discount_pct = floatval($discount);
    }

    public function get_is_required()
    {
        return $this->is_required;
    }

    public function set_is_required($required): void
    {
        $this->is_required = absint($required);
    }

    public function get_sort_order()
    {
        return $this->sort_order;
    }

    public function set_sort_order($order): void
    {
        $this->sort_order = absint($order);
    }
}
