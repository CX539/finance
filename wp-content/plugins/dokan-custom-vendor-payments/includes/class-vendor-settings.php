<?php

namespace DCP\Vendor;

use DCP\Method_Registry;

final class Vendor_Settings
{
    public function __construct(private Method_Registry $registry)
    {
    }

    public function register_hooks(): void
    {
        add_action('init', [$this, 'register_handlers']);
    }

    public function register_handlers(): void
    {
        add_action('wp_ajax_dcp_save_vendor_method_settings', [$this, 'save_vendor_method_settings']);
    }

    public function save_vendor_method_settings(): void
    {
        check_ajax_referer('dcp_vendor_settings_nonce');

        $vendor_id = get_current_user_id();
        $method_id = sanitize_text_field((string) ($_POST['method_id'] ?? ''));
        $settings  = (array) ($_POST['settings'] ?? []);

        if (! isset($this->registry->all()[$method_id])) {
            wp_send_json_error(['message' => 'Unknown method']);
        }

        $method = $this->registry->get($method_id);
        $errors = $method->validate_vendor_settings($vendor_id, $settings);

        if ($errors !== []) {
            do_action('dcp_vendor_method_validation_errors', $vendor_id, $method_id, $errors);
            wp_send_json_error(['errors' => $errors]);
        }

        $clean = [
            'enabled' => ! empty($settings['enabled']),
            'beneficiary_ref' => sanitize_text_field((string) ($settings['beneficiary_ref'] ?? '')),
            'country' => sanitize_text_field((string) ($settings['country'] ?? '')),
            'currency' => sanitize_text_field((string) ($settings['currency'] ?? '')),
            'kyc_status' => sanitize_text_field((string) ($settings['kyc_status'] ?? 'pending')),
            'payout_schedule' => sanitize_text_field((string) ($settings['payout_schedule'] ?? 'manual')),
        ];

        update_user_meta($vendor_id, 'dcp_vendor_method_' . $method_id, $clean);
        wp_send_json_success(['saved' => true]);
    }
}
