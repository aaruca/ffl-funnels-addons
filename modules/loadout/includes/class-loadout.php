<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout
{
    private $id;
    private $name;
    private $slug;
    private $status;
    private $anchor_product_id;
    private $hero_image_id;
    private $brand_logo_id;
    private $headline;
    private $subheadline;
    private $created_at;
    private $updated_at;

    private $tiers = [];
    private $cross_sells = [];

    public static function create(array $data): ?self
    {
        global $wpdb;

        $sanitized = self::sanitize_data($data);

        if (empty($sanitized['name'])) {
            return null;
        }

        // Auto-generate slug if not provided.
        if (empty($sanitized['slug'])) {
            $base_slug = sanitize_title($sanitized['name']);
            $sanitized['slug'] = $base_slug;
            $counter = 1;
            while (self::slug_exists($sanitized['slug'])) {
                $sanitized['slug'] = $base_slug . '-' . $counter;
                $counter++;
            }
        }

        $table = $wpdb->prefix . 'ffla_loadouts';
        $wpdb->query('START TRANSACTION');

        $insert = $wpdb->insert(
            $table,
            [
                'name'               => $sanitized['name'],
                'slug'               => $sanitized['slug'],
                'status'             => $sanitized['status'],
                'anchor_product_id'  => $sanitized['anchor_product_id'],
                'hero_image_id'      => $sanitized['hero_image_id'],
                'brand_logo_id'      => $sanitized['brand_logo_id'],
                'headline'           => $sanitized['headline'],
                'subheadline'        => $sanitized['subheadline'],
            ],
            [
                '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s'
            ]
        );

        if (!$insert) {
            $wpdb->query('ROLLBACK');
            return null;
        }

        $id = $wpdb->insert_id;
        $wpdb->query('COMMIT');

        return self::get($id);
    }

    public static function get($id): ?self
    {
        global $wpdb;

        $id = absint($id);
        if (!$id) {
            return null;
        }

        $table = $wpdb->prefix . 'ffla_loadouts';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

        if (!$row) {
            return null;
        }

        $loadout = new self();
        $loadout->id = (int) $row->id;
        $loadout->name = $row->name;
        $loadout->slug = $row->slug;
        $loadout->status = (int) $row->status;
        $loadout->anchor_product_id = (int) $row->anchor_product_id ?: null;
        $loadout->hero_image_id = (int) $row->hero_image_id ?: null;
        $loadout->brand_logo_id = (int) $row->brand_logo_id ?: null;
        $loadout->headline = $row->headline;
        $loadout->subheadline = $row->subheadline;
        $loadout->created_at = $row->created_at;
        $loadout->updated_at = $row->updated_at;

        return $loadout;
    }

    public static function get_by_slug($slug): ?self
    {
        global $wpdb;

        $slug = sanitize_key($slug);
        $table = $wpdb->prefix . 'ffla_loadouts';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE slug = %s", $slug));

        if (!$row) {
            return null;
        }

        return self::get($row->id);
    }

    public static function get_all($status = null): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ffla_loadouts';

        if ($status === null) {
            $query = "SELECT id FROM $table ORDER BY name ASC";
            $ids = $wpdb->get_col($query);
        } else {
            $status = absint($status);
            $query = $wpdb->prepare("SELECT id FROM $table WHERE status = %d ORDER BY name ASC", $status);
            $ids = $wpdb->get_col($query);
        }

        $loadouts = [];
        foreach ($ids as $id) {
            $loadout = self::get($id);
            if ($loadout) {
                $loadouts[] = $loadout;
            }
        }

        return $loadouts;
    }

    public function save(): bool
    {
        global $wpdb;

        if (!$this->id) {
            return false;
        }

        $sanitized = self::sanitize_data([
            'name'              => $this->name,
            'status'            => $this->status,
            'anchor_product_id' => $this->anchor_product_id,
            'hero_image_id'     => $this->hero_image_id,
            'brand_logo_id'     => $this->brand_logo_id,
            'headline'          => $this->headline,
            'subheadline'       => $this->subheadline,
        ]);

        $table = $wpdb->prefix . 'ffla_loadouts';
        $updated = $wpdb->update(
            $table,
            [
                'name'               => $sanitized['name'],
                'status'             => $sanitized['status'],
                'anchor_product_id'  => $sanitized['anchor_product_id'],
                'hero_image_id'      => $sanitized['hero_image_id'],
                'brand_logo_id'      => $sanitized['brand_logo_id'],
                'headline'           => $sanitized['headline'],
                'subheadline'        => $sanitized['subheadline'],
            ],
            ['id' => $this->id],
            [
                '%s', '%d', '%d', '%d', '%d', '%s', '%s'
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

        $table_items = $wpdb->prefix . 'ffla_loadout_tier_items';
        $table_tiers = $wpdb->prefix . 'ffla_loadout_tiers';
        $table_cross_sells = $wpdb->prefix . 'ffla_loadout_cross_sells';
        $table_loadouts = $wpdb->prefix . 'ffla_loadouts';

        // Delete tier items.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_items WHERE tier_id IN (SELECT id FROM $table_tiers WHERE loadout_id = %d)",
            $id
        ));

        // Delete tiers.
        $wpdb->query($wpdb->prepare("DELETE FROM $table_tiers WHERE loadout_id = %d", $id));

        // Delete cross-sells.
        $wpdb->query($wpdb->prepare("DELETE FROM $table_cross_sells WHERE loadout_id = %d", $id));

        // Delete loadout.
        $deleted = $wpdb->delete($table_loadouts, ['id' => $id], ['%d']);

        if (!$deleted) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $wpdb->query('COMMIT');
        return true;
    }

    public function toggle_status(): bool
    {
        $this->status = $this->status ? 0 : 1;
        return $this->save();
    }

    private static function sanitize_data(array $data): array
    {
        return [
            'name'              => isset($data['name']) ? sanitize_text_field($data['name']) : '',
            'slug'              => isset($data['slug']) ? sanitize_title($data['slug']) : '',
            'status'            => isset($data['status']) ? absint($data['status']) : 1,
            'anchor_product_id' => isset($data['anchor_product_id']) ? absint($data['anchor_product_id']) : null,
            'hero_image_id'     => isset($data['hero_image_id']) ? absint($data['hero_image_id']) : null,
            'brand_logo_id'     => isset($data['brand_logo_id']) ? absint($data['brand_logo_id']) : null,
            'headline'          => isset($data['headline']) ? sanitize_text_field($data['headline']) : null,
            'subheadline'       => isset($data['subheadline']) ? sanitize_text_field($data['subheadline']) : null,
        ];
    }

    private static function slug_exists($slug): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ffla_loadouts';
        return $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE slug = %s", $slug)) !== null;
    }

    // Getters and setters.
    public function get_id()
    {
        return $this->id;
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

    public function get_status()
    {
        return $this->status;
    }

    public function set_status($status): void
    {
        $this->status = absint($status);
    }

    public function get_anchor_product_id()
    {
        return $this->anchor_product_id;
    }

    public function set_anchor_product_id($id): void
    {
        $this->anchor_product_id = $id ? absint($id) : null;
    }

    public function get_hero_image_id()
    {
        return $this->hero_image_id;
    }

    public function set_hero_image_id($id): void
    {
        $this->hero_image_id = $id ? absint($id) : null;
    }

    public function get_brand_logo_id()
    {
        return $this->brand_logo_id;
    }

    public function set_brand_logo_id($id): void
    {
        $this->brand_logo_id = $id ? absint($id) : null;
    }

    public function get_headline()
    {
        return $this->headline;
    }

    public function set_headline($text): void
    {
        $this->headline = $text ? sanitize_text_field($text) : null;
    }

    public function get_subheadline()
    {
        return $this->subheadline;
    }

    public function set_subheadline($text): void
    {
        $this->subheadline = $text ? sanitize_text_field($text) : null;
    }

    public function get_created_at()
    {
        return $this->created_at;
    }

    public function get_updated_at()
    {
        return $this->updated_at;
    }
}
