<?php

if ( ! class_exists( 'MM_Logger' ) ) {
	class MM_Logger {

		public function log_before_change( $action_type, $post_id, $old_value, $new_value, $source = 'system' ) {
			global $wpdb;

			$table = $wpdb->prefix . 'meesho_audit_logs';
			$today = date( 'd/m/Y' );

			return false !== $wpdb->insert(
				$table,
				array(
					'post_id'     => intval( $post_id ),
					'action_type' => sanitize_text_field( $action_type ),
					'old_value'   => is_string( $old_value ) ? $old_value : wp_json_encode( $old_value ),
					'new_value'   => is_string( $new_value ) ? $new_value : wp_json_encode( $new_value ),
					'source'      => sanitize_text_field( $source ),
					'user_id'     => get_current_user_id(),
					'created_at'  => $today,
					'expires_at'  => date( 'd/m/Y', strtotime( '+15 days' ) ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}
}
