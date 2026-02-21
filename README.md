# Dokan Preferred Withdrawal Methods

This plugin extends Dokan so sellers can choose and configure extra preferred withdrawal channels.

## Maintainer

- **Developer:** Forge Tara
- **Plugin URL:** https://forgetara.com/

## What this version adds

- Adds **M-Pesa** as a Dokan withdrawal method with icon support.
- Vendors can save **M-Pesa account name + phone number**.
- Vendors can set a **Preferred Withdrawal Method** from currently active Dokan withdrawal methods.
- Keeps Dokan default behavior if no preferred method is selected.
- Admin can view/edit vendor withdrawal data from WordPress profile pages.
- Syncs vendor `dokan_withdraw_methods` meta so Dokan marks M-Pesa as connected when details are saved.

## Technical notes

- Registers method via `dokan_withdraw_methods` filter.
- Persists values into `dokan_profile_settings`.
- Syncs connected status with `dokan_withdraw_methods` user meta.
- Includes compatibility hooks for multiple Dokan admin save events.

## Installation

1. Place files in `wp-content/plugins/dokan-preferred-withdrawal-methods/`.
2. Activate plugin.
3. Enable M-Pesa from **Dokan → Settings → Withdraw Options**.
4. Ask vendor to save M-Pesa details from their dashboard payment settings.

## Extend methods

```php
add_filter( 'finance_dokan_preferred_withdraw_methods', function( $methods ) {
    $methods['mobile_money'] = [
        'label'       => __( 'Mobile Money', 'my-text-domain' ),
        'placeholder' => __( 'Wallet Number', 'my-text-domain' ),
        'icon'        => 'https://example.com/mobile-money-icon.png',
    ];

    return $methods;
} );
```
