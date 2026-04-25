# AbilityGuard - Reference Demo Plugins

This directory contains intentionally minimal example plugins that show third-party authors how to integrate with AbilityGuard. Each plugin registers one ability with a complete `safety` config; neither requires the actual third-party plugin it mimics.

| Directory | Simulates | Pattern shown |
|---|---|---|
| `abilityguard-woocommerce-pack/` | WooCommerce product update | post\_meta snapshot + full rollback |
| `abilityguard-fluent-forms-pack/` | Fluent Forms duplication | create operation + rollback limitation |

Both plugins are GPLv2, intentionally minimal, and work in any WordPress 6.9+ install. Read each plugin's own README for install steps and wp-cli examples.

## How to use these

These are **reference implementations**, not the docs-driven walkthrough. They show the final shape of a working safety-config'd plugin so you can see all the pieces wired together.

If you're learning from scratch, read [`docs/safety-config.md`](../docs/safety-config.md) first - it walks you through the prerequisites (category registration), the schema, the snapshot resolver forms, and a "Testing via wp-cli" recipe. Come back here once you want to compare your in-progress code against a complete example.

For an even smaller end-to-end trial, see [`tests/Adoption/test-plugin/heritage-trial.php`](../tests/Adoption/test-plugin/heritage-trial.php) - written in 30 lines following only the docs.
