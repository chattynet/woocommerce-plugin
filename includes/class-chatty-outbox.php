<?php
/**
 * Event capture → outbox. WooCommerce hooks build a payload and INSERT one row,
 * then return immediately (checkout is never blocked on network I/O). Each row
 * carries a UUID event_id used by Helpdesk for dedup (hd_integration_events).
 *
 * Payload builders read from WC_Order / WP_User only — no network I/O here.
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
		$payload = self::build_order_payload( $order_id );
		if ( null !== $payload ) {
			self::enqueue( 'order.updated', $payload );
		}
	}

	public static function on_order_status( $order_id, $from, $to, $order ) {
		$payload = self::build_order_payload( $order_id );
		if ( null === $payload ) {
			return;
		}
		$payload['status_from'] = $from;
		$payload['status_to']   = $to;
		self::enqueue( 'order.status_changed', $payload );
	}

	public static function on_customer_changed( $user_id ) {
		$payload = self::build_customer_payload( $user_id );
		if ( null !== $payload ) {
			self::enqueue( 'customer.updated', $payload );
		}
	}

	/**
	 * Build the order event payload from a WC_Order. Returns null if the order
	 * can't be loaded (never throws — capture must not break the store).
	 *
	 * @return array|null
	 */
	public static function build_order_payload( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => $item->get_total(),
				'sku'      => $item->get_product() ? $item->get_product()->get_sku() : null,
			);
		}

		return array(
			'order_id'       => $order->get_id(),
			'order_number'   => $order->get_order_number(),
			'status'         => $order->get_status(),
			'currency'       => $order->get_currency(),
			'total'          => $order->get_total(),
			'customer_id'    => $order->get_customer_id(),
			'customer_email' => $order->get_billing_email(),
			'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'items'          => $items,
			'created_at'     => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null,
			'updated_at'     => $order->get_date_modified() ? $order->get_date_modified()->date( DATE_ATOM ) : null,
			'admin_url'      => $order->get_edit_order_url(),
		);
	}

	/**
	 * Build the customer event payload from a WP user id. Returns null if the
	 * user can't be loaded.
	 *
	 * @return array|null
	 */
	public static function build_customer_payload( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		return array(
			'customer_id' => $user->ID,
			'email'       => $user->user_email,
			'first_name'  => $user->first_name,
			'last_name'   => $user->last_name,
			'phone'       => get_user_meta( $user->ID, 'billing_phone', true ),
			'created_at'  => $user->user_registered,
		);
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
