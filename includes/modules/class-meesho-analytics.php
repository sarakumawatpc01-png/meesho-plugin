<?php

class Meesho_Master_Analytics {
public function __construct() {
add_action( 'wp_ajax_meesho_get_rankings', array( $this, 'ajax_get_rankings' ) );
add_action( 'wp_ajax_meesho_add_keyword', array( $this, 'ajax_add_keyword' ) );
add_action( 'wp_ajax_meesho_send_report', array( $this, 'ajax_send_report' ) );
add_action( 'wp_ajax_meesho_get_heatmap_insights', array( $this, 'ajax_heatmap_insights' ) );
add_action( 'wp_head', array( $this, 'inject_hotjar' ) );
add_action( 'meesho_email_report', array( $this, 'send_scheduled_report' ) );
if ( ! wp_next_scheduled( 'meesho_email_report' ) ) {
wp_schedule_event( time(), 'daily', 'meesho_email_report' );
}
}

public function inject_hotjar() {
$settings = new Meesho_Master_Settings();
$site_id  = $settings->get( 'hotjar_site_id' ) ?: $settings->get( 'mm_hotjar_id' );
if ( empty( $site_id ) ) {
return;
}
echo "<!-- Hotjar Tracking Code -->\n<script>(function(h,o,t,j,a,r){h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};h._hjSettings={hjid:" . intval( $site_id ) . ",hjsv:6};a=o.getElementsByTagName('head')[0];r=o.createElement('script');r.async=1;r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;a.appendChild(r);})(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');</script>\n";
}

public function fetch_gsc_data( $keyword ) {
$cache_key = 'mm_gsc_' . md5( $keyword );
$cached = get_transient( $cache_key );
if ( false !== $cached ) {
return $cached;
}
$settings = new Meesho_Master_Settings();
$token = $settings->get( 'gsc_refresh_token' );
if ( empty( $token ) ) {
return new WP_Error( 'no_gsc', 'GSC not connected' );
}
$access_token = $this->refresh_gsc_token();
if ( is_wp_error( $access_token ) ) {
return $access_token;
}
$site_url = home_url();
$response = wp_remote_post( 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query', array(
'timeout' => 15,
'headers' => array(
'Authorization' => 'Bearer ' . $access_token,
'Content-Type'  => 'application/json',
),
'body' => wp_json_encode( array(
'startDate'  => date( 'Y-m-d', strtotime( '-30 days' ) ),
'endDate'    => date( 'Y-m-d' ),
'dimensions' => array( 'query', 'page' ),
'dimensionFilterGroups' => array( array( 'filters' => array( array( 'dimension' => 'query', 'operator' => 'contains', 'expression' => $keyword ) ) ) ),
'rowLimit' => 50,
) ),
) );
if ( is_wp_error( $response ) ) {
return $response;
}
$rows = json_decode( wp_remote_retrieve_body( $response ), true );
$rows = $rows['rows'] ?? array();
set_transient( $cache_key, $rows, DAY_IN_SECONDS );
return $rows;
}

private function refresh_gsc_token() {
$settings = new Meesho_Master_Settings();
$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
'body' => array(
'client_id'     => $settings->get( 'gsc_client_id' ),
'client_secret' => $settings->get( 'gsc_client_secret' ),
'refresh_token' => $settings->get( 'gsc_refresh_token' ),
'grant_type'    => 'refresh_token',
),
) );
if ( is_wp_error( $response ) ) {
return $response;
}
$data = json_decode( wp_remote_retrieve_body( $response ), true );
return ! empty( $data['access_token'] ) ? $data['access_token'] : new WP_Error( 'gsc_auth', 'GSC auth expired — reconnect in Settings.' );
}

public function save_snapshot( $keyword, $gsc_rows ) {
global $wpdb;
$table = MM_DB::table( 'ranking_data' );
foreach ( $gsc_rows as $row ) {
$wpdb->insert( $table, array(
'keyword'     => sanitize_text_field( $keyword ),
'page_url'    => esc_url_raw( $row['keys'][1] ?? '' ),
'position'    => (float) ( $row['position'] ?? 0 ),
'impressions' => (int) ( $row['impressions'] ?? 0 ),
'clicks'      => (int) ( $row['clicks'] ?? 0 ),
'ctr'         => (float) ( $row['ctr'] ?? 0 ),
'source'      => 'gsc',
'recorded_at' => current_time( 'Y-m-d' ),
), array( '%s', '%s', '%f', '%d', '%d', '%f', '%s', '%s' ) );
}
}

public function generate_report_html() {
global $wpdb;
$today        = wp_date( 'd/m/Y' );
$site_name    = get_bloginfo( 'name' );
$order_count  = wp_count_posts( 'shop_order' );
$product_count = wp_count_posts( 'product' );
$suggestions_table = MM_DB::table( 'seo_suggestions' );
$score_table       = MM_DB::table( 'seo_post_scores' );
$pending           = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$suggestions_table} WHERE status = %s", 'pending' ) );
$applied_today     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$suggestions_table} WHERE DATE(applied_at) = %s", current_time( 'Y-m-d' ) ) );
$avg_scores        = $wpdb->get_row( "SELECT AVG(seo_score) AS seo, AVG(aeo_score) AS aeo, AVG(geo_score) AS geo FROM {$score_table}" );
$html  = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>";
$html .= "<h1 style='color:#6C2EB9;'>📊 Meesho Master Report — {$today}</h1>";
$html .= "<p>Site: <strong>{$site_name}</strong></p><hr>";
$html .= '<h2>📦 Store Overview</h2><ul>';
$html .= '<li>Published Products: ' . ( $product_count->publish ?? 0 ) . '</li>';
$html .= '<li>Total Orders: ' . array_sum( (array) $order_count ) . '</li>';
$html .= '</ul><h2>🔍 SEO / AEO / GEO Scores</h2><ul>';
$html .= '<li>Avg SEO: ' . round( $avg_scores->seo ?? 0 ) . '/100</li>';
$html .= '<li>Avg AEO: ' . round( $avg_scores->aeo ?? 0 ) . '/100</li>';
$html .= '<li>Avg GEO: ' . round( $avg_scores->geo ?? 0 ) . '/100</li>';
$html .= '</ul><h2>🤖 AI Actions</h2><ul>';
$html .= '<li>Pending Suggestions: ' . $pending . '</li>';
$html .= '<li>Applied Today: ' . $applied_today . '</li>';
$html .= '</ul><hr><p style="color:#888;font-size:12px;">Generated by Meesho Master v6 on ' . esc_html( $today ) . '</p></body></html>';
return $html;
}

public function send_scheduled_report() {
$settings   = new Meesho_Master_Settings();
$recipients = $settings->get( 'email_recipients' );
if ( empty( $recipients ) ) {
return;
}
$from = $settings->get( 'email_from_override' );
if ( empty( $from ) ) {
$from = get_option( 'admin_email' );
}
add_filter( 'wp_mail_from', static function () use ( $from ) { return $from; } );
wp_mail( $recipients, 'Meesho Master Report — ' . wp_date( 'd/m/Y' ), $this->generate_report_html(), array( 'Content-Type: text/html; charset=UTF-8' ) );
}

public function ajax_get_rankings() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
global $wpdb;
$table = MM_DB::table( 'ranking_data' );
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY recorded_at DESC LIMIT %d", 100 ) );
wp_send_json_success( $rows );
}

public function ajax_add_keyword() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
if ( '' === $keyword ) {
wp_send_json_error( array( 'message' => 'Keyword required' ), 400 );
}
$rows = $this->fetch_gsc_data( $keyword );
if ( is_wp_error( $rows ) ) {
wp_send_json_error( array( 'message' => $rows->get_error_message() ), 400 );
}
$this->save_snapshot( $keyword, $rows );
wp_send_json_success( array( 'keyword' => $keyword, 'rows' => $rows, 'date' => wp_date( 'd/m/Y' ) ) );
}

public function ajax_send_report() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$this->send_scheduled_report();
wp_send_json_success( 'Report sent.' );
}

public function ajax_heatmap_insights() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$settings = new Meesho_Master_Settings();
$api_key  = $settings->get( 'openrouter_api_key' );
if ( empty( $api_key ) ) {
wp_send_json_error( array( 'message' => 'OpenRouter API key required' ), 400 );
}
$summary = array_slice( (array) $this->fetch_gsc_data( '' ), 0, 5 );
$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
'timeout' => 15,
'headers' => array( 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ),
'body'    => wp_json_encode( array(
'model' => $settings->get( 'ai_model_seo' ) ?: 'openai/gpt-4o-mini',
'messages' => array(
array( 'role' => 'system', 'content' => 'Return only JSON array of {suggestion,priority,action}. No prose.' ),
array( 'role' => 'user', 'content' => 'Use this 30-day GSC summary to suggest 3-5 UX improvements: ' . wp_json_encode( $summary ) ),
),
) ),
) );
if ( is_wp_error( $response ) ) {
wp_send_json_error( array( 'message' => $response->get_error_message() ), 400 );
}
$payload = json_decode( wp_remote_retrieve_body( $response ), true );
$reply = trim( (string) ( $payload['choices'][0]['message']['content'] ?? '[]' ) );
$insights = json_decode( $reply, true );
wp_send_json_success( array( 'insights' => is_array( $insights ) ? $insights : array(), 'date' => wp_date( 'd/m/Y' ) ) );
}
}
