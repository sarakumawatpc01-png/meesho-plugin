<?php

class Meesho_Master_Copilot {
private $forbidden_tokens = array( 'DROP', 'TRUNCATE', 'DELETE FROM', 'wp_delete_site' );
private $allowed_actions = array( 'update_meta_title', 'update_meta_desc', 'update_post_title', 'update_post_content', 'unpublish', 'update_product_price', 'apply_seo_suggestion' );

public function __construct() {
add_action( 'wp_ajax_meesho_copilot_chat', array( $this, 'ajax_chat' ) );
add_action( 'wp_ajax_meesho_copilot_apply', array( $this, 'ajax_apply_action' ) );
add_action( 'wp_ajax_meesho_copilot_history', array( $this, 'ajax_get_history' ) );
add_action( 'wp_ajax_meesho_copilot_undo_last', array( $this, 'ajax_undo_last' ) );
}

public function ajax_chat() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
if ( 'no' === ( new Meesho_Master_Settings() )->get( 'mm_copilot_enabled', 'yes' ) ) {
wp_send_json_error( array( 'message' => 'Copilot is disabled.' ), 403 );
}
$message   = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
$model     = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
$thread_key = sanitize_key( wp_unslash( $_POST['thread_key'] ?? '' ) );
if ( '' === $message ) {
wp_send_json_error( array( 'message' => 'Message is required' ), 400 );
}
$settings = new Meesho_Master_Settings();
$api_key  = $settings->get( 'openrouter_api_key' );
if ( '' === $api_key ) {
wp_send_json_error( array( 'message' => 'OpenRouter API key not configured' ), 400 );
}
if ( '' === $model ) {
$model = $settings->get( 'ai_model_copilot' ) ?: 'openai/gpt-4o-mini';
}
if ( '' === $thread_key ) {
$thread_key = 'thread_' . wp_generate_uuid4();
}
$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
'timeout' => 30,
'headers' => array( 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ),
'body'    => wp_json_encode( array(
'model'    => $model,
'messages' => array(
array( 'role' => 'system', 'content' => $this->build_system_prompt() ),
array( 'role' => 'user', 'content' => $message ),
),
) ),
) );
if ( is_wp_error( $response ) ) {
wp_send_json_error( array( 'message' => $response->get_error_message() ), 400 );
}
$body   = json_decode( wp_remote_retrieve_body( $response ), true );
$reply  = $this->scrub_secret_output( (string) ( $body['choices'][0]['message']['content'] ?? '' ) );
$actions = $this->extract_actions( $reply );
$applied = array();
$auto = 'yes' === $settings->get( 'copilot_auto_implement', 'no' );
foreach ( $actions as $action ) {
if ( ! empty( $action['is_destructive'] ) ) {
continue;
}
if ( $auto && $this->is_allowed_action( $action ) ) {
$result = $this->execute_action( $action );
if ( ! is_wp_error( $result ) ) {
$applied[] = $action;
}
}
}
$this->persist_thread( $thread_key, $message, $reply, $applied );
wp_send_json_success( array( 'reply' => $reply, 'actions' => $actions, 'auto_applied' => $applied, 'auto_implement' => $auto, 'thread_key' => $thread_key, 'timestamp' => wp_date( 'd/m/Y H:i' ) ) );
}

private function build_system_prompt() {
return 'You are Meesho Master Copilot. Only propose allowlisted admin actions. Always emit JSON action blocks in fenced ```json blocks with keys action, params, explanation, is_destructive. Refuse anything involving DROP, TRUNCATE, DELETE FROM, wp_delete_site, secrets, or disabled tools.';
}

private function scrub_secret_output( $text ) {
$settings = new Meesho_Master_Settings();
foreach ( $settings->get_all() as $key => $value ) {
if ( preg_match( '/(key|secret|credentials)$/i', $key ) && is_string( $value ) && '' !== $value ) {
$text = str_replace( $value, '[REDACTED]', $text );
}
}
return $text;
}

private function extract_actions( $reply ) {
$actions = array();
if ( preg_match_all( '/```json\s*(\{.*?\})\s*```/is', $reply, $matches ) ) {
foreach ( $matches[1] as $block ) {
$decoded = json_decode( $block, true );
if ( is_array( $decoded ) && ! empty( $decoded['action'] ) ) {
$actions[] = $decoded;
}
}
}
return $actions;
}

private function is_allowed_action( $action ) {
$action_name = strtoupper( (string) ( $action['action'] ?? '' ) );
foreach ( $this->forbidden_tokens as $token ) {
if ( false !== strpos( $action_name, $token ) ) {
return false;
}
}
$params_json = wp_json_encode( $action['params'] ?? array() );
if ( preg_match( '/^mm_.*(key|secret|credentials)/i', $params_json ) ) {
return false;
}
return in_array( $action['action'] ?? '', $this->allowed_actions, true );
}

public function execute_action( $action ) {
if ( ! $this->is_allowed_action( $action ) ) {
return new WP_Error( 'forbidden', 'This action is not allowed.' );
}
$params = $action['params'] ?? array();
$actor  = 'copilot';
switch ( $action['action'] ) {
case 'update_meta_title':
case 'update_meta_desc':
$suggestion = array(
'id' => 0,
'post_id' => absint( $params['post_id'] ?? 0 ),
'type' => 'update_meta_title' === $action['action'] ? 'meta_title' : 'meta_desc',
'current_value' => '',
'suggested_value' => sanitize_text_field( $params['value'] ?? '' ),
);
return ( new MM_SEO_Implementor() )->apply( $suggestion, $actor );
case 'update_post_title':
$post_id = absint( $params['post_id'] ?? 0 );
$new_title = sanitize_text_field( $params['value'] ?? '' );
( new MM_Logger() )->log_before_change( 'copilot_edit', 'post', $post_id, get_the_title( $post_id ), $new_title, 0, $actor, 'title' );
wp_update_post( array( 'ID' => $post_id, 'post_title' => $new_title ) );
return true;
case 'update_post_content':
$post_id = absint( $params['post_id'] ?? 0 );
$new_content = wp_kses_post( $params['value'] ?? '' );
( new MM_Logger() )->log_before_change( 'copilot_edit', 'post', $post_id, get_post_field( 'post_content', $post_id ), $new_content, 0, $actor, 'content' );
wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );
return true;
case 'unpublish':
$post_id = absint( $params['post_id'] ?? 0 );
( new MM_Logger() )->log_before_change( 'copilot_edit', 'post', $post_id, get_post_status( $post_id ), 'draft', 0, $actor, 'status' );
wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
return true;
case 'update_product_price':
$product = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $params['post_id'] ?? 0 ) ) : null;
if ( ! $product ) {
return new WP_Error( 'not_found', 'Product not found.' );
}
$new_price = wc_format_decimal( $params['value'] ?? '' );
( new MM_Logger() )->log_before_change( 'copilot_edit', 'post', $product->get_id(), $product->get_regular_price(), $new_price, 0, $actor, 'price' );
$product->set_regular_price( $new_price );
$product->save();
return true;
case 'apply_seo_suggestion':
return ( new Meesho_Master_SEO() )->apply_suggestion( absint( $params['suggestion_id'] ?? 0 ), $actor );
}
return new WP_Error( 'unknown_action', 'Unknown action type.' );
}

private function persist_thread( $thread_key, $message, $reply, $applied ) {
global $wpdb;
$table = MM_DB::table( 'copilot_threads' );
$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE thread_key = %s", $thread_key ) );
$messages = $existing ? json_decode( $existing->messages, true ) : array();
if ( ! is_array( $messages ) ) {
$messages = array();
}
$messages[] = array( 'role' => 'user', 'content' => $message, 'timestamp' => current_time( 'mysql' ) );
$messages[] = array( 'role' => 'assistant', 'content' => $reply, 'timestamp' => current_time( 'mysql' ), 'action_taken' => $applied );
$payload = array( 'title' => wp_trim_words( $message, 8 ), 'messages' => wp_json_encode( $messages ), 'updated_at' => current_time( 'mysql' ) );
if ( $existing ) {
$wpdb->update( $table, $payload, array( 'id' => $existing->id ), array( '%s', '%s', '%s' ), array( '%d' ) );
} else {
$wpdb->insert( $table, array_merge( array( 'thread_key' => $thread_key, 'created_at' => current_time( 'mysql' ) ), $payload ), array( '%s', '%s', '%s', '%s', '%s' ) );
}
}

public function ajax_apply_action() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$action = json_decode( wp_unslash( $_POST['action_data'] ?? '' ), true );
if ( ! is_array( $action ) ) {
wp_send_json_error( array( 'message' => 'Invalid action data' ), 400 );
}
$result = $this->execute_action( $action );
if ( is_wp_error( $result ) ) {
wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
}
wp_send_json_success( 'Action applied.' );
}

public function ajax_get_history() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
global $wpdb;
$table = MM_DB::table( 'copilot_threads' );
$thread_key = sanitize_key( wp_unslash( $_POST['thread_key'] ?? '' ) );
if ( $thread_key ) {
$thread = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE thread_key = %s", $thread_key ) );
wp_send_json_success( $thread ? json_decode( $thread->messages, true ) : array() );
}
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT thread_key, title, updated_at FROM {$table} ORDER BY updated_at DESC LIMIT %d", 20 ) );
wp_send_json_success( $rows );
}

public function ajax_undo_last() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$result = ( new Meesho_Master_Undo() )->revert_last( get_current_user_id() );
if ( is_wp_error( $result ) ) {
wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
}
wp_send_json_success( 'Last Copilot action undone.' );
}
}
