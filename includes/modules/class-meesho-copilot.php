<?php
/**
 * Meesho Master Copilot Module
 * AI chat assistant via OpenRouter. Auto-implement toggle, safety checks,
 * action logging with before/after snapshots. All dates dd/mm/yyyy.
 */

class Meesho_Master_Copilot {

	// Actions Copilot is NEVER allowed to perform
	private $forbidden_actions = array(
		'delete_site', 'drop_database', 'reveal_api_keys', 'reveal_passwords',
		'delete_wp_core', 'modify_wp_config',
	);

	public function __construct() {
		add_action( 'wp_ajax_meesho_copilot_chat', array( $this, 'ajax_chat' ) );
		add_action( 'wp_ajax_meesho_copilot_apply', array( $this, 'ajax_apply_action' ) );
		add_action( 'wp_ajax_meesho_copilot_history', array( $this, 'ajax_get_history' ) );
	}

	/* ---- Main chat handler ---- */

	public function ajax_chat() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$message = sanitize_textarea_field( $_POST['message'] ?? '' );
		$model   = sanitize_text_field( $_POST['model'] ?? '' );
		if ( empty( $message ) ) wp_send_json_error( 'Message is required' );

		$settings = new Meesho_Master_Settings();
		$api_key  = $settings->get( 'openrouter_api_key' );
		if ( empty( $api_key ) ) wp_send_json_error( 'OpenRouter API key not configured' );
		if ( empty( $model ) ) $model = $settings->get( 'ai_model_copilot' ) ?: 'openai/gpt-3.5-turbo';

		// Build context (current page data, no API keys)
		$context = $this->build_context();

		$system_prompt = "You are Meesho Master Copilot, an AI assistant for WordPress/WooCommerce store management. "
			. "You can help with: product management, SEO optimization, order tracking, content creation, analytics insights. "
			. "You CANNOT: delete the WordPress site, drop the database, reveal API keys or passwords. "
			. "When suggesting destructive actions, always output a structured JSON action block for user approval. "
			. "Current date: " . date( 'd/m/Y' ) . "\n\nSite context:\n" . $context;

		$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'    => $model,
				'messages' => array(
					array( 'role' => 'system', 'content' => $system_prompt ),
					array( 'role' => 'user', 'content' => $message ),
				),
				'temperature' => 0.5,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'AI service error: ' . $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		$reply = $data['choices'][0]['message']['content'] ?? 'Sorry, I could not generate a response.';

		// Check for action blocks in the response
		$actions = $this->extract_actions( $reply );
		$auto_implement = $settings->get( 'copilot_auto_implement' ) === 'yes';

		// Auto-implement safe actions if toggle is on
		$applied = array();
		if ( $auto_implement && ! empty( $actions ) ) {
			foreach ( $actions as $action ) {
				if ( $this->is_safe_action( $action ) ) {
					$result = $this->execute_action( $action );
					if ( $result === true ) $applied[] = $action;
				}
			}
		}

		wp_send_json_success( array(
			'reply'           => $reply,
			'actions'         => $actions,
			'auto_applied'    => $applied,
			'auto_implement'  => $auto_implement,
			'timestamp'       => date( 'd/m/Y' ),
		) );
	}

	/* ---- Build context (no secrets) ---- */

	private function build_context() {
		$ctx = array();
		$ctx[] = 'Site: ' . get_bloginfo( 'name' );
		$ctx[] = 'URL: ' . home_url();
		$ctx[] = 'WooCommerce: ' . ( class_exists( 'WooCommerce' ) ? 'Active' : 'Not installed' );

		// Product count
		$product_count = wp_count_posts( 'product' );
		$ctx[] = 'Published products: ' . ( $product_count->publish ?? 0 );

		// Recent orders
		$order_count = wp_count_posts( 'shop_order' );
		$ctx[] = 'Total orders: ' . array_sum( (array) $order_count );

		// Active SEO plugin
		$seo = new Meesho_Master_SEO();
		$ctx[] = 'SEO plugin: ' . $seo->detect_seo_plugin();

		return implode( "\n", $ctx );
	}

	/* ---- Extract action blocks from AI response ---- */

	private function extract_actions( $reply ) {
		$actions = array();
		// Look for JSON action blocks: ```json { "action": ... } ```
		if ( preg_match_all( '/```json\s*(\{[^`]+\})\s*```/s', $reply, $matches ) ) {
			foreach ( $matches[1] as $json_str ) {
				$parsed = json_decode( $json_str, true );
				if ( $parsed && isset( $parsed['action'] ) ) {
					$actions[] = $parsed;
				}
			}
		}
		return $actions;
	}

	/* ---- Safety check ---- */

	private function is_safe_action( $action ) {
		$type = $action['action'] ?? '';
		if ( in_array( $type, $this->forbidden_actions, true ) ) return false;

		// Content rewrites need manual approval
		if ( $type === 'rewrite_content' ) return false;

		// Deletions need manual approval
		if ( strpos( $type, 'delete' ) !== false ) return false;

		return true;
	}

	/* ---- Execute an approved action ---- */

	public function execute_action( $action ) {
		$undo = new Meesho_Master_Undo();
		$type = $action['action'] ?? '';
		$post_id = intval( $action['post_id'] ?? 0 );

		switch ( $type ) {
			case 'update_meta_title':
			case 'update_meta_desc':
				$seo = new Meesho_Master_SEO();
				$keys = $seo->get_meta_keys();
				$meta_key = ( $type === 'update_meta_title' ) ? $keys['title'] : $keys['desc'];
				$old = get_post_meta( $post_id, $meta_key, true );
				$undo->log( 'meta_update', $post_id,
					wp_json_encode( array( 'meta_key' => $meta_key, 'meta_value' => $old ) ),
					wp_json_encode( array( 'meta_key' => $meta_key, 'meta_value' => $action['value'] ) ),
					'copilot'
				);
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $action['value'] ) );
				return true;

			case 'update_post_title':
				$old = get_the_title( $post_id );
				$undo->log( 'post_update', $post_id,
					wp_json_encode( array( 'post_title' => $old ) ),
					wp_json_encode( array( 'post_title' => $action['value'] ) ),
					'copilot'
				);
				wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $action['value'] ) ) );
				return true;

			case 'unpublish':
				$undo->log( 'post_update', $post_id,
					wp_json_encode( array( 'post_status' => get_post_status( $post_id ) ) ),
					wp_json_encode( array( 'post_status' => 'draft' ) ),
					'copilot'
				);
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
				return true;

			case 'update_product_price':
				if ( function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $post_id );
					if ( $product ) {
						$old_price = $product->get_regular_price();
						$undo->log( 'meta_update', $post_id,
							wp_json_encode( array( 'meta_key' => '_regular_price', 'meta_value' => $old_price ) ),
							wp_json_encode( array( 'meta_key' => '_regular_price', 'meta_value' => $action['value'] ) ),
							'copilot'
						);
						$product->set_regular_price( $action['value'] );
						$product->save();
					}
				}
				return true;

			default:
				return false;
		}
	}

	/* ---- AJAX: Apply a pending action ---- */

	public function ajax_apply_action() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$action_json = isset( $_POST['action_data'] ) ? wp_unslash( $_POST['action_data'] ) : '';
		$action = json_decode( $action_json, true );
		if ( ! $action || ! isset( $action['action'] ) ) wp_send_json_error( 'Invalid action data' );

		if ( in_array( $action['action'], $this->forbidden_actions, true ) ) {
			wp_send_json_error( 'This action is not allowed.' );
		}

		$result = $this->execute_action( $action );
		$result ? wp_send_json_success( 'Action applied on ' . date( 'd/m/Y' ) )
		        : wp_send_json_error( 'Unknown action type' );
	}

	/* ---- AJAX: Chat history ---- */

	public function ajax_get_history() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		global $wpdb;
		$logs = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}meesho_audit_logs WHERE source = 'copilot' ORDER BY STR_TO_DATE(created_at, '%%d/%%m/%%Y') DESC LIMIT 50"
		) );
		wp_send_json_success( $logs );
	}
}
