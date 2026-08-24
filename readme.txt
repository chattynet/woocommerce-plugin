=== Chatty Helpdesk for WooCommerce ===
Contributors: avada
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 0.1.0
License: GPLv2 or later

Connect your WooCommerce store to Chatty Helpdesk. Pushes order and customer
events to Helpdesk (with reliable retry) and runs Helpdesk actions on your store
through a secure, outbound-only connection — no inbound access required.

== Description ==

The plugin is outbound-only: it opens connections to Chatty Helpdesk, never the
other way around, so it works behind firewalls and WAFs. Order and customer
changes are queued locally and delivered with automatic retry, and write actions
(refund, cancel, edit shipping, edit order) are pulled from Helpdesk and executed
on your store.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin, then Activate.
2. Go to WooCommerce > Chatty Helpdesk.
3. Paste the connection token from your Chatty Helpdesk settings and click Connect.

== Changelog ==

= 0.1.0 =
* Initial scaffold: outbox, pusher, command poller, registration handshake.
