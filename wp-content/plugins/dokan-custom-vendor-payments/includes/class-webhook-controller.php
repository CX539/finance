<?php

namespace DCP;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Webhook_Controller
{
    private const TABLE = 'dcp_webhook_event';

    public function __construct(
        private Method_Registry $registry,
        private Transaction_Repository $transactions,
        private Audit_Log $audit_log
    ) {
    }

    public function register_routes(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('dcp/v1', '/webhook/(?P<method_id>[a-zA-Z0-9_-]+)', [
                'methods' => 'POST',
                'permission_callback' => '__return_true',
                'callback' => [$this, 'handle'],
            ]);
        });
    }

    public function handle(WP_REST_Request $request)
    {
        $method_id = (string) $request->get_param('method_id');
        $payload   = (array) $request->get_json_params();
        $headers   = $request->get_headers();

        try {
            $method = $this->registry->get($method_id);
        } catch (\Throwable $e) {
            return new WP_Error('dcp_unknown_method', $e->getMessage(), ['status' => 404]);
        }

        $event = $method->handle_webhook($payload, $headers);
        $event_id = (string) ($event['provider_event_id'] ?? '');
        if ($event_id === '') {
            return new WP_Error('dcp_invalid_event', 'Missing provider event id', ['status' => 400]);
        }

        if ($this->is_duplicate($method_id, $event_id)) {
            return new WP_REST_Response(['ok' => true, 'dedupe' => true], 200);
        }

        $this->store_event($method_id, $event, $payload, true);
        do_action('dcp_webhook_received', $method_id, $event_id);

        (new Payout_Orchestrator($this->registry, $this->transactions, $this->audit_log))
            ->reconcile_webhook($method_id, $event);

        return new WP_REST_Response(['ok' => true], 200);
    }

    private function is_duplicate(string $method_id, string $event_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE method_id = %s AND provider_event_id = %s LIMIT 1",
            $method_id,
            $event_id
        ));

        return ! empty($found);
    }

    private function store_event(string $method_id, array $event, array $payload, bool $signature_valid): void
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->insert($table, [
            'method_id' => $method_id,
            'provider_event_id' => (string) ($event['provider_event_id'] ?? ''),
            'provider_txn_id' => (string) ($event['provider_txn_id'] ?? ''),
            'payload_json' => wp_json_encode($payload),
            'signature_valid' => $signature_valid ? 1 : 0,
            'processed' => 1,
            'created_at' => current_time('mysql', true),
        ]);
    }
}
