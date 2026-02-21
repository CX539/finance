<?php

namespace DCP;

final class Retry_Queue
{
    public const CRON_HOOK = 'dcp_retry_failed_payouts';

    public function __construct(
        private Payout_Orchestrator $orchestrator,
        private Transaction_Repository $transactions
    ) {
    }

    public function register_hooks(): void
    {
        add_action(self::CRON_HOOK, [$this, 'run']);

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    public function run(): void
    {
        $policy = apply_filters('dcp_retry_policy', [
            'max_attempts' => 5,
            'limit' => 20,
        ]);

        $failed = $this->transactions->eligible_failed_transactions((int) ($policy['limit'] ?? 20));

        foreach ($failed as $tx) {
            if ((int) ($tx['attempt_count'] ?? 0) >= (int) ($policy['max_attempts'] ?? 5)) {
                continue;
            }

            $withdraw = [
                'id' => (int) $tx['withdraw_id'],
                'user_id' => (int) $tx['vendor_id'],
                'amount' => ((int) $tx['amount_minor']) / 100,
                'currency' => (string) $tx['currency'],
                'method_id' => (string) $tx['method_id'],
            ];

            $this->orchestrator->process_withdraw($withdraw);
        }
    }
}
