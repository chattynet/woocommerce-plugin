<?php
/**
 * Admin settings: merchant pastes the one-time connection token from Helpdesk
 * and clicks Connect. Also stores the resolved config after a successful
 * handshake. Config lives in a single wp_option `chatty_hd_settings`:
 *   { base_url, connection_token, signing_secret, connected_at, capabilities }
 *
 * STUB — Phase 2 fleshes out the settings page UI + Connect action.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatty_HD_Settings {

	const OPTION = 'chatty_hd_settings';

	/** @return array config or [] if not connected. */
	public static function get() {
		$opt = get_option( self::OPTION, array() );
		return is_array( $opt ) ? $opt : array();
	}

	public static function set( array $config ) {
		update_option( self::OPTION, $config, false );
	}

	public static function is_connected() {
		$c = self::get();
		return ! empty( $c['base_url'] ) && ! empty( $c['signing_secret'] );
	}

	/** TODO(Phase 2): register admin menu under WooCommerce, render token field + Connect button. */
	public static function register_admin_page() {}
}
