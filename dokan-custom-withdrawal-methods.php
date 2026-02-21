<?php
/**
 * Plugin Name: Dokan Preferred Withdrawal Methods
 * Description: Adds extra seller withdrawal methods in Dokan and lets vendors save their preferred payout account details.
 * Version: 1.1.0
 * Author: Finance Dev Team
 * Requires Plugins: dokan-lite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Finance_Dokan_Preferred_Withdrawal_Methods {
    /**
     * Boot plugin.
     */
    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }

    /**
     * Register hooks after plugins are loaded.
     */
    public function init() {
        if ( ! function_exists( 'dokan' ) ) {
            return;
        }

        add_filter( 'dokan_withdraw_methods', [ $this, 'register_withdraw_methods' ] );
        add_action( 'dokan_store_profile_saved', [ $this, 'save_vendor_payment_details' ], 20, 2 );
    }

    /**
     * Register additional withdrawal methods.
     *
     * Dokan uses this list both in admin Withdraw Settings (toggle list)
     * and on the vendor payment settings UI.
     *
     * @param array $methods Existing Dokan methods.
     * @return array
     */
    public function register_withdraw_methods( $methods ) {
        foreach ( $this->get_custom_methods() as $method_key => $method ) {
            $methods[ $method_key ] = [
                'title'    => $method['label'],
                'callback' => $this->build_render_callback( $method_key, $method ),
            ];
        }

        return $methods;
    }

    /**
     * Create one renderer per withdrawal method.
     *
     * @param string $method_key Method key.
     * @param array  $method     Method configuration.
     * @return callable
     */
    private function build_render_callback( $method_key, $method ) {
        return function( $payment_settings = [], $store_id = 0 ) use ( $method_key, $method ) {
            $profile_settings = dokan_get_store_info( $store_id );
            $saved_payment    = $profile_settings['payment'] ?? [];

            $account_name   = $saved_payment[ $method_key ]['account_name'] ?? '';
            $account_number = $saved_payment[ $method_key ]['account_number'] ?? '';
            ?>
            <div class="dokan-form-group dokan-clearfix finance-custom-withdraw-method finance-custom-withdraw-method-<?php echo esc_attr( $method_key ); ?>">
                <div class="dokan-w8">
                    <div class="dokan-w4 dokan-control-label">
                        <label for="finance-<?php echo esc_attr( $method_key ); ?>-account-name">
                            <?php echo esc_html( $method['label'] ); ?>
                        </label>
                    </div>
                    <div class="dokan-w6">
                        <input
                            id="finance-<?php echo esc_attr( $method_key ); ?>-account-name"
                            name="settings[payment][<?php echo esc_attr( $method_key ); ?>][account_name]"
                            value="<?php echo esc_attr( $account_name ); ?>"
                            class="dokan-form-control"
                            placeholder="<?php esc_attr_e( 'Account Name', 'finance' ); ?>"
                            type="text"
                        />
                        <input
                            name="settings[payment][<?php echo esc_attr( $method_key ); ?>][account_number]"
                            value="<?php echo esc_attr( $account_number ); ?>"
                            class="dokan-form-control"
                            placeholder="<?php echo esc_attr( $method['placeholder'] ); ?>"
                            type="text"
                            style="margin-top: 8px;"
                        />
                    </div>
                </div>
            </div>
            <?php
        };
    }

    /**
     * Save account details for custom methods.
     *
     * @param int   $store_id       Vendor user ID.
     * @param array $dokan_settings Existing settings from Dokan.
     */
    public function save_vendor_payment_details( $store_id, $dokan_settings ) {
        if ( empty( $_POST['settings']['payment'] ) || ! is_array( $_POST['settings']['payment'] ) ) {
            return;
        }

        $payment = $dokan_settings['payment'] ?? [];

        foreach ( $this->get_custom_methods() as $method_key => $method ) {
            $posted_method = $_POST['settings']['payment'][ $method_key ] ?? [];

            $payment[ $method_key ] = [
                'account_name'   => sanitize_text_field( $posted_method['account_name'] ?? '' ),
                'account_number' => sanitize_text_field( $posted_method['account_number'] ?? '' ),
            ];
        }

        $dokan_settings['payment'] = $payment;
        update_user_meta( $store_id, 'dokan_profile_settings', $dokan_settings );
    }

    /**
     * Default custom methods.
     *
     * @return array
     */
    private function get_custom_methods() {
        $methods = [
            'mpesa' => [
                'label'       => __( 'M-Pesa', 'finance' ),
                'placeholder' => __( 'M-Pesa Number', 'finance' ),
            ],
        ];

        /**
         * Filter custom methods added by this plugin.
         *
         * @param array $methods
         */
        return apply_filters( 'finance_dokan_preferred_withdraw_methods', $methods );
    }
}

new Finance_Dokan_Preferred_Withdrawal_Methods();
