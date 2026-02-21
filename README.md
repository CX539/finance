# Dokan Preferred Withdrawal Methods

This plugin extends Dokan so sellers can choose and configure extra preferred withdrawal channels.

## What happens after install?

- `M-Pesa` is added to Dokan's registered withdraw methods.
- In **Dokan → Settings → Withdraw Options** (the screen from your screenshot), Dokan should show **M-Pesa** as a new method with its own enable/disable toggle.
- If the method is enabled by admin, vendors can see it in their store payment settings on the frontend dashboard and save account details.

## What it does technically

- Registers custom methods through Dokan's `dokan_withdraw_methods` filter.
- Renders one dedicated field block per method in vendor payment settings.
- Saves account details in `dokan_profile_settings`.
- Supports extension via `finance_dokan_preferred_withdraw_methods`.

## Installation

1. Put `dokan-custom-withdrawal-methods.php` in `wp-content/plugins/dokan-preferred-withdrawal-methods/`.
2. Activate the plugin.
3. Go to Dokan withdraw settings and enable M-Pesa.

## Add more methods

```php
add_filter( 'finance_dokan_preferred_withdraw_methods', function( $methods ) {
    $methods['mobile_money'] = [
        'label'       => __( 'Mobile Money', 'my-text-domain' ),
        'placeholder' => __( 'Wallet Number', 'my-text-domain' ),
    ];

    return $methods;
} );
```
