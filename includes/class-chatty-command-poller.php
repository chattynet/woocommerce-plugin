<?php
/**
 * The A2 half: Helpdesk never calls into the store. Instead the plugin polls a
 * Helpdesk command queue, executes each command locally with WooCommerce APIs,
 * and pushes the result back through the outbox (topic `command.result`).
 *
 * Poll: GET {base_url}/v1/integrations/woocommerce/commands (signed) →
 *   [{ id, action, payload }]  where action ∈ refund|cancel|edit_shipping|edit_order (Wave 2 / B2).
 * Execute → ack: the result row in the outbox carries the command id so Helpdesk
 * can correlate. Idempotency: a claimed command id is recorded to avoid re-running.
 *
 * Wave 2 (write actions). Poll + dispatch are fully wired; refund/cancel are
 * implemented against stable WooCommerce APIs. edit_shipping/edit_order are
 * shaped but flagged TODO where the exact WC API needs sandbox verification
 * (plan §2 QĐ-B: write ships only after sandbox testing).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatty_HD_Command_Poller {

	const RECURRING_HOOK = 'chatty_hd_poll_commands';
	const INTERVAL = 10; // seconds — keep write-action latency low.

	public static function ensure_scheduled() {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::RECURRING_HOOK ) ) {
			as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::RECURRING_HOOK, array(), 'chatty-hd' );
		}
	}

	public static function register_hooks() {
		add_action( self::RECURRING_HOOK, array( __CLASS__, 'poll' ) );
	}

	/** Fetch and execute pending commands. */
	public static function poll() {
		$config = Chatty_HD_Settings::get();
		if ( empty( $config['base_url'] ) || empty( $config['connection_token'] ) || empty( $config['signing_secret'] ) ) {
			return;
		}

		$url = rtrim( $config['base_url'], '/' ) . '/v1/integrations/woocommerce/commands';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'X-Chatty-Signature' => Chatty_HD_Signer::sign( $config['connection_token'], $config['signing_secret'] ),
					'X-Chatty-Connection-Token' => $config['connection_token'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return;
		}

		$commands = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $commands ) || ! is_array( $commands ) ) {
			return;
		}

		foreach ( $commands as $command ) {
			if ( empty( $command['id'] ) || empty( $command['action'] ) ) {
				continue;
			}
			self::claim_and_run( $command );
		}
	}

	/** Guard against re-running a command id already claimed (idempotency). */
	private static function claim_and_run( array $command ) {
		$claimed_key = 'chatty_hd_claimed_' . sanitize_key( $command['id'] );
		if ( get_transient( $claimed_key ) ) {
			return;
		}
		set_transient( $claimed_key, 1, DAY_IN_SECONDS );

		$payload = isset( $command['payload'] ) && is_array( $command['payload'] ) ? $command['payload'] : array();
		$result  = self::execute( $command['action'], $payload );

		Chatty_HD_Outbox::enqueue(
			'command.result',
			array(
				'command_id' => $command['id'],
				'action'     => $command['action'],
				'result'     => $result,
			)
		);
	}

	/** @return array result payload */
	public static function execute( $action, array $payload ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array( 'ok' => false, 'error' => 'woocommerce_not_available' );
		}

		switch ( $action ) {
			case 'refund':
				return self::execute_refund( $payload );
			case 'cancel':
				return self::execute_cancel( $payload );
			case 'edit_shipping':
				return self::execute_edit_shipping( $payload );
			case 'edit_order':
				return self::execute_edit_order( $payload );
			default:
				return array( 'ok' => false, 'error' => 'unknown_action' );
		}
	}

	/** refund: amount + optional reason via wc_create_refund(). Depends on gateway refund support. */
	private static function execute_refund( array $payload ) {
		if ( empty( $payload['order_id'] ) ) {
			return array( 'ok' => false, 'error' => 'missing_order_id' );
		}
		$order = wc_get_order( $payload['order_id'] );
		if ( ! $order ) {
			return array( 'ok' => false, 'error' => 'order_not_found' );
		}

		$amount = isset( $payload['amount'] ) ? wc_format_decimal( $payload['amount'] ) : $order->get_total();
		$reason = isset( $payload['reason'] ) ? sanitize_text_field( $payload['reason'] ) : '';

		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $amount,
				'reason'   => $reason,
			)
		);

		if ( is_wp_error( $refund ) ) {
			// Gateway may not support programmatic refund — surface a clear error.
			return array( 'ok' => false, 'error' => $refund->get_error_message() );
		}

		return array( 'ok' => true, 'refund_id' => $refund->get_id() );
	}

	/** cancel: straightforward status transition. */
	private static function execute_cancel( array $payload ) {
		if ( empty( $payload['order_id'] ) ) {
			return array( 'ok' => false, 'error' => 'missing_order_id' );
		}
		$order = wc_get_order( $payload['order_id'] );
		if ( ! $order ) {
			return array( 'ok' => false, 'error' => 'order_not_found' );
		}

		$note = isset( $payload['reason'] ) ? sanitize_text_field( $payload['reason'] ) : __( 'Cancelled via Chatty Helpdesk', 'chatty-helpdesk' );
		$order->update_status( 'cancelled', $note );

		return array( 'ok' => true );
	}

	/**
	 * edit_shipping: update shipping line total/method on an existing order.
	 * TODO(Wave 2, sandbox): WooCommerce has no single official "update shipping"
	 * call — this manipulates the order's shipping line item directly via
	 * WC_Order_Item_Shipping, which is the commonly used approach but has edge
	 * cases (multiple shipping lines, tax recalculation) that need sandbox
	 * verification before this ships live.
	 */
	private static function execute_edit_shipping( array $payload ) {
		if ( empty( $payload['order_id'] ) ) {
			return array( 'ok' => false, 'error' => 'missing_order_id' );
		}
		$order = wc_get_order( $payload['order_id'] );
		if ( ! $order ) {
			return array( 'ok' => false, 'error' => 'order_not_found' );
		}

		$shipping_items = $order->get_items( 'shipping' );
		$item           = reset( $shipping_items );

		if ( ! $item instanceof WC_Order_Item_Shipping ) {
			return array( 'ok' => false, 'error' => 'no_shipping_line_item' );
		}

		if ( isset( $payload['method_title'] ) ) {
			$item->set_method_title( sanitize_text_field( $payload['method_title'] ) );
		}
		if ( isset( $payload['total'] ) ) {
			$item->set_total( wc_format_decimal( $payload['total'] ) );
		}
		$item->save();

		$order->calculate_totals();
		$order->save();

		return array( 'ok' => true );
	}

	/**
	 * edit_order: generic line-item edits (qty/price).
	 * TODO(Wave 2, sandbox): declarations/woocommerce.ts flags edit_order as
	 * having a known bug — needs sandbox verification of exactly which fields
	 * are safe to mutate post-payment before this path is trusted with real data.
	 */
	private static function execute_edit_order( array $payload ) {
		if ( empty( $payload['order_id'] ) || empty( $payload['line_items'] ) || ! is_array( $payload['line_items'] ) ) {
			return array( 'ok' => false, 'error' => 'missing_order_id_or_line_items' );
		}
		$order = wc_get_order( $payload['order_id'] );
		if ( ! $order ) {
			return array( 'ok' => false, 'error' => 'order_not_found' );
		}

		foreach ( $payload['line_items'] as $line ) {
			if ( empty( $line['item_id'] ) ) {
				continue;
			}
			$item = $order->get_item( (int) $line['item_id'] );
			if ( ! $item ) {
				continue;
			}
			if ( isset( $line['quantity'] ) && method_exists( $item, 'set_quantity' ) ) {
				$item->set_quantity( (int) $line['quantity'] );
			}
			if ( isset( $line['total'] ) && method_exists( $item, 'set_total' ) ) {
				$item->set_total( wc_format_decimal( $line['total'] ) );
			}
			$item->save();
		}

		$order->calculate_totals();
		$order->save();

		return array( 'ok' => true );
	}
}
