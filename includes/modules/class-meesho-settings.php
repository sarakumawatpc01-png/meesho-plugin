<?php
/**
 * Meesho Master Settings Module
 * Handles encrypted API keys, all plugin configuration, Meesho account management.
 * All dates stored/displayed as dd/mm/yyyy.
 */

class Meesho_Master_Settings {

	private $option_key = 'meesho_master_settings';
	private $accounts_key = 'meesho_master_accounts';

	// Fields that must be encrypted at rest
	private $encrypted_fields = array(
		'openrouter_api_key',
		'dataforseo_login',
		'dataforseo_password',
		'firecrawl_api_key',
		'gsc_client_id',
		'gsc_client_secret',
		'gsc_refresh_token',
	);

	public function __construct() {
		add_action( 'wp_ajax_meesho_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_meesho_save_accounts', array( $this, 'ajax_save_accounts' ) );
		add_action( 'wp_ajax_meesho_test_email', array( $this, 'ajax_test_email' ) );
	}

	/* --------------------------------------------------------
	 * Encryption helpers — uses SECURE_AUTH_KEY from wp-config
	 * -------------------------------------------------------- */

	private function get_encryption_key() {
		return defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'meesho-fallback-key-change-me';
	}

	public function encrypt( $plain ) {
		if ( empty( $plain ) ) {
			return '';
		}
		$key    = hash( 'sha256', $this->get_encryption_key(), true );
		$iv     = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'AES-256-CBC', $key, 0, $iv );
		return base64_encode( $iv . '::' . $cipher );
	}

	public function decrypt( $encoded ) {
		if ( empty( $encoded ) ) {
			return '';
		}
		$key  = hash( 'sha256', $this->get_encryption_key(), true );
		$data = base64_decode( $encoded );
		$parts = explode( '::', $data, 2 );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		return openssl_decrypt( $parts[1], 'AES-256-CBC', $key, 0, $parts[0] );
	}

	/* --------------------------------------------------------
	 * Read / write helpers
	 * -------------------------------------------------------- */

	public function get_all() {
		$settings = get_option( $this->option_key, array() );
		return wp_parse_args( $settings, $this->defaults() );
	}

	public function get( $key, $default = '' ) {
		$all = $this->get_all();
		$val = isset( $all[ $key ] ) ? $all[ $key ] : $default;
		// Decrypt transparently
		if ( in_array( $key, $this->encrypted_fields, true ) ) {
			$val = $this->decrypt( $val );
		}
		return $val;
	}

	public function set( $key, $value ) {
		$all = $this->get_all();
		if ( in_array( $key, $this->encrypted_fields, true ) ) {
			$value = $this->encrypt( $value );
		}
		$all[ $key ] = $value;
		update_option( $this->option_key, $all );
	}

	public function save_bulk( $data ) {
		$all = $this->get_all();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, $this->encrypted_fields, true ) ) {
				$value = $this->encrypt( $value );
			}
			$all[ $key ] = sanitize_text_field( $value );
		}
		update_option( $this->option_key, $all );
	}

	public function defaults() {
		return array(
			// Pricing
			'pricing_markup_type'    => 'percentage',
			'pricing_markup_value'   => '20',
			'pricing_rounding'       => 'none',      // none | 1 | 5 | 9 | 10

			// Scrapling
			'scrapling_url'          => 'http://localhost:5000/scrape',
			'scrapling_timeout'      => '30',

			// OpenRouter
			'openrouter_api_key'     => '',
			'ai_model_seo'           => '',
			'ai_model_blog'          => '',
			'ai_model_image'         => '',
			'ai_model_copilot'       => '',
			'ai_model_schema'        => '',
			'ai_model_aeo'           => '',
			'ai_model_geo'           => '',
			'ai_show_free_only'      => 'no',

			// COD risk
			'cod_risk_threshold'     => '2000',
			'cod_repeat_window_hrs'  => '24',

			// Copilot
			'copilot_auto_implement' => 'no',

			// Automation schedule
			'automation_enabled'     => 'yes',
			'automation_time_1'      => '02:00',
			'automation_time_2'      => '14:00',
			'automation_batch_size'  => '5',
			'automation_delay_ms'    => '500',

			// Email reports
			'email_recipients'       => '',
			'email_from_override'    => '',
			'email_frequency'        => 'daily',  // daily | weekly
			'email_pdf_library'      => 'dompdf', // dompdf | mpdf

			// Hotjar
			'hotjar_site_id'         => '',

			// GSC
			'gsc_client_id'          => '',
			'gsc_client_secret'      => '',
			'gsc_refresh_token'      => '',

			// DataForSEO
			'dataforseo_login'       => '',
			'dataforseo_password'    => '',

			// Firecrawl
			'firecrawl_api_key'      => '',

			// llms.txt bot config
			'llms_txt_config'        => "User-agent: GPTBot\nAllow: /\n\nUser-agent: ClaudeBot\nAllow: /\n\nUser-agent: PerplexityBot\nAllow: /\n\nUser-agent: *\nDisallow: /wp-admin/",
		);
	}

	/* --------------------------------------------------------
	 * Meesho account management (up to 4 accounts, encrypted)
	 * -------------------------------------------------------- */

	public function get_accounts() {
		$enc = get_option( $this->accounts_key, '' );
		if ( empty( $enc ) ) {
			return array();
		}
		$json = $this->decrypt( $enc );
		$accounts = json_decode( $json, true );
		return is_array( $accounts ) ? $accounts : array();
	}

	public function save_accounts( $accounts ) {
		// Enforce max 4 accounts
		$accounts = array_slice( $accounts, 0, 4 );
		$json = wp_json_encode( $accounts );
		$enc  = $this->encrypt( $json );
		update_option( $this->accounts_key, $enc );
	}

	/* --------------------------------------------------------
	 * Pricing calculation
	 * -------------------------------------------------------- */

	public function calculate_selling_price( $meesho_price, $override_type = null, $override_value = null ) {
		$type  = $override_type  ? $override_type  : $this->get( 'pricing_markup_type' );
		$value = $override_value ? $override_value : $this->get( 'pricing_markup_value' );

		if ( $type === 'flat' ) {
			$price = floatval( $meesho_price ) + floatval( $value );
		} else {
			$price = floatval( $meesho_price ) * ( 1 + floatval( $value ) / 100 );
		}

		return $this->apply_rounding( $price );
	}

	public function apply_rounding( $price ) {
		$rule = $this->get( 'pricing_rounding' );
		switch ( $rule ) {
			case '1':
				return ceil( $price );
			case '5':
				return ceil( $price / 5 ) * 5;
			case '9':
				return floor( $price / 10 ) * 10 + 9;
			case '10':
				return ceil( $price / 10 ) * 10;
			default:
				return round( $price, 2 );
		}
	}

	/* --------------------------------------------------------
	 * AJAX handlers
	 * -------------------------------------------------------- */

	public function ajax_save_settings() {
		check_ajax_referer( 'meesho_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$fields = $_POST;
		unset( $fields['action'], $fields['nonce'] );
		$this->save_bulk( $fields );
		wp_send_json_success( 'Settings saved on ' . date( 'd/m/Y' ) );
	}

	public function ajax_save_accounts() {
		check_ajax_referer( 'meesho_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$raw = isset( $_POST['accounts'] ) ? wp_unslash( $_POST['accounts'] ) : '[]';
		$accounts = json_decode( $raw, true );
		if ( ! is_array( $accounts ) ) {
			wp_send_json_error( 'Invalid accounts data' );
		}

		// Sanitize each account
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
		wp_send_json_success( 'Accounts saved' );
	}

	public function ajax_test_email() {
		check_ajax_referer( 'meesho_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$to = $this->get( 'email_recipients' );
		if ( empty( $to ) ) {
			wp_send_json_error( 'No email recipients configured' );
		}

		$from = $this->get( 'email_from_override' );
		if ( ! empty( $from ) ) {
			add_filter( 'wp_mail_from', function() use ( $from ) { return $from; } );
		}

		$sent = wp_mail(
			$to,
			'Meesho Master — Test Email (' . date( 'd/m/Y' ) . ')',
			'This is a test email from Meesho Master plugin sent on ' . date( 'd/m/Y' ) . '.',
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		if ( $sent ) {
			wp_send_json_success( 'Test email sent successfully' );
		} else {
			wp_send_json_error( 'Failed to send test email. Check your SMTP settings.' );
		}
	}
}
