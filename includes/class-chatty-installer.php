<?php
/**
 * Outbox table lifecycle. The outbox is the durability boundary: order/customer
 * hooks write a row and return immediately (never block checkout), Action
 * Scheduler drains it with retry. See plan §1.A.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatty_HD_Installer {

	/** Create the outbox table via dbDelta (idempotent). */
	public static function activate() {
		global $wpdb;
		$table   = $wpdb->prefix . CHATTY_HD_OUTBOX_TABLE;
		$charset = $wpdb->get_charset_collate();

		// event_id is the dedup key mirrored to Helpdesk hd_integration_events.external_event_id.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id VARCHAR(64) NOT NULL,
			topic VARCHAR(64) NOT NULL,
			payload LONGTEXT NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY event_id (event_id),
			KEY status_created (status, created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** Unschedule recurring Action Scheduler jobs on deactivate. */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'chatty_hd_flush_outbox' );
			as_unschedule_all_actions( 'chatty_hd_poll_commands' );
		}
	}
}
