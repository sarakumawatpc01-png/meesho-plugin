<?php
/**
 * Meesho Master Import Module
 * Handles product import via Scrapling URL or HTML paste fallback.
 * SKU extraction from image URL primary, product URL fallback.
 * Variation SKU: {MEESHO_SKU}-{SIZE}. Sizes from scraped data only.
 * All dates stored/displayed as dd/mm/yyyy.
 */

class Meesho_Master_Import {

	private $settings;
	private $undo;

	public function __construct() {
		add_action( 'wp_ajax_meesho_import_url', array( $this, 'ajax_import_url' ) );
		add_action( 'wp_ajax_meesho_import_html', array( $this, 'ajax_import_html' ) );
		add_action( 'wp_ajax_meesho_manual_sku', array( $this, 'ajax_manual_sku' ) );
	}

	/**
	 * Lazy-load settings module.
	 */
	private function settings() {
		if ( ! $this->settings ) {
			$this->settings = new Meesho_Master_Settings();
		}
		return $this->settings;
	}

	/**
	 * Lazy-load undo module.
	 */
	private function undo() {
		if ( ! $this->undo ) {
			$this->undo = new Meesho_Master_Undo();
		}
		return $this->undo;
	}

	/* ================================================================
	 *  AJAX — Import by URL (via Scrapling)
	 * ================================================================ */

	public function ajax_import_url() {
		check_ajax_referer( 'meesho_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '';
		if ( empty( $url ) ) {
			wp_send_json_error( 'Product URL is required.' );
		}

		// Try Scrapling first
		$scraped = $this->fetch_from_scrapling( $url );

		if ( is_wp_error( $scraped ) ) {
			// Scrapling failed — tell user to use HTML paste
			wp_send_json_error( array(
				'message'  => 'Scrapling service unavailable: ' . $scraped->get_error_message() . '. Please use the HTML paste method.',
				'fallback' => true,
			) );
		}

		// Add the source URL to scraped data
		$scraped['meesho_url'] = $url;

		try {
			$result = $this->process_import( $scraped );
			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/* ================================================================
	 *  AJAX — Import by HTML paste (fallback)
	 * ================================================================ */

	public function ajax_import_html() {
		check_ajax_referer( 'meesho_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$html = isset( $_POST['html'] ) ? wp_unslash( $_POST['html'] ) : '';
		if ( empty( $html ) ) {
			wp_send_json_error( 'HTML source is required.' );
		}

		$product_url = isset( $_POST['product_url'] ) ? esc_url_raw( $_POST['product_url'] ) : '';

		try {
			$parsed = $this->parse_html( $html, $product_url );
			$result = $this->process_import( $parsed );
			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/* ================================================================
	 *  AJAX — Manual SKU entry
	 * ================================================================ */

	public function ajax_manual_sku() {
		check_ajax_referer( 'meesho_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$sku  = sanitize_text_field( $_POST['sku'] ?? '' );
		$data = isset( $_POST['product_data'] ) ? json_decode( wp_unslash( $_POST['product_data'] ), true ) : null;

		if ( empty( $sku ) || ! is_numeric( $sku ) ) {
			wp_send_json_error( 'Please enter a valid numeric Meesho SKU.' );
		}
		if ( ! $data ) {
			wp_send_json_error( 'Missing product data.' );
		}

		$data['meesho_sku_override'] = $sku;

		try {
			$result = $this->process_import( $data );
			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/* ================================================================
	 *  Scrapling service call
	 * ================================================================ */

	private function fetch_from_scrapling( $url ) {
		$scrapling_url = $this->settings()->get( 'scrapling_url' );
		$timeout       = intval( $this->settings()->get( 'scrapling_timeout' ) );

		if ( empty( $scrapling_url ) ) {
			return new WP_Error( 'no_scrapling', 'Scrapling service URL is not configured.' );
		}

		$response = wp_remote_post( $scrapling_url, array(
			'timeout' => $timeout > 0 ? $timeout : 30,
			'body'    => wp_json_encode( array( 'url' => $url ) ),
			'headers' => array( 'Content-Type' => 'application/json' ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_Error( 'scrapling_error', 'Scrapling returned HTTP ' . $code );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_json', 'Scrapling returned invalid JSON.' );
		}

		return $data;
	}

	/* ================================================================
	 *  HTML parser (fallback)
	 * ================================================================ */

	private function parse_html( $html, $product_url = '' ) {
		$data = array(
			'title'              => '',
			'description'        => '',
			'images'             => array(),
			'sizes'              => array(),
			'reviews'            => array(),
			'rating'             => 0,
			'rating_breakdown'   => array(),
			'delivery_estimate'  => '',
			'meesho_url'         => $product_url,
			'image_url'          => '',
		);

		// Suppress DOM warnings for malformed HTML
		libxml_use_internal_errors( true );
		$doc = new DOMDocument();
		$doc->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
		libxml_clear_errors();

		$xpath = new DOMXPath( $doc );

		// Extract title — typically in <h1> or structured data
		$h1 = $xpath->query( '//h1' );
		if ( $h1->length > 0 ) {
			$data['title'] = trim( $h1->item(0)->textContent );
		}

		// Extract images — look for Meesho product image URLs
		$imgs = $xpath->query( '//img[contains(@src, "images.meesho.com")]' );
		foreach ( $imgs as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( ! empty( $src ) ) {
				$data['images'][] = $src;
				// Use the first image URL for SKU extraction
				if ( empty( $data['image_url'] ) ) {
					$data['image_url'] = $src;
				}
			}
		}

		// Also check srcset and data-src attributes
		$all_imgs = $xpath->query( '//img' );
		foreach ( $all_imgs as $img ) {
			foreach ( array( 'data-src', 'srcset' ) as $attr ) {
				$val = $img->getAttribute( $attr );
				if ( strpos( $val, 'images.meesho.com' ) !== false ) {
					// Extract first URL from srcset if needed
					$first_url = preg_split( '/[\s,]+/', $val )[0];
					if ( ! in_array( $first_url, $data['images'], true ) ) {
						$data['images'][] = $first_url;
					}
					if ( empty( $data['image_url'] ) ) {
						$data['image_url'] = $first_url;
					}
				}
			}
		}

		// Extract JSON-LD structured data (Meesho often embeds this)
		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		foreach ( $scripts as $script ) {
			$json = json_decode( $script->textContent, true );
			if ( is_array( $json ) ) {
				if ( isset( $json['@type'] ) && $json['@type'] === 'Product' ) {
					if ( ! empty( $json['name'] ) && empty( $data['title'] ) ) {
						$data['title'] = $json['name'];
					}
					if ( ! empty( $json['description'] ) ) {
						$data['description'] = $json['description'];
					}
					if ( isset( $json['offers'] ) && is_array( $json['offers'] ) ) {
						foreach ( $json['offers'] as $offer ) {
							$data['sizes'][] = array(
								'size'      => $offer['name'] ?? 'Free Size',
								'price'     => floatval( $offer['price'] ?? 0 ),
								'mrp'       => floatval( $offer['highPrice'] ?? $offer['price'] ?? 0 ),
								'available' => ( $offer['availability'] ?? '' ) !== 'OutOfStock',
							);
						}
					}
				}
			}
		}

		// Attempt to find sizes from button/span elements if not found in JSON-LD
		if ( empty( $data['sizes'] ) ) {
			$size_nodes = $xpath->query( '//*[contains(@class, "size") or contains(@class, "Size")]//button | //*[contains(@class, "size") or contains(@class, "Size")]//span' );
			foreach ( $size_nodes as $node ) {
				$text = trim( $node->textContent );
				if ( preg_match( '/^(XXS|XS|S|M|L|XL|XXL|XXXL|Free Size|\d+)$/i', $text ) ) {
					$data['sizes'][] = array(
						'size'      => $text,
						'price'     => 0,
						'mrp'       => 0,
						'available' => strpos( $node->getAttribute('class'), 'disabled' ) === false,
					);
				}
			}
		}

		// Extract description
		if ( empty( $data['description'] ) ) {
			$desc_nodes = $xpath->query( '//*[contains(@class, "pdp-description") or contains(@class, "product-details") or contains(@class, "ProductDescription")]' );
			foreach ( $desc_nodes as $node ) {
				$data['description'] .= $doc->saveHTML( $node );
			}
		}

		// Extract delivery estimate
		$delivery_nodes = $xpath->query( '//*[contains(text(), "Delivery by") or contains(text(), "delivery by")]' );
		if ( $delivery_nodes->length > 0 ) {
			$data['delivery_estimate'] = trim( $delivery_nodes->item(0)->textContent );
		}

		return $data;
	}

	/* ================================================================
	 *  SKU Extraction — Fix 1
	 *  Primary: image URL /products/{NUMERIC_SKU}/
	 *  Fallback: product URL /p/{NUMERIC_SKU}
	 *  Never silent import without SKU.
	 * ================================================================ */

	public function extract_sku( $image_url, $product_url ) {
		$meesho_sku = null;

		// Primary source: Meesho product image URL
		if ( ! empty( $image_url ) && preg_match( '/\/products\/(\d+)\//', $image_url, $matches ) ) {
			$meesho_sku = $matches[1];
		}
		// Fallback source: Meesho product page URL
		elseif ( ! empty( $product_url ) && preg_match( '/\/p\/(\d+)/', $product_url, $matches ) ) {
			$meesho_sku = $matches[1];
		}

		return $meesho_sku; // null if not found — caller must handle
	}

	/* ================================================================
	 *  Core import pipeline
	 * ================================================================ */

	public function process_import( $data ) {
		// Allow manual SKU override
		if ( ! empty( $data['meesho_sku_override'] ) ) {
			$meesho_sku = $data['meesho_sku_override'];
		} else {
			$meesho_sku = $this->extract_sku( $data['image_url'] ?? '', $data['meesho_url'] ?? '' );
		}

		// If SKU still not found, flag the import — never silently import without a SKU
		if ( ! $meesho_sku ) {
			throw new Exception(
				'Could not extract Meesho SKU from image or product URL. '
				. 'Please enter it manually using the SKU field below.'
			);
		}

		// Duplicate check
		$duplicate = $this->check_duplicate( $meesho_sku );
		if ( $duplicate ) {
			return array(
				'status'     => 'duplicate',
				'message'    => 'A product with SKU ' . $meesho_sku . ' already exists (WC Product #' . $duplicate . ').',
				'product_id' => $duplicate,
				'sku'        => $meesho_sku,
				'actions'    => array( 'overwrite', 'skip', 'create_new' ),
			);
		}

		// Clean the description
		$clean_desc = $this->clean_description( $data['description'] ?? '' );

		// Create the parent WooCommerce variable product
		$parent_id = $this->create_parent_product( $meesho_sku, $data, $clean_desc );

		// Create variations from sizes — Fix 2: sizes from scraped data only
		if ( ! empty( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
			$this->create_variations( $parent_id, $meesho_sku, $data['sizes'] );
		}

		// Import images
		if ( ! empty( $data['images'] ) ) {
			$this->attach_images( $parent_id, $data['images'] );
		}

		// Import reviews
		if ( ! empty( $data['reviews'] ) ) {
			$this->import_reviews( $meesho_sku, $data['reviews'] );
		}

		// Store rating data
		if ( ! empty( $data['rating'] ) ) {
			update_post_meta( $parent_id, '_meesho_avg_rating', floatval( $data['rating'] ) );
		}
		if ( ! empty( $data['rating_breakdown'] ) ) {
			update_post_meta( $parent_id, '_meesho_rating_breakdown', $data['rating_breakdown'] );
		}
		if ( ! empty( $data['review_count'] ) ) {
			update_post_meta( $parent_id, '_meesho_review_count', intval( $data['review_count'] ) );
		}

		// Store delivery estimate
		if ( ! empty( $data['delivery_estimate'] ) ) {
			update_post_meta( $parent_id, '_meesho_delivery_estimate', sanitize_text_field( $data['delivery_estimate'] ) );
		}

		// Log import in meesho_products table — dd/mm/yyyy
		$import_date = date( 'd/m/Y' );
		$this->log_import( $meesho_sku, $parent_id, $data['meesho_url'] ?? '', $import_date );

		// Audit log
		$this->undo()->log(
			'product_import',
			$parent_id,
			'',
			wp_json_encode( array( 'sku' => $meesho_sku, 'title' => $data['title'] ?? '' ) ),
			'manual'
		);

		return array(
			'status'     => 'imported',
			'message'    => 'Product "' . ( $data['title'] ?? $meesho_sku ) . '" imported successfully on ' . $import_date,
			'product_id' => $parent_id,
			'sku'        => $meesho_sku,
		);
	}

	/* ================================================================
	 *  Duplicate check
	 * ================================================================ */

	private function check_duplicate( $meesho_sku ) {
		global $wpdb;

		// Check _meesho_sku meta
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_meesho_sku' AND meta_value = %s LIMIT 1",
			$meesho_sku
		) );
		if ( $existing ) {
			return intval( $existing );
		}

		// Check WooCommerce SKU
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
			$meesho_sku
		) );
		if ( $existing ) {
			return intval( $existing );
		}

		// Also check our custom table
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT wc_product_id FROM {$wpdb->prefix}meesho_products WHERE meesho_sku = %s LIMIT 1",
			$meesho_sku
		) );
		if ( $existing ) {
			return intval( $existing );
		}

		return false;
	}

	/* ================================================================
	 *  Create parent WooCommerce variable product
	 * ================================================================ */

	private function create_parent_product( $meesho_sku, $data, $clean_desc ) {
		$product = new WC_Product_Variable();

		$product->set_name( sanitize_text_field( $data['title'] ?? 'Meesho Product ' . $meesho_sku ) );
		$product->set_description( $clean_desc );
		$product->set_short_description( wp_trim_words( strip_tags( $clean_desc ), 30 ) );
		$product->set_sku( $meesho_sku );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_manage_stock( false );

		// Set the size attribute
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$sizes_list = array();
		if ( ! empty( $data['sizes'] ) ) {
			foreach ( $data['sizes'] as $s ) {
				$sizes_list[] = strtoupper( $s['size'] );
			}
		}
		$attribute->set_options( array_unique( $sizes_list ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );

		$parent_id = $product->save();

		// Store the raw Meesho SKU in product meta for deduplication
		update_post_meta( $parent_id, '_meesho_sku', $meesho_sku );
		update_post_meta( $parent_id, '_meesho_source_url', $data['meesho_url'] ?? '' );

		return $parent_id;
	}

	/* ================================================================
	 *  Create variations — Fix 2
	 *  Sizes come entirely from scraped product data.
	 *  Variation SKU = {MEESHO_SKU}-{SIZE}
	 *  Available → instock; Unavailable → outofstock (still created)
	 * ================================================================ */

	private function create_variations( $parent_id, $meesho_sku, $sizes ) {
		foreach ( $sizes as $size_data ) {
			$size_name     = strtoupper( $size_data['size'] );
			$variation_sku = $meesho_sku . '-' . $size_name;
			$stock_status  = ! empty( $size_data['available'] ) ? 'instock' : 'outofstock';

			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_sku( $variation_sku );
			$variation->set_stock_status( $stock_status );
			$variation->set_manage_stock( false );
			$variation->set_attributes( array( 'size' => $size_name ) );

			// Pricing with markup
			$meesho_price = floatval( $size_data['price'] ?? 0 );
			$mrp          = floatval( $size_data['mrp'] ?? 0 );

			if ( $meesho_price > 0 ) {
				$selling_price = $this->settings()->calculate_selling_price( $meesho_price );
				$variation->set_regular_price( $mrp > $selling_price ? $mrp : $selling_price );
				$variation->set_sale_price( $selling_price );
			}

			$variation->save();
		}
	}

	/* ================================================================
	 *  Attach images
	 * ================================================================ */

	private function attach_images( $parent_id, $images ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$gallery_ids = array();
		foreach ( $images as $i => $url ) {
			$attach_id = media_sideload_image( $url, $parent_id, '', 'id' );
			if ( ! is_wp_error( $attach_id ) ) {
				if ( $i === 0 ) {
					set_post_thumbnail( $parent_id, $attach_id );
				} else {
					$gallery_ids[] = $attach_id;
				}
			}
		}

		if ( ! empty( $gallery_ids ) ) {
			update_post_meta( $parent_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
		}
	}

	/* ================================================================
	 *  Import reviews — Fix 3: dates stored as dd/mm/yyyy
	 * ================================================================ */

	private function import_reviews( $meesho_sku, $reviews ) {
		global $wpdb;
		$table = $wpdb->prefix . 'meesho_reviews';

		foreach ( $reviews as $review ) {
			// Parse Meesho date format "DD Month YYYY" → dd/mm/yyyy
			$review_date = $this->parse_meesho_date( $review['date'] ?? '' );

			$wpdb->insert(
				$table,
				array(
					'meesho_sku'    => $meesho_sku,
					'reviewer_name' => sanitize_text_field( $review['name'] ?? 'Anonymous' ),
					'review_text'   => sanitize_textarea_field( $review['text'] ?? '' ),
					'star_rating'   => intval( $review['rating'] ?? 5 ),
					'review_date'   => $review_date,
					'review_images' => wp_json_encode( $review['images'] ?? array() ),
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Parse Meesho-style "12 April 2024" to dd/mm/yyyy.
	 */
	private function parse_meesho_date( $date_str ) {
		if ( empty( $date_str ) ) {
			return date( 'd/m/Y' );
		}
		$ts = strtotime( $date_str );
		if ( $ts === false ) {
			return date( 'd/m/Y' );
		}
		return date( 'd/m/Y', $ts );
	}

	/* ================================================================
	 *  Description cleaning
	 * ================================================================ */

	private function clean_description( $html ) {
		if ( empty( $html ) ) {
			return '';
		}

		// Strip Meesho brand references
		$html = preg_replace( '/(?:Buy\s+on\s+)?Meesho/i', '', $html );

		// Strip price tables
		$html = preg_replace( '/<table[^>]*>.*?<\/table>/is', '', $html );

		// Strip inline styles
		$html = preg_replace( '/\s*style\s*=\s*"[^"]*"/i', '', $html );
		$html = preg_replace( '/\s*style\s*=\s*\'[^\']*\'/i', '', $html );

		// Strip empty HTML tags
		$html = preg_replace( '/<(p|span|div|b|i|strong|em)\s*>\s*<\/\1>/i', '', $html );
		// Run twice to catch nested empties
		$html = preg_replace( '/<(p|span|div|b|i|strong|em)\s*>\s*<\/\1>/i', '', $html );

		// Strip script and noscript tags
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<noscript[^>]*>.*?<\/noscript>/is', '', $html );

		// Allowed tags for WooCommerce product description
		$html = wp_kses( $html, array(
			'p'      => array(),
			'br'     => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'h2'     => array(),
			'h3'     => array(),
			'h4'     => array(),
			'table'  => array(),
			'thead'  => array(),
			'tbody'  => array(),
			'tr'     => array(),
			'th'     => array(),
			'td'     => array(),
		) );

		return trim( $html );
	}

	/* ================================================================
	 *  Log import record — dd/mm/yyyy
	 * ================================================================ */

	private function log_import( $meesho_sku, $wc_product_id, $meesho_url, $import_date ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'meesho_products',
			array(
				'meesho_sku'    => $meesho_sku,
				'wc_product_id' => $wc_product_id,
				'meesho_url'    => $meesho_url,
				'import_date'   => $import_date,
				'status'        => 'active',
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);
	}
}
