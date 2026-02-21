# DCP Hooks

## Filters

### `dcp_register_payout_methods`
Register payout method classes keyed by method id.

### `dcp_retry_policy`
Override retry configuration.

### `dcp_tx_context`
Inject additional metadata into transaction context.

## Actions

### `dcp_before_payout_create`
Fires before provider create payout call.

### `dcp_after_payout_create`
Fires after provider create payout call.

### `dcp_payout_status_changed`
Fires when payout state is transitioned.

### `dcp_webhook_received`
Fires when webhook passes validation and is persisted.

### `dcp_webhook_reconciled`
Fires after a webhook changes a payout status.

### `dcp_vendor_method_validation_errors`
Fires when vendor settings fail validation.
