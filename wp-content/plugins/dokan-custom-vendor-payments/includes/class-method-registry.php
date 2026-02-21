<?php

namespace DCP;

use DCP\Interfaces\Payout_Method_Interface;
use RuntimeException;

final class Method_Registry
{
    /** @var array<string,Payout_Method_Interface> */
    private array $instances = [];

    /** @return array<string,Payout_Method_Interface> */
    public function all(): array
    {
        if ($this->instances !== []) {
            return $this->instances;
        }

        $classes = apply_filters('dcp_register_payout_methods', []);
        foreach ($classes as $id => $class) {
            if (! class_exists($class)) {
                continue;
            }
            $instance = new $class();
            if (! $instance instanceof Payout_Method_Interface) {
                continue;
            }
            $this->instances[$id] = $instance;
        }

        return $this->instances;
    }

    public function get(string $method_id): Payout_Method_Interface
    {
        $methods = $this->all();
        if (! isset($methods[$method_id])) {
            throw new RuntimeException("Unknown payout method: {$method_id}");
        }

        return $methods[$method_id];
    }

    public function enabled_method_ids(): array
    {
        $settings = get_option('dcp_settings', []);
        $enabled  = $settings['enabled_methods'] ?? [];

        if (! is_array($enabled)) {
            return [];
        }

        return array_values(array_filter($enabled, static fn ($id) => is_string($id) && $id !== ''));
    }
}
