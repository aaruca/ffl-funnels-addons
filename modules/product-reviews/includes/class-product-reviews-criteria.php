<?php
/**
 * Configurable secondary rating criteria.
 *
 * A review always carries an overall `rating` (1-5). On top of that a shop can
 * define any number of secondary criteria — "Quality", "Value for money",
 * "Accuracy" — each scored 1-5 and each optional.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Criteria
{
    /**
     * Hard cap so a pasted essay in the settings textarea cannot generate
     * hundreds of star groups on every product page.
     */
    const MAX_CRITERIA = 6;

    /**
     * Criteria that predate this class stored their score under a bespoke meta
     * key. Those keys must keep resolving or every review written before the
     * upgrade silently loses its secondary scores.
     *
     * @var array<string, string>
     */
    const LEGACY_META_KEYS = [
        'quality' => 'ffla_review_quality',
        'value'   => 'ffla_review_value',
    ];

    /** @var array<int, array{slug:string,label:string}>|null */
    private static $memo = null;

    /**
     * The stored form is one criterion per line, `slug|Label`. Keeping the slug
     * explicit means renaming a label never orphans the meta already written
     * under the old slug.
     */
    public static function default_definition(): string
    {
        return "quality|Quality\nvalue|Value for money";
    }

    /**
     * @return array<int, array{slug:string,label:string}>
     */
    public static function get_criteria(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $raw = (string) Product_Reviews_Core::get_setting('review_criteria', self::default_definition());
        self::$memo = self::parse_definition($raw);

        return self::$memo;
    }

    /**
     * Settings writes happen in the same request as a later read on the
     * settings screen, so the memo has to be droppable.
     */
    public static function flush_memo(): void
    {
        self::$memo = null;
    }

    /**
     * @return array<int, array{slug:string,label:string}>
     */
    public static function parse_definition(string $raw): array
    {
        $criteria = [];
        $seen     = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (strpos($line, '|') !== false) {
                [$slug, $label] = array_map('trim', explode('|', $line, 2));
            } else {
                $slug  = $line;
                $label = $line;
            }

            $slug  = sanitize_key($slug);
            $label = sanitize_text_field($label);

            if ($slug === '' || $label === '' || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;
            $criteria[]  = ['slug' => $slug, 'label' => $label];

            if (count($criteria) >= self::MAX_CRITERIA) {
                break;
            }
        }

        return $criteria;
    }

    public static function is_enabled(): bool
    {
        return '1' === Product_Reviews_Core::get_setting('enable_criteria', '1')
            && !empty(self::get_criteria());
    }

    public static function meta_key(string $slug): string
    {
        return self::LEGACY_META_KEYS[$slug] ?? 'ffla_review_criteria_' . $slug;
    }

    /**
     * Field name posted by the review form for a criterion.
     */
    public static function field_name(string $slug): string
    {
        return 'ffla_review_criteria[' . $slug . ']';
    }

    /**
     * Pull a criterion's score out of $_POST.
     *
     * Accepts the modern grouped field and, for criteria that used to have a
     * dedicated input, the flat legacy name still emitted by any cached page
     * or third-party template.
     */
    public static function read_submitted_score(string $slug): int
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verified.
        if (isset($_POST['ffla_review_criteria']) && is_array($_POST['ffla_review_criteria'])) {
            $group = wp_unslash($_POST['ffla_review_criteria']);
            if (isset($group[$slug])) {
                return self::clamp((int) $group[$slug]);
            }
        }

        $legacy = self::LEGACY_META_KEYS[$slug] ?? '';
        if ($legacy !== '' && isset($_POST[$legacy])) {
            return self::clamp((int) wp_unslash($_POST[$legacy]));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        return 0;
    }

    private static function clamp(int $score): int
    {
        if ($score < 1 || $score > 5) {
            return 0;
        }

        return $score;
    }

    /**
     * Scores actually recorded on a review, in the shop's configured order.
     *
     * Criteria removed from the settings after a review was written are not
     * returned — the meta stays on the comment, so re-adding the criterion
     * brings the historical scores back.
     *
     * @return array<int, array{slug:string,label:string,score:int}>
     */
    public static function get_review_scores(int $comment_id): array
    {
        $out = [];

        foreach (self::get_criteria() as $criterion) {
            $score = (int) get_comment_meta($comment_id, self::meta_key($criterion['slug']), true);
            if ($score < 1) {
                continue;
            }

            $out[] = [
                'slug'  => $criterion['slug'],
                'label' => $criterion['label'],
                'score' => min(5, $score),
            ];
        }

        return $out;
    }

    /**
     * Persist every configured criterion posted with a review.
     */
    public static function save_review_scores(int $comment_id): void
    {
        foreach (self::get_criteria() as $criterion) {
            $score = self::read_submitted_score($criterion['slug']);
            if ($score > 0) {
                update_comment_meta($comment_id, self::meta_key($criterion['slug']), $score);
            }
        }
    }
}
