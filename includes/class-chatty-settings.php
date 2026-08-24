<?php
/**
 * Admin settings: merchant pastes the one-time connection token from Helpdesk
 * and clicks Connect. Also stores the resolved config after a successful
 * handshake. Config lives in a single wp_option `chatty_hd_settings`:
 *   { base_url, connection_token, signing_secret, connected_at, capabilities }
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatty_HD_Settings {

	const OPTION = 'chatty_hd_settings';
	const NONCE_ACTION = 'chatty_hd_connect';
	const NONCE_FIELD  = 'chatty_hd_connect_nonce';

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

	/** Register the submenu page under WooCommerce. */
	public static function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Chatty Helpdesk', 'chatty-helpdesk' ),
			__( 'Chatty Helpdesk', 'chatty-helpdesk' ),
			'manage_woocommerce',
			'chatty-helpdesk',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/** Render the connect form + connection status. Handles the Connect submit. */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$notice = '';

		if ( isset( $_POST['chatty_hd_connect_submit'] ) ) {
			check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

			$base_url         = isset( $_POST['chatty_hd_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['chatty_hd_base_url'] ) ) : '';
			$connection_token = isset( $_POST['chatty_hd_connection_token'] ) ? sanitize_text_field( wp_unslash( $_POST['chatty_hd_connection_token'] ) ) : '';

			$result = Chatty_HD_Registration::connect( $base_url, $connection_token );

			if ( ! empty( $result['ok'] ) ) {
				$notice = '<div class="notice notice-success"><p>' . esc_html__( 'Connected to Chatty Helpdesk.', 'chatty-helpdesk' ) . '</p></div>';
			} else {
				$error  = isset( $result['error'] ) ? $result['error'] : __( 'Unknown error.', 'chatty-helpdesk' );
				$notice = '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
			}
		}

		$config    = self::get();
		$connected = self::is_connected();
		$base_url  = isset( $config['base_url'] ) ? $config['base_url'] : '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Chatty Helpdesk', 'chatty-helpdesk' ) . '</h1>';
		echo wp_kses_post( $notice );

		if ( $connected ) {
			echo '<p>' . esc_html__( 'Status:', 'chatty-helpdesk' ) . ' <strong>' . esc_html__( 'Connected', 'chatty-helpdesk' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Base URL:', 'chatty-helpdesk' ) . ' ' . esc_html( $base_url ) . '</p>';
			if ( ! empty( $config['connected_at'] ) ) {
				echo '<p>' . esc_html__( 'Connected at:', 'chatty-helpdesk' ) . ' ' . esc_html( $config['connected_at'] ) . '</p>';
			}
		} else {
			echo '<p>' . esc_html__( 'Status:', 'chatty-helpdesk' ) . ' <strong>' . esc_html__( 'Not connected', 'chatty-helpdesk' ) . '</strong></p>';
		}

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<table class="form-table">';
		echo '<tr><th scope="row"><label for="chatty_hd_base_url">' . esc_html__( 'Helpdesk base URL', 'chatty-helpdesk' ) . '</label></th>';
		echo '<td><input type="url" id="chatty_hd_base_url" name="chatty_hd_base_url" class="regular-text" value="' . esc_attr( $base_url ) . '" placeholder="https://helpdesk.example.com" required /></td></tr>';
		echo '<tr><th scope="row"><label for="chatty_hd_connection_token">' . esc_html__( 'Connection token', 'chatty-helpdesk' ) . '</label></th>';
		echo '<td><input type="text" id="chatty_hd_connection_token" name="chatty_hd_connection_token" class="regular-text" value="" placeholder="' . esc_attr__( 'Paste one-time token', 'chatty-helpdesk' ) . '" required /></td></tr>';
		echo '</table>';
		submit_button( $connected ? __( 'Reconnect', 'chatty-helpdesk' ) : __( 'Connect', 'chatty-helpdesk' ), 'primary', 'chatty_hd_connect_submit' );
		echo '</form>';
		echo '</div>';
	}
}
