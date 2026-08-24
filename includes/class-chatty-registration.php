<?php
/**
 * Connection handshake. Merchant pastes a one-time connectionToken (issued by
 * Helpdesk POST /v1/integrations/woocommerce/connect). The plugin calls
 * POST {base_url}/v1/integrations/woocommerce/register with:
 *   { connection_token, site_url, wc_version, capabilities_probe }
 * Helpdesk validates the token, provisions a long-lived signing_secret, and
 * returns it. From then on the plugin is outbound-only (push + poll). See plan §1.
 *
 * STUB — Phase 2 implements the HTTP call, capability probe (detect refund
 * gateway support, WC version) and secret persistence.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatty_HD_Registration {

	/**
	 * @param string $base_url         Helpdesk base URL (merchant enters, or embedded in token).
	 * @param string $connection_token One-time token from Helpdesk.
	 * @return array{ok:bool,error?:string}
	 */
	public static function connect( $base_url, $connection_token ) {
		// TODO(Phase 2): wp_remote_post register endpoint, parse signing_secret, Chatty_HD_Settings::set().
		return array( 'ok' => false, 'error' => 'not_implemented' );
	}

	/** Probe what this store can actually do (refund gateway, WC version). */
	public static function capabilities_probe() {
		return array(
			'wc_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			// refund/cancel/edit_shipping/edit_order — declared in Helpdesk declarations/woocommerce.ts.
		);
	}
}
