<?php

class Meesho_Master_Settings {
private $option_key = 'meesho_master_settings';
private $accounts_key = 'meesho_master_accounts';
private $encrypted_fields = array( 'openrouter_api_key', 'dataforseo_login', 'dataforseo_password', 'firecrawl_api_key', 'gsc_client_id', 'gsc_client_secret', 'gsc_refresh_token', 'mm_gsc_credentials' );
private $crypto;

public function __construct() {
$this->crypto = new MM_Crypto();
add_action( 'wp_ajax_meesho_save_settings', array( $this, 'ajax_save_settings' ) );
add_action( 'wp_ajax_meesho_save_accounts', array( $this, 'ajax_save_accounts' ) );
add_action( 'wp_ajax_meesho_test_email', array( $this, 'ajax_test_email' ) );
}

private function should_encrypt( $key ) {
return in_array( $key, $this->encrypted_fields, true ) || (bool) preg_match( '/(key|secret|credentials)$/i', $key );
}

public function encrypt( $plain ) { return $this->crypto->encrypt( $plain ); }
public function decrypt( $encoded ) { return $this->crypto->decrypt( $encoded ); }

public function get_all() {
$settings = get_option( $this->option_key, array() );
$settings = wp_parse_args( $settings, $this->defaults() );
foreach ( $settings as $key => $value ) {
if ( $this->should_encrypt( $key ) ) {
$settings[ $key ] = $this->decrypt( $value );
}
}
return $settings;
}

public function get( $key, $default = '' ) {
$all = $this->get_all();
return isset( $all[ $key ] ) ? $all[ $key ] : $default;
}

public function set( $key, $value ) {
$all = get_option( $this->option_key, array() );
$all[ $key ] = $this->should_encrypt( $key ) ? $this->encrypt( $value ) : $value;
update_option( $this->option_key, $all );
}

public function save_bulk( $data ) {
$all = get_option( $this->option_key, array() );
foreach ( $data as $key => $value ) {
$clean = is_array( $value ) ? wp_json_encode( $value ) : sanitize_text_field( wp_unslash( $value ) );
$all[ $key ] = $this->should_encrypt( $key ) ? $this->encrypt( $clean ) : $clean;
}
update_option( $this->option_key, wp_parse_args( $all, $this->defaults() ) );
}

public function defaults() {
return array(
'pricing_markup_type'    => 'percentage',
'pricing_markup_value'   => '20',
'pricing_rounding'       => 'none',
'scrapling_url'          => 'http://localhost:5000/scrape',
'scrapling_timeout'      => '30',
'openrouter_api_key'     => '',
'ai_model_seo'           => '',
'ai_model_blog'          => '',
'ai_model_image'         => '',
'ai_model_copilot'       => '',
'ai_model_schema'        => '',
'ai_model_aeo'           => '',
'ai_model_geo'           => '',
'ai_show_free_only'      => 'no',
'cod_risk_threshold'     => '2000',
'cod_repeat_window_hrs'  => '24',
'copilot_auto_implement' => 'no',
'mm_copilot_enabled'     => 'yes',
'automation_enabled'     => 'yes',
'automation_time_1'      => '08:00',
'automation_time_2'      => '20:00',
'automation_batch_size'  => '5',
'automation_delay_ms'    => '500',
'mm_seo_max_suggestions' => '10',
'email_recipients'       => '',
'email_from_override'    => '',
'email_frequency'        => 'daily',
'email_pdf_library'      => 'dompdf',
'hotjar_site_id'         => '',
'mm_hotjar_id'           => '',
'gsc_client_id'          => '',
'gsc_client_secret'      => '',
'gsc_refresh_token'      => '',
'mm_gsc_credentials'     => '',
'dataforseo_login'       => '',
'dataforseo_password'    => '',
'firecrawl_api_key'      => '',
'llms_txt_config'        => "User-agent: GPTBot\nAllow: /\n\nUser-agent: ClaudeBot\nAllow: /\n",
);
}

public function get_accounts() {
$enc = get_option( $this->accounts_key, '' );
if ( '' === $enc ) {
return array();
}
$accounts = json_decode( $this->decrypt( $enc ), true );
return is_array( $accounts ) ? $accounts : array();
}

public function save_accounts( $accounts ) {
update_option( $this->accounts_key, $this->encrypt( wp_json_encode( array_slice( $accounts, 0, 4 ) ) ) );
}

public function calculate_selling_price( $meesho_price, $override_type = null, $override_value = null ) {
$type  = $override_type ? $override_type : $this->get( 'pricing_markup_type' );
$value = $override_value ? $override_value : $this->get( 'pricing_markup_value' );
$price = 'flat' === $type ? (float) $meesho_price + (float) $value : (float) $meesho_price * ( 1 + ( (float) $value / 100 ) );
return $this->apply_rounding( $price );
}

public function apply_rounding( $price ) {
switch ( $this->get( 'pricing_rounding' ) ) {
case '1': return ceil( $price );
case '5': return ceil( $price / 5 ) * 5;
case '9': return floor( $price / 10 ) * 10 + 9;
case '10': return ceil( $price / 10 ) * 10;
default: return round( $price, 2 );
}
}

public function ajax_save_settings() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 ); }
$fields = $_POST;
unset( $fields['action'], $fields['nonce'] );
$this->save_bulk( $fields );
wp_send_json_success( 'Settings saved.' );
}

public function ajax_save_accounts() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 ); }
$accounts = json_decode( wp_unslash( $_POST['accounts'] ?? '[]' ), true );
if ( ! is_array( $accounts ) ) { wp_send_json_error( array( 'message' => 'Invalid accounts data' ), 400 ); }
$clean = array();
foreach ( $accounts as $acc ) {
$clean[] = array(
'label' => sanitize_text_field( $acc['label'] ?? '' ),
'email' => sanitize_email( $acc['email'] ?? '' ),
'phone' => sanitize_text_field( $acc['phone'] ?? '' ),
'notes' => sanitize_textarea_field( $acc['notes'] ?? '' ),
);
}
$this->save_accounts( $clean );
wp_send_json_success( 'Accounts saved.' );
}

public function ajax_test_email() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 ); }
$to = $this->get( 'email_recipients' );
if ( '' === $to ) { wp_send_json_error( array( 'message' => 'No email recipients configured' ), 400 ); }
$from = $this->get( 'email_from_override' );
if ( '' === $from ) { $from = get_option( 'admin_email' ); }
add_filter( 'wp_mail_from', static function () use ( $from ) { return $from; } );
$sent = wp_mail( $to, 'Meesho Master — Test Email (' . wp_date( 'd/m/Y' ) . ')', 'This is a test email from Meesho Master.', array( 'Content-Type: text/html; charset=UTF-8' ) );
$sent ? wp_send_json_success( 'Test email sent successfully.' ) : wp_send_json_error( array( 'message' => 'Failed to send test email.' ), 400 );
}
}
