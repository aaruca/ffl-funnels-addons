<?php
/**
 * WooBooster abilities (WordPress Abilities API, WordPress 6.9+).
 *
 * Registers WooBooster's AI tools as abilities and marks them MCP-public, so
 * any MCP client (Claude, ChatGPT, Cursor…) connected through the MCP Adapter
 * plugin — or a plugin that loads it, such as Novamira — can search the
 * catalog and manage recommendation rules with the user's own AI.
 *
 * Only WooBooster operations are exposed (no PHP, database or file access);
 * every ability requires `manage_woocommerce`, and writes go through
 * WooBooster_AI_Tools::validate_rule(). Rules are created inactive.
 *
 * On WordPress < 6.9 (no Abilities API) nothing is registered and the
 * built-in AI chat keeps working on its own.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Abilities
{
    const CATEGORY = 'woobooster';

    /**
     * Hook registration (no-op without the Abilities API).
     */
    public static function init(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        add_action('wp_abilities_api_categories_init', array(__CLASS__, 'register_category'));
        add_action('wp_abilities_api_init', array(__CLASS__, 'register_abilities'));
    }

    /**
     * Whether abilities can be registered on this site.
     */
    public static function abilities_supported(): bool
    {
        return function_exists('wp_register_ability');
    }

    /**
     * Whether an MCP server is available to expose them (MCP Adapter plugin,
     * or a plugin bundling it such as Novamira).
     */
    public static function mcp_available(): bool
    {
        return class_exists('WP\\MCP\\Core\\McpAdapter');
    }

    public static function register_category(): void
    {
        wp_register_ability_category(self::CATEGORY, array(
            'label'       => __('WooBooster', 'ffl-funnels-addons'),
            'description' => __('Product recommendation rules and catalog search for WooBooster.', 'ffl-funnels-addons'),
        ));
    }

    /**
     * Only store managers may use WooBooster abilities.
     *
     * @return bool
     */
    public static function can_manage(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public static function register_abilities(): void
    {
        $rule_properties = array(
            'name'                => array('type' => 'string', 'description' => 'Descriptive rule name.'),
            'priority'            => array('type' => 'integer', 'description' => 'Lower runs first. Default 10.'),
            'condition_attribute' => array('type' => 'string', 'description' => 'When to show: product_cat, product_tag, specific_product, or a pa_* attribute taxonomy (e.g. pa_caliber).'),
            'condition_operator'  => array('type' => 'string', 'enum' => WooBooster_AI_Tools::OPERATORS),
            'condition_value'     => array('type' => 'string', 'description' => 'Term slug for taxonomies, or comma-separated product IDs for specific_product. Use slugs/IDs from search-catalog.'),
            'action_source'       => array('type' => 'string', 'enum' => WooBooster_AI_Tools::ACTION_SOURCES, 'description' => 'What to recommend.'),
            'action_value'        => array('type' => 'string', 'description' => 'Category or tag slug, or "pa_attribute:term-slug" for attribute_value. Empty for smart sources and specific_products.'),
            'action_products'     => array('type' => 'string', 'description' => 'Comma-separated product IDs for specific_products.'),
            'action_orderby'      => array('type' => 'string', 'enum' => WooBooster_AI_Tools::ORDERBY),
            'action_limit'        => array('type' => 'integer', 'minimum' => 1, 'maximum' => WooBooster_AI_Tools::MAX_LIMIT),
        );

        $read = array('readonly' => true, 'destructive' => false, 'idempotent' => true);

        self::register('woobooster/search-catalog', array(
            'label'         => __('Search catalog', 'ffl-funnels-addons'),
            'description'   => 'Search the WooCommerce catalog. type=product matches title, description and SKU and returns id, name, sku, price, stock and categories; type=category|tag|attribute returns term ids and slugs (attribute results include the action_value to use). Always use this to get real IDs and slugs before proposing a rule.',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'type'  => array('type' => 'string', 'enum' => array('product', 'category', 'tag', 'attribute'), 'default' => 'product'),
                    'query' => array('type' => 'string', 'description' => 'Search text, e.g. "Glock 19", "holsters", "9mm".'),
                    'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10),
                ),
                'required'   => array('query'),
            ),
            'execute'       => static function ($input) {
                return WooBooster_AI_Tools::search_catalog((array) $input);
            },
            'annotations'   => $read,
        ));

        self::register('woobooster/list-rules', array(
            'label'        => __('List rules', 'ffl-funnels-addons'),
            'description'  => 'List every WooBooster rule with its condition groups, action groups, priority and whether it is active. Check this before creating rules to avoid duplicates.',
            'execute'      => static function () {
                return WooBooster_AI_Tools::list_rules();
            },
            'annotations'  => $read,
        ));

        self::register('woobooster/validate-rule', array(
            'label'        => __('Validate rule', 'ffl-funnels-addons'),
            'description'  => 'Check a proposed rule without saving it: verifies every slug and product ID exists and returns errors, warnings and the normalized rule. Pass rule_id to validate changes to an existing rule.',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array_merge(array('rule_id' => array('type' => 'integer')), $rule_properties),
            ),
            'execute'      => static function ($input) {
                $input = (array) $input;
                $rule_id = isset($input['rule_id']) ? absint($input['rule_id']) : 0;
                unset($input['rule_id']);
                return WooBooster_AI_Tools::validate_rule($input, $rule_id);
            },
            'annotations'  => $read,
        ));

        self::register('woobooster/create-rule', array(
            'label'        => __('Create rule', 'ffl-funnels-addons'),
            'description'  => 'Create a recommendation rule. It is validated first and saved INACTIVE for review; activate it with set-rule-status. One condition and one action per rule.',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => $rule_properties,
                'required'   => array('name', 'condition_attribute', 'condition_value', 'action_source'),
            ),
            'execute'      => static function ($input) {
                return WooBooster_AI_Tools::create_rule((array) $input);
            },
            'annotations'  => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
        ));

        self::register('woobooster/update-rule', array(
            'label'        => __('Update rule', 'ffl-funnels-addons'),
            'description'  => 'Change an existing rule. Only the fields given change; the result is validated before saving, and the rule\'s condition and action are rewritten as a single condition and action.',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array_merge(array('rule_id' => array('type' => 'integer', 'minimum' => 1)), $rule_properties),
                'required'   => array('rule_id'),
            ),
            'execute'      => static function ($input) {
                $input = (array) $input;
                return WooBooster_AI_Tools::update_rule(absint($input['rule_id'] ?? 0), $input);
            },
            'annotations'  => array('readonly' => false, 'destructive' => true, 'idempotent' => true),
        ));

        self::register('woobooster/set-rule-status', array(
            'label'        => __('Activate or deactivate rule', 'ffl-funnels-addons'),
            'description'  => 'Turn a rule on (active=true) or off (active=false). Active rules show on the storefront immediately.',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'rule_id' => array('type' => 'integer', 'minimum' => 1),
                    'active'  => array('type' => 'boolean'),
                ),
                'required'   => array('rule_id', 'active'),
            ),
            'execute'      => static function ($input) {
                $input = (array) $input;
                return WooBooster_AI_Tools::set_rule_status(absint($input['rule_id'] ?? 0), !empty($input['active']));
            },
            'annotations'  => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
        ));

        self::register('woobooster/diagnose-product', array(
            'label'        => __('Diagnose product', 'ffl-funnels-addons'),
            'description'  => 'Show which rule a product matches, what each of its actions returns and the final recommendations shoppers see.',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'product_id' => array('type' => 'integer', 'minimum' => 1),
                ),
                'required'   => array('product_id'),
            ),
            'execute'      => static function ($input) {
                $input = (array) $input;
                return WooBooster_AI_Tools::diagnose_product(absint($input['product_id'] ?? 0));
            },
            'annotations'  => $read,
        ));
    }

    /**
     * Register one ability with WooBooster's shared settings.
     *
     * @param string $name Ability name.
     * @param array  $def  label, description, input_schema, execute, annotations.
     */
    private static function register(string $name, array $def): void
    {
        $args = array(
            'label'               => $def['label'],
            'description'         => $def['description'],
            'category'            => self::CATEGORY,
            'execute_callback'    => $def['execute'],
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'meta'                => array(
                // MCP only: not exposed to other ability clients or the REST API.
                'mcp'         => array('public' => true, 'type' => 'tool'),
                'annotations' => $def['annotations'],
            ),
        );
        if (!empty($def['input_schema'])) {
            $args['input_schema'] = $def['input_schema'];
        }

        wp_register_ability($name, $args);
    }
}
