<?php

namespace DCP\Migrations;

final class Create_Payout_Tables
{
    public static function run(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $tx_table = $wpdb->prefix . 'dcp_payout_tx';
        $webhook_table = $wpdb->prefix . 'dcp_webhook_event';
        $audit_table = $wpdb->prefix . 'dcp_audit_log';

        $sql_tx = "CREATE TABLE {$tx_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            withdraw_id BIGINT UNSIGNED NOT NULL,
            vendor_id BIGINT UNSIGNED NOT NULL,
            method_id VARCHAR(64) NOT NULL,
            amount_minor BIGINT NOT NULL,
            currency CHAR(3) NOT NULL,
            status VARCHAR(32) NOT NULL,
            provider_txn_id VARCHAR(191) NULL,
            provider_event_id VARCHAR(191) NULL,
            idempotency_key VARCHAR(128) NOT NULL,
            attempt_count INT NOT NULL DEFAULT 0,
            failure_code VARCHAR(64) NULL,
            failure_message TEXT NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_withdraw_method (withdraw_id, method_id),
            UNIQUE KEY uniq_idempotency (idempotency_key),
            KEY idx_vendor_status (vendor_id, status),
            KEY idx_provider_txn (provider_txn_id)
        ) {$charset};";

        $sql_webhook = "CREATE TABLE {$webhook_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            method_id VARCHAR(64) NOT NULL,
            provider_event_id VARCHAR(191) NOT NULL,
            provider_txn_id VARCHAR(191) NULL,
            payload_json LONGTEXT NOT NULL,
            signature_valid TINYINT(1) NOT NULL,
            processed TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_method_event (method_id, provider_event_id)
        ) {$charset};";

        $sql_audit = "CREATE TABLE {$audit_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tx_id BIGINT UNSIGNED NULL,
            actor_type VARCHAR(32) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            event_key VARCHAR(64) NOT NULL,
            old_status VARCHAR(32) NULL,
            new_status VARCHAR(32) NULL,
            context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_tx_created (tx_id, created_at)
        ) {$charset};";

        dbDelta($sql_tx);
        dbDelta($sql_webhook);
        dbDelta($sql_audit);
    }
}
