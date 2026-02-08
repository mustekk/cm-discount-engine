<?php
/**
 * CM Bonus Program — accrues bonus on order completion, applies as separate virtual coupon.
 *
 * Bonus stacks with the Resolver discount. Uses its own virtual coupon `cm-bonus-discount`,
 * separate from `cm-auto-discount`.
 */

defined( 'ABSPATH' ) || exit;

class CM_Bonus_Program {

	const BONUS_COUPON_CODE = 'cm-bonus-discount';

	public static function init() {
		// Accrue bonus on order paid (processing = paid, completed = delivered)
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'accrue_bonus' ), 10 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'accrue_bonus' ), 10 );

		// Apply bonus to cart (priority 100 — AFTER Resolver at 99)
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_bonus' ), 100 );

		// Virtual coupon data for bonus coupon (priority 11, after CM_Virtual_Coupon at 10)
		add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'bonus_coupon_data' ), 11, 2 );

		// Validate bonus coupon
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_bonus_coupon' ), 10, 2 );

		// Custom label for bonus coupon
		add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'bonus_coupon_label' ), 10, 2 );

		// Save bonus data to order meta
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_bonus_order_meta' ), 10, 2 );

		// Deduct bonus after order completion (priority 20, after accrue at 10)
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'deduct_bonus' ), 20 );
	}

	/**
	 * Accrue bonus after order is completed.
	 *
	 * @param int $order_id
	 */
	public static function accrue_bonus( $order_id ) {
		if ( 'yes' !== get_option( 'cm_bonus_enabled', 'no' ) ) {
			return;
		}

		$order   = wc_get_order( $order_id );
		$user_id = $order ? $order->get_customer_id() : 0;

		if ( ! $order || $user_id <= 0 ) {
			return;
		}

		// Prevent double-accrual
		if ( $order->get_meta( '_cm_bonus_accrued' ) ) {
			return;
		}

		$min_order = (float) get_option( 'cm_bonus_min_order', 0 );
		$total     = (float) $order->get_total();

		if ( $min_order > 0 && $total < $min_order ) {
			return;
		}

		// Net amount = total - shipping - tax (net amount after discounts)
		$net = $total
			- (float) $order->get_shipping_total()
			- (float) $order->get_total_tax();

		// Exclude the bonus amount used on this order from accrual base
		// (customer should not earn bonus on the portion paid by bonus).
		if ( $order->get_meta( 'cm_bonus_applied' ) === 'yes' ) {
			$bonus_used = (float) $order->get_meta( 'cm_bonus_amount' );
			$net -= $bonus_used;
		}

		if ( $net <= 0 ) {
			return;
		}

		$rate  = (float) get_option( 'cm_bonus_rate', 5 );
		$bonus = round( $net * ( $rate / 100 ), 2 );

		if ( $bonus <= 0 ) {
			return;
		}

		$current_balance = (float) get_user_meta( $user_id, 'cm_bonus_balance', true );
		$new_balance     = round( $current_balance + $bonus, 2 );
		update_user_meta( $user_id, 'cm_bonus_balance', $new_balance );

		// Set / extend expiry
		$expiry_days = (int) get_option( 'cm_bonus_expiry_days', 60 );
		if ( $expiry_days > 0 ) {
			$expires = gmdate( 'Y-m-d', strtotime( "+{$expiry_days} days" ) );
			update_user_meta( $user_id, 'cm_bonus_expires', $expires );
		} else {
			delete_user_meta( $user_id, 'cm_bonus_expires' );
		}

		$order->update_meta_data( '_cm_bonus_accrued', $bonus );
		$order->save();
	}

	/**
	 * Get available bonus balance for a user.
	 *
	 * @param int $user_id
	 * @return float
	 */
	public static function get_available_bonus( $user_id ) {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$balance = (float) get_user_meta( $user_id, 'cm_bonus_balance', true );

		if ( $balance <= 0 ) {
			return 0;
		}

		// Check expiry
		$expires = get_user_meta( $user_id, 'cm_bonus_expires', true );
		if ( ! empty( $expires ) ) {
			$expires_ts = strtotime( $expires . ' 23:59:59' );
			if ( $expires_ts && time() > $expires_ts ) {
				// Expired — reset balance
				update_user_meta( $user_id, 'cm_bonus_balance', 0 );
				return 0;
			}
		}

		return $balance;
	}

	/**
	 * Apply bonus as a virtual coupon in the cart.
	 *
	 * @param WC_Cart $cart
	 */
	public static function apply_bonus( $cart ) {
		static $running = false;
		if ( $running ) {
			return;
		}
		$running = true;

		if ( 'yes' !== get_option( 'cm_bonus_enabled', 'no' ) ) {
			$running = false;
			return;
		}

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			$running = false;
			return;
		}

		if ( ! WC()->session ) {
			$running = false;
			return;
		}

		$user_id = get_current_user_id();
		$bonus   = self::get_available_bonus( $user_id );

		$has_coupon = in_array( self::BONUS_COUPON_CODE, $cart->get_applied_coupons(), true );

		if ( $bonus <= 0 ) {
			WC()->session->set( 'cm_bonus_data', null );
			if ( $has_coupon ) {
				$cart->remove_coupon( self::BONUS_COUPON_CODE );
			}
			$running = false;
			return;
		}

		// Bonus does not apply to subscription items.
		$non_sub_total = self::get_non_subscription_eligible_total( $cart );

		if ( $non_sub_total <= 0 ) {
			WC()->session->set( 'cm_bonus_data', null );
			if ( $has_coupon ) {
				$cart->remove_coupon( self::BONUS_COUPON_CODE );
			}
			$running = false;
			return;
		}

		$bonus_to_apply = min( $bonus, $non_sub_total );

		if ( $bonus_to_apply <= 0 ) {
			WC()->session->set( 'cm_bonus_data', null );
			if ( $has_coupon ) {
				$cart->remove_coupon( self::BONUS_COUPON_CODE );
			}
			$running = false;
			return;
		}

		WC()->session->set( 'cm_bonus_data', array(
			'amount'  => round( $bonus_to_apply, 2 ),
			'balance' => $bonus,
		) );

		if ( ! $has_coupon ) {
			$cart->apply_coupon( self::BONUS_COUPON_CODE );
		}

		$running = false;
	}

	/**
	 * Provide virtual coupon data for the bonus coupon.
	 */
	public static function bonus_coupon_data( $data, $code ) {
		$code = strtolower( trim( $code ) );

		if ( $code !== self::BONUS_COUPON_CODE ) {
			return $data;
		}

		$session_data = WC()->session ? WC()->session->get( 'cm_bonus_data' ) : null;

		if ( $session_data && ! empty( $session_data['amount'] ) ) {
			return array(
				'id'                         => PHP_INT_MAX - 2,
				'amount'                     => $session_data['amount'],
				'discount_type'              => 'fixed_cart',
				'individual_use'             => false,
				'product_ids'                => array(),
				'excluded_product_ids'       => array(),
				'usage_limit'                => '',
				'usage_count'                => 0,
				'date_expires'               => '',
				'free_shipping'              => false,
				'product_categories'         => array(),
				'excluded_product_categories' => array(),
				'exclude_sale_items'         => false,
				'minimum_amount'             => '',
				'maximum_amount'             => '',
				'usage_limit_per_user'       => '',
				'virtual'                    => true,
			);
		}

		return array(
			'id'             => PHP_INT_MAX - 2,
			'amount'         => 0,
			'discount_type'  => 'fixed_cart',
			'individual_use' => false,
			'virtual'        => true,
		);
	}

	/**
	 * Validate bonus coupon.
	 */
	public static function validate_bonus_coupon( $valid, $coupon ) {
		if ( strtolower( $coupon->get_code() ) === self::BONUS_COUPON_CODE ) {
			return true;
		}
		return $valid;
	}

	/**
	 * Custom label for bonus coupon.
	 */
	public static function bonus_coupon_label( $label, $coupon ) {
		if ( strtolower( $coupon->get_code() ) === self::BONUS_COUPON_CODE ) {
			$session_data = WC()->session ? WC()->session->get( 'cm_bonus_data' ) : null;
			$balance      = $session_data && isset( $session_data['balance'] ) ? $session_data['balance'] : 0;
			return sprintf(
				__( 'Bonus balance (€%s available)', 'cm-discount-engine' ),
				number_format( $balance, 2 )
			);
		}
		return $label;
	}

	/**
	 * Save bonus data to order meta.
	 */
	public static function save_bonus_order_meta( $order, $data ) {
		if ( ! WC()->session ) {
			return;
		}

		$bonus_data = WC()->session->get( 'cm_bonus_data' );

		if ( $bonus_data && ! empty( $bonus_data['amount'] ) ) {
			$order->update_meta_data( 'cm_bonus_applied', 'yes' );
			$order->update_meta_data( 'cm_bonus_amount', $bonus_data['amount'] );
		}
	}

	/**
	 * Get total of eligible non-subscription items in the cart.
	 *
	 * @param WC_Cart $cart
	 * @return float
	 */
	private static function get_non_subscription_eligible_total( $cart ) {
		$total = 0;
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['_cm_subscription'] ) && $item['_cm_subscription'] === 'yes' ) {
				continue;
			}
			if ( CM_Discount_Types::is_item_eligible( $item ) ) {
				$total += (float) $item['data']->get_price() * $item['quantity'];
			}
		}
		return $total;
	}

	/**
	 * Deduct used bonus from user balance after order completion.
	 *
	 * @param int $order_id
	 */
	public static function deduct_bonus( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( 'cm_bonus_applied' ) !== 'yes' ) {
			return;
		}

		// Prevent double-deduction
		if ( $order->get_meta( '_cm_bonus_deducted' ) ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$used    = (float) $order->get_meta( 'cm_bonus_amount' );
		$balance = (float) get_user_meta( $user_id, 'cm_bonus_balance', true );
		$new     = max( 0, round( $balance - $used, 2 ) );
		update_user_meta( $user_id, 'cm_bonus_balance', $new );

		$order->update_meta_data( '_cm_bonus_deducted', 'yes' );
		$order->save();
	}
}
