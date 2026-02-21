<?php

namespace DCP\Interfaces;

interface Payout_Method_Interface
{
    public function get_id(): string;

    public function get_label(): string;

    public function get_capabilities(): array;

    public function validate_admin_settings(array $settings): array;

    public function validate_vendor_settings(int $vendor_id, array $settings): array;

    public function is_available_for_vendor(int $vendor_id, array $context = []): bool;

    public function supports_currency(string $currency): bool;

    public function create_payout(array $tx, array $withdraw, array $vendor_profile): array;

    public function handle_webhook(array $payload, array $headers): array;
}
