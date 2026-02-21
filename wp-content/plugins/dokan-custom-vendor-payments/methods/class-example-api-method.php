<?php

namespace DCP\Methods;

use DCP\Interfaces\Payout_Method_Interface;

final class Example_API_Method implements Payout_Method_Interface
{
    public function get_id(): string
    {
        return 'example_api';
    }

    public function get_label(): string
    {
        return 'Example API Payout';
    }

    public function get_capabilities(): array
    {
        return ['async_webhook' => true, 'supports_retry' => true];
    }

    public function validate_admin_settings(array $settings): array
    {
        $errors = [];
        if (empty($settings['api_key'])) {
            $errors[] = 'API key is required.';
        }

        return $errors;
    }

    public function validate_vendor_settings(int $vendor_id, array $settings): array
    {
        $errors = [];
        if (empty($settings['beneficiary_ref'])) {
            $errors[] = 'Beneficiary account reference is required.';
        }
        if (empty($settings['kyc_status']) || $settings['kyc_status'] !== 'verified') {
            $errors[] = 'Vendor KYC must be verified.';
        }

        return $errors;
    }

    public function is_available_for_vendor(int $vendor_id, array $context = []): bool
    {
        $settings = get_user_meta($vendor_id, 'dcp_vendor_method_' . $this->get_id(), true);

        return ! empty($settings['enabled']) && (($settings['kyc_status'] ?? '') === 'verified');
    }

    public function supports_currency(string $currency): bool
    {
        $allowed = (array) ((get_option('dcp_settings', [])['allowed_currencies'] ?? []));
        if ($allowed === []) {
            return true;
        }

        return in_array(strtoupper($currency), array_map('strtoupper', $allowed), true);
    }

    public function create_payout(array $tx, array $withdraw, array $vendor_profile): array
    {
        return [
            'result' => 'pending',
            'provider_txn_id' => 'ex_' . uniqid('', true),
            'failure_code' => null,
            'failure_message' => null,
            'raw' => ['simulated' => true],
        ];
    }

    public function handle_webhook(array $payload, array $headers): array
    {
        $status_map = [
            'paid' => 'paid',
            'failed' => 'failed',
            'reversed' => 'reversed',
            'pending' => 'pending_external',
        ];

        $incoming = (string) ($payload['status'] ?? 'pending');

        return [
            'provider_event_id' => (string) ($payload['event_id'] ?? ''),
            'provider_txn_id' => (string) ($payload['payout_id'] ?? ''),
            'mapped_status' => $status_map[$incoming] ?? 'pending_external',
            'reason' => 'provider_webhook',
            'context' => [
                'status' => $incoming,
            ],
        ];
    }
}
