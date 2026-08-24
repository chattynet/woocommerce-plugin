# Chatty Helpdesk for WooCommerce

WordPress/WooCommerce plugin that connects a store to **Chatty Helpdesk**.

## Design (locked 2026-08-24)

- **Outbound-only (A2).** The plugin never accepts inbound connections from Helpdesk.
  It (1) **pushes** order/customer events out via an outbox + Action Scheduler
  (with retry — fixing Woo's native webhook that has no retry and self-disables
  after 5 failures), and (2) **polls** a Helpdesk command queue to execute write
  actions locally, pushing results back. Works behind firewalls/WAF/self-signed certs.
- **Wave 1 = read-only (B1):** register + event push. **Wave 2 = write (B2):**
  refund / cancel / edit_shipping / edit_order via the command queue, only after
  sandbox verification.
- **Handshake:** merchant pastes a one-time connection token from Helpdesk → plugin
  registers → Helpdesk returns a signing secret. All requests HMAC-SHA256 signed.

Full architecture: `docs/platforms/woocommerce-plugin-plan.md` in the Helpdesk repo.

## Layout

```
chatty-helpdesk.php              # bootstrap: constants, requires, (de)activation, boot
includes/
  class-chatty-plugin.php        # orchestrator
  class-chatty-installer.php     # outbox table (dbDelta)
  class-chatty-settings.php      # admin page + config in wp_options
  class-chatty-registration.php  # connection-token handshake
  class-chatty-outbox.php        # WC hooks → outbox rows (non-blocking)
  class-chatty-pusher.php        # drain outbox → Helpdesk webhook (signed, backoff)
  class-chatty-command-poller.php# poll command queue → execute → push result (Wave 2)
  class-chatty-signer.php        # HMAC-SHA256
build/build-zip.sh               # package an installable release zip
```

## Requirements

WordPress ≥ 6.0 · PHP ≥ 7.4 · WooCommerce ≥ 7.0 · Action Scheduler (bundled with WooCommerce).

## Install (merchant)

Download the release zip from GitHub Releases → WP Admin → Plugins → Add New →
Upload Plugin → Activate → WooCommerce → Chatty Helpdesk → paste connection token → Connect.

## Build a release zip

```bash
bash build/build-zip.sh   # → dist/woocommerce-plugin-<version>.zip
```
