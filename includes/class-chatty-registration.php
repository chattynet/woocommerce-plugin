<?php
/**
 * Connection handshake. Merchant pastes a one-time connectionToken (issued by
 * Helpdesk POST /v1/integrations/woocommerce/connect). The plugin calls
 * POST {base_url}/v1/integrations/woocommerce/register with:
 *   { connection_token, site_url, wc_version, capabilities_probe }
 * Helpdesk validates the token, provisions a long-lived signing_secret, and
 * returns it. From then on the plugin is outbound-only (push + poll). See plan §1.
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
		$base_url = untrailingslashit( trim( $base_url ) );
		$connection_token = trim( $connection_token );

		if ( empty( $base_url ) || empty( $connection_token ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Base URL and connection token are required.', 'chatty-helpdesk' ),
			);
		}

		$body = wp_json_encode(
			array(
				'connection_token'   => $connection_token,
				'site_url'           => site_url(),
				'wc_version'         => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'capabilities_probe' => self::capabilities_probe(),
			)
		);

		$response = wp_remote_post(
			$base_url . '/v1/integrations/woocommerce/register',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $data['signing_secret'] ) ) {
			$error = is_array( $data ) && ! empty( $data['error'] )
				? $data['error']
				: sprintf( /* translators: %d: HTTP status code */ __( 'Registration failed (HTTP %d).', 'chatty-helpdesk' ), $code );
			return array( 'ok' => false, 'error' => $error );
		}

		Chatty_HD_Settings::set(
			array(
				'base_url'         => $base_url,
				'connection_token' => $connection_token,
				'signing_secret'   => $data['signing_secret'],
				'connected_at'     => current_time( 'mysql', true ),
				'capabilities'     => isset( $data['capabilities'] ) ? $data['capabilities'] : self::capabilities_probe(),
			)
		);

		return array( 'ok' => true );
	}

	/** Probe what this store can actually do (refund gateway, WC version). */
	public static function capabilities_probe() {
		return array(
			'wc_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			// refund/cancel/edit_shipping/edit_order — declared in Helpdesk declarations/woocommerce.ts.
		);
	}
}
