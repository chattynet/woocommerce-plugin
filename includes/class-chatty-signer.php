<?php
/**
 * HMAC-SHA256 request signing, shared by the pusher (outbound events) and the
 * command poller (authenticating result pushes). Secret is provisioned at
 * registration and stored in wp_options. See plan §1.A + Helpdesk vendorSignature.ts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatty_HD_Signer {

	/** Hex HMAC-SHA256 of the raw JSON body with the shared signing secret. */
	public static function sign( $raw_body, $secret ) {
		return hash_hmac( 'sha256', (string) $raw_body, (string) $secret );
	}

	/** Constant-time verify (for inbound command payloads). */
	public static function verify( $raw_body, $secret, $signature ) {
		$expected = self::sign( $raw_body, $secret );
		return hash_equals( $expected, (string) $signature );
	}
}
