<?php
/**
 * Meesho Master Undo / Audit Log Module
 * Handles logging every destructive action with old/new value snapshots.
 * Undo within 15-day window. Cron purge of expired snapshots.
 * All dates stored/displayed as dd/mm/yyyy.
 */

class Meesho_Master_Undo {

	public function __construct() {
		add_action( 'wp_ajax_meesho_undo_action', array( $this, 'ajax_undo' ) );
		add_action( 'wp_ajax_meesho_get_logs', array( $this, 'ajax_get_logs' ) );

		// Register cron for purging old snapshots
		add_action( 'meesho_purge_old_snapshots', array( $this, 'purge_expired_snapshots' ) );
		if ( ! wp_next_scheduled( 'meesho_purge_old_snapshots' ) ) {
			wp_schedule_event( time(), 'daily', 'meesho_purge_old_snapshots' );
		}
	}

	/**
	 * Log an action to the audit table.
	 *
	 * @param string      $action_type  e.g. meta_update, delete, schema_add, product_import
	 * @param int|null    $post_id      Affected post/product (nullable for non-post actions)
	 * @param string      $old_value    Snapshot before change
	 * @param string      $new_value    Value after change
	 * @param string      $source       manual | ai | copilot | auto
	 * @return int|false  Insert ID on success, false on failure
	 */
	public function log( $action_type, $post_id, $old_value, $new_value, $source = 'manual' ) {
		global $wpdb;

		$now        = date( 'd/m/Y' );
		$expires_at = date( 'd/m/Y', strtotime( '+15 days' ) );

		return $wpdb->insert(
			$wpdb->prefix . 'meesho_audit_logs',
			array(
				'post_id'     => $post_id,
				'action_type' => $action_type,
				'old_value'   => $old_value,
				'new_value'   => $new_value,
				'source'      => $source,
				'user_id'     => get_current_user_id(),
				'created_at'  => $now,
				'expires_at'  => $expires_at,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Undo a single logged action by its ID.
	 */
	public function undo( $log_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'meesho_audit_logs';

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $log_id ) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Log entry not found.' );
		}

		// Check expiry — compare dd/mm/yyyy dates
		$expires_ts = $this->ddmmyyyy_to_timestamp( $row->expires_at );
		if ( time() > $expires_ts ) {
			return new WP_Error( 'expired', 'Undo window has expired (15 days).' );
		}

		if ( $row->old_value === '[Expired]' ) {
			return new WP_Error( 'expired', 'Snapshot data has been purged.' );
		}

		// Perform the undo based on action type
		$result = $this->apply_undo( $row );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Log the undo itself
		$this->log( 'undo_' . $row->action_type, $row->post_id, $row->new_value, $row->old_value, 'manual' );

		return true;
	}

	/**
	 * Restore old value via the appropriate WP API.
	 */
	private function apply_undo( $row ) {
		switch ( $row->action_type ) {
			case 'meta_update':
				// old_value is JSON: {"meta_key": "...", "meta_value": "..."}
				$meta = json_decode( $row->old_value, true );
				if ( $meta && isset( $meta['meta_key'] ) ) {
					update_post_meta( $row->post_id, $meta['meta_key'], $meta['meta_value'] );
				}
				break;

			case 'post_update':
				// old_value is JSON with post fields
				$post_data = json_decode( $row->old_value, true );
				if ( $post_data ) {
					$post_data['ID'] = $row->post_id;
					wp_update_post( $post_data );
				}
				break;

			case 'delete':
			case 'soft_delete':
				// Restore trashed post
				if ( $row->post_id ) {
					wp_untrash_post( $row->post_id );
				}
				break;

			case 'schema_add':
			case 'schema_update':
				// old_value is the previous schema JSON-LD (or empty)
				if ( $row->post_id ) {
					update_post_meta( $row->post_id, '_meesho_schema_jsonld', $row->old_value );
				}
				break;

			case 'product_import':
				// Soft-delete the imported product
				if ( $row->post_id ) {
					wp_trash_post( $row->post_id );
				}
				break;

			default:
				// Generic meta restore
				if ( $row->post_id && ! empty( $row->old_value ) ) {
					$meta = json_decode( $row->old_value, true );
					if ( $meta && isset( $meta['meta_key'] ) ) {
						update_post_meta( $row->post_id, $meta['meta_key'], $meta['meta_value'] );
					}
				}
				break;
		}

		return true;
	}

	/**
	 * Purge old_value/new_value snapshots after 15 days.
	 * Log metadata (action type, timestamp, source, post ID) is kept indefinitely.
	 */
	public function purge_expired_snapshots() {
		global $wpdb;
		$table = $wpdb->prefix . 'meesho_audit_logs';

		// Get all rows where expires_at is in the past and old_value is not yet purged
		$rows = $wpdb->get_results( "SELECT id, expires_at FROM $table WHERE old_value != '[Expired]'" );

		$now = time();
		foreach ( $rows as $row ) {
			$exp_ts = $this->ddmmyyyy_to_timestamp( $row->expires_at );
			if ( $now > $exp_ts ) {
				$wpdb->update(
					$table,
					array( 'old_value' => '[Expired]', 'new_value' => '[Expired]' ),
					array( 'id' => $row->id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
		}
	}

	/**
	 * Convert dd/mm/yyyy string to Unix timestamp.
	 */
	private function ddmmyyyy_to_timestamp( $date_str ) {
		$parts = explode( '/', $date_str );
		if ( count( $parts ) !== 3 ) {
			return 0;
		}
		return mktime( 23, 59, 59, intval( $parts[1] ), intval( $parts[0] ), intval( $parts[2] ) );
	}

	/* --------------------------------------------------------
	 * AJAX handlers
	 * -------------------------------------------------------- */

	public function ajax_undo() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$log_id = intval( $_POST['log_id'] ?? 0 );
		if ( ! $log_id ) {
			wp_send_json_error( 'Invalid log ID' );
		}

		$result = $this->undo( $log_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( 'Action undone successfully on ' . date( 'd/m/Y' ) );
	}

	public function ajax_get_logs() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'meesho_audit_logs';

		// Filters
		$where = '1=1';
		$params = array();

		if ( ! empty( $_POST['action_type'] ) ) {
			$where .= ' AND action_type = %s';
			$params[] = sanitize_text_field( $_POST['action_type'] );
		}
		if ( ! empty( $_POST['source'] ) ) {
			$where .= ' AND source = %s';
			$params[] = sanitize_text_field( $_POST['source'] );
		}
		if ( ! empty( $_POST['post_id'] ) ) {
			$where .= ' AND post_id = %d';
			$params[] = intval( $_POST['post_id'] );
		}

		// Date range filter (dd/mm/yyyy) — use STR_TO_DATE for sorting
		$order = "ORDER BY STR_TO_DATE(created_at, '%d/%m/%Y') DESC";
		$limit = 'LIMIT 50';

		if ( ! empty( $_POST['date_from'] ) ) {
			$where .= " AND STR_TO_DATE(created_at, '%d/%m/%Y') >= STR_TO_DATE(%s, '%d/%m/%Y')";
			$params[] = sanitize_text_field( $_POST['date_from'] );
		}
		if ( ! empty( $_POST['date_to'] ) ) {
			$where .= " AND STR_TO_DATE(created_at, '%d/%m/%Y') <= STR_TO_DATE(%s, '%d/%m/%Y')";
			$params[] = sanitize_text_field( $_POST['date_to'] );
		}

		$page   = max( 1, intval( $_POST['page'] ?? 1 ) );
		$offset = ( $page - 1 ) * 50;
		$limit  = "LIMIT 50 OFFSET $offset";

		if ( ! empty( $params ) ) {
			$query = $wpdb->prepare( "SELECT * FROM $table WHERE $where $order $limit", $params );
		} else {
			$query = "SELECT * FROM $table WHERE $where $order $limit";
		}

		$logs  = $wpdb->get_results( $query );
		$total = $wpdb->get_var(
			! empty( $params )
				? $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where", $params )
				: "SELECT COUNT(*) FROM $table WHERE $where"
		);

		wp_send_json_success( array(
			'logs'  => $logs,
			'total' => intval( $total ),
			'page'  => $page,
		) );
	}
}
