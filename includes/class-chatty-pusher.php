<?php
/**
 * Drains the outbox to Helpdesk. Runs on the recurring Action Scheduler action
 * `chatty_hd_flush_outbox` (safety net) and on the async nudge from enqueue().
 * POSTs each pending row to {base_url}/v1/webhooks/int/{connection_token} with an
 * X-Chatty-Signature HMAC header. On failure: attempts++, exponential backoff,
 * leave status=pending (retry) until a cap, then status=failed. Solves Woo's
 * unreliable native webhook (no retry, self-disables after 5 fails). See plan §1.A.
 *
 * STUB — Phase 2 implements the drain loop + backoff.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatty_HD_Pusher {

	const RECURRING_HOOK = 'chatty_hd_flush_outbox';
	const BATCH = 25;
	const MAX_ATTEMPTS = 12;

	/** Ensure the safety-net recurring flush is scheduled (every minute). */
	public static function ensure_scheduled() {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::RECURRING_HOOK ) ) {
			as_schedule_recurring_action( time() + 60, 60, self::RECURRING_HOOK, array(), 'chatty-hd' );
		}
	}

	public static function register_hooks() {
		add_action( self::RECURRING_HOOK, array( __CLASS__, 'flush' ) );
	}

	/** Drain up to BATCH pending rows. */
	public static function flush() {
		// TODO(Phase 2): select pending, POST signed body, mark sent/failed with backoff.
	}
}
