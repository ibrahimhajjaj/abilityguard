# AbilityGuard - Reference Demo Plugins

This directory contains intentionally minimal example plugins that show third-party authors how to integrate with AbilityGuard. Each plugin registers one ability with a complete `safety` config; neither requires the actual third-party plugin it mimics.

| Directory | Simulates | Pattern shown |
|---|---|---|
| `abilityguard-woocommerce-pack/` | WooCommerce product update | post\_meta snapshot + full rollback |
| `abilityguard-fluent-forms-pack/` | Fluent Forms duplication | create operation + rollback limitation |

Both plugins are GPLv2, intentionally minimal, and work in any WordPress 6.9+ install. Read each plugin's own README for install steps and wp-cli examples.
