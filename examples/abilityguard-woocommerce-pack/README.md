# AbilityGuard WooCommerce Pack (demo)

A reference plugin showing how a third-party author declares a `safety` config on an ability that mutates post meta - using plain WordPress primitives so it runs in any install without WooCommerce.

## What this demonstrates

- Registering a custom category (`acme-shop`) on `wp_abilities_api_categories_init`.
- Declaring a `safety` config with `destructive => true` and a dynamic `snapshot` callable.
- The snapshot callable receives the runtime `$input` and returns only the post meta keys that need capturing - keeping the snapshot surface minimal.
- AbilityGuard intercepts the execute callback, captures `_price` and `_regular_price` before the write, logs the invocation, and enables point-in-time rollback.

## Install

1. Activate **AbilityGuard** (must load first).
2. Drop this directory into `wp-content/plugins/` and activate it from the Plugins screen.

## wp-cli example

```bash
# Execute the ability (requires manage_options - run as admin)
wp ability execute acme-shop/update-product-price \
  --input='{"product_id":42,"regular_price":"19.99"}'

# Inspect the audit log
wp abilityguard log list --ability=acme-shop/update-product-price

# Roll back the last invocation (replace UUID with the logged invocation_id)
wp abilityguard rollback <invocation-id>
```

## Rollback surface

The snapshot captures `_price` and `_regular_price` for the target product. Rollback restores both meta keys to their pre-execution values in a single operation.
