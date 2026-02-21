# Dokan Custom Vendor Payments Add-on — Design Schema

## 1) Goals and Non-Goals

### Goals
- Add a Dokan extension that allows **multiple custom vendor payout providers** behind a single abstraction.
- Keep payout logic idempotent and auditable.
- Support asynchronous provider callbacks (webhooks) safely.
- Allow third parties to register additional payout methods without changing core plugin code.

### Non-Goals
- Replacing Dokan's full withdraw system.
- Storing sensitive payment instruments directly (card/bank PAN). Keep only provider references/tokens.
- Hard-coding provider SDK logic in the orchestration layer.

---

## 2) Plugin Topology

Proposed plugin slug: `dokan-custom-vendor-payments`

```text
wp-content/plugins/dokan-custom-vendor-payments/
├── dokan-custom-vendor-payments.php
├── includes/
│   ├── class-plugin.php
│   ├── class-capabilities.php
│   ├── class-method-registry.php
│   ├── class-payout-orchestrator.php
│   ├── class-withdraw-bridge.php
│   ├── class-transaction-repository.php
│   ├── class-webhook-controller.php
│   ├── class-retry-queue.php
│   ├── class-audit-log.php
│   └── interfaces/
│       └── interface-payout-method.php
├── methods/
│   ├── class-manual-bank-method.php
│   └── class-example-api-method.php
├── admin/
│   ├── class-admin-settings.php
│   └── views/
├── vendor/
│   ├── class-vendor-settings.php
│   └── views/
├── migrations/
│   └── 001_create_payout_tables.php
└── docs/
    └── hooks.md
```

---

## 3) Domain Model and Data Schema

### 3.1 Tables

#### A) `{prefix}dcp_payout_tx`
Canonical payout transaction state for each approved withdraw execution.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | Internal tx id |
| withdraw_id | BIGINT NOT NULL | Dokan withdraw request id |
| vendor_id | BIGINT NOT NULL | WP user id |
| method_id | VARCHAR(64) NOT NULL | Registered method slug |
| amount_minor | BIGINT NOT NULL | Integer in minor units |
| currency | CHAR(3) NOT NULL | ISO-4217 |
| status | VARCHAR(32) NOT NULL | See state machine below |
| provider_txn_id | VARCHAR(191) NULL | External payout id |
| provider_event_id | VARCHAR(191) NULL | Latest webhook event id |
| idempotency_key | VARCHAR(128) NOT NULL | Unique token per payout attempt |
| attempt_count | INT NOT NULL DEFAULT 0 | Retry attempts |
| failure_code | VARCHAR(64) NULL | Provider/app code |
| failure_message | TEXT NULL | Human-readable details |
| meta_json | LONGTEXT NULL | JSON metadata (non-sensitive) |
| created_at | DATETIME NOT NULL | |
| updated_at | DATETIME NOT NULL | |

**Indexes / constraints**
- `UNIQUE (withdraw_id, method_id)`
- `UNIQUE (idempotency_key)`
- `INDEX (vendor_id, status)`
- `INDEX (provider_txn_id)`

#### B) `{prefix}dcp_webhook_event`
Inbound callback store for dedupe and replay handling.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| method_id | VARCHAR(64) NOT NULL | |
| provider_event_id | VARCHAR(191) NOT NULL | Unique per provider |
| provider_txn_id | VARCHAR(191) NULL | Correlation |
| payload_json | LONGTEXT NOT NULL | Original payload |
| signature_valid | TINYINT(1) NOT NULL | Result of signature check |
| processed | TINYINT(1) NOT NULL DEFAULT 0 | Reconciled flag |
| created_at | DATETIME NOT NULL | |

**Constraint**: `UNIQUE(method_id, provider_event_id)`

#### C) `{prefix}dcp_audit_log`
Immutable audit trail.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| tx_id | BIGINT NULL | Link to `dcp_payout_tx.id` |
| actor_type | VARCHAR(32) NOT NULL | `system`, `admin`, `vendor`, `webhook` |
| actor_id | BIGINT NULL | WP user id if available |
| event_key | VARCHAR(64) NOT NULL | `payout_created`, `status_changed`, etc. |
| old_status | VARCHAR(32) NULL | |
| new_status | VARCHAR(32) NULL | |
| context_json | LONGTEXT NULL | Redacted context |
| created_at | DATETIME NOT NULL | |

---

## 4) State Machine (Strict)

Allowed transaction statuses:
- `queued`
- `processing`
- `pending_external`
- `paid`
- `failed`
- `reversed`
- `cancelled`

Allowed transitions:
- `queued -> processing | cancelled`
- `processing -> pending_external | paid | failed`
- `pending_external -> paid | failed | reversed`
- `failed -> processing` (retry only when policy allows)
- `paid -> reversed` (provider reversal/chargeback style payout rollback where applicable)

All status transitions must pass through one method:
`TransactionRepository::transition($tx_id, $from, $to, $reason, $context)`

This method enforces:
1. Current status matches expected `$from`.
2. `$from -> $to` is in allowed map.
3. Writes audit log in same DB transaction.

---

## 5) Provider Contract (Interface)

`interface-payout-method.php`

```php
interface DCP_Payout_Method_Interface {
    public function get_id(): string;
    public function get_label(): string;
    public function get_capabilities(): array; // ['async_webhook' => true, ...]

    public function validate_admin_settings(array $settings): array; // error list
    public function validate_vendor_settings(int $vendor_id, array $settings): array; // error list

    public function is_available_for_vendor(int $vendor_id, array $context = []): bool;
    public function supports_currency(string $currency): bool;

    /**
     * Create payout for a withdraw/tx pair.
     * Return normalized shape:
     * [
     *   'result' => 'success|pending|failed',
     *   'provider_txn_id' => '...',
     *   'failure_code' => null,
     *   'failure_message' => null,
     *   'raw' => [] // redacted
     * ]
     */
    public function create_payout(array $tx, array $withdraw, array $vendor_profile): array;

    public function handle_webhook(array $payload, array $headers): array;
    // Returns normalized event:
    // [
    //   'provider_event_id' => 'evt_123',
    //   'provider_txn_id' => 'po_123',
    //   'mapped_status' => 'paid|failed|reversed|pending_external',
    //   'reason' => '...',
    //   'context' => []
    // ]
}
```

---

## 6) Hook Strategy

## 6.1 Dokan/WP integration points (bridge)
Bind in `class-withdraw-bridge.php` and forward to orchestrator.

- Withdraw created (request submitted)
- Withdraw approved (admin approval)
- Withdraw cancelled/rejected
- Withdraw marked paid (if Dokan-side manual events occur)

> Exact Dokan hook names vary by version; define a compatibility map in one class and gate by `defined(DOKAN_PLUGIN_VERSION)` checks.

## 6.2 Custom plugin hooks (stable public contract)

### Filters
- `dcp_register_payout_methods` → add/override method classes.
- `dcp_retry_policy` → decide retry backoff + max attempts.
- `dcp_tx_context` → enrich normalized tx context.

### Actions
- `dcp_before_payout_create` (`$tx`, `$withdraw`, `$method_id`)
- `dcp_after_payout_create` (`$tx`, `$provider_response`)
- `dcp_payout_status_changed` (`$tx_id`, `$old`, `$new`, `$reason`)
- `dcp_webhook_received` (`$method_id`, `$provider_event_id`)
- `dcp_webhook_reconciled` (`$tx_id`, `$mapped_status`)

All hooks should be documented in `docs/hooks.md` with argument signatures and examples.

---

## 7) End-to-End Flow

### Flow A: Withdraw approved → payout created
1. Dokan approval hook fires.
2. `WithdrawBridge` acquires lock (`withdraw:{id}`).
3. Registry resolves vendor-selected method.
4. Orchestrator creates tx row (`queued`) if absent.
5. Transition `queued -> processing`.
6. Method `create_payout()` called with idempotency key.
7. Map result:
   - success → `paid`
   - pending → `pending_external`
   - failed → `failed`
8. Emit action hooks + audit entries.
9. Release lock.

### Flow B: Webhook callback
1. REST endpoint validates signature and timestamp tolerance.
2. Persist raw event in `dcp_webhook_event` with dedupe key.
3. If duplicate, return 200 noop.
4. Resolve tx via `provider_txn_id` (fallback metadata mapping).
5. Transition tx state according to allowed map.
6. Mark webhook event `processed=1`.
7. Emit reconcile hooks.

### Flow C: Retry worker
1. Cron selects `failed` tx eligible by retry policy.
2. Transition `failed -> processing` guarded by compare-and-swap.
3. Re-call method `create_payout()` with same idempotency key if provider supports idempotent retry; otherwise generate attempt key and track chain in metadata.

---

## 8) Settings Schema

### 8.1 Admin/global settings (`wp_options`)
Option key: `dcp_settings`

```json
{
  "mode": "test|live",
  "enabled_methods": ["manual_bank", "provider_x"],
  "default_method": "manual_bank",
  "allowed_currencies": ["USD", "EUR"],
  "retry": { "max_attempts": 5, "base_delay_sec": 300, "max_delay_sec": 86400 },
  "method_settings": {
    "provider_x": {
      "api_key": "***",
      "webhook_secret": "***",
      "account_mode": "platform"
    }
  }
}
```

### 8.2 Vendor settings (`usermeta`)
Meta key prefix: `dcp_vendor_method_{method_id}`

```json
{
  "enabled": true,
  "beneficiary_ref": "acct_123",
  "country": "US",
  "currency": "USD",
  "kyc_status": "verified",
  "payout_schedule": "manual"
}
```

Validation rules:
- `enabled=true` only if required fields complete.
- KYC gating before payout creation.
- Country/currency must intersect with method/admin policy.

---

## 9) Security + Compliance Controls

- Nonces + capability checks for admin/vendor updates.
- Encrypt sensitive settings at rest when feasible; otherwise support env constants to avoid DB storage.
- Redact secrets and account numbers in logs.
- Webhook verification: signature + timestamp + replay window.
- Principle of least privilege for REST endpoints.
- Data retention policy for payload logs (e.g., 90 days) with scheduled purge.

---

## 10) Failure Modes and Safeguards

- **Double payout risk** → lock + unique constraints + idempotency keys.
- **Out-of-order webhook** → store first, reconcile later if tx not ready.
- **Provider timeout ambiguity** → keep `processing` until confirm; poll API if method supports it.
- **Manual admin override conflicts** → all overrides must pass transition guard and be audited.
- **Version drift in Dokan hooks** → central compatibility map and boot-time diagnostics.

---

## 11) Minimal Implementation Checklist

1. Bootstrap plugin and dependency/version checks.
2. Create migrations for 3 tables.
3. Implement method interface + registry.
4. Build orchestrator + withdraw bridge + locking.
5. Build one reference method (`manual_bank`) and one async sample method.
6. Add webhook REST controller.
7. Add admin + vendor settings screens with validation.
8. Add audit timeline in admin.
9. Add docs for hooks and extension examples.
10. Add integration tests for state transitions and idempotency.

---

## 12) Recommended Test Matrix

- Unit: transition map validation.
- Unit: method registry (missing method, disabled method).
- Integration: withdraw approved creates one tx only under concurrent triggers.
- Integration: duplicate webhook event id dedupes correctly.
- Integration: retry policy stops after max attempts.
- Security: unauthorized settings write blocked.
- Compatibility: Dokan hook map for supported version range.

