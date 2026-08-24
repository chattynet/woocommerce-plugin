<?php
/**
 * Drains the outbox to Helpdesk. Runs on the recurring Action Scheduler action
 * `chatty_hd_flush_outbox` (safety net) and on the async nudge from enqueue().
 * POSTs each pending row to {base_url}/v1/webhooks/int/{connection_token} with an
 * X-Chatty-Signature HMAC header. On failure: attempts++, exponential backoff,
 * leave status=pending (retry) until a cap, then status=failed. Solves Woo's
 * unreliable native webhook (no retry, self-disables after 5 fails). See plan §1.A.
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
		global $wpdb;

		$config = Chatty_HD_Settings::get();
		if ( empty( $config['base_url'] ) || empty( $config['connection_token'] ) || empty( $config['signing_secret'] ) ) {
			return;
		}

		$table = $wpdb->prefix . CHATTY_HD_OUTBOX_TABLE;
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at ASC LIMIT %d",
				'pending',
				self::BATCH
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		$url = rtrim( $config['base_url'], '/' ) . '/v1/webhooks/int/' . rawurlencode( $config['connection_token'] );

		foreach ( $rows as $row ) {
			self::push_row( $row, $url, $config['signing_secret'] );
		}
	}

	/** POST a single outbox row and update its status/attempts. */
	private static function push_row( $row, $url, $secret ) {
		global $wpdb;
		$table = $wpdb->prefix . CHATTY_HD_OUTBOX_TABLE;

		$body = wp_json_encode(
			array(
				'event_id' => $row->event_id,
				'topic'    => $row->topic,
				'payload'  => json_decode( $row->payload, true ),
			)
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'        => 'application/json',
					'X-Chatty-Signature'  => Chatty_HD_Signer::sign( $body, $secret ),
					'X-Chatty-Event-Id'   => $row->event_id,
					'X-Chatty-Topic'      => $row->topic,
				),
				'body'    => $body,
			)
		);

		$now = current_time( 'mysql', true );

		if ( is_wp_error( $response ) ) {
			self::mark_retry( $row, $response->get_error_message(), $now );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			$wpdb->update(
				$table,
				array(
					'status'     => 'sent',
					'updated_at' => $now,
				),
				array( 'id' => $row->id )
			);
			return;
		}

		self::mark_retry( $row, 'HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ), $now );
	}

	/** Bump attempts and either leave pending (backoff) or mark failed once MAX_ATTEMPTS is hit. */
	private static function mark_retry( $row, $error, $now ) {
		global $wpdb;
		$table    = $wpdb->prefix . CHATTY_HD_OUTBOX_TABLE;
		$attempts = (int) $row->attempts + 1;
		$status   = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';

		$wpdb->update(
			$table,
			array(
				'status'     => $status,
				'attempts'   => $attempts,
				'last_error' => is_string( $error ) ? substr( $error, 0, 1000 ) : '',
				'updated_at' => $now,
			),
			array( 'id' => $row->id )
		);

		if ( 'pending' === $status ) {
			// Exponential backoff: next recurring flush (every 60s) naturally spaces
			// retries out; schedule a delayed nudge so short-lived failures recover fast.
			$delay = min( 60 * pow( 2, $attempts ), 3600 );
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $delay, self::RECURRING_HOOK, array(), 'chatty-hd' );
			}
		}
	}
}
