<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Tier
{
    private $id;
    private $loadout_id;
    private $name;
    private $slug;
    private $sort_order;
    private $accessory_discount;
    private $set_discount_pct;
    private $perks_json;
    private $bonus_product_id;
    private $bonus_label;
    private $bonus_display_value;
    private $threshold_items;

    private $items = [];

    public static function create(int $loadout_id, array $data): ?self
    {
        global $wpdb;

        if (!$loadout_id || empty($data['name'])) {
            return null;
        }

        $sanitized = self::sanitize_data($data);
        $sanitized['loadout_id'] = $loadout_id;

        if (empty($sanitized['slug'])) {
            $sanitized['slug'] = sanitize_title($sanitized['name']);
        }

        $table = $wpdb->prefix . 'ffla_loadout_tiers';
        $inserted = $wpdb->insert(
            $table,
            [
                'loadout_id'         => $sanitized['loadout_id'],
                'name'               => $sanitized['name'],
                'slug'               => $sanitized['slug'],
                'sort_order'         => $sanitized['sort_order'],
                'accessory_discount' => $sanitized['accessory_discount'],
                'set_discount_pct'   => $sanitized['set_discount_pct'],
                'perks_json'         => $sanitized['perks_json'],
                'bonus_product_id'   => $sanitized['bonus_product_id'],
                'bonus_label'        => $sanitized['bonus_label'],
                'bonus_display_value' => $sanitized['bonus_display_value'],
                'threshold_items'    => $sanitized['threshold_items'],
            ],
            [
                '%d', '%s', '%s', '%d', '%f', '%f', '%s', '%d', '%s', '%f', '%d'
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

        $table = $wpdb->prefix . 'ffla_loadout_tiers';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

        if (!$row) {
            return null;
        }

        $tier = new self();
        $tier->id = (int) $row->id;
        $tier->loadout_id = (int) $row->loadout_id;
        $tier->name = $row->name;
        $tier->slug = $row->slug;
        $tier->sort_order = (int) $row->sort_order;
        $tier->accessory_discount = (float) $row->accessory_discount;
        $tier->set_discount_pct = (float) $row->set_discount_pct;
        $tier->perks_json = $row->perks_json;
        $tier->bonus_product_id = $row->bonus_product_id ? (int) $row->bonus_product_id : null;
        $tier->bonus_label = $row->bonus_label;
        $tier->bonus_display_value = $row->bonus_display_value ? (float) $row->bonus_display_value : null;
        $tier->threshold_items = (int) $row->threshold_items;

        return $tier;
    }

    public static function get_by_loadout($loadout_id): array
    {
        global $wpdb;

        $loadout_id = absint($loadout_id);
        if (!$loadout_id) {
            return [];
        }

        $table = $wpdb->prefix . 'ffla_loadout_tiers';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE loadout_id = %d ORDER BY sort_order ASC",
            $loadout_id
        ));

        $tiers = [];
        foreach ($ids as $id) {
            $tier = self::get($id);
            if ($tier) {
                $tiers[] = $tier;
            }
        }

        return $tiers;
    }

    public function save(): bool
    {
        global $wpdb;

        if (!$this->id) {
            return false;
        }

        $table = $wpdb->prefix . 'ffla_loadout_tiers';
        $updated = $wpdb->update(
            $table,
            [
                'name'               => $this->name,
                'sort_order'         => $this->sort_order,
                'accessory_discount' => $this->accessory_discount,
                'set_discount_pct'   => $this->set_discount_pct,
                'perks_json'         => $this->perks_json,
                'bonus_product_id'   => $this->bonus_product_id,
                'bonus_label'        => $this->bonus_label,
                'bonus_display_value' => $this->bonus_display_value,
                'threshold_items'    => $this->threshold_items,
            ],
            ['id' => $this->id],
            [
                '%s', '%d', '%f', '%f', '%s', '%d', '%s', '%f', '%d'
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

        $wpdb->query('START TRANSACTION');

        // Delete items in this tier.
        $table_items = $wpdb->prefix . 'ffla_loadout_tier_items';
        $wpdb->query($wpdb->prepare("DELETE FROM $table_items WHERE tier_id = %d", $id));

        // Delete the tier.
        $table = $wpdb->prefix . 'ffla_loadout_tiers';
        $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);

        if (!$deleted) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $wpdb->query('COMMIT');
        return true;
    }

    public function get_items(): array
    {
        if (empty($this->items) && $this->id) {
            $this->items = Loadout_Tier_Item::get_by_tier($this->id);
        }
        return $this->items;
    }

    private static function sanitize_data(array $data): array
    {
        $perks = isset($data['perks']) ? (is_array($data['perks']) ? $data['perks'] : []) : [];

        return [
            'name'               => isset($data['name']) ? sanitize_text_field($data['name']) : '',
            'slug'               => isset($data['slug']) ? sanitize_title($data['slug']) : '',
            'sort_order'         => isset($data['sort_order']) ? absint($data['sort_order']) : 0,
            'accessory_discount' => isset($data['accessory_discount']) ? floatval($data['accessory_discount']) : 0.0,
            'set_discount_pct'   => isset($data['set_discount_pct']) ? floatval($data['set_discount_pct']) : 0.0,
            'perks_json'         => !empty($perks) ? wp_json_encode($perks) : null,
            'bonus_product_id'   => isset($data['bonus_product_id']) ? absint($data['bonus_product_id']) : null,
            'bonus_label'        => isset($data['bonus_label']) ? sanitize_text_field($data['bonus_label']) : null,
            'bonus_display_value' => isset($data['bonus_display_value']) ? floatval($data['bonus_display_value']) : null,
            'threshold_items'    => isset($data['threshold_items']) ? absint($data['threshold_items']) : 0,
        ];
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_loadout_id()
    {
        return $this->loadout_id;
    }

    public function get_name()
    {
        return $this->name;
    }

    public function set_name($name): void
    {
        $this->name = sanitize_text_field($name);
    }

    public function get_slug()
    {
        return $this->slug;
    }

    public function get_sort_order()
    {
        return $this->sort_order;
    }

    public function set_sort_order($order): void
    {
        $this->sort_order = absint($order);
    }

    public function get_accessory_discount()
    {
        return $this->accessory_discount;
    }

    public function set_accessory_discount($discount): void
    {
        $this->accessory_discount = floatval($discount);
    }

    public function get_set_discount_pct()
    {
        return $this->set_discount_pct;
    }

    public function set_set_discount_pct($discount): void
    {
        $this->set_discount_pct = floatval($discount);
    }

    public function get_perks()
    {
        return $this->perks_json ? json_decode($this->perks_json, true) : [];
    }

    public function set_perks(array $perks): void
    {
        $this->perks_json = !empty($perks) ? wp_json_encode($perks) : null;
    }

    public function get_bonus_product_id()
    {
        return $this->bonus_product_id;
    }

    public function set_bonus_product_id($id): void
    {
        $this->bonus_product_id = $id ? absint($id) : null;
    }

    public function get_bonus_label()
    {
        return $this->bonus_label;
    }

    public function set_bonus_label($label): void
    {
        $this->bonus_label = $label ? sanitize_text_field($label) : null;
    }

    public function get_bonus_display_value()
    {
        return $this->bonus_display_value;
    }

    public function set_bonus_display_value($value): void
    {
        $this->bonus_display_value = $value ? floatval($value) : null;
    }

    public function get_threshold_items()
    {
        return $this->threshold_items;
    }

    public function set_threshold_items($threshold): void
    {
        $this->threshold_items = absint($threshold);
    }
}
