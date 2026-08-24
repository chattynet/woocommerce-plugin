<?php
/**
 * Orchestrator. Guards on WooCommerce + Action Scheduler being present, wires
 * the settings page always, and wires the event/push/poll machinery only once
 * the store is connected. See plan §1.A.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatty_HD_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// WooCommerce is required for the order/customer hooks to mean anything.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_wc' ) );
			return;
		}

		add_action( 'admin_menu', array( 'Chatty_HD_Settings', 'register_admin_page' ) );

		Chatty_HD_Pusher::register_hooks();
		Chatty_HD_Command_Poller::register_hooks();

		if ( Chatty_HD_Settings::is_connected() ) {
			Chatty_HD_Outbox::register_hooks();
			Chatty_HD_Pusher::ensure_scheduled();
			Chatty_HD_Command_Poller::ensure_scheduled();
		}
	}

	public function notice_missing_wc() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Chatty Helpdesk requires WooCommerce to be installed and active.', 'chatty-helpdesk' );
		echo '</p></div>';
	}
}
