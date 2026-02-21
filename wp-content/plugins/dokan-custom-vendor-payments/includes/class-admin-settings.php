<?php

namespace DCP\Admin;

use DCP\Capabilities;
use DCP\Method_Registry;

final class Admin_Settings
{
    public function __construct(private Method_Registry $registry)
    {
    }

    public function register_hooks(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_settings(): void
    {
        register_setting('dcp_settings_group', 'dcp_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => [],
        ]);
    }

    public function sanitize(array $input): array
    {
        if (! Capabilities::can_manage_settings()) {
            return (array) get_option('dcp_settings', []);
        }

        $enabled = array_values(array_intersect(
            array_map('sanitize_text_field', (array) ($input['enabled_methods'] ?? [])),
            array_keys($this->registry->all())
        ));

        return [
            'mode' => in_array(($input['mode'] ?? 'test'), ['test', 'live'], true) ? $input['mode'] : 'test',
            'enabled_methods' => $enabled,
            'default_method' => sanitize_text_field((string) ($input['default_method'] ?? '')),
            'allowed_currencies' => array_values(array_map('sanitize_text_field', (array) ($input['allowed_currencies'] ?? []))),
            'retry' => [
                'max_attempts' => max(0, (int) (($input['retry']['max_attempts'] ?? 5))),
                'base_delay_sec' => max(0, (int) (($input['retry']['base_delay_sec'] ?? 300))),
                'max_delay_sec' => max(0, (int) (($input['retry']['max_delay_sec'] ?? 86400))),
            ],
            'method_settings' => (array) ($input['method_settings'] ?? []),
        ];
    }
}
