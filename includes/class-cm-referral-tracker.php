<?php
/**
 * CM Referral Tracker — tracks referral promo codes and commissions.
 *
 * Referral promo code = normal promo in Resolver. Attribution saved to order meta
 * even if promo loses. Commission tracked in custom DB table.
 */

defined( 'ABSPATH' ) || exit;

class CM_Referral_Tracker {

	public static function init() {
		// Track referral before Resolver runs (priority 98)
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'track_referral' ), 98 );

		// Save referral meta to order
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_referral_meta' ), 10, 2 );

		// Record commission on order completion
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'record_commission' ), 10 );
	}

	/**
	 * Create the commissions table (called from activation hook).
	 */
	public static function create_table() {
		global $wpdb;

		$table   = $wpdb->prefix . 'cm_referral_commissions';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			referrer_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			amount decimal(10,2) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY order_id (order_id),
			KEY referrer_id (referrer_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Track referral promo code in session.
	 *
	 * @param WC_Cart $cart
	 */
	public static function track_referral( $cart ) {
		if ( 'yes' !== get_option( 'cm_referral_enabled', 'no' ) ) {
			return;
		}

		if ( ! WC()->session ) {
			return;
		}

		$promo_code = WC()->session->get( 'cm_promo_code_input', '' );

		if ( empty( $promo_code ) ) {
			// Also check applied coupons
			foreach ( $cart->get_applied_coupons() as $coupon_code ) {
				$coupon_code_lower = strtolower( $coupon_code );
				if ( $coupon_code_lower !== CM_Virtual_Coupon::COUPON_CODE
					&& $coupon_code_lower !== CM_Bonus_Program::BONUS_COUPON_CODE ) {
					$promo = CM_Promo_Codes::validate_code( $coupon_code_lower );
					if ( $promo ) {
						$promo_code = $coupon_code_lower;
						break;
					}
				}
			}
		}

		if ( empty( $promo_code ) ) {
			return;
		}

		// Look up the promo code post
		$promo = CM_Promo_Codes::validate_code( $promo_code );
		if ( ! $promo ) {
			return;
		}

		// Check if this promo is a referral code
		$is_referral = get_post_meta( $promo['id'], '_cm_is_referral', true );
		if ( $is_referral !== 'yes' ) {
			return;
		}

		$referrer_user_id = get_post_meta( $promo['id'], '_cm_referrer_user_id', true );
		if ( ! empty( $referrer_user_id ) ) {
			WC()->session->set( 'cm_referrer_id', (int) $referrer_user_id );
		}
	}

	/**
	 * Save referral attribution to order meta (regardless of Resolver outcome).
	 */
	public static function save_referral_meta( $order, $data ) {
		if ( ! WC()->session ) {
			return;
		}

		$referrer_id = WC()->session->get( 'cm_referrer_id' );
		if ( ! empty( $referrer_id ) ) {
			$order->update_meta_data( 'cm_referrer_id', (int) $referrer_id );
		}
	}

	/**
	 * Record referral commission when order is completed.
	 *
	 * @param int $order_id
	 */
	public static function record_commission( $order_id ) {
		if ( 'yes' !== get_option( 'cm_referral_enabled', 'no' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$referrer_id = $order->get_meta( 'cm_referrer_id' );
		if ( empty( $referrer_id ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cm_referral_commissions';

		// Check for existing commission (prevent duplicates via UNIQUE KEY)
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE order_id = %d",
			$order_id
		) );

		if ( $existing ) {
			return;
		}

		// For subscription orders, check payment count limit
		if ( $order->get_meta( 'cm_is_subscription' ) === 'yes' ) {
			$sub_limit = (int) get_option( 'cm_referral_sub_payments', 3 );
			if ( $sub_limit > 0 ) {
				$customer_id = $order->get_customer_id();
				if ( $customer_id > 0 ) {
					$payment_count = $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$table} c
						INNER JOIN {$wpdb->prefix}wc_orders o ON c.order_id = o.id
						WHERE c.referrer_id = %d AND o.customer_id = %d",
						$referrer_id,
						$customer_id
					) );
					if ( $payment_count >= $sub_limit ) {
						return;
					}
				}
			}
		}

		// Calculate commission: net revenue = total - shipping - tax
		$net_revenue = (float) $order->get_total()
			- (float) $order->get_shipping_total()
			- (float) $order->get_total_tax();

		if ( $net_revenue <= 0 ) {
			return;
		}

		$rate       = (float) get_option( 'cm_referral_rate', 10 );
		$commission = round( $net_revenue * ( $rate / 100 ), 2 );

		if ( $commission <= 0 ) {
			return;
		}

		$wpdb->insert( $table, array(
			'referrer_id' => (int) $referrer_id,
			'order_id'    => $order_id,
			'amount'      => $commission,
			'status'      => 'pending',
			'created_at'  => current_time( 'mysql', true ),
		), array( '%d', '%d', '%f', '%s', '%s' ) );
	}
}
