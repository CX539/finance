# Dokan Preferred Withdrawal Methods

This plugin extends Dokan so sellers can choose and configure extra preferred withdrawal channels.

## Maintainer

- **Developer:** Forge Tara
- **Plugin URL:** https://forgetara.com/

## What this version adds

- Adds **M-Pesa** as a Dokan withdrawal method with an icon (`assets/mpesa-logo.svg`).
- Vendors can save **M-Pesa phone number** in their payment settings.
- Vendors can set a **Preferred Withdrawal Method** from currently active Dokan withdrawal methods.
- If no preferred method is selected, Dokan default behavior remains in place.
- Admin can view/edit vendor **M-Pesa phone number** and **preferred method** in WordPress admin profile for vendor users.

## Expected behavior in Dokan

- In **Dokan → Settings → Withdraw Options**, M-Pesa appears as a method and can be toggled on/off.
- When enabled, vendors can configure M-Pesa in frontend payment settings.
- During manual withdrawal review, admin can inspect the selected method in Dokan request data and can also verify/update vendor M-Pesa details in admin profile.

## Installation

1. Put files under `wp-content/plugins/dokan-preferred-withdrawal-methods/`.
2. Activate plugin in WordPress.
3. Go to Dokan withdraw settings and enable M-Pesa.

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
