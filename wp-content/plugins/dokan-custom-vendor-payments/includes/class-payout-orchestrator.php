<?php

namespace DCP;

use RuntimeException;

final class Payout_Orchestrator
{
    public function __construct(
        private Method_Registry $registry,
        private Transaction_Repository $transactions,
        private Audit_Log $audit_log
    ) {
    }

    public function process_withdraw(array $withdraw): ?array
    {
        $method_id = (string) ($withdraw['method_id'] ?? '');
        if ($method_id === '') {
            return null;
        }

        $method = $this->registry->get($method_id);

        $tx = $this->transactions->create_or_get([
            'withdraw_id' => (int) $withdraw['id'],
            'vendor_id' => (int) $withdraw['user_id'],
            'method_id' => $method_id,
            'amount_minor' => (int) round(((float) $withdraw['amount']) * 100),
            'currency' => (string) ($withdraw['currency'] ?? get_woocommerce_currency()),
            'idempotency_key' => $this->idempotency_key((int) $withdraw['id'], $method_id),
            'meta' => ['source' => 'withdraw_approved'],
        ]);

        $status = (string) ($tx['status'] ?? 'queued');
        if ($status === 'paid' || $status === 'pending_external') {
            return $tx;
        }

        if (! $this->transactions->transition((int) $tx['id'], 'queued', 'processing', 'start_processing')) {
            if (! $this->transactions->transition((int) $tx['id'], 'failed', 'processing', 'retry_processing')) {
                throw new RuntimeException('Unable to transition payout transaction to processing state.');
            }
        }

        do_action('dcp_before_payout_create', $tx, $withdraw, $method_id);

        $response = $method->create_payout($tx, $withdraw, ['vendor_id' => (int) $withdraw['user_id']]);
        $result   = (string) ($response['result'] ?? 'failed');

        $this->transactions->update_provider_fields((int) $tx['id'], [
            'provider_txn_id' => $response['provider_txn_id'] ?? null,
            'failure_code' => $response['failure_code'] ?? null,
            'failure_message' => $response['failure_message'] ?? null,
            'attempt_count' => ((int) ($tx['attempt_count'] ?? 0)) + 1,
        ]);

        $new_status = match ($result) {
            'success' => 'paid',
            'pending' => 'pending_external',
            default => 'failed',
        };

        $this->transactions->transition((int) $tx['id'], 'processing', $new_status, 'provider_result', ['result' => $result]);
        $this->audit_log->record((int) $tx['id'], 'provider_result', 'processing', $new_status, $response);

        do_action('dcp_after_payout_create', $tx, $response);

        return $this->transactions->find((int) $tx['id']);
    }

    public function reconcile_webhook(string $method_id, array $event): bool
    {
        $tx = $this->transactions->find_by_provider_txn((string) ($event['provider_txn_id'] ?? ''));
        if (! $tx) {
            return false;
        }

        $mapped = (string) ($event['mapped_status'] ?? '');
        if ($mapped === '') {
            return false;
        }

        $ok = $this->transactions->transition((int) $tx['id'], (string) $tx['status'], $mapped, (string) ($event['reason'] ?? 'webhook'));

        if ($ok) {
            $this->audit_log->record((int) $tx['id'], 'webhook_reconciled', (string) $tx['status'], $mapped, $event['context'] ?? []);
            do_action('dcp_webhook_reconciled', (int) $tx['id'], $mapped);
        }

        return $ok;
    }

    private function idempotency_key(int $withdraw_id, string $method_id): string
    {
        return hash('sha256', implode(':', ['dcp', $withdraw_id, $method_id]));
    }
}
