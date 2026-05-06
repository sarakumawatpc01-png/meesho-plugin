<?php
/**
 * Plugin Name: Meesho Master
 * Plugin URI:  
 * Description: A complete Meesho-to-WooCommerce automation and management suite.
 * Version:     6.0.0
 * Author:      
 * Text Domain: meesho-master
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'MEESHO_MASTER_VERSION', '6.0.0' );
define( 'MEESHO_MASTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEESHO_MASTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! function_exists( 'meesho_master_verify_ajax_nonce' ) ) {
	function meesho_master_verify_ajax_nonce() {
		$nonce = '';
		if ( isset( $_REQUEST['nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) );
		}

		$valid = ! empty( $nonce ) && ( wp_verify_nonce( $nonce, 'mm_nonce' ) || wp_verify_nonce( $nonce, 'meesho_nonce' ) );
		if ( ! $valid ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ), 403 );
		}
	}
}

require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-meesho-master.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-meesho-activator.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-meesho-deactivator.php';

if ( ! function_exists( 'activate_meesho_master' ) ) {
	function activate_meesho_master() {
		Meesho_Master_Activator::activate();
	}
}

if ( ! function_exists( 'deactivate_meesho_master' ) ) {
	function deactivate_meesho_master() {
		Meesho_Master_Deactivator::deactivate();
	}
}

register_activation_hook( __FILE__, 'activate_meesho_master' );
register_deactivation_hook( __FILE__, 'deactivate_meesho_master' );

if ( ! function_exists( 'run_meesho_master' ) ) {
	function run_meesho_master() {
		$plugin = new Meesho_Master();
		$plugin->run();
	}
}
run_meesho_master();
