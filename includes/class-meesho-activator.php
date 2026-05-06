<?php

class Meesho_Master_Activator {

	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = array();

		// wp_meesho_seo_suggestions
		$sql[] = "CREATE TABLE {$wpdb->prefix}meesho_seo_suggestions (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) NOT NULL,
			type VARCHAR(50) NOT NULL,
			current_value LONGTEXT,
			suggested_value LONGTEXT,
			reasoning TEXT,
			priority VARCHAR(10) NOT NULL,
			confidence TINYINT(3) NOT NULL,
			safe_to_apply TINYINT(1) NOT NULL,
			status VARCHAR(20) NOT NULL,
			created_at VARCHAR(20) NOT NULL,
			applied_at VARCHAR(20),
			PRIMARY KEY  (id)
		) $charset_collate;";

		// wp_meesho_audit_logs
		$sql[] = "CREATE TABLE {$wpdb->prefix}meesho_audit_logs (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20),
			action_type VARCHAR(50) NOT NULL,
			old_value LONGTEXT,
			new_value LONGTEXT,
			source VARCHAR(20) NOT NULL,
			user_id BIGINT(20) NOT NULL,
			created_at VARCHAR(20) NOT NULL,
			expires_at VARCHAR(20) NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		// wp_meesho_run_history
		$sql[] = "CREATE TABLE {$wpdb->prefix}meesho_run_history (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			run_type VARCHAR(50) NOT NULL,
			status VARCHAR(20) NOT NULL,
			pages_processed INT(11) NOT NULL DEFAULT 0,
			suggestions_generated INT(11) NOT NULL DEFAULT 0,
			suggestions_applied INT(11) NOT NULL DEFAULT 0,
			error_message TEXT,
			started_at VARCHAR(20) NOT NULL,
			completed_at VARCHAR(20) NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		// wp_meesho_products
		$sql[] = "CREATE TABLE {$wpdb->prefix}meesho_products (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			meesho_sku VARCHAR(191) NOT NULL,
			wc_product_id BIGINT(20) NOT NULL,
			meesho_url TEXT,
			import_date VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			PRIMARY KEY  (id),
			UNIQUE KEY meesho_sku (meesho_sku)
		) $charset_collate;";

		// wp_meesho_reviews
		$sql[] = "CREATE TABLE {$wpdb->prefix}meesho_reviews (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			meesho_sku VARCHAR(255) NOT NULL,
			reviewer_name VARCHAR(255) NOT NULL,
			review_text TEXT,
			star_rating TINYINT(3) NOT NULL,
			review_date VARCHAR(20) NOT NULL,
			review_images TEXT,
			PRIMARY KEY  (id)
		) $charset_collate;";

		// wp_meesho_orders
		$sql[] = "CREATE TABLE {$wpdb->prefix}meesho_orders (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			wc_order_id BIGINT(20) NOT NULL,
			meesho_order_id VARCHAR(255),
			tracking_id VARCHAR(255),
			account_used VARCHAR(255),
			fulfillment_status VARCHAR(50) NOT NULL DEFAULT 'pending',
			sla_status VARCHAR(20) NOT NULL DEFAULT 'ok',
			notes TEXT,
			created_at VARCHAR(20) NOT NULL,
			updated_at VARCHAR(20) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wc_order_id (wc_order_id)
		) $charset_collate;";

		// wp_meesho_customers
		$sql[] = "CREATE TABLE {$wpdb->prefix}meesho_customers (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			phone_number VARCHAR(20) NOT NULL,
			risk_score INT(11) NOT NULL DEFAULT 0,
			history_summary TEXT,
			created_at VARCHAR(20) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY phone_number (phone_number)
		) $charset_collate;";

		// wp_meesho_gsc_snapshots
		$sql[] = "CREATE TABLE {$wpdb->prefix}meesho_gsc_snapshots (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			keyword VARCHAR(255) NOT NULL,
			url TEXT NOT NULL,
			position FLOAT NOT NULL,
			impressions INT(11) NOT NULL,
			ctr FLOAT NOT NULL,
			snapshot_date VARCHAR(20) NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		// Add default options if not set
		if ( false === get_option( 'meesho_master_settings' ) ) {
			add_option( 'meesho_master_settings', array(
				'pricing_markup_type' => 'percentage',
				'pricing_markup_value' => '20',
				'pricing_rounding' => 'none',
				'scrapling_url' => 'http://localhost:5000/scrape',
				'scrapling_timeout' => '30',
				'cod_risk_threshold' => '2000',
				'copilot_auto_implement' => 'no',
			) );
		}
	}

}
