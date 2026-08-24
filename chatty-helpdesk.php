<?php
/**
 * Plugin Name:       Chatty Helpdesk for WooCommerce
 * Plugin URI:        https://github.com/chattynet/woocommerce-plugin
 * Description:        Connects a WooCommerce store to Chatty Helpdesk. Pushes order/customer events outbound (outbox + Action Scheduler, with retry) and executes Helpdesk commands via a poll-based queue. The plugin is outbound-only — Helpdesk never opens a connection into the store (firewall/WAF/self-signed-cert safe).
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            Avada / Chatty
 * License:           GPL-2.0-or-later
 * Text Domain:       chatty-helpdesk
 *
 * Architecture: docs/platforms/woocommerce-plugin-plan.md in the Helpdesk repo.
 * Decisions (2026-08-24): A2 command-queue (outbound-only) · B1 read-only first, B2 write after sandbox.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'CHATTY_HD_VERSION', '0.1.0' );
define( 'CHATTY_HD_FILE', __FILE__ );
define( 'CHATTY_HD_DIR', plugin_dir_path( __FILE__ ) );
define( 'CHATTY_HD_OUTBOX_TABLE', 'chatty_hd_outbox' );

require_once CHATTY_HD_DIR . 'includes/class-chatty-installer.php';
require_once CHATTY_HD_DIR . 'includes/class-chatty-signer.php';
require_once CHATTY_HD_DIR . 'includes/class-chatty-settings.php';
require_once CHATTY_HD_DIR . 'includes/class-chatty-registration.php';
require_once CHATTY_HD_DIR . 'includes/class-chatty-outbox.php';
require_once CHATTY_HD_DIR . 'includes/class-chatty-pusher.php';
require_once CHATTY_HD_DIR . 'includes/class-chatty-command-poller.php';
require_once CHATTY_HD_DIR . 'includes/class-chatty-plugin.php';

// Activation: create the outbox table (dbDelta). Deactivation: unschedule Action Scheduler jobs.
register_activation_hook( __FILE__, array( 'Chatty_HD_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Chatty_HD_Installer', 'deactivate' ) );

// Boot after all plugins are loaded (WooCommerce + Action Scheduler must be present).
add_action( 'plugins_loaded', array( 'Chatty_HD_Plugin', 'instance' ) );
