<?php

namespace DCP;

final class Capabilities
{
    public const MANAGE_SETTINGS = 'manage_woocommerce';

    public static function can_manage_settings(): bool
    {
        return current_user_can(self::MANAGE_SETTINGS);
    }
}
