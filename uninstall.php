<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop all plugin-created tables
$tables = array(
	'meesho_seo_suggestions',
	'meesho_audit_logs',
	'meesho_run_history',
	'meesho_products',
	'meesho_reviews',
	'meesho_orders',
	'meesho_customers',
	'meesho_gsc_snapshots',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
}

// Remove plugin options
delete_option( 'meesho_master_settings' );
delete_option( 'meesho_master_accounts' );
delete_option( 'meesho_seo_crawl_offset' );

// Clear scheduled cron hooks
wp_clear_scheduled_hook( 'meesho_seo_batch_process' );
wp_clear_scheduled_hook( 'meesho_purge_old_snapshots' );
