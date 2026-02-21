<?php
/**
 * Plugin Name: Dokan Custom Vendor Payments
 * Description: Extensible payout add-on for Dokan with provider abstraction, idempotency, and webhook reconciliation.
 * Version: 0.1.0
 * Author: Finance Team
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('DCP_PLUGIN_FILE')) {
    define('DCP_PLUGIN_FILE', __FILE__);
}
if (! defined('DCP_PLUGIN_DIR')) {
    define('DCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (! defined('DCP_PLUGIN_VERSION')) {
    define('DCP_PLUGIN_VERSION', '0.1.0');
}

require_once DCP_PLUGIN_DIR . 'includes/class-plugin.php';

add_action('plugins_loaded', static function () {
    \DCP\Plugin::instance()->boot();
});

register_activation_hook(__FILE__, static function () {
    \DCP\Plugin::instance()->activate();
});
