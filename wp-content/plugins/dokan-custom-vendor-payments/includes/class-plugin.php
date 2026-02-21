<?php

namespace DCP;

use DCP\Methods\Example_API_Method;
use DCP\Methods\Manual_Bank_Method;

final class Plugin
{
    private static ?self $instance = null;

    private Method_Registry $registry;

    private Transaction_Repository $transactions;

    private Audit_Log $audit_log;

    private Payout_Orchestrator $orchestrator;

    private bool $booted = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function activate(): void
    {
        $this->load_files();
        Migrations\Create_Payout_Tables::run();
    }

    public function boot(): void
    {
        if ($this->booted || ! $this->is_compatible()) {
            return;
        }

        $this->load_files();
        $this->register_default_methods_filter();

        $this->registry      = new Method_Registry();
        $this->transactions  = new Transaction_Repository();
        $this->audit_log     = new Audit_Log();
        $this->orchestrator  = new Payout_Orchestrator($this->registry, $this->transactions, $this->audit_log);

        (new Withdraw_Bridge($this->orchestrator))->register_hooks();
        (new Webhook_Controller($this->registry, $this->transactions, $this->audit_log))->register_routes();
        (new Retry_Queue($this->orchestrator, $this->transactions))->register_hooks();
        (new Admin\Admin_Settings($this->registry))->register_hooks();
        (new Vendor\Vendor_Settings($this->registry))->register_hooks();

        $this->booted = true;
    }

    private function is_compatible(): bool
    {
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            add_action('admin_notices', static function () {
                echo '<div class="notice notice-error"><p>Dokan Custom Vendor Payments requires PHP 8.0+.</p></div>';
            });

            return false;
        }

        return true;
    }

    private function register_default_methods_filter(): void
    {
        add_filter('dcp_register_payout_methods', static function (array $methods): array {
            $methods['manual_bank'] = Manual_Bank_Method::class;
            $methods['example_api'] = Example_API_Method::class;

            return $methods;
        });
    }

    private function load_files(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        require_once DCP_PLUGIN_DIR . 'includes/interfaces/interface-payout-method.php';
        require_once DCP_PLUGIN_DIR . 'includes/class-method-registry.php';
        require_once DCP_PLUGIN_DIR . 'includes/class-capabilities.php';
        require_once DCP_PLUGIN_DIR . 'includes/class-transaction-repository.php';
        require_once DCP_PLUGIN_DIR . 'includes/class-audit-log.php';
        require_once DCP_PLUGIN_DIR . 'includes/class-payout-orchestrator.php';
        require_once DCP_PLUGIN_DIR . 'includes/class-withdraw-bridge.php';
        require_once DCP_PLUGIN_DIR . 'includes/class-webhook-controller.php';
        require_once DCP_PLUGIN_DIR . 'includes/class-retry-queue.php';
        require_once DCP_PLUGIN_DIR . 'includes/class-admin-settings.php';
        require_once DCP_PLUGIN_DIR . 'includes/class-vendor-settings.php';
        require_once DCP_PLUGIN_DIR . 'methods/class-manual-bank-method.php';
        require_once DCP_PLUGIN_DIR . 'methods/class-example-api-method.php';
        require_once DCP_PLUGIN_DIR . 'migrations/001_create_payout_tables.php';

        $loaded = true;
    }
}
