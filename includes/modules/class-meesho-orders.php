<?php
/**
 * Meesho Master Orders Module
 * Handles order tracking, COD risk flags, Meesho account assignment,
 * SLA monitoring, status flow, and clipboard helpers.
 * All dates stored/displayed as dd/mm/yyyy.
 */

class Meesho_Master_Orders {

	private $undo;

	/**
	 * Order status flow constants.
	 */
	const STATUS_PENDING    = 'pending';
	const STATUS_ORDERED    = 'ordered_on_meesho';
	const STATUS_TRACKING   = 'tracking_received';
	const STATUS_DISPATCHED = 'dispatched';
	const STATUS_DELIVERED  = 'delivered';
	const STATUS_CANCELLED  = 'cancelled';
	const STATUS_RETURNED   = 'returned';

	/**
	 * SLA threshold in seconds (4 hours).
	 */
	const SLA_THRESHOLD_SECONDS = 14400;

	public function __construct() {
		add_action( 'wp_ajax_meesho_get_orders', array( $this, 'ajax_get_orders' ) );
		add_action( 'wp_ajax_meesho_update_order', array( $this, 'ajax_update_order' ) );
		add_action( 'wp_ajax_meesho_check_cod_risk', array( $this, 'ajax_check_cod_risk' ) );
		add_action( 'wp_ajax_meesho_get_accounts', array( $this, 'ajax_get_accounts' ) );

		// Hook into WooCommerce new order to create our tracking record
		add_action( 'woocommerce_new_order', array( $this, 'on_new_wc_order' ), 10, 1 );
	}

	private function undo() {
		if ( ! $this->undo ) {
			$this->undo = new Meesho_Master_Undo();
		}
		return $this->undo;
	}

	/* ================================================================
	 *  Hook: New WooCommerce order → create meesho_orders record
	 * ================================================================ */

	public function on_new_wc_order( $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'meesho_orders';

		// Avoid duplicates
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE wc_order_id = %d", $order_id
		) );
		if ( $exists ) {
			return;
		}

		$now = date( 'd/m/Y' );

		$wpdb->insert( $table, array(
			'wc_order_id'        => $order_id,
			'meesho_order_id'    => '',
			'tracking_id'        => '',
			'account_used'       => '',
			'fulfillment_status' => self::STATUS_PENDING,
			'sla_status'         => 'ok',
			'notes'              => '',
			'created_at'         => $now,
			'updated_at'         => $now,
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

		// Also assess COD risk
		$order = wc_get_order( $order_id );
		if ( $order && $order->get_payment_method() === 'cod' ) {
			$this->assess_cod_risk( $order );
		}
	}

	/* ================================================================
	 *  AJAX: Get orders list
	 * ================================================================ */

	public function ajax_get_orders() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'meesho_orders';

		$page   = max( 1, intval( $_POST['page'] ?? 1 ) );
		$limit  = 25;
		$offset = ( $page - 1 ) * $limit;

		// Filters
		$where  = '1=1';
		$params = array();

		if ( ! empty( $_POST['status'] ) ) {
			$where .= ' AND o.fulfillment_status = %s';
			$params[] = sanitize_text_field( $_POST['status'] );
		}
		if ( ! empty( $_POST['search'] ) ) {
			$search = '%' . $wpdb->esc_like( sanitize_text_field( $_POST['search'] ) ) . '%';
			$where .= ' AND (o.meesho_order_id LIKE %s OR o.tracking_id LIKE %s OR o.wc_order_id LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		$order_by = "ORDER BY STR_TO_DATE(o.created_at, '%d/%m/%Y') DESC";

		$query = "SELECT o.* FROM $table o WHERE $where $order_by LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$rows  = $wpdb->get_results( $wpdb->prepare( $query, $params ) );
		$total = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table o WHERE $where",
			array_slice( $params, 0, -2 )
		) );

		// Enrich each order with WooCommerce data
		$orders = array();
		foreach ( $rows as $row ) {
			$wc_order = wc_get_order( $row->wc_order_id );
			$order_data = array(
				'id'                 => $row->id,
				'wc_order_id'        => $row->wc_order_id,
				'meesho_order_id'    => $row->meesho_order_id,
				'tracking_id'        => $row->tracking_id,
				'account_used'       => $row->account_used,
				'fulfillment_status' => $row->fulfillment_status,
				'sla_status'         => $row->sla_status,
				'notes'              => $row->notes,
				'created_at'         => $row->created_at,
				'updated_at'         => $row->updated_at,
			);

			if ( $wc_order ) {
				$order_data['customer_name']  = $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name();
				$order_data['phone']          = $wc_order->get_billing_phone();
				$order_data['address']        = $wc_order->get_formatted_shipping_address() ?: $wc_order->get_formatted_billing_address();
				$order_data['payment_method']  = $wc_order->get_payment_method() === 'cod' ? 'COD' : 'Prepaid';
				$order_data['order_total']    = $wc_order->get_total();

				// Get product details
				$items = array();
				foreach ( $wc_order->get_items() as $item ) {
					$product = $item->get_product();
					$items[] = array(
						'name' => $item->get_name(),
						'sku'  => $product ? $product->get_sku() : '',
						'size' => $item->get_meta( 'pa_size' ) ?: $item->get_meta( 'size' ) ?: '',
						'qty'  => $item->get_quantity(),
					);
				}
				$order_data['items'] = $items;

				// COD risk flags
				$order_data['cod_risk'] = get_post_meta( $row->wc_order_id, '_meesho_cod_risk', true );
				$order_data['cod_risk_reasons'] = get_post_meta( $row->wc_order_id, '_meesho_cod_risk_reasons', true );

				// SLA check — parse created_at dd/mm/yyyy and check 4-hour threshold
				$order_data['sla_status'] = $this->check_sla( $row );
			}

			$orders[] = $order_data;
		}

		wp_send_json_success( array(
			'orders' => $orders,
			'total'  => intval( $total ),
			'page'   => $page,
		) );
	}

	/* ================================================================
	 *  AJAX: Update order status / fulfillment details
	 * ================================================================ */

	public function ajax_update_order() {
		meesho_master_verify_ajax_nonce();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'meesho_orders';

		$order_id = intval( $_POST['order_id'] ?? 0 );
		if ( ! $order_id ) {
			wp_send_json_error( 'Invalid order ID' );
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table WHERE id = %d", $order_id
		) );
		if ( ! $row ) {
			wp_send_json_error( 'Order not found' );
		}

		// Build update data
		$update = array();
		$format = array();

		if ( isset( $_POST['fulfillment_status'] ) ) {
			$update['fulfillment_status'] = sanitize_text_field( $_POST['fulfillment_status'] );
			$format[] = '%s';
		}
		if ( isset( $_POST['meesho_order_id'] ) ) {
			$update['meesho_order_id'] = sanitize_text_field( $_POST['meesho_order_id'] );
			$format[] = '%s';
		}
		if ( isset( $_POST['tracking_id'] ) ) {
			$update['tracking_id'] = sanitize_text_field( $_POST['tracking_id'] );
			$format[] = '%s';
		}
		if ( isset( $_POST['account_used'] ) ) {
			$update['account_used'] = sanitize_text_field( $_POST['account_used'] );
			$format[] = '%s';
		}
		if ( isset( $_POST['notes'] ) ) {
			$new_note = sanitize_textarea_field( $_POST['notes'] );
			$existing = $row->notes ? $row->notes . "\n" : '';
			$update['notes'] = $existing . '[' . date( 'd/m/Y' ) . '] ' . $new_note;
			$format[] = '%s';
		}

		$update['updated_at'] = date( 'd/m/Y' );
		$format[] = '%s';

		// Audit log before update
		$this->undo()->log(
			'order_update',
			$row->wc_order_id,
			wp_json_encode( (array) $row ),
			wp_json_encode( $update ),
			'manual'
		);

		$wpdb->update( $table, $update, array( 'id' => $order_id ), $format, array( '%d' ) );

		wp_send_json_success( 'Order updated on ' . date( 'd/m/Y' ) );
	}

	/* ================================================================
	 *  SLA check — 4-hour threshold
	 * ================================================================ */

	private function check_sla( $row ) {
		if ( $row->fulfillment_status !== self::STATUS_PENDING ) {
			return 'ok';
		}

		// Parse created_at dd/mm/yyyy
		$parts = explode( '/', $row->created_at );
		if ( count( $parts ) !== 3 ) {
			return 'ok';
		}

		// We store only date not time, so we use start of day for the order date
		$created_ts = mktime( 0, 0, 0, intval( $parts[1] ), intval( $parts[0] ), intval( $parts[2] ) );
		$elapsed    = time() - $created_ts;

		if ( $elapsed > self::SLA_THRESHOLD_SECONDS ) {
			// Update SLA status in DB
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'meesho_orders',
				array( 'sla_status' => 'breached' ),
				array( 'id' => $row->id ),
				array( '%s' ),
				array( '%d' )
			);
			return 'breached';
		}

		return 'ok';
	}

	/* ================================================================
	 *  COD Risk Assessment
	 * ================================================================ */

	public function assess_cod_risk( $order ) {
		$settings  = new Meesho_Master_Settings();
		$threshold = floatval( $settings->get( 'cod_risk_threshold' ) );
		$window    = intval( $settings->get( 'cod_repeat_window_hrs' ) );

		$reasons = array();
		$phone   = $order->get_billing_phone();
		$name    = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
		$address = $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2();

		// Rule 1: Previous COD orders returned/failed
		$history = $this->get_customer_history( $phone );
		if ( $history && $history->risk_score >= 2 ) {
			$reasons[] = 'Customer has ' . $history->risk_score . '+ previous failed/returned COD orders.';
		}

		// Rule 2: Order value exceeds threshold and is COD
		if ( floatval( $order->get_total() ) > $threshold ) {
			$reasons[] = 'COD order value (₹' . $order->get_total() . ') exceeds ₹' . $threshold . ' threshold.';
		}

		// Rule 3: Address incomplete
		if ( strlen( trim( $address ) ) < 15 || preg_match( '/^\d{6}$/', trim( $address ) ) ) {
			$reasons[] = 'Address appears incomplete (only PIN code or too short).';
		}

		// Rule 4: Invalid phone
		$clean_phone = preg_replace( '/[^0-9]/', '', $phone );
		if ( strlen( $clean_phone ) !== 10 || in_array( $clean_phone[0], array( '0', '1' ) ) ) {
			$reasons[] = 'Phone number invalid (not 10 digits or starts with 0/1).';
		}

		// Rule 5: Generic name
		$generic_names = array( 'test', 'abc', 'xyz', 'asdf', 'dummy', 'fake' );
		if ( in_array( strtolower( trim( $name ) ), $generic_names, true ) ) {
			$reasons[] = 'Customer name appears generic/fake ("' . $name . '").';
		}

		// Rule 6: Multiple orders from same phone/address within window
		$recent_count = $this->count_recent_orders( $phone, $window );
		if ( $recent_count >= 2 ) {
			$reasons[] = $recent_count . ' orders from same phone in the last ' . $window . ' hours.';
		}

		// Flag if any reasons found
		$is_risky = ! empty( $reasons );
		update_post_meta( $order->get_id(), '_meesho_cod_risk', $is_risky ? 'high' : 'low' );
		update_post_meta( $order->get_id(), '_meesho_cod_risk_reasons', $reasons );

		// Update customer risk table
		$this->update_customer_risk( $phone, $is_risky );

		return $reasons;
	}

	public function ajax_check_cod_risk() {
		meesho_master_verify_ajax_nonce();
		$order_id = intval( $_POST['wc_order_id'] ?? 0 );
		if ( ! $order_id ) {
			wp_send_json_error( 'Invalid order' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( 'Order not found' );
		}

		$reasons = $this->assess_cod_risk( $order );
		wp_send_json_success( array(
			'is_risky' => ! empty( $reasons ),
			'reasons'  => $reasons,
		) );
	}

	/* ================================================================
	 *  Customer risk tracking
	 * ================================================================ */

	private function get_customer_history( $phone ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}meesho_customers WHERE phone_number = %s",
			$phone
		) );
	}

	private function update_customer_risk( $phone, $is_risky ) {
		global $wpdb;
		$table = $wpdb->prefix . 'meesho_customers';

		$existing = $this->get_customer_history( $phone );
		if ( $existing ) {
			$new_score = $is_risky ? $existing->risk_score + 1 : $existing->risk_score;
			$wpdb->update(
				$table,
				array( 'risk_score' => $new_score ),
				array( 'id' => $existing->id ),
				array( '%d' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert( $table, array(
				'phone_number'    => $phone,
				'risk_score'      => $is_risky ? 1 : 0,
				'history_summary' => '',
				'created_at'      => date( 'd/m/Y' ),
			), array( '%s', '%d', '%s', '%s' ) );
		}
	}

	private function count_recent_orders( $phone, $window_hours ) {
		global $wpdb;

		// Count WooCommerce orders from the same phone in the last N hours
		$cutoff = date( 'Y-m-d H:i:s', time() - ( $window_hours * 3600 ) );
		$count  = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_billing_phone'
			 AND pm.meta_value = %s
			 AND p.post_type IN ('shop_order', 'wc_order')
			 AND p.post_date >= %s",
			$phone,
			$cutoff
		) );

		return intval( $count );
	}

	/* ================================================================
	 *  AJAX: Get Meesho accounts for dropdown
	 * ================================================================ */

	public function ajax_get_accounts() {
		meesho_master_verify_ajax_nonce();
		$settings = new Meesho_Master_Settings();
		$accounts = $settings->get_accounts();

		// Return labels only (not credentials) for the dropdown
		$labels = array();
		foreach ( $accounts as $acc ) {
			$labels[] = array(
				'label' => $acc['label'] ?? 'Unnamed',
				'phone' => substr( $acc['phone'] ?? '', -4 ), // last 4 digits only
			);
		}
		wp_send_json_success( $labels );
	}

	/* ================================================================
	 *  Get status flow labels
	 * ================================================================ */

	public static function get_status_labels() {
		return array(
			self::STATUS_PENDING    => 'Pending Fulfillment',
			self::STATUS_ORDERED    => 'Ordered on Meesho',
			self::STATUS_TRACKING   => 'Tracking Received',
			self::STATUS_DISPATCHED => 'Dispatched',
			self::STATUS_DELIVERED  => 'Delivered',
			self::STATUS_CANCELLED  => 'Cancelled',
			self::STATUS_RETURNED   => 'Returned',
		);
	}
}
