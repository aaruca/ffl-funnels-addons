<?php
/**
 * Customer requests as abilities (WordPress Abilities API, 6.9+), exposed to
 * MCP clients through the MCP Adapter — so staff can ask their own AI things
 * like "which returns are waiting on us?" or "summarize request 1234-1".
 *
 * Read-only, plus internal notes (never shown or emailed to customers).
 * Customer replies, status changes and closing stay in the admin screen.
 * Every ability requires `manage_woocommerce`.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Abilities
{
    const CATEGORY = 'ffla-requests';

    public static function boot(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        add_action('wp_abilities_api_categories_init', [__CLASS__, 'register_category']);
        add_action('wp_abilities_api_init', [__CLASS__, 'register_abilities']);
    }

    public static function register_category(): void
    {
        wp_register_ability_category(self::CATEGORY, [
            'label'       => __('Customer requests', 'ffl-funnels-addons'),
            'description' => __('Customer issue reports and return requests on WooCommerce orders.', 'ffl-funnels-addons'),
        ]);
    }

    public static function can_manage(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public static function register_abilities(): void
    {
        if (!FFLA_Requests::enabled()) {
            return;
        }
        $read = ['readonly' => true, 'destructive' => false, 'idempotent' => true];

        self::register('ffla-requests/list', [
            'label'        => __('List customer requests', 'ffl-funnels-addons'),
            'description'  => 'List customer requests (issues and returns). view: open (default), awaiting (customer is waiting for a staff reply), new, mine, overdue, closed, all. Optional type (issue|return), status, search (request number, order number, customer name or email).',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'view'   => ['type' => 'string', 'enum' => ['open', 'awaiting', 'new', 'mine', 'overdue', 'closed', 'all'], 'default' => 'open'],
                    'type'   => ['type' => 'string', 'enum' => ['issue', 'return']],
                    'status' => ['type' => 'string', 'enum' => array_keys(FFLA_Requests::statuses())],
                    'search' => ['type' => 'string'],
                    'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
                ],
            ],
            'execute'      => static function ($input) {
                $input = (array) $input;
                $result = FFLA_Requests::query([
                    'view'     => $input['view'] ?? 'open',
                    'type'     => $input['type'] ?? '',
                    'status'   => $input['status'] ?? '',
                    'search'   => $input['search'] ?? '',
                    'per_page' => min(50, max(1, (int) ($input['limit'] ?? 20))),
                ]);
                return [
                    'total'    => $result['total'],
                    'counts'   => FFLA_Requests::counts(),
                    'requests' => array_map([__CLASS__, 'row'], $result['rows']),
                ];
            },
            'annotations'  => $read,
        ]);

        self::register('ffla-requests/get', [
            'label'        => __('Get customer request', 'ffl-funnels-addons'),
            'description'  => 'Full detail of one request by its number (e.g. "1234-1"): items, customer preference, status, assignment and the complete timeline including internal notes.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => ['number' => ['type' => 'string']],
                'required'   => ['number'],
            ],
            'execute'      => static function ($input) {
                $request = FFLA_Requests::get_by_number((string) (((array) $input)['number'] ?? ''));
                if (!$request) {
                    return new WP_Error('not_found', 'No request with that number.');
                }
                $out = self::row($request);
                $out['items'] = FFLA_Requests::items($request);
                $out['resolution_note'] = (string) $request->resolution_note;
                $out['admin_url'] = admin_url('admin.php?page=ffla-requests&request=' . (int) $request->id);
                $out['timeline'] = array_map(static function ($event) {
                    return [
                        'time'   => FFLA_Requests::local_time($event->created_at),
                        'actor'  => $event->actor_type,
                        'kind'   => $event->kind,
                        'public' => (bool) $event->is_public,
                        'text'   => (string) $event->body,
                        'meta'   => json_decode((string) $event->meta, true),
                    ];
                }, FFLA_Requests::events((int) $request->id));
                return $out;
            },
            'annotations'  => $read,
        ]);

        self::register('ffla-requests/add-note', [
            'label'        => __('Add internal note', 'ffl-funnels-addons'),
            'description'  => 'Add an internal (staff-only) note to a request, e.g. a triage summary. Never shown or emailed to the customer.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => ['number' => ['type' => 'string'], 'note' => ['type' => 'string']],
                'required'   => ['number', 'note'],
            ],
            'execute'      => static function ($input) {
                $input = (array) $input;
                $request = FFLA_Requests::get_by_number((string) ($input['number'] ?? ''));
                if (!$request) {
                    return new WP_Error('not_found', 'No request with that number.');
                }
                try {
                    FFLA_Requests::add_message($request, ['type' => 'staff', 'id' => get_current_user_id()], (string) ($input['note'] ?? ''), false);
                } catch (InvalidArgumentException $e) {
                    return new WP_Error('invalid', $e->getMessage());
                }
                return ['saved' => true];
            },
            'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
        ]);
    }

    public static function row($r): array
    {
        $assignee = (int) $r->assignee ? get_user_by('id', (int) $r->assignee) : null;
        return [
            'number'     => $r->number,
            'order'      => $r->order_number,
            'type'       => $r->type,
            'reason'     => FFLA_Requests::reasons($r->type)[$r->reason] ?? $r->reason,
            'preferred'  => $r->preferred ? (FFLA_Requests::preferences()[$r->preferred] ?? $r->preferred) : '',
            'status'     => FFLA_Requests::status_label($r->status),
            'awaiting'   => $r->awaiting,
            'priority'   => $r->priority,
            'assignee'   => $assignee ? $assignee->display_name : '',
            'due'        => $r->due_at ? FFLA_Requests::local_time($r->due_at, 'Y-m-d') : '',
            'customer'   => $r->customer_name,
            'email'      => $r->customer_email,
            'firearm'    => (bool) $r->has_firearm,
            'resolution' => $r->resolution ? (FFLA_Requests::resolutions()[$r->resolution] ?? $r->resolution) : '',
            'created'    => FFLA_Requests::local_time($r->created_at),
            'updated'    => FFLA_Requests::local_time($r->updated_at),
        ];
    }

    private static function register(string $name, array $def): void
    {
        wp_register_ability($name, [
            'label'               => $def['label'],
            'description'         => $def['description'],
            'category'            => self::CATEGORY,
            'execute_callback'    => $def['execute'],
            'permission_callback' => [__CLASS__, 'can_manage'],
            'input_schema'        => $def['input_schema'],
            'meta'                => [
                'mcp'         => ['public' => true, 'type' => 'tool'],
                'annotations' => $def['annotations'],
            ],
        ]);
    }
}
