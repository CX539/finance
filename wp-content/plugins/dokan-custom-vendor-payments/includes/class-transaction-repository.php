<?php

namespace DCP;

use wpdb;

final class Transaction_Repository
{
    private const TABLE = 'dcp_payout_tx';

    private const ALLOWED_TRANSITIONS = [
        'queued' => ['processing', 'cancelled'],
        'processing' => ['pending_external', 'paid', 'failed'],
        'pending_external' => ['paid', 'failed', 'reversed'],
        'failed' => ['processing'],
        'paid' => ['reversed'],
    ];

    public function create_or_get(array $data): array
    {
        $found = $this->find_by_withdraw((int) $data['withdraw_id'], (string) $data['method_id']);
        if ($found !== null) {
            return $found;
        }

        global $wpdb;
        $table = $this->table_name($wpdb);
        $now   = current_time('mysql', true);

        $insert = [
            'withdraw_id' => (int) $data['withdraw_id'],
            'vendor_id' => (int) $data['vendor_id'],
            'method_id' => (string) $data['method_id'],
            'amount_minor' => (int) $data['amount_minor'],
            'currency' => strtoupper((string) $data['currency']),
            'status' => 'queued',
            'idempotency_key' => (string) $data['idempotency_key'],
            'meta_json' => wp_json_encode($data['meta'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $wpdb->insert($table, $insert);

        return $this->find((int) $wpdb->insert_id) ?? $insert;
    }

    public function transition(int $tx_id, string $from, string $to, string $reason = '', array $context = []): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            return false;
        }

        global $wpdb;
        $table = $this->table_name($wpdb);

        $updated = $wpdb->update(
            $table,
            [
                'status' => $to,
                'updated_at' => current_time('mysql', true),
            ],
            [
                'id' => $tx_id,
                'status' => $from,
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        if ($updated !== 1) {
            return false;
        }

        do_action('dcp_payout_status_changed', $tx_id, $from, $to, $reason, $context);

        return true;
    }

    public function find(int $tx_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . $this->table_name($wpdb) . ' WHERE id = %d', $tx_id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function find_by_withdraw(int $withdraw_id, string $method_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->table_name($wpdb) . ' WHERE withdraw_id = %d AND method_id = %s LIMIT 1',
                $withdraw_id,
                $method_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function find_by_provider_txn(string $provider_txn_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . $this->table_name($wpdb) . ' WHERE provider_txn_id = %s LIMIT 1', $provider_txn_id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function update_provider_fields(int $tx_id, array $fields): void
    {
        global $wpdb;
        $update = [];
        if (isset($fields['provider_txn_id'])) {
            $update['provider_txn_id'] = (string) $fields['provider_txn_id'];
        }
        if (isset($fields['failure_code'])) {
            $update['failure_code'] = (string) $fields['failure_code'];
        }
        if (isset($fields['failure_message'])) {
            $update['failure_message'] = (string) $fields['failure_message'];
        }
        if (isset($fields['attempt_count'])) {
            $update['attempt_count'] = (int) $fields['attempt_count'];
        }
        if ($update === []) {
            return;
        }
        $update['updated_at'] = current_time('mysql', true);

        $wpdb->update($this->table_name($wpdb), $update, ['id' => $tx_id]);
    }

    public function eligible_failed_transactions(int $limit = 20): array
    {
        global $wpdb;
        $table = $this->table_name($wpdb);

        return (array) $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE status = 'failed' ORDER BY updated_at ASC LIMIT %d", $limit),
            ARRAY_A
        );
    }

    private function table_name(wpdb $wpdb): string
    {
        return $wpdb->prefix . self::TABLE;
    }
}
