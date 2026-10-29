<?php
namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Reviews_List extends \Bricks\Element
{
    public $category = 'FFL Funnels';
    public $name     = 'ffla-reviews-list';
    public $icon     = 'ti-comment-alt';
    public $tag      = 'div';

    public function get_label()
    {
        return esc_html__('Reviews List', 'ffl-funnels-addons');
    }

    /**
     * CSS only: the list itself enqueues the script when something on it
     * needs it (votes, toolbar, Show more, lightbox, slider).
     */
    public function enqueue_scripts()
    {
        \Product_Reviews_Assets::enqueue(false);
    }

    public function set_control_groups()
    {
        $this->control_groups['query'] = [
            'title' => esc_html__('Which reviews', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['layout'] = [
            'title' => esc_html__('Layout', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['toolbar'] = [
            'title' => esc_html__('Filters and paging', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];
    }

    /**
     * Product categories for the category source.
     *
     * @return array<int, string>
     */
    private static function category_options(): array
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500]);
        if (!is_array($terms)) {
            return [];
        }

        $options = [];
        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $options[(int) $term->term_id] = $term->name;
            }
        }

        return $options;
    }

    /**
     * "Use module setting" / Show / Hide.
     *
     * @return array<string, string>
     */
    private static function tri_options(): array
    {
        return [
            ''     => esc_html__('Use module setting', 'ffl-funnels-addons'),
            'show' => esc_html__('Show', 'ffl-funnels-addons'),
            'hide' => esc_html__('Hide', 'ffl-funnels-addons'),
        ];
    }

    public function set_controls()
    {
        $this->controls['source'] = [
            'tab'         => 'content',
            'group'       => 'query',
            'label'       => esc_html__('Reviews of', 'ffl-funnels-addons'),
            'type'        => 'select',
            'options'     => [
                ''           => esc_html__('This product (or Product ID)', 'ffl-funnels-addons'),
                'all'        => esc_html__('All products', 'ffl-funnels-addons'),
                'categories' => esc_html__('Product categories', 'ffl-funnels-addons'),
            ],
            'default'     => '',
            'description' => esc_html__('All products or a category show a store-wide list, for example on the home page or a reviews page.', 'ffl-funnels-addons'),
        ];

        $this->controls['categories'] = [
            'tab'         => 'content',
            'group'       => 'query',
            'label'       => esc_html__('Categories', 'ffl-funnels-addons'),
            'type'        => 'select',
            'options'     => self::category_options(),
            'multiple'    => true,
            'searchable'  => true,
            'placeholder' => esc_html__('Select categories', 'ffl-funnels-addons'),
            'description' => esc_html__('Subcategories are included.', 'ffl-funnels-addons'),
            'required'    => ['source', '=', 'categories'],
        ];

        $this->controls['productId'] = [
            'tab'            => 'content',
            'group'          => 'query',
            'label'          => esc_html__('Product ID', 'ffl-funnels-addons'),
            'type'           => 'number',
            'hasDynamicData' => true,
            'description'    => esc_html__('With “This product”: leave empty to use the current product context.', 'ffl-funnels-addons'),
        ];

        $this->controls['minRating'] = [
            'tab'     => 'content',
            'group'   => 'query',
            'label'   => esc_html__('Minimum rating', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                ''  => esc_html__('Any rating', 'ffl-funnels-addons'),
                '3' => esc_html__('3 stars and up', 'ffl-funnels-addons'),
                '4' => esc_html__('4 stars and up', 'ffl-funnels-addons'),
                '5' => esc_html__('5 stars only', 'ffl-funnels-addons'),
            ],
            'default' => '',
        ];

        $this->controls['withMedia'] = [
            'tab'     => 'content',
            'group'   => 'query',
            'label'   => esc_html__('Only reviews with photos', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                ''     => esc_html__('No', 'ffl-funnels-addons'),
                'only' => esc_html__('Yes', 'ffl-funnels-addons'),
            ],
            'default' => '',
        ];

        $this->controls['layout'] = [
            'tab'     => 'content',
            'group'   => 'layout',
            'label'   => esc_html__('Layout', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                ''       => esc_html__('List', 'ffl-funnels-addons'),
                'grid'   => esc_html__('Grid', 'ffl-funnels-addons'),
                'slider' => esc_html__('Slider', 'ffl-funnels-addons'),
            ],
            'default' => '',
        ];

        $this->controls['columns'] = [
            'tab'         => 'content',
            'group'       => 'layout',
            'label'       => esc_html__('Columns / cards per view', 'ffl-funnels-addons'),
            'type'        => 'number',
            'min'         => 1,
            'max'         => 6,
            'placeholder' => 3,
            'description' => esc_html__('Grid columns, or cards visible at once in the slider. Fewer on tablets and phones.', 'ffl-funnels-addons'),
            'required'    => ['layout', '!=', ''],
        ];

        $this->controls['showProduct'] = [
            'tab'         => 'content',
            'group'       => 'layout',
            'label'       => esc_html__('Product on each card', 'ffl-funnels-addons'),
            'type'        => 'select',
            'options'     => [
                ''     => esc_html__('Auto (store-wide lists only)', 'ffl-funnels-addons'),
                'show' => esc_html__('Show', 'ffl-funnels-addons'),
                'hide' => esc_html__('Hide', 'ffl-funnels-addons'),
            ],
            'default'     => '',
        ];

        $this->controls['showFilters'] = [
            'tab'         => 'content',
            'group'       => 'toolbar',
            'label'       => esc_html__('Star filter', 'ffl-funnels-addons'),
            'type'        => 'select',
            'options'     => self::tri_options(),
            'default'     => '',
            'description' => esc_html__('Chips (and the summary bars) that show only 5-star, 4-star… reviews. Never shown in the slider layout unless set to Show.', 'ffl-funnels-addons'),
        ];

        $this->controls['showMediaFilter'] = [
            'tab'     => 'content',
            'group'   => 'toolbar',
            'label'   => esc_html__('“With photos” filter', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => self::tri_options(),
            'default' => '',
        ];

        $this->controls['showSort'] = [
            'tab'     => 'content',
            'group'   => 'toolbar',
            'label'   => esc_html__('Sort menu', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => self::tri_options(),
            'default' => '',
        ];

        $this->controls['showSearch'] = [
            'tab'     => 'content',
            'group'   => 'toolbar',
            'label'   => esc_html__('Search box', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => self::tri_options(),
            'default' => '',
        ];

        $this->controls['showMore'] = [
            'tab'         => 'content',
            'group'       => 'toolbar',
            'label'       => esc_html__('“Show more” button', 'ffl-funnels-addons'),
            'type'        => 'select',
            'options'     => self::tri_options(),
            'default'     => '',
            'description' => esc_html__('Loads the next Max reviews without reloading the page. Not used in the slider layout.', 'ffl-funnels-addons'),
        ];

        $this->controls['perPage'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Max reviews', 'ffl-funnels-addons'),
            'type'    => 'number',
            'default' => 5,
            'min'     => 1,
            'max'     => 50,
        ];

        $this->controls['orderBy'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Order by', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                'recent'  => esc_html__('Most recent', 'ffl-funnels-addons'),
                'helpful' => esc_html__('Most helpful', 'ffl-funnels-addons'),
                'highest' => esc_html__('Highest rating', 'ffl-funnels-addons'),
                'lowest'  => esc_html__('Lowest rating', 'ffl-funnels-addons'),
            ],
            'default' => 'recent',
        ];

        // A checkbox cannot express "off" here: Bricks omits the key entirely
        // when it is unchecked, which is indistinguishable from "never set".
        $this->controls['summaryMode'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Rating summary', 'ffl-funnels-addons'),
            'type'        => 'select',
            'options'     => [
                ''     => esc_html__('Use module setting', 'ffl-funnels-addons'),
                'show' => esc_html__('Show', 'ffl-funnels-addons'),
                'hide' => esc_html__('Hide', 'ffl-funnels-addons'),
            ],
            'default'     => '',
            'description' => esc_html__('Average score and star-by-star breakdown above the list.', 'ffl-funnels-addons'),
        ];

        $this->controls['starFilledColor'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Filled stars color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'default' => '#d4a017',
            'css'     => [['property' => '--ffla-star-filled', 'selector' => '']],
        ];

        $this->controls['starEmptyColor'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Empty stars color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'default' => '#d8dce5',
            'css'     => [['property' => '--ffla-star-empty', 'selector' => '']],
        ];

        $this->controls['starSize'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Star size', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'default' => '16px',
            'css'     => [['property' => 'font-size', 'selector' => '.ffla-stars']],
        ];

        $this->controls['cardBg'] = [
            'tab'   => 'content',
            'label' => esc_html__('Card background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.ffla-review-card']],
        ];

        $this->controls['cardBorder'] = [
            'tab'   => 'content',
            'label' => esc_html__('Card border', 'ffl-funnels-addons'),
            'type'  => 'border',
            'css'   => [['property' => 'border', 'selector' => '.ffla-review-card']],
        ];

        $this->controls['cardRadius'] = [
            'tab'   => 'content',
            'label' => esc_html__('Card border radius', 'ffl-funnels-addons'),
            'type'  => 'number',
            'units' => true,
            'css'   => [['property' => 'border-radius', 'selector' => '.ffla-review-card']],
        ];

        $this->controls['cardPadding'] = [
            'tab'   => 'content',
            'label' => esc_html__('Card padding', 'ffl-funnels-addons'),
            'type'  => 'spacing',
            'css'   => [['property' => 'padding', 'selector' => '.ffla-review-card']],
        ];

        $this->controls['listGap'] = [
            'tab'   => 'content',
            'label' => esc_html__('List gap', 'ffl-funnels-addons'),
            'type'  => 'number',
            'units' => true,
            'css'   => [['property' => 'gap', 'selector' => '.ffla-reviews-list__items']],
        ];

        $this->controls['authorTypography'] = [
            'tab'   => 'content',
            'label' => esc_html__('Author typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-review-card__author']],
        ];

        $this->controls['dateTypography'] = [
            'tab'   => 'content',
            'label' => esc_html__('Date typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-review-card__date']],
        ];

        $this->controls['contentTypography'] = [
            'tab'   => 'content',
            'label' => esc_html__('Review text typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-review-card__content']],
        ];
    }

    public function render()
    {
        $settings = $this->settings;
        $source   = isset($settings['source']) ? sanitize_key((string) $settings['source']) : '';

        $product_id = 0;
        if ('' === $source) {
            $explicit   = !empty($settings['productId']) ? absint($settings['productId']) : 0;
            $product_id = \Product_Reviews_Core::resolve_context_product_id($explicit);
            if ($product_id <= 0) {
                return $this->render_element_placeholder([
                    'title' => esc_html__('No product found for reviews list.', 'ffl-funnels-addons'),
                ]);
            }
        }

        $per_page = !empty($settings['perPage']) ? absint($settings['perPage']) : 5;
        $per_page = max(1, min(50, $per_page));
        $layout   = isset($settings['layout']) && in_array($settings['layout'], ['grid', 'slider'], true) ? $settings['layout'] : 'list';

        $list_settings = [
            'perPage'         => $per_page,
            'orderBy'         => $settings['orderBy'] ?? 'recent',
            'source'          => '' === $source ? 'product' : $source,
            'categories'      => $settings['categories'] ?? [],
            'layout'          => $layout,
            'columns'         => $settings['columns'] ?? 3,
            'minRating'       => $settings['minRating'] ?? 0,
            'withMedia'       => 'only' === ($settings['withMedia'] ?? ''),
            'showProduct'     => $settings['showProduct'] ?? '',
            'showFilters'     => $settings['showFilters'] ?? '',
            'showMediaFilter' => $settings['showMediaFilter'] ?? '',
            'showSort'        => $settings['showSort'] ?? '',
            'showSearch'      => $settings['showSearch'] ?? '',
            'showMore'        => $settings['showMore'] ?? '',
        ];

        // Only override when the element takes a side; otherwise leave the key
        // absent so the renderer falls back to the module setting.
        $summary_mode = $settings['summaryMode'] ?? '';
        if ('show' === $summary_mode) {
            $list_settings['showSummary'] = true;
        } elseif ('hide' === $summary_mode) {
            $list_settings['showSummary'] = false;
        }

        $this->set_attribute('_root', 'class', 'ffla-reviews-list');
        $this->set_attribute('_root', 'class', 'ffla-reviews-list--' . $layout);

        echo '<div ' . $this->render_attributes('_root') . '>';
        \Product_Reviews_Frontend_Render::render_reviews_list($product_id, $list_settings, false);
        echo '</div>';
    }
}
