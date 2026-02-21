<?php

namespace DCP;

final class Withdraw_Bridge
{
    public function __construct(private Payout_Orchestrator $orchestrator)
    {
    }

    public function register_hooks(): void
    {
        $map = [
            'dokan_withdraw_request_approved' => 'on_withdraw_approved',
            'dokan_withdraw_request_cancelled' => 'on_withdraw_cancelled',
            'dokan_withdraw_request_created' => 'on_withdraw_created',
        ];

        foreach ($map as $hook => $handler) {
            add_action($hook, [$this, $handler], 10, 1);
        }
    }

    public function on_withdraw_created($withdraw): void
    {
        if (is_array($withdraw) && isset($withdraw['id'])) {
            do_action('dcp_withdraw_seen', (int) $withdraw['id']);
        }
    }

    public function on_withdraw_approved($withdraw): void
    {
        $data = $this->normalize_withdraw($withdraw);
        if (! $data) {
            return;
        }

        $lock_key = 'dcp_lock_withdraw_' . $data['id'];
        if (get_transient($lock_key)) {
            return;
        }

        set_transient($lock_key, 1, MINUTE_IN_SECONDS);
        try {
            $this->orchestrator->process_withdraw($data);
        } finally {
            delete_transient($lock_key);
        }
    }

    public function on_withdraw_cancelled($withdraw): void
    {
        $data = $this->normalize_withdraw($withdraw);
        if (! $data) {
            return;
        }

        $tx = (new Transaction_Repository())->find_by_withdraw((int) $data['id'], (string) ($data['method_id'] ?? ''));
        if ($tx) {
            (new Transaction_Repository())->transition((int) $tx['id'], (string) $tx['status'], 'cancelled', 'withdraw_cancelled');
        }
    }

    private function normalize_withdraw($withdraw): ?array
    {
        if (is_array($withdraw)) {
            return [
                'id' => (int) ($withdraw['id'] ?? 0),
                'user_id' => (int) ($withdraw['user_id'] ?? 0),
                'amount' => (float) ($withdraw['amount'] ?? 0),
                'currency' => (string) ($withdraw['currency'] ?? ''),
                'method_id' => (string) ($withdraw['method_id'] ?? ''),
            ];
        }

        return null;
    }
}
