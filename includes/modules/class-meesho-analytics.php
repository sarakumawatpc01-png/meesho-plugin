<?php
/**
 * Meesho Master Analytics Module
 * Hotjar integration, GSC ranking tracking, email reports. All dates dd/mm/yyyy.
 */

class Meesho_Master_Analytics {

	public function __construct() {
		add_action( 'wp_ajax_meesho_get_rankings', array( $this, 'ajax_get_rankings' ) );
		add_action( 'wp_ajax_meesho_add_keyword', array( $this, 'ajax_add_keyword' ) );
		add_action( 'wp_ajax_meesho_send_report', array( $this, 'ajax_send_report' ) );
		add_action( 'wp_ajax_meesho_get_heatmap_insights', array( $this, 'ajax_heatmap_insights' ) );

		// Public hooks for tracking scripts
		add_action( 'wp_head', array( $this, 'inject_hotjar' ) );

		// Cron for email reports
		add_action( 'meesho_email_report', array( $this, 'send_scheduled_report' ) );
		if ( ! wp_next_scheduled( 'meesho_email_report' ) ) {
			wp_schedule_event( time(), 'daily', 'meesho_email_report' );
		}
	}

	/* ---- Hotjar injection ---- */

	public function inject_hotjar() {
		$settings = new Meesho_Master_Settings();
		$site_id = $settings->get( 'hotjar_site_id' );
		if ( empty( $site_id ) ) return;

		echo "<!-- Hotjar Tracking Code -->\n"
			. "<script>\n"
			. "(function(h,o,t,j,a,r){\n"
			. "h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};\n"
			. "h._hjSettings={hjid:" . intval( $site_id ) . ",hjsv:6};\n"
			. "a=o.getElementsByTagName('head')[0];\n"
			. "r=o.createElement('script');r.async=1;\n"
			. "r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;\n"
			. "a.appendChild(r);\n"
			. "})(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');\n"
			. "</script>\n";
	}

	/* ---- GSC Ranking Tracking ---- */

	public function fetch_gsc_data( $keyword ) {
		$settings = new Meesho_Master_Settings();
		$token = $settings->get( 'gsc_refresh_token' );
		if ( empty( $token ) ) return new WP_Error( 'no_gsc', 'GSC not connected' );

		$access_token = $this->refresh_gsc_token();
		if ( is_wp_error( $access_token ) ) return $access_token;

		$site_url = home_url();
		$response = wp_remote_post(
			'https://www.googleapis.com/webmasters/v3/sites/' . urlencode( $site_url ) . '/searchAnalytics/query',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( array(
					'startDate'  => date( 'Y-m-d', strtotime( '-30 days' ) ),
					'endDate'    => date( 'Y-m-d' ),
					'dimensions' => array( 'query', 'page' ),
					'dimensionFilterGroups' => array( array(
						'filters' => array( array(
							'dimension'  => 'query',
							'operator'   => 'contains',
							'expression' => $keyword,
						) ),
					) ),
					'rowLimit' => 10,
				) ),
			)
		);

		if ( is_wp_error( $response ) ) return $response;

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return $data['rows'] ?? array();
	}

	private function refresh_gsc_token() {
		$settings = new Meesho_Master_Settings();
		$client_id     = $settings->get( 'gsc_client_id' );
		$client_secret = $settings->get( 'gsc_client_secret' );
		$refresh_token = $settings->get( 'gsc_refresh_token' );

		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'body' => array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'refresh_token' => $refresh_token,
				'grant_type'    => 'refresh_token',
			),
		) );

		if ( is_wp_error( $response ) ) return $response;
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['access_token'] ) ) return $data['access_token'];
		return new WP_Error( 'gsc_auth', 'GSC auth expired — please reconnect in Settings' );
	}

	public function save_snapshot( $keyword, $gsc_rows ) {
		global $wpdb;
		$table = $wpdb->prefix . 'meesho_gsc_snapshots';
		$today = date( 'd/m/Y' );

		foreach ( $gsc_rows as $row ) {
			$wpdb->insert( $table, array(
				'keyword'       => $keyword,
				'url'           => $row['keys'][1] ?? '',
				'position'      => floatval( $row['position'] ?? 0 ),
				'impressions'   => intval( $row['impressions'] ?? 0 ),
				'ctr'           => floatval( $row['ctr'] ?? 0 ),
				'snapshot_date' => $today,
			), array( '%s', '%s', '%f', '%d', '%f', '%s' ) );
		}
	}

	/* ---- Email Reports ---- */

	public function generate_report_html() {
		global $wpdb;

		$settings = new Meesho_Master_Settings();
		$today = date( 'd/m/Y' );
		$site_name = get_bloginfo( 'name' );

		// Gather data
		$order_count = wp_count_posts( 'shop_order' );
		$product_count = wp_count_posts( 'product' );

		$recent_suggestions = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}meesho_seo_suggestions WHERE status = 'pending'"
		);
		$applied_today = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}meesho_seo_suggestions WHERE applied_at = %s",
			$today
		) );

		// Average scores
		$avg_seo = $wpdb->get_var(
			"SELECT AVG(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_meesho_seo_score'"
		);
		$avg_aeo = $wpdb->get_var(
			"SELECT AVG(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_meesho_aeo_score'"
		);
		$avg_geo = $wpdb->get_var(
			"SELECT AVG(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_meesho_geo_score'"
		);

		$html = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>";
		$html .= "<h1 style='color:#6C2EB9;'>📊 Meesho Master Report — {$today}</h1>";
		$html .= "<p>Site: <strong>{$site_name}</strong></p><hr>";
		$html .= "<h2>📦 Store Overview</h2><ul>";
		$html .= "<li>Published Products: " . ( $product_count->publish ?? 0 ) . "</li>";
		$html .= "<li>Total Orders: " . array_sum( (array) $order_count ) . "</li>";
		$html .= "</ul>";
		$html .= "<h2>🔍 SEO / AEO / GEO Scores</h2><ul>";
		$html .= "<li>Avg SEO: " . round( $avg_seo ?? 0 ) . "/100</li>";
		$html .= "<li>Avg AEO: " . round( $avg_aeo ?? 0 ) . "/100</li>";
		$html .= "<li>Avg GEO: " . round( $avg_geo ?? 0 ) . "/100</li>";
		$html .= "</ul>";
		$html .= "<h2>🤖 AI Actions</h2><ul>";
		$html .= "<li>Pending Suggestions: {$recent_suggestions}</li>";
		$html .= "<li>Applied Today: {$applied_today}</li>";
		$html .= "</ul>";
		$html .= "<hr><p style='color:#888;font-size:12px;'>Generated by Meesho Master v6 on {$today}</p>";
		$html .= "</body></html>";

		return $html;
	}

	public function send_scheduled_report() {
		$settings = new Meesho_Master_Settings();
		$recipients = $settings->get( 'email_recipients' );
		if ( empty( $recipients ) ) return;

		$from = $settings->get( 'email_from_override' );
		if ( ! empty( $from ) ) {
			add_filter( 'wp_mail_from', function() use ( $from ) { return $from; } );
		}

		$html = $this->generate_report_html();
		$subject = 'Meesho Master Report — ' . date( 'd/m/Y' );

		wp_mail( $recipients, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/* ---- AJAX handlers ---- */

	public function ajax_get_rankings() {
		check_ajax_referer( 'meesho_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		global $wpdb;
		$table = $wpdb->prefix . 'meesho_gsc_snapshots';
		$snapshots = $wpdb->get_results(
			"SELECT * FROM $table ORDER BY STR_TO_DATE(snapshot_date, '%d/%m/%Y') DESC LIMIT 100"
		);
		wp_send_json_success( $snapshots );
	}

	public function ajax_add_keyword() {
		check_ajax_referer( 'meesho_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$keyword = sanitize_text_field( $_POST['keyword'] ?? '' );
		if ( empty( $keyword ) ) wp_send_json_error( 'Keyword required' );

		$rows = $this->fetch_gsc_data( $keyword );
		if ( is_wp_error( $rows ) ) wp_send_json_error( $rows->get_error_message() );

		$this->save_snapshot( $keyword, $rows );
		wp_send_json_success( array( 'keyword' => $keyword, 'rows' => $rows, 'date' => date( 'd/m/Y' ) ) );
	}

	public function ajax_send_report() {
		check_ajax_referer( 'meesho_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
		$this->send_scheduled_report();
		wp_send_json_success( 'Report sent on ' . date( 'd/m/Y' ) );
	}

	public function ajax_heatmap_insights() {
		check_ajax_referer( 'meesho_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		$settings = new Meesho_Master_Settings();
		$api_key = $settings->get( 'openrouter_api_key' );
		if ( empty( $api_key ) ) wp_send_json_error( 'OpenRouter API key required' );

		$model = $settings->get( 'ai_model_seo' ) ?: 'openai/gpt-3.5-turbo';
		$prompt = "Based on typical e-commerce heatmap patterns for an Indian Meesho reseller store, suggest 3-5 UI improvements. Return JSON: [{\"suggestion\":\"...\",\"priority\":\"high|medium|low\"}]";

		$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( array(
				'model' => $model,
				'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ),
			) ),
		) );

		if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$reply = $body['choices'][0]['message']['content'] ?? '[]';
		$insights = json_decode( $reply, true );
		if ( ! is_array( $insights ) ) $insights = array();

		wp_send_json_success( array( 'insights' => $insights, 'date' => date( 'd/m/Y' ) ) );
	}
}
