<?php

namespace DCP\Methods;

use DCP\Interfaces\Payout_Method_Interface;

final class Manual_Bank_Method implements Payout_Method_Interface
{
    public function get_id(): string
    {
        return 'manual_bank';
    }

    public function get_label(): string
    {
        return 'Manual Bank Transfer';
    }

    public function get_capabilities(): array
    {
        return ['async_webhook' => false, 'supports_retry' => false];
    }

    public function validate_admin_settings(array $settings): array
    {
        return [];
    }

    public function validate_vendor_settings(int $vendor_id, array $settings): array
    {
        $errors = [];
        if (empty($settings['beneficiary_ref'])) {
            $errors[] = 'Beneficiary reference is required.';
        }

        return $errors;
    }

    public function is_available_for_vendor(int $vendor_id, array $context = []): bool
    {
        return true;
    }

    public function supports_currency(string $currency): bool
    {
        return true;
    }

    public function create_payout(array $tx, array $withdraw, array $vendor_profile): array
    {
        return [
            'result' => 'pending',
            'provider_txn_id' => 'manual-' . (string) $tx['id'],
            'failure_code' => null,
            'failure_message' => null,
            'raw' => ['note' => 'Requires manual settlement'],
        ];
    }

    public function handle_webhook(array $payload, array $headers): array
    {
        return [];
    }
}
