<?php
/**
 * Event capture → outbox. WooCommerce hooks build a payload and INSERT one row,
 * then return immediately (checkout is never blocked on network I/O). Each row
 * carries a UUID event_id used by Helpdesk for dedup (hd_integration_events).
 *
 * STUB — signatures + hook wiring only; Phase 2 fills payload builders.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatty_HD_Outbox {

	/** Wire order/customer hooks. Called from Chatty_HD_Plugin on init. */
	public static function register_hooks() {
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_order_changed' ), 10, 1 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'on_order_changed' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status' ), 10, 4 );
		add_action( 'woocommerce_new_customer', array( __CLASS__, 'on_customer_changed' ), 10, 1 );
		add_action( 'profile_update', array( __CLASS__, 'on_customer_changed' ), 10, 1 );
	}

	public static function on_order_changed( $order_id ) {
		// TODO(Phase 2): self::enqueue('order.updated', self::build_order_payload($order_id));
	}

	public static function on_order_status( $order_id, $from, $to, $order ) {
		// TODO(Phase 2): enqueue with status transition context.
	}

	public static function on_customer_changed( $user_id ) {
		// TODO(Phase 2): enqueue('customer.updated', ...).
	}

	/**
	 * Insert one outbox row. Returns the event_id, or null on failure.
	 * Never throws — capture must not break the store.
	 */
	public static function enqueue( $topic, array $payload ) {
		global $wpdb;
		$table = $wpdb->prefix . CHATTY_HD_OUTBOX_TABLE;
		$now   = current_time( 'mysql', true );
		$event_id = wp_generate_uuid4();
		$ok = $wpdb->insert(
			$table,
			array(
				'event_id'   => $event_id,
				'topic'      => $topic,
				'payload'    => wp_json_encode( $payload ),
				'status'     => 'pending',
				'attempts'   => 0,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( ! $ok ) {
			return null;
		}
		// Nudge an immediate flush for low latency; recurring flush is the safety net.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'chatty_hd_flush_outbox', array(), 'chatty-hd' );
		}
		return $event_id;
	}
}
