<?php
/**
 * WooBooster Activator.
 *
 * Handles plugin activation — database table creation and updates.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Activator
{
    /**
     * Run activation tasks.
     */
    public static function activate()
    {
        self::create_tables();
        self::migrate_tables();
        self::set_default_options();
        update_option('woobooster_version', WOOBOOSTER_VERSION);
    }

    /**
     * Create custom database tables.
     */
    private static function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $rules_table = $wpdb->prefix . 'woobooster_rules';
        $conditions_table = $wpdb->prefix . 'woobooster_rule_conditions';
        $actions_table = $wpdb->prefix . 'woobooster_rule_actions';
        $index_table = $wpdb->prefix . 'woobooster_rule_index';

        // Main Rules Table.
        $sql_rules = "CREATE TABLE $rules_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			priority int(11) NOT NULL DEFAULT 10,
			status tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			condition_attribute varchar(255) NOT NULL DEFAULT '',
			condition_operator varchar(50) NOT NULL DEFAULT 'equals',
			condition_value varchar(255) NOT NULL DEFAULT '',
			include_children tinyint(1) NOT NULL DEFAULT 0,
			action_source varchar(50) NOT NULL DEFAULT 'category',
			action_value varchar(255) NOT NULL DEFAULT '',
			action_orderby varchar(50) NOT NULL DEFAULT 'rand',
			action_limit int(11) NOT NULL DEFAULT 4,
			exclude_outofstock tinyint(1) NOT NULL DEFAULT 1,
			start_date datetime DEFAULT NULL,
			end_date datetime DEFAULT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset_collate;";

        // Conditions Table.
        $sql_conditions = "CREATE TABLE $conditions_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			rule_id bigint(20) NOT NULL,
			group_id int(11) NOT NULL DEFAULT 0,
			condition_attribute varchar(255) NOT NULL,
			condition_operator varchar(50) NOT NULL DEFAULT 'equals',
			condition_value longtext NOT NULL,
			include_children tinyint(1) NOT NULL DEFAULT 0,
			min_quantity int(11) NOT NULL DEFAULT 1,
			exclude_categories longtext DEFAULT NULL,
			exclude_products longtext DEFAULT NULL,
			exclude_price_min decimal(10,2) DEFAULT NULL,
			exclude_price_max decimal(10,2) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY rule_id (rule_id),
			KEY group_id (group_id)
		) $charset_collate;";

        // Actions Table.
        $sql_actions = "CREATE TABLE $actions_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			rule_id bigint(20) NOT NULL,
			action_source varchar(50) NOT NULL,
			action_value longtext NOT NULL,
			action_limit int(11) NOT NULL DEFAULT 4,
			action_orderby varchar(50) NOT NULL DEFAULT 'rand',
			include_children tinyint(1) NOT NULL DEFAULT 0,
			action_products longtext DEFAULT NULL,
			action_coupon_id bigint(20) DEFAULT NULL,
			action_coupon_message text DEFAULT NULL,
			exclude_categories longtext DEFAULT NULL,
			exclude_products longtext DEFAULT NULL,
			exclude_price_min decimal(10,2) DEFAULT NULL,
			exclude_price_max decimal(10,2) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY rule_id (rule_id)
		) $charset_collate;";

        // Index Table.
        $sql_index = "CREATE TABLE $index_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			condition_key varchar(355) NOT NULL,
			rule_id bigint(20) NOT NULL,
			priority int(11) NOT NULL DEFAULT 10,
			PRIMARY KEY  (id),
			KEY condition_key (condition_key),
			KEY rule_id (rule_id)
		) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_rules);
        dbDelta($sql_conditions);
        dbDelta($sql_actions);
        dbDelta($sql_index);
    }

    /**
     * Handle database schema updates and data migration.
     */
    public static function migrate_tables()
    {
        global $wpdb;
        $current_db_version = get_option('woobooster_db_version');

        if (version_compare($current_db_version, WOOBOOSTER_DB_VERSION, '<')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $rules_table = $wpdb->prefix . 'woobooster_rules';
            $conditions_table = $wpdb->prefix . 'woobooster_rule_conditions';
            $actions_table = $wpdb->prefix . 'woobooster_rule_actions';

            // 1. Ensure `include_children` column exists in rules table (legacy support).
            $row = $wpdb->get_results($wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s AND column_name = 'include_children'",
                $rules_table
            ));
            if (empty($row)) {
                $wpdb->query("ALTER TABLE $rules_table ADD include_children tinyint(1) NOT NULL DEFAULT 0");
            }

            // 2. Migrate legacy v1.1 single conditions to v1.2 table.
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $conditions_table");
            if (0 == $count) {
                $attr_col = $wpdb->get_results($wpdb->prepare(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s AND column_name = 'condition_attribute'",
                    $rules_table
                ));

                if (!empty($attr_col)) {
                    $legacy_rules = $wpdb->get_results("SELECT id, condition_attribute, condition_value, include_children FROM $rules_table WHERE condition_attribute != ''");
                    foreach ($legacy_rules as $rule) {
                        $wpdb->insert(
                            $conditions_table,
                            array(
                                'rule_id' => $rule->id,
                                'group_id' => 0,
                                'condition_attribute' => $rule->condition_attribute,
                                'condition_operator' => 'equals',
                                'condition_value' => $rule->condition_value,
                                'include_children' => $rule->include_children,
                            )
                        );
                    }
                }
            }

            // 3. Migrate legacy v1.2 single actions to v1.3 table.
            $action_count = $wpdb->get_var("SELECT COUNT(*) FROM $actions_table");
            if (0 == $action_count) {
                $action_cols = $wpdb->get_results($wpdb->prepare(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s AND column_name = 'action_source'",
                    $rules_table
                ));
                if (!empty($action_cols)) {
                    $legacy_actions = $wpdb->get_results("SELECT id, action_source, action_value, action_limit, action_orderby FROM $rules_table");
                    foreach ($legacy_actions as $rule) {
                        $wpdb->insert(
                            $actions_table,
                            array(
                                'rule_id' => $rule->id,
                                'action_source' => $rule->action_source,
                                'action_value' => $rule->action_value,
                                'action_limit' => $rule->action_limit,
                                'action_orderby' => $rule->action_orderby,
                            )
                        );
                    }
                }
            }

            // 4. Add `include_children` column to actions table if missing.
            $row_action_children = $wpdb->get_results($wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s AND column_name = 'include_children'",
                $actions_table
            ));
            if (empty($row_action_children)) {
                $wpdb->query("ALTER TABLE $actions_table ADD include_children tinyint(1) NOT NULL DEFAULT 0");
            }

            // 5. Add coupon/exclusion columns to actions table (v1.5.0).
            $new_action_cols = array(
                'action_products' => 'longtext DEFAULT NULL',
                'action_coupon_id' => 'bigint(20) DEFAULT NULL',
                'exclude_categories' => 'longtext DEFAULT NULL',
                'exclude_products' => 'longtext DEFAULT NULL',
                'exclude_price_min' => 'decimal(10,2) DEFAULT NULL',
                'exclude_price_max' => 'decimal(10,2) DEFAULT NULL',
            );
            foreach ($new_action_cols as $col_name => $col_def) {
                $col_exists = $wpdb->get_results($wpdb->prepare(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s AND column_name = %s",
                    $actions_table,
                    $col_name
                ));
                if (empty($col_exists)) {
                    $wpdb->query("ALTER TABLE {$actions_table} ADD {$col_name} {$col_def}"); // phpcs:ignore WordPress.DB.PreparedSQL
                }
            }

            // 6. Add action_coupon_message column to actions table (v1.6.0).
            $msg_col_exists = $wpdb->get_results($wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s AND column_name = 'action_coupon_message'",
                $actions_table
            ));
            if (empty($msg_col_exists)) {
                $wpdb->query("ALTER TABLE {$actions_table} ADD action_coupon_message text DEFAULT NULL"); // phpcs:ignore WordPress.DB.PreparedSQL
            }

            // 7. Add exclusion + min_quantity columns to conditions table (v1.5.0).
            $new_cond_cols = array(
                'min_quantity' => 'int(11) NOT NULL DEFAULT 1',
                'exclude_categories' => 'longtext DEFAULT NULL',
                'exclude_products' => 'longtext DEFAULT NULL',
                'exclude_price_min' => 'decimal(10,2) DEFAULT NULL',
                'exclude_price_max' => 'decimal(10,2) DEFAULT NULL',
            );
            foreach ($new_cond_cols as $col_name => $col_def) {
                $col_exists = $wpdb->get_results($wpdb->prepare(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s AND column_name = %s",
                    $conditions_table,
                    $col_name
                ));
                if (empty($col_exists)) {
                    $wpdb->query("ALTER TABLE {$conditions_table} ADD {$col_name} {$col_def}"); // phpcs:ignore WordPress.DB.PreparedSQL
                }
            }

            // 8. Add scheduling columns to rules table (v1.7.0).
            $schedule_cols = array(
                'start_date' => 'datetime DEFAULT NULL',
                'end_date' => 'datetime DEFAULT NULL',
            );
            foreach ($schedule_cols as $col_name => $col_def) {
                $col_exists = $wpdb->get_results($wpdb->prepare(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s AND column_name = %s",
                    $rules_table,
                    $col_name
                ));
                if (empty($col_exists)) {
                    $wpdb->query("ALTER TABLE {$rules_table} ADD {$col_name} {$col_def}"); // phpcs:ignore WordPress.DB.PreparedSQL
                }
            }

            // Mark migration as complete.
            update_option('woobooster_db_version', WOOBOOSTER_DB_VERSION);

        }
    }

    /**
     * Set default plugin options.
     */
    private static function set_default_options()
    {
        if (false === get_option('woobooster_settings')) {
            $defaults = array(
                'debug_mode' => '0',
            );
            update_option('woobooster_settings', $defaults);
        }
    }
}
