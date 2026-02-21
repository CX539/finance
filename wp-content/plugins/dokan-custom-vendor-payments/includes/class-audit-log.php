<?php

namespace DCP;

final class Audit_Log
{
    private const TABLE = 'dcp_audit_log';

    public function record(?int $tx_id, string $event_key, ?string $old_status, ?string $new_status, array $context = []): void
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            [
                'tx_id' => $tx_id,
                'actor_type' => $this->actor_type(),
                'actor_id' => get_current_user_id() ?: null,
                'event_key' => $event_key,
                'old_status' => $old_status,
                'new_status' => $new_status,
                'context_json' => wp_json_encode($this->redact($context)),
                'created_at' => current_time('mysql', true),
            ]
        );
    }

    private function actor_type(): string
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'webhook';
        }

        if (is_admin()) {
            return 'admin';
        }

        if (get_current_user_id() > 0) {
            return 'vendor';
        }

        return 'system';
    }

    private function redact(array $context): array
    {
        $sensitive = ['api_key', 'webhook_secret', 'account_number', 'routing_number'];
        foreach ($sensitive as $key) {
            if (array_key_exists($key, $context)) {
                $context[$key] = '***';
            }
        }

        return $context;
    }
}
