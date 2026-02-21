<?php
/**
 * Plugin Name: Dokan Preferred Withdrawal Methods
 * Plugin URI: https://forgetara.com/
 * Description: Adds extra seller withdrawal methods in Dokan and lets vendors save their preferred payout account details.
 * Version: 1.2.0
 * Author: Forge Tara
 * Author URI: https://forgetara.com/
 * Text Domain: finance
 * Requires at least: 6.0
 * Requires PHP: 7.4
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

        add_action( 'show_user_profile', [ $this, 'render_admin_vendor_fields' ] );
        add_action( 'edit_user_profile', [ $this, 'render_admin_vendor_fields' ] );
        add_action( 'personal_options_update', [ $this, 'save_admin_vendor_fields' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_admin_vendor_fields' ] );
    }

    /**
     * Register additional withdrawal methods.
     *
     * @param array $methods Existing Dokan methods.
     * @return array
     */
    public function register_withdraw_methods( $methods ) {
        foreach ( $this->get_custom_methods() as $method_key => $method ) {
            $methods[ $method_key ] = [
                'title'    => $method['label'],
                'icon'     => $method['icon'] ?? '',
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

            $account_name      = $saved_payment[ $method_key ]['account_name'] ?? '';
            $account_number    = $saved_payment[ $method_key ]['account_number'] ?? '';
            $preferred_method  = $saved_payment['preferred_withdraw_method'] ?? '';
            $available_methods = $this->get_enabled_withdraw_methods();
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

                        <?php if ( 'mpesa' === $method_key ) : ?>
                            <label style="display:block;margin:10px 0 4px;" for="finance-preferred-withdraw-method">
                                <?php esc_html_e( 'Preferred Withdrawal Method', 'finance' ); ?>
                            </label>
                            <select
                                id="finance-preferred-withdraw-method"
                                name="settings[payment][preferred_withdraw_method]"
                                class="dokan-form-control"
                            >
                                <option value=""><?php esc_html_e( 'Use Dokan default behavior', 'finance' ); ?></option>
                                <?php foreach ( $available_methods as $available_method_key => $available_method_label ) : ?>
                                    <option value="<?php echo esc_attr( $available_method_key ); ?>" <?php selected( $preferred_method, $available_method_key ); ?>>
                                        <?php echo esc_html( $available_method_label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
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

        $posted_payment = wp_unslash( $_POST['settings']['payment'] );
        $payment        = $dokan_settings['payment'] ?? [];

        foreach ( $this->get_custom_methods() as $method_key => $method ) {
            $posted_method = $posted_payment[ $method_key ] ?? [];

            $payment[ $method_key ] = [
                'account_name'   => sanitize_text_field( $posted_method['account_name'] ?? '' ),
                'account_number' => sanitize_text_field( $posted_method['account_number'] ?? '' ),
            ];
        }

        $enabled_methods = array_keys( $this->get_enabled_withdraw_methods() );
        $preferred       = sanitize_key( $posted_payment['preferred_withdraw_method'] ?? '' );

        $payment['preferred_withdraw_method'] = in_array( $preferred, $enabled_methods, true ) ? $preferred : '';

        $dokan_settings['payment'] = $payment;
        update_user_meta( $store_id, 'dokan_profile_settings', $dokan_settings );
    }

    /**
     * Display MPesa + preferred method fields for admins on user profile.
     *
     * @param WP_User $user User object.
     */
    public function render_admin_vendor_fields( $user ) {
        if ( ! $this->is_vendor( $user ) ) {
            return;
        }

        $profile_settings = dokan_get_store_info( $user->ID );
        $payment_settings = $profile_settings['payment'] ?? [];
        $mpesa            = $payment_settings['mpesa'] ?? [];
        $preferred        = $payment_settings['preferred_withdraw_method'] ?? '';
        $enabled_methods  = $this->get_enabled_withdraw_methods();
        ?>
        <h2><?php esc_html_e( 'Dokan Withdrawal Preferences', 'finance' ); ?></h2>
        <?php wp_nonce_field( 'finance_save_vendor_withdraw_fields', 'finance_vendor_withdraw_nonce' ); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="finance_admin_mpesa_phone"><?php esc_html_e( 'M-Pesa Phone Number', 'finance' ); ?></label></th>
                <td>
                    <input type="text" name="finance_admin_mpesa_phone" id="finance_admin_mpesa_phone" value="<?php echo esc_attr( $mpesa['account_number'] ?? '' ); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="finance_admin_preferred_withdraw_method"><?php esc_html_e( 'Preferred Withdrawal Method', 'finance' ); ?></label></th>
                <td>
                    <select name="finance_admin_preferred_withdraw_method" id="finance_admin_preferred_withdraw_method">
                        <option value=""><?php esc_html_e( 'Use Dokan default behavior', 'finance' ); ?></option>
                        <?php foreach ( $enabled_methods as $method_key => $method_label ) : ?>
                            <option value="<?php echo esc_attr( $method_key ); ?>" <?php selected( $preferred, $method_key ); ?>>
                                <?php echo esc_html( $method_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save admin-updated vendor withdrawal fields.
     *
     * @param int $user_id User ID.
     */
    public function save_admin_vendor_fields( $user_id ) {
        $user = get_user_by( 'id', $user_id );

        if ( ! $user || ! $this->is_vendor( $user ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        $nonce = $_POST['finance_vendor_withdraw_nonce'] ?? '';

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'finance_save_vendor_withdraw_fields' ) ) {
            return;
        }

        $profile_settings = dokan_get_store_info( $user_id );
        $payment_settings = $profile_settings['payment'] ?? [];

        $payment_settings['mpesa']['account_name']   = sanitize_text_field( $payment_settings['mpesa']['account_name'] ?? '' );
        $payment_settings['mpesa']['account_number'] = sanitize_text_field( wp_unslash( $_POST['finance_admin_mpesa_phone'] ?? '' ) );

        $enabled_methods = array_keys( $this->get_enabled_withdraw_methods() );
        $preferred       = sanitize_key( wp_unslash( $_POST['finance_admin_preferred_withdraw_method'] ?? '' ) );

        $payment_settings['preferred_withdraw_method'] = in_array( $preferred, $enabled_methods, true ) ? $preferred : '';

        $profile_settings['payment'] = $payment_settings;
        update_user_meta( $user_id, 'dokan_profile_settings', $profile_settings );
    }

    /**
     * Get currently enabled withdraw methods for selector fields.
     *
     * @return array<string,string>
     */
    private function get_enabled_withdraw_methods() {
        $enabled = dokan_get_option( 'withdraw_methods', 'dokan_withdraw', [] );
        $all     = apply_filters( 'dokan_withdraw_methods', [] );

        $methods = [];

        foreach ( $all as $method_key => $method_config ) {
            if ( isset( $enabled[ $method_key ] ) && 'on' === $enabled[ $method_key ] ) {
                $methods[ $method_key ] = $method_config['title'] ?? ucfirst( $method_key );
            }
        }

        return $methods;
    }

    /**
     * Determine if user is a Dokan vendor.
     *
     * @param WP_User $user User object.
     * @return bool
     */
    private function is_vendor( $user ) {
        if ( function_exists( 'dokan_is_user_seller' ) ) {
            return dokan_is_user_seller( $user->ID );
        }

        return in_array( 'seller', (array) $user->roles, true );
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
                'placeholder' => __( 'M-Pesa Phone Number', 'finance' ),
                'icon'        => plugin_dir_url( __FILE__ ) . 'assets/mpesa-logo.svg',
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
