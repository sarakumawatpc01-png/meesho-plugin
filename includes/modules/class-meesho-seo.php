<?php
/**
 * Meesho Master SEO Module — Core Engine
 * Crawler, SEO plugin detection, batch processing, cron scheduler.
 * All dates dd/mm/yyyy.
 */

class Meesho_Master_SEO {

	public function __construct() {
		add_action( 'wp_ajax_meesho_run_seo_crawl', array( $this, 'ajax_run_crawl' ) );
		add_action( 'wp_ajax_meesho_get_seo_scores', array( $this, 'ajax_get_scores' ) );
		add_action( 'wp_ajax_meesho_get_suggestions', array( $this, 'ajax_get_suggestions' ) );
		add_action( 'wp_ajax_meesho_apply_suggestion', array( $this, 'ajax_apply_suggestion' ) );
		add_action( 'wp_ajax_meesho_apply_all_safe', array( $this, 'ajax_apply_all_safe' ) );
		add_action( 'wp_ajax_meesho_reject_suggestion', array( $this, 'ajax_reject_suggestion' ) );
		add_action( 'wp_ajax_meesho_generate_llms_txt', array( $this, 'ajax_generate_llms_txt' ) );

		// Cron hooks
		add_action( 'meesho_seo_batch_process', array( $this, 'run_scheduled_batch' ) );
		$this->maybe_schedule_cron();
	}

	private function maybe_schedule_cron() {
		$settings = new Meesho_Master_Settings();
		if ( $settings->get( 'automation_enabled' ) !== 'yes' ) return;
		if ( ! wp_next_scheduled( 'meesho_seo_batch_process' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'meesho_seo_batch_process' );
		}
	}

	/* ---- SEO Plugin Detection ---- */

	public function detect_seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) return 'yoast';
		if ( defined( 'RANK_MATH_VERSION' ) ) return 'rankmath';
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) return 'aioseo';
		return 'none';
	}

	public function get_meta_keys() {
		$map = array(
			'yoast'    => array( 'title' => '_yoast_wpseo_title', 'desc' => '_yoast_wpseo_metadesc' ),
			'rankmath' => array( 'title' => 'rank_math_title', 'desc' => 'rank_math_description' ),
			'aioseo'   => array( 'title' => '_aioseo_title', 'desc' => '_aioseo_description' ),
			'none'     => array( 'title' => '_meesho_meta_title', 'desc' => '_meesho_meta_desc' ),
		);
		return $map[ $this->detect_seo_plugin() ];
	}

	/* ---- Crawler ---- */

	public function crawl_page( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return null;

		$meta_keys = $this->get_meta_keys();
		$content   = $post->post_content;

		// Extract headings
		$headings = array();
		if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $hm ) ) {
			foreach ( $hm[1] as $i => $level ) {
				$headings[] = array( 'level' => 'H' . $level, 'text' => strip_tags( $hm[2][$i] ) );
			}
		}

		// Extract image alt tags
		$alt_tags = array();
		if ( preg_match_all( '/<img[^>]+alt=["\']([^"\']*)["\'][^>]*>/i', $content, $am ) ) {
			$alt_tags = $am[1];
		}
		$missing_alts = 0;
		if ( preg_match_all( '/<img(?![^>]*alt=)[^>]*>/i', $content, $ma ) ) {
			$missing_alts = count( $ma[0] );
		}

		// Internal links
		$site_url = home_url();
		$internal_links = array();
		if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $lm ) ) {
			foreach ( $lm[1] as $href ) {
				if ( strpos( $href, $site_url ) === 0 || strpos( $href, '/' ) === 0 ) {
					$internal_links[] = $href;
				}
			}
		}

		// Existing schema
		$schema = get_post_meta( $post_id, '_meesho_schema_jsonld', true );

		// WooCommerce data
		$wc_data = null;
		if ( $post->post_type === 'product' && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				$wc_data = array(
					'price' => $product->get_price(),
					'sku'   => $product->get_sku(),
					'cats'  => wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) ),
					'tags'  => wp_get_post_terms( $post_id, 'product_tag', array( 'fields' => 'names' ) ),
				);
			}
		}

		return array(
			'post_id'        => $post_id,
			'title'          => $post->post_title,
			'content'        => $content,
			'excerpt'        => $post->post_excerpt,
			'meta_title'     => get_post_meta( $post_id, $meta_keys['title'], true ),
			'meta_desc'      => get_post_meta( $post_id, $meta_keys['desc'], true ),
			'headings'       => $headings,
			'alt_tags'       => $alt_tags,
			'missing_alts'   => $missing_alts,
			'internal_links' => $internal_links,
			'schema'         => $schema,
			'word_count'     => str_word_count( strip_tags( $content ) ),
			'post_type'      => $post->post_type,
			'wc_data'        => $wc_data,
		);
	}

	/* ---- Batch Crawl ---- */

	public function run_batch_crawl( $batch_size = 5 ) {
		$offset_key = 'meesho_seo_crawl_offset';
		$offset = intval( get_option( $offset_key, 0 ) );

		$posts = get_posts( array(
			'post_type'   => array( 'post', 'page', 'product' ),
			'post_status' => 'publish',
			'fields'      => 'ids',
			'numberposts' => $batch_size,
			'offset'      => $offset,
		) );

		if ( empty( $posts ) ) {
			update_option( $offset_key, 0 );
			return array();
		}

		$results = array();
		foreach ( $posts as $pid ) {
			$results[] = $this->crawl_page( $pid );
		}

		update_option( $offset_key, $offset + count( $posts ) );
		return $results;
	}

	/* ---- Apply a single suggestion ---- */

	public function apply_suggestion( $suggestion_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'meesho_seo_suggestions';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $suggestion_id ) );
		if ( ! $row ) return new WP_Error( 'not_found', 'Suggestion not found' );

		$undo = new Meesho_Master_Undo();
		$meta_keys = $this->get_meta_keys();

		switch ( $row->type ) {
			case 'meta_title':
				$old = get_post_meta( $row->post_id, $meta_keys['title'], true );
				$undo->log( 'meta_update', $row->post_id,
					wp_json_encode( array( 'meta_key' => $meta_keys['title'], 'meta_value' => $old ) ),
					wp_json_encode( array( 'meta_key' => $meta_keys['title'], 'meta_value' => $row->suggested_value ) ),
					'ai'
				);
				update_post_meta( $row->post_id, $meta_keys['title'], $row->suggested_value );
				break;

			case 'meta_desc':
				$old = get_post_meta( $row->post_id, $meta_keys['desc'], true );
				$undo->log( 'meta_update', $row->post_id,
					wp_json_encode( array( 'meta_key' => $meta_keys['desc'], 'meta_value' => $old ) ),
					wp_json_encode( array( 'meta_key' => $meta_keys['desc'], 'meta_value' => $row->suggested_value ) ),
					'ai'
				);
				update_post_meta( $row->post_id, $meta_keys['desc'], $row->suggested_value );
				break;

			case 'alt_tag':
				$old_content = get_post_field( 'post_content', $row->post_id );
				$new_content = str_replace( $row->current_value, $row->suggested_value, $old_content );
				$undo->log( 'post_update', $row->post_id,
					wp_json_encode( array( 'post_content' => $old_content ) ),
					wp_json_encode( array( 'post_content' => $new_content ) ),
					'ai'
				);
				wp_update_post( array( 'ID' => $row->post_id, 'post_content' => $new_content ) );
				break;

			case 'schema':
				$old = get_post_meta( $row->post_id, '_meesho_schema_jsonld', true );
				$decoded = json_decode( $row->suggested_value );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					return new WP_Error( 'invalid_schema', 'Schema JSON-LD is invalid' );
				}
				$undo->log( 'schema_update', $row->post_id, $old, $row->suggested_value, 'ai' );
				update_post_meta( $row->post_id, '_meesho_schema_jsonld', $row->suggested_value );
				break;
		}

		$wpdb->update( $table,
			array( 'status' => 'applied', 'applied_at' => date( 'd/m/Y' ) ),
			array( 'id' => $suggestion_id ),
			array( '%s', '%s' ), array( '%d' )
		);

		return true;
	}

	/* ---- Safety filter: auto-apply check ---- */

	public function passes_safety_filter( $suggestion ) {
		$safe_types = array( 'meta_title', 'meta_desc', 'alt_tag', 'internal_link' );
		return (
			$suggestion->priority === 'high' &&
			intval( $suggestion->confidence ) >= 85 &&
			intval( $suggestion->safe_to_apply ) === 1 &&
			in_array( $suggestion->type, $safe_types, true )
		);
	}

	/* ---- Scheduled batch run ---- */

	public function run_scheduled_batch() {
		global $wpdb;
		$settings  = new Meesho_Master_Settings();
		$batch_size = intval( $settings->get( 'automation_batch_size' ) ) ?: 5;
		$delay_ms   = intval( $settings->get( 'automation_delay_ms' ) ) ?: 500;

		$run_table = $wpdb->prefix . 'meesho_run_history';
		$started   = date( 'd/m/Y' );

		$wpdb->insert( $run_table, array(
			'run_type' => 'seo_batch', 'status' => 'running',
			'pages_processed' => 0, 'suggestions_generated' => 0,
			'suggestions_applied' => 0, 'started_at' => $started, 'completed_at' => '',
		), array( '%s','%s','%d','%d','%d','%s','%s' ) );
		$run_id = $wpdb->insert_id;

		$pages = $this->run_batch_crawl( $batch_size );
		$total_suggestions = 0;
		$total_applied     = 0;

		$analyzer = new Meesho_Master_SEO_Analyzer();

		foreach ( $pages as $page_data ) {
			if ( ! $page_data ) continue;
			usleep( $delay_ms * 1000 );

			$suggestions = $analyzer->analyze_page( $page_data );
			if ( is_wp_error( $suggestions ) ) continue;

			$total_suggestions += count( $suggestions );

			foreach ( $suggestions as $s ) {
				if ( $this->passes_safety_filter( (object) $s ) ) {
					$this->apply_suggestion( $s['id'] );
					$total_applied++;
				}
			}
		}

		$wpdb->update( $run_table, array(
			'status' => 'success',
			'pages_processed' => count( $pages ),
			'suggestions_generated' => $total_suggestions,
			'suggestions_applied' => $total_applied,
			'completed_at' => date( 'd/m/Y' ),
		), array( 'id' => $run_id ) );
	}

	/* ---- llms.txt Generator ---- */

	public function generate_llms_txt() {
		$settings = new Meesho_Master_Settings();
		$content = "# llms.txt — AI Crawler Access Rules\n" . $settings->get( 'llms_txt_config' );

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() ) {
			return false;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return false;
		}

		$path = trailingslashit( ABSPATH ) . 'llms.txt';
		return (bool) $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
	}

	/* ---- Schema injection via wp_head ---- */

	public static function inject_schema() {
		if ( ! is_singular() ) return;
		$schema = get_post_meta( get_the_ID(), '_meesho_schema_jsonld', true );
		if ( ! empty( $schema ) && json_decode( $schema ) !== null ) {
			echo '<script type="application/ld+json">' . $schema . '</script>' . "\n";
		}
	}

	/* ---- Fallback meta output when no SEO plugin ---- */

	public static function inject_fallback_meta() {
		if ( ! is_singular() ) return;
		$seo = new self();
		if ( $seo->detect_seo_plugin() !== 'none' ) return;

		$pid = get_the_ID();
		$title = get_post_meta( $pid, '_meesho_meta_title', true );
		$desc  = get_post_meta( $pid, '_meesho_meta_desc', true );
		if ( $title ) echo '<meta name="title" content="' . esc_attr( $title ) . '">' . "\n";
		if ( $desc )  echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	}

	/* ---- AJAX handlers ---- */

	public function ajax_run_crawl() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
		$this->run_scheduled_batch();
		wp_send_json_success( 'SEO batch completed on ' . date( 'd/m/Y' ) );
	}

	public function ajax_get_scores() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
		$post_id = intval( $_POST['post_id'] ?? 0 );
		$data = $this->crawl_page( $post_id );
		$analyzer = new Meesho_Master_SEO_Analyzer();
		wp_send_json_success( $analyzer->calculate_scores( $data ) );
	}

	public function ajax_get_suggestions() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		global $wpdb;
		$table = $wpdb->prefix . 'meesho_seo_suggestions';
		$where = "status = 'pending'";
		$params = array();

		if ( ! empty( $_POST['priority'] ) ) {
			$where .= ' AND priority = %s';
			$params[] = sanitize_text_field( $_POST['priority'] );
		}
		if ( ! empty( $_POST['type'] ) ) {
			$where .= ' AND type = %s';
			$params[] = sanitize_text_field( $_POST['type'] );
		}

		$query = "SELECT * FROM $table WHERE $where ORDER BY STR_TO_DATE(created_at, '%d/%m/%Y') DESC LIMIT 50";
		$rows = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $query, $params ) ) : $wpdb->get_results( $query );

		wp_send_json_success( $rows );
	}

	public function ajax_apply_suggestion() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
		$id = intval( $_POST['suggestion_id'] ?? 0 );
		$result = $this->apply_suggestion( $id );
		if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
		wp_send_json_success( 'Applied on ' . date( 'd/m/Y' ) );
	}

	public function ajax_apply_all_safe() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		global $wpdb;
		$table = $wpdb->prefix . 'meesho_seo_suggestions';
		$rows = $wpdb->get_results( "SELECT * FROM $table WHERE status = 'pending'" );
		$applied = 0;
		foreach ( $rows as $row ) {
			if ( $this->passes_safety_filter( $row ) ) {
				$this->apply_suggestion( $row->id );
				$applied++;
			}
		}
		wp_send_json_success( $applied . ' safe suggestions applied on ' . date( 'd/m/Y' ) );
	}

	public function ajax_reject_suggestion() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

		global $wpdb;
		$id = intval( $_POST['suggestion_id'] ?? 0 );
		$wpdb->update(
			$wpdb->prefix . 'meesho_seo_suggestions',
			array( 'status' => 'rejected' ),
			array( 'id' => $id )
		);
		wp_send_json_success( 'Rejected' );
	}

	public function ajax_generate_llms_txt() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
		$ok = $this->generate_llms_txt();
		$ok ? wp_send_json_success( 'llms.txt generated' ) : wp_send_json_error( 'Failed to write llms.txt' );
	}
}
