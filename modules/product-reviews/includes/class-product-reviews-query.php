<?php
/**
 * Review queries for lists with filters, sorting and paging.
 *
 * One SQL builder serves every list that is not a plain "newest reviews of
 * this product" list: shopper filters (stars, with photos, search), the
 * Most helpful / Highest / Lowest sorts, "Show more" paging and the
 * store-wide or per-category lists. Only approved top-level `review`
 * comments on published products are ever returned, whatever a request
 * asks for, so the AJAX endpoint can take its arguments from the browser.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Query
{
    const ORDERS = ['recent', 'helpful', 'highest', 'lowest'];

    const MAX_PER_PAGE = 50;

    const MAX_SEARCH_LENGTH = 100;

    /** At most this many products or categories in one scope. */
    const MAX_SCOPE_IDS = 100;

    /** Cached scope numbers (rating counts, store stats). */
    const CACHE_PREFIX = 'ffla_rev_scope_';

    /** Bumped whenever a review changes, which retires every cached scope. */
    const CACHE_VERSION_OPTION = 'ffla_rev_cache_version';

    public static function init(): void
    {
        // A review was posted, edited, approved, unapproved, trashed or
        // deleted: WordPress recounts the product's comments for each.
        add_action('wp_update_comment_count', [__CLASS__, 'bump_cache_version'], 10, 1);
    }

    /**
     * @param int|mixed $post_id
     */
    public static function bump_cache_version($post_id = 0): void
    {
        if ((int) $post_id > 0 && 'product' !== get_post_type((int) $post_id)) {
            return;
        }

        update_option(self::CACHE_VERSION_OPTION, (int) get_option(self::CACHE_VERSION_OPTION, 0) + 1, false);
    }

    /**
     * Clamp and type every argument. Unknown keys are dropped.
     *
     * @param array<string, mixed> $args products (int[]), categories (int[]), rating (0-5, exact),
     *                                   minRating (0-5), withMedia (bool), search (string), orderBy,
     *                                   pinnedFirst (bool, default true), page (1-based), perPage (1-50).
     * @return array{products:int[],categories:int[],rating:int,minRating:int,withMedia:bool,search:string,orderBy:string,pinnedFirst:bool,page:int,perPage:int}
     */
    public static function normalize(array $args): array
    {
        $order = isset($args['orderBy']) ? sanitize_key((string) $args['orderBy']) : 'recent';

        $search = isset($args['search']) ? trim(sanitize_text_field((string) $args['search'])) : '';
        if (function_exists('mb_substr')) {
            $search = mb_substr($search, 0, self::MAX_SEARCH_LENGTH);
        } else {
            $search = substr($search, 0, self::MAX_SEARCH_LENGTH);
        }

        return [
            'products'    => self::id_list($args['products'] ?? []),
            'categories'  => self::id_list($args['categories'] ?? []),
            'rating'      => self::stars($args['rating'] ?? 0),
            'minRating'   => self::stars($args['minRating'] ?? 0),
            'withMedia'   => !empty($args['withMedia']) && 'false' !== $args['withMedia'],
            'search'      => $search,
            'orderBy'     => in_array($order, self::ORDERS, true) ? $order : 'recent',
            'pinnedFirst' => !array_key_exists('pinnedFirst', $args) || !empty($args['pinnedFirst']),
            // Signed on purpose: absint() would turn page -3 into page 3.
            'page'        => max(1, (int) ($args['page'] ?? 1)),
            'perPage'     => max(1, min(self::MAX_PER_PAGE, (int) ($args['perPage'] ?? 5) ?: 5)),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private static function id_list($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $value))));

        return array_slice($ids, 0, self::MAX_SCOPE_IDS);
    }

    /**
     * @param mixed $value
     */
    private static function stars($value): int
    {
        $stars = absint($value);

        return $stars >= 1 && $stars <= 5 ? $stars : 0;
    }

    /**
     * Product categories with all their subcategories.
     *
     * @param array<int, int> $term_ids
     * @return array<int, int>
     */
    private static function with_child_categories(array $term_ids): array
    {
        $all = $term_ids;
        foreach ($term_ids as $term_id) {
            $children = get_term_children($term_id, 'product_cat');
            if (is_array($children)) {
                $all = array_merge($all, array_map('intval', $children));
            }
        }

        return array_values(array_unique(array_filter($all)));
    }

    /**
     * FROM, JOIN and WHERE shared by the list, count and rating queries.
     *
     * @param array<string, mixed> $q       Normalised arguments.
     * @param bool                 $filters False for the scope alone (rating counts ignore the shopper's filters).
     * @param string               $joins   Extra joins (the helpful sort's vote counts).
     */
    private static function from_where(array $q, bool $filters = true, string $joins = ''): string
    {
        global $wpdb;

        $sql = " FROM {$wpdb->comments} c"
            . " INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID AND p.post_type = 'product' AND p.post_status = 'publish'"
            . " LEFT JOIN {$wpdb->commentmeta} r ON r.comment_id = c.comment_ID AND r.meta_key = 'rating'"
            . $joins
            . " WHERE c.comment_approved = '1' AND c.comment_type = 'review' AND c.comment_parent = 0";

        if (!empty($q['products'])) {
            $sql .= ' AND c.comment_post_ID IN (' . implode(',', array_map('absint', $q['products'])) . ')';
        } elseif (!empty($q['categories'])) {
            $terms = self::with_child_categories($q['categories']);
            $sql  .= " AND c.comment_post_ID IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
                . " WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN (" . implode(',', array_map('absint', $terms)) . '))';
        }

        if (!$filters) {
            return $sql;
        }

        if ($q['rating'] > 0) {
            $sql .= $wpdb->prepare(' AND CAST(r.meta_value AS UNSIGNED) = %d', $q['rating']);
        } elseif ($q['minRating'] > 0) {
            $sql .= $wpdb->prepare(' AND CAST(r.meta_value AS UNSIGNED) >= %d', $q['minRating']);
        }

        if ($q['withMedia']) {
            $sql .= " AND EXISTS (SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id = c.comment_ID"
                . " AND m.meta_key = 'ffla_review_media_ids' AND m.meta_value NOT IN ('', 'a:0:{}'))";
        }

        if ($q['search'] !== '') {
            $like = '%' . $wpdb->esc_like($q['search']) . '%';
            $sql .= $wpdb->prepare(' AND (c.comment_content LIKE %s OR c.comment_author LIKE %s)', $like, $like);
        }

        return $sql;
    }

    /**
     * Matching review IDs for one page, in display order, and how many match
     * in all.
     *
     * Pinned reviews lead (pin state lives in `comment_karma`). Every sort
     * ends on the comment ID, so a page boundary never repeats or skips a
     * review between two "Show more" requests.
     *
     * @param array<string, mixed> $args See normalize().
     * @param bool                 $with_total False skips the count query (total is then -1).
     * @return array{ids:array<int,int>,total:int}
     */
    public static function find(array $args, bool $with_total = true): array
    {
        global $wpdb;

        $q = self::normalize($args);

        $joins = '';
        $order = [];
        if ($q['pinnedFirst']) {
            $order[] = 'MAX(c.comment_karma = 1) DESC';
        }

        $rating = "CAST(COALESCE(MAX(r.meta_value), '0') AS SIGNED)";

        switch ($q['orderBy']) {
            case 'helpful':
                $joins   = " LEFT JOIN {$wpdb->commentmeta} y ON y.comment_id = c.comment_ID AND y.meta_key = 'ffla_helpful_yes'"
                    . " LEFT JOIN {$wpdb->commentmeta} n ON n.comment_id = c.comment_ID AND n.meta_key = 'ffla_helpful_no'";
                $order[] = "(CAST(COALESCE(MAX(y.meta_value), '0') AS SIGNED) - CAST(COALESCE(MAX(n.meta_value), '0') AS SIGNED)) DESC";
                break;

            case 'highest':
                $order[] = $rating . ' DESC';
                break;

            case 'lowest':
                // Reviews left without stars are not "the lowest".
                $order[] = 'MAX(r.meta_value) IS NULL ASC';
                $order[] = $rating . ' ASC';
                break;
        }

        $order[] = 'MAX(c.comment_date_gmt) DESC';
        $order[] = 'c.comment_ID DESC';

        $sql = 'SELECT c.comment_ID' . self::from_where($q, true, $joins)
            . ' GROUP BY c.comment_ID ORDER BY ' . implode(', ', $order)
            . $wpdb->prepare(' LIMIT %d OFFSET %d', $q['perPage'], ($q['page'] - 1) * $q['perPage']);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- built from prepared parts and integers.
        $ids = array_map('intval', (array) $wpdb->get_col($sql));

        $total = -1;
        if ($with_total) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $total = (int) $wpdb->get_var('SELECT COUNT(DISTINCT c.comment_ID)' . self::from_where($q));
        }

        return ['ids' => $ids, 'total' => $total];
    }

    /**
     * The reviews of find(), as comments in display order.
     *
     * @param array<string, mixed> $args
     * @return array{reviews:array<int,\WP_Comment>,total:int}
     */
    public static function reviews(array $args, bool $with_total = true): array
    {
        $found = self::find($args, $with_total);
        if (empty($found['ids'])) {
            return ['reviews' => [], 'total' => max(0, $found['total'])];
        }

        $reviews = get_comments([
            'comment__in' => $found['ids'],
            'orderby'     => 'comment__in',
            'status'      => 'approve',
            'number'      => count($found['ids']),
        ]);

        return ['reviews' => is_array($reviews) ? array_values($reviews) : [], 'total' => $found['total']];
    }

    /**
     * Star counts, average and review count for a scope (store-wide, some
     * categories or some products), worked out once and cached until a
     * review changes. A single product's numbers come from WooCommerce
     * instead (Product_Reviews_Core::get_rating_distribution()).
     *
     * @param array<string, mixed> $args products / categories; filters are ignored.
     * @return array{counts:array<int,int>,total:int,average:float,review_count:int}
     */
    public static function distribution(array $args): array
    {
        global $wpdb;

        $q   = self::normalize($args);
        $key = self::cache_key('dist', [$q['products'], $q['categories']]);

        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['counts'])) {
            return $cached;
        }

        $where = self::from_where($q, false);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results(
            'SELECT CAST(r.meta_value AS UNSIGNED) AS stars, COUNT(DISTINCT c.comment_ID) AS n' . $where
            . " AND r.meta_value IN ('1','2','3','4','5') GROUP BY CAST(r.meta_value AS UNSIGNED)",
            ARRAY_A
        );

        $result = [
            'counts'       => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
            'total'        => 0,
            'average'      => 0.0,
            'review_count' => 0,
        ];

        $sum = 0;
        foreach ($rows as $row) {
            $stars = (int) ($row['stars'] ?? 0);
            $count = (int) ($row['n'] ?? 0);
            if ($stars < 1 || $stars > 5) {
                continue;
            }
            $result['counts'][$stars] += $count;
            $result['total']          += $count;
            $sum                      += $stars * $count;
        }

        $result['average'] = $result['total'] > 0 ? round($sum / $result['total'], 2) : 0.0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $result['review_count'] = (int) $wpdb->get_var('SELECT COUNT(DISTINCT c.comment_ID)' . $where);

        set_transient($key, $result, HOUR_IN_SECONDS);

        return $result;
    }

    /**
     * Store-wide numbers for the trust badge: the rating distribution of
     * every published product plus how many reviews carry Verified buyer
     * (the module's own flag, or WooCommerce's for reviews written before
     * the module was switched on).
     *
     * @return array{counts:array<int,int>,total:int,average:float,review_count:int,verified:int,verified_percent:int}
     */
    public static function store_stats(): array
    {
        global $wpdb;

        $stats = self::distribution([]);
        $key   = self::cache_key('verified', []);

        $verified = get_transient($key);
        if (false === $verified) {
            $where = self::from_where(self::normalize([]), false);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $verified = (int) $wpdb->get_var(
                'SELECT COUNT(DISTINCT c.comment_ID)' . $where
                . " AND (EXISTS (SELECT 1 FROM {$wpdb->commentmeta} f WHERE f.comment_id = c.comment_ID AND f.meta_key = 'ffla_verified_purchase' AND f.meta_value = '1')"
                . " OR (NOT EXISTS (SELECT 1 FROM {$wpdb->commentmeta} f2 WHERE f2.comment_id = c.comment_ID AND f2.meta_key = 'ffla_verified_purchase')"
                . " AND EXISTS (SELECT 1 FROM {$wpdb->commentmeta} w WHERE w.comment_id = c.comment_ID AND w.meta_key = 'verified' AND w.meta_value = '1')))"
            );
            set_transient($key, $verified, HOUR_IN_SECONDS);
        }

        $stats['verified']         = (int) $verified;
        $stats['verified_percent'] = $stats['review_count'] > 0 ? (int) round(($stats['verified'] / $stats['review_count']) * 100) : 0;

        return $stats;
    }

    /**
     * @param mixed $parts
     */
    private static function cache_key(string $kind, $parts): string
    {
        $version = (int) get_option(self::CACHE_VERSION_OPTION, 0);

        return self::CACHE_PREFIX . $kind . '_' . md5($version . '|' . wp_json_encode($parts));
    }
}
