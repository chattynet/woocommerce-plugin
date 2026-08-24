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
 * STUB — Wave 2. Read-only Wave 1 (B1) does not need command execution; only the
 * poll scaffold + no-op executor ship in Phase 0 so the queue can be smoke-tested.
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
		// TODO(Wave 2): GET commands, dispatch to execute(), push results via outbox.
	}

	/** @return array result payload */
	public static function execute( $action, array $payload ) {
		// TODO(Wave 2): refund/cancel/edit_shipping/edit_order via wc_* / WC_Order APIs.
		return array( 'ok' => false, 'error' => 'not_implemented' );
	}
}
