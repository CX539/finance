<?php
/**
 * Plugin Name: Dokan Preferred Withdrawal Methods
 * Plugin URI: https://forgetara.com/
 * Description: Adds extra seller withdrawal methods in Dokan and lets vendors save their preferred payout account details.
 * Version: 1.4.0
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
     * Cached list of all registered withdraw methods.
     *
     * @var array|null
     */
    private $all_methods_cache = null;

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

        // Vendor dashboard.
        add_filter( 'dokan_withdraw_methods', [ $this, 'register_withdraw_methods' ] );
        add_filter( 'dokan_get_seller_active_withdraw_methods', [ $this, 'ensure_custom_methods_in_active_list' ], 20, 2 );
        add_action( 'dokan_store_profile_saved', [ $this, 'save_vendor_payment_details' ], 20, 2 );

        // WordPress user profile admin page.
        add_action( 'show_user_profile', [ $this, 'render_admin_vendor_fields' ] );
        add_action( 'edit_user_profile', [ $this, 'render_admin_vendor_fields' ] );
        add_action( 'personal_options_update', [ $this, 'save_admin_vendor_fields' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_admin_vendor_fields' ] );

        // Dokan admin vendor edit panel hooks (varies by Dokan version).
        add_action( 'dokan_admin_user_profile_saved', [ $this, 'save_admin_vendor_fields' ] );
        add_action( 'dokan_after_saving_vendor', [ $this, 'save_admin_vendor_fields' ] );
    }

    /**
     * Register additional withdrawal methods with Dokan.
     *
     * @param array $methods Existing Dokan methods.
     * @return array
     */
    public function register_withdraw_methods( $methods ) {
        if ( null === $this->all_methods_cache ) {
            $this->all_methods_cache = $methods;
        }

        foreach ( $this->get_custom_methods() as $method_key => $method ) {
            $entry = [
                'title'    => $method['label'],
                'icon'     => $method['icon'] ?? '',
                'callback' => $this->build_render_callback( $method_key, $method ),
            ];

            $methods[ $method_key ]                 = $entry;
            $this->all_methods_cache[ $method_key ] = $entry;
        }

        return $methods;
    }

    /**
     * Build a render callback for a single withdrawal method.
     *
     * @param string $method_key Method identifier.
     * @param array  $method     Method configuration.
     * @return callable
     */
    private function build_render_callback( $method_key, $method ) {
        $custom_method_keys = array_keys( $this->get_custom_methods() );
        $last_custom_key    = end( $custom_method_keys );

        return function( $payment_settings = [], $store_id = 0 ) use ( $method_key, $method, $last_custom_key ) {
            // Some Dokan versions pass vendor object/array in first arg.
            if ( is_array( $payment_settings ) && isset( $payment_settings['ID'] ) ) {
                $store_id = $payment_settings['ID'];
            }

            $store_id         = (int) $store_id;
            $profile_settings = dokan_get_store_info( $store_id );
            $saved_payment    = $profile_settings['payment'] ?? [];

            $account_name   = sanitize_text_field( $saved_payment[ $method_key ]['account_name'] ?? '' );
            $account_number = sanitize_text_field( $saved_payment[ $method_key ]['account_number'] ?? '' );
            $preferred      = sanitize_key( $saved_payment['preferred_withdraw_method'] ?? '' );
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

            <?php if ( $method_key === $last_custom_key ) : ?>
                <?php $available_methods = $this->get_enabled_withdraw_methods(); ?>
                <div class="dokan-form-group dokan-clearfix finance-preferred-withdraw-wrapper">
                    <div class="dokan-w8">
                        <div class="dokan-w4 dokan-control-label">
                            <label for="finance-preferred-withdraw-method">
                                <?php esc_html_e( 'Preferred Withdrawal Method', 'finance' ); ?>
                            </label>
                        </div>
                        <div class="dokan-w6">
                            <select
                                id="finance-preferred-withdraw-method"
                                name="settings[payment][preferred_withdraw_method]"
                                class="dokan-form-control"
                            >
                                <option value=""><?php esc_html_e( 'Use Dokan default behavior', 'finance' ); ?></option>
                                <?php foreach ( $available_methods as $avail_key => $avail_label ) : ?>
                                    <option value="<?php echo esc_attr( $avail_key ); ?>" <?php selected( $preferred, $avail_key ); ?>>
                                        <?php echo esc_html( $avail_label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php
        };
    }

    /**
     * Save custom payment method data when a vendor saves dashboard profile.
     *
     * @param int   $store_id       Vendor user ID.
     * @param array $dokan_settings Current stored settings passed by Dokan.
     */
    public function save_vendor_payment_details( $store_id, $dokan_settings ) {
        $store_id = (int) $store_id;

        if ( $store_id <= 0 ) {
            return;
        }

        $posted_payment = isset( $_POST['settings']['payment'] ) && is_array( $_POST['settings']['payment'] )
            ? wp_unslash( $_POST['settings']['payment'] )
            : [];

        if ( empty( $posted_payment ) ) {
            return;
        }

        $payment = $dokan_settings['payment'] ?? [];

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

        $this->sync_dokan_active_methods( $store_id, $payment );
    }

    /**
     * Render withdrawal fields on WP user-profile admin page.
     *
     * @param WP_User|int $user WP_User object, or user ID from Dokan hooks.
     */
    public function render_admin_vendor_fields( $user ) {
        if ( is_numeric( $user ) ) {
            $user = get_user_by( 'id', (int) $user );
        }

        if ( ! $user instanceof WP_User || ! $this->is_vendor( $user ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_user', $user->ID ) ) {
            return;
        }

        $profile_settings = dokan_get_store_info( $user->ID );
        $payment_settings = $profile_settings['payment'] ?? [];
        $preferred        = sanitize_key( $payment_settings['preferred_withdraw_method'] ?? '' );
        $enabled_methods  = $this->get_enabled_withdraw_methods();
        ?>
        <h2><?php esc_html_e( 'Dokan Withdrawal Preferences', 'finance' ); ?></h2>
        <?php wp_nonce_field( 'finance_save_vendor_withdraw_fields', 'finance_vendor_withdraw_nonce' ); ?>
        <table class="form-table" role="presentation">
            <?php foreach ( $this->get_custom_methods() as $method_key => $method ) : ?>
                <?php
                $saved_name   = sanitize_text_field( $payment_settings[ $method_key ]['account_name'] ?? '' );
                $saved_number = sanitize_text_field( $payment_settings[ $method_key ]['account_number'] ?? '' );
                $field_id     = 'finance_admin_' . $method_key;
                ?>
                <tr>
                    <th>
                        <label for="<?php echo esc_attr( $field_id . '_account_name' ); ?>">
                            <?php echo esc_html( $method['label'] ); ?>
                        </label>
                    </th>
                    <td>
                        <input
                            type="text"
                            name="finance_admin[<?php echo esc_attr( $method_key ); ?>][account_name]"
                            id="<?php echo esc_attr( $field_id . '_account_name' ); ?>"
                            value="<?php echo esc_attr( $saved_name ); ?>"
                            placeholder="<?php esc_attr_e( 'Account Name', 'finance' ); ?>"
                            class="regular-text"
                            style="margin-bottom: 6px;"
                        />
                        <br>
                        <input
                            type="text"
                            name="finance_admin[<?php echo esc_attr( $method_key ); ?>][account_number]"
                            id="<?php echo esc_attr( $field_id . '_account_number' ); ?>"
                            value="<?php echo esc_attr( $saved_number ); ?>"
                            placeholder="<?php echo esc_attr( $method['placeholder'] ); ?>"
                            class="regular-text"
                        />
                    </td>
                </tr>
            <?php endforeach; ?>

            <tr>
                <th>
                    <label for="finance_admin_preferred_withdraw_method">
                        <?php esc_html_e( 'Preferred Withdrawal Method', 'finance' ); ?>
                    </label>
                </th>
                <td>
                    <select name="finance_admin_preferred_withdraw_method" id="finance_admin_preferred_withdraw_method" class="regular-text">
                        <option value=""><?php esc_html_e( 'Use Dokan default behavior', 'finance' ); ?></option>
                        <?php foreach ( $enabled_methods as $method_key => $method_label ) : ?>
                            <option value="<?php echo esc_attr( $method_key ); ?>" <?php selected( $preferred, $method_key ); ?>>
                                <?php echo esc_html( $method_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'This will be used as the default payout method when processing withdrawals for this vendor.', 'finance' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save admin-side vendor withdrawal fields.
     *
     * @param int $user_id User ID.
     */
    public function save_admin_vendor_fields( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 ) {
            return;
        }

        $user = get_user_by( 'id', $user_id );

        if ( ! $user || ! $this->is_vendor( $user ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        // Present on WP profile page, but not always in Dokan admin panel saves.
        $nonce = sanitize_text_field( wp_unslash( $_POST['finance_vendor_withdraw_nonce'] ?? '' ) );

        if ( $nonce && ! wp_verify_nonce( $nonce, 'finance_save_vendor_withdraw_fields' ) ) {
            return;
        }

        $has_our_data = isset( $_POST['finance_admin'] ) || isset( $_POST['finance_admin_preferred_withdraw_method'] );

        if ( ! $nonce && ! $has_our_data ) {
            return;
        }

        $profile_settings = dokan_get_store_info( $user_id );
        $payment_settings = $profile_settings['payment'] ?? [];

        $posted_methods = isset( $_POST['finance_admin'] ) && is_array( $_POST['finance_admin'] )
            ? wp_unslash( $_POST['finance_admin'] )
            : [];

        foreach ( $this->get_custom_methods() as $method_key => $method ) {
            $posted = $posted_methods[ $method_key ] ?? [];

            $payment_settings[ $method_key ] = [
                'account_name'   => sanitize_text_field( $posted['account_name'] ?? ( $payment_settings[ $method_key ]['account_name'] ?? '' ) ),
                'account_number' => sanitize_text_field( $posted['account_number'] ?? ( $payment_settings[ $method_key ]['account_number'] ?? '' ) ),
            ];
        }

        $enabled_methods = array_keys( $this->get_enabled_withdraw_methods() );
        $preferred       = sanitize_key( wp_unslash( $_POST['finance_admin_preferred_withdraw_method'] ?? '' ) );

        $payment_settings['preferred_withdraw_method'] = in_array( $preferred, $enabled_methods, true ) ? $preferred : '';

        $profile_settings['payment'] = $payment_settings;
        update_user_meta( $user_id, 'dokan_profile_settings', $profile_settings );

        $this->sync_dokan_active_methods( $user_id, $payment_settings );
    }

    /**
     * Sync method connected status with Dokan's vendor methods user meta.
     *
     * @param int   $user_id          Vendor user ID.
     * @param array $payment_settings Payment settings array.
     */
    private function sync_dokan_active_methods( $user_id, $payment_settings ) {
        $active = get_user_meta( $user_id, 'dokan_withdraw_methods', true );

        if ( ! is_array( $active ) ) {
            $active = [];
        }

        $active = $this->normalize_method_list( $active );

        foreach ( $this->get_custom_methods() as $method_key => $method ) {
            $account_number = sanitize_text_field( $payment_settings[ $method_key ]['account_number'] ?? '' );

            if ( '' !== $account_number ) {
                if ( ! in_array( $method_key, $active, true ) ) {
                    $active[] = $method_key;
                }
            } else {
                $active = array_values(
                    array_filter(
                        $active,
                        static function( $key ) use ( $method_key ) {
                            return $key !== $method_key;
                        }
                    )
                );
            }
        }

        update_user_meta( $user_id, 'dokan_withdraw_methods', $active );
        // Extra compatibility for some Dokan builds/integrations using underscored key.
        update_user_meta( $user_id, '_dokan_withdraw_methods', $active );
    }

    /**
     * Ensure saved custom methods appear in Dokan active method list.
     *
     * @param array $methods Existing active methods.
     * @param int   $user_id Vendor user ID.
     * @return array
     */
    public function ensure_custom_methods_in_active_list( $methods, $user_id ) {
        $methods = $this->normalize_method_list( $methods );

        $profile_settings = dokan_get_store_info( (int) $user_id );
        $payment_settings = $profile_settings['payment'] ?? [];

        foreach ( $this->get_custom_methods() as $method_key => $method ) {
            $account_number = sanitize_text_field( $payment_settings[ $method_key ]['account_number'] ?? '' );

            if ( '' !== $account_number && ! in_array( $method_key, $methods, true ) ) {
                $methods[] = $method_key;
            }
        }

        return array_values( array_unique( $methods ) );
    }

    /**
     * Return enabled Dokan withdraw methods (key => label).
     *
     * @return array<string,string>
     */
    private function get_enabled_withdraw_methods() {
        $enabled = dokan_get_option( 'withdraw_methods', 'dokan_withdraw', [] );

        $all = null !== $this->all_methods_cache
            ? $this->all_methods_cache
            : apply_filters( 'dokan_withdraw_methods', [] );

        $methods = [];

        foreach ( $all as $method_key => $method_config ) {
            if ( $this->is_method_enabled( $enabled, $method_key ) ) {
                $methods[ $method_key ] = $method_config['title'] ?? ucfirst( $method_key );
            }
        }

        return $methods;
    }


    /**
     * Normalize method list from either indexed or associative format.
     *
     * @param mixed $methods Method list from Dokan/user meta.
     * @return array<int,string>
     */
    private function normalize_method_list( $methods ) {
        if ( ! is_array( $methods ) ) {
            return [];
        }

        $is_assoc = array_keys( $methods ) !== range( 0, count( $methods ) - 1 );
        $list     = $is_assoc ? array_keys( $methods ) : $methods;

        $list = array_filter(
            array_map( 'sanitize_key', $list ),
            static function( $value ) {
                return '' !== $value;
            }
        );

        return array_values( array_unique( $list ) );
    }

    /**
     * Determine if method is enabled in Dokan settings.
     *
     * @param array  $enabled    Dokan enabled method option.
     * @param string $method_key Method key.
     * @return bool
     */
    private function is_method_enabled( $enabled, $method_key ) {
        if ( ! isset( $enabled[ $method_key ] ) ) {
            return false;
        }

        $value = $enabled[ $method_key ];

        return 'on' === $value || 'yes' === $value || 1 === $value || '1' === $value || true === $value;
    }

    /**
     * Determine if a user is a Dokan vendor.
     *
     * @param WP_User $user User object.
     * @return bool
     */
    private function is_vendor( WP_User $user ) {
        if ( function_exists( 'dokan_is_user_seller' ) ) {
            return dokan_is_user_seller( $user->ID );
        }

        return in_array( 'seller', (array) $user->roles, true );
    }

    /**
     * Define custom withdrawal methods added by this plugin.
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

        return apply_filters( 'finance_dokan_preferred_withdraw_methods', $methods );
    }
}

new Finance_Dokan_Preferred_Withdrawal_Methods();
