<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Cross_Sell
{
    private $id;
    private $loadout_id;
    private $label;
    private $image_id;
    private $link_type;
    private $link_value;
    private $sort_order;

    public static function create(int $loadout_id, array $data): ?self
    {
        global $wpdb;

        if (!$loadout_id || empty($data['label'])) {
            return null;
        }

        $sanitized = self::sanitize_data($data);
        $sanitized['loadout_id'] = $loadout_id;

        $table = $wpdb->prefix . 'ffla_loadout_cross_sells';
        $inserted = $wpdb->insert(
            $table,
            [
                'loadout_id' => $sanitized['loadout_id'],
                'label'      => $sanitized['label'],
                'image_id'   => $sanitized['image_id'],
                'link_type'  => $sanitized['link_type'],
                'link_value' => $sanitized['link_value'],
                'sort_order' => $sanitized['sort_order'],
            ],
            [
                '%d', '%s', '%d', '%s', '%s', '%d'
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

        $table = $wpdb->prefix . 'ffla_loadout_cross_sells';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

        if (!$row) {
            return null;
        }

        $cs = new self();
        $cs->id = (int) $row->id;
        $cs->loadout_id = (int) $row->loadout_id;
        $cs->label = $row->label;
        $cs->image_id = $row->image_id ? (int) $row->image_id : null;
        $cs->link_type = $row->link_type;
        $cs->link_value = $row->link_value;
        $cs->sort_order = (int) $row->sort_order;

        return $cs;
    }

    public static function get_by_loadout($loadout_id): array
    {
        global $wpdb;

        $loadout_id = absint($loadout_id);
        if (!$loadout_id) {
            return [];
        }

        $table = $wpdb->prefix . 'ffla_loadout_cross_sells';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE loadout_id = %d ORDER BY sort_order ASC",
            $loadout_id
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

        $table = $wpdb->prefix . 'ffla_loadout_cross_sells';
        $updated = $wpdb->update(
            $table,
            [
                'label'      => $this->label,
                'image_id'   => $this->image_id,
                'link_type'  => $this->link_type,
                'link_value' => $this->link_value,
                'sort_order' => $this->sort_order,
            ],
            ['id' => $this->id],
            [
                '%s', '%d', '%s', '%s', '%d'
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

        $table = $wpdb->prefix . 'ffla_loadout_cross_sells';
        return $wpdb->delete($table, ['id' => $id], ['%d']) !== false;
    }

    private static function sanitize_data(array $data): array
    {
        return [
            'label'      => isset($data['label']) ? sanitize_text_field($data['label']) : '',
            'image_id'   => isset($data['image_id']) ? absint($data['image_id']) : null,
            'link_type'  => isset($data['link_type']) ? sanitize_key($data['link_type']) : 'category',
            'link_value' => isset($data['link_value']) ? sanitize_text_field($data['link_value']) : null,
            'sort_order' => isset($data['sort_order']) ? absint($data['sort_order']) : 0,
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

    public function get_label()
    {
        return $this->label;
    }

    public function set_label($label): void
    {
        $this->label = sanitize_text_field($label);
    }

    public function get_image_id()
    {
        return $this->image_id;
    }

    public function set_image_id($id): void
    {
        $this->image_id = $id ? absint($id) : null;
    }

    public function get_link_type()
    {
        return $this->link_type;
    }

    public function set_link_type($type): void
    {
        $this->link_type = sanitize_key($type);
    }

    public function get_link_value()
    {
        return $this->link_value;
    }

    public function set_link_value($value): void
    {
        $this->link_value = $value ? sanitize_text_field($value) : null;
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
