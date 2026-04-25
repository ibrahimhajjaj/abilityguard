# AbilityGuard

Snapshot + audit + rollback middleware for the WordPress Abilities API.

**Status:** v0.1-dev. Not production ready.

## What it is

A developer library for plugin authors who register abilities via `wp_register_ability()` and want snapshot capture, audit logging, and one-click rollback for every invocation - across REST, MCP, internal PHP, and wp-cli - without building it themselves.

Declare what state your ability touches; AbilityGuard handles the safety wrapper.

```php
wp_register_ability( 'my-plugin/update-product-price', array(
    'label'               => 'Update product price',
    'description'         => 'Updates the price on a WooCommerce product.',
    'category'            => 'woocommerce',
    'input_schema'        => array( /* ... */ ),
    'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ),
    'execute_callback'    => fn( $args ) => update_post_meta( $args['product_id'], '_price', $args['price'] ),

    // AbilityGuard extension (stripped before core validation):
    'safety' => array(
        'destructive' => true,
        'snapshot'    => array(
            'post_meta' => array( '_price', '_regular_price' ),
            'options'   => array(),
        ),
    ),
) );
```

## Development

Requires PHP 8.1+, Composer, Node 20+, Docker.

```bash
composer install
npm install && npm run build      # bundles assets/admin.jsx → assets/admin.js

# Pure unit tests (Hash + Json - fast, no DB).
composer test

# Lint + static analysis.
composer lint
composer stan

# Real integration tests against WordPress + MySQL via wp-env.
# Boots WordPress, abilities-api plugin (mapped from lab/), and our plugin.
composer env:start
composer test:integration
composer env:stop
```

The integration suite is the primary correctness source. It exercises:

- `Installer` - real `dbDelta`, real columns
- `SnapshotService` + `SnapshotStore` - captures real `post_meta` / `options` and reads them back from the snapshots table
- `AuditLogger` + `LogRepository` - write/read against the real audit table
- `RollbackService` - full round-trip: capture → mutate → rollback → assert state restored
- `RegistrationFilter` - registers a real ability via `wp_register_ability()` and asserts the wrapper records snapshot + log when `WP_Ability::execute()` is called
- CLI command class - instantiated and dispatched in-process



## License

GPL-2.0-or-later.
