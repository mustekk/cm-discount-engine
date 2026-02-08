<?php
/**
 * CM Virtual Coupon — WooCommerce coupon hooks, apply/remove, order meta.
 */

defined( 'ABSPATH' ) || exit;

class CM_Virtual_Coupon {

	const COUPON_CODE = 'cm-auto-discount';

	/** @var string[] Promo code slugs to hide via CSS in wp_footer. */
	private static $hidden_coupon_codes = array();

	public static function init() {
		// Virtual coupon data provider
		add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'virtual_coupon_data' ), 10, 2 );

		// Run resolver before cart totals
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_discount' ), 99 );

		// Save discount data to order
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order_meta' ), 10, 2 );

		// Save subscription data to order line items
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_subscription_item_meta' ), 10, 4 );

		// After order completed: mark first order, increment promo usage, save subscription
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_completed' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_processing' ), 10, 1 );

		// Custom coupon label in cart/checkout
		add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'coupon_label' ), 10, 2 );

		// Hide dummy promo code coupon line (zero-value) in cart totals
		add_filter( 'woocommerce_cart_totals_coupon_html', array( __CLASS__, 'hide_dummy_coupon' ), 10, 3 );

		// Validate virtual and promo coupons
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_coupon' ), 10, 2 );

		// Output CSS to hide dummy coupon rows
		add_action( 'wp_footer', array( __CLASS__, 'hide_dummy_coupon_css' ) );
	}

	/**
	 * Provide virtual coupon data when WooCommerce looks up our coupon code.
	 * Also intercepts promo codes entered in the coupon field.
	 */
	public static function virtual_coupon_data( $data, $code ) {
		$code = strtolower( trim( $code ) );

		// Our auto-discount coupon
		if ( $code === self::COUPON_CODE ) {
			$session_data = WC()->session ? WC()->session->get( 'cm_coupon_data' ) : null;

			if ( $session_data && ! empty( $session_data['amount'] ) ) {
				return array(
					'id'                         => PHP_INT_MAX,
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

			// Return minimal data to keep coupon valid even with zero amount (avoid error)
			return array(
				'id'             => PHP_INT_MAX,
				'amount'         => 0,
				'discount_type'  => 'fixed_cart',
				'individual_use' => false,
				'virtual'        => true,
			);
		}

		// Intercept promo code entered in WC coupon field
		if ( $data === false ) {
			$promo = CM_Promo_Codes::validate_code( $code );
			if ( $promo ) {
				// Store the promo code in session for the Resolver
				if ( WC()->session ) {
					WC()->session->set( 'cm_promo_code_input', $code );
				}

				// Return a zero-value dummy coupon to suppress "invalid coupon" error
				return array(
					'id'             => PHP_INT_MAX - 1,
					'amount'         => 0,
					'discount_type'  => 'fixed_cart',
					'individual_use' => false,
					'virtual'        => true,
				);
			}
		}

		return $data;
	}

	/**
	 * Main hook: run the Resolver and apply/remove the virtual coupon.
	 */
	public static function apply_discount( $cart ) {
		// Recursion guard
		static $running = false;
		if ( $running ) {
			return;
		}
		$running = true;

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			$running = false;
			return;
		}

		if ( ! WC()->session ) {
			$running = false;
			return;
		}

		$user_id = get_current_user_id();
		$email   = '';

		// Try to get email from checkout fields or session
		if ( $user_id > 0 ) {
			$user  = get_userdata( $user_id );
			$email = $user ? $user->user_email : '';
		}

		// Get promo code from session (set when user applies a coupon that matches CPT)
		$promo_code = WC()->session->get( 'cm_promo_code_input', '' );

		// Also check currently applied coupons for promo codes
		if ( empty( $promo_code ) ) {
			foreach ( $cart->get_applied_coupons() as $coupon_code ) {
				$coupon_code_lower = strtolower( $coupon_code );
				if ( $coupon_code_lower !== self::COUPON_CODE ) {
					$promo = CM_Promo_Codes::validate_code( $coupon_code_lower );
					if ( $promo ) {
						$promo_code = $coupon_code_lower;
						WC()->session->set( 'cm_promo_code_input', $promo_code );
						break;
					}
				}
			}
		}

		// Run Resolver
		$result = CM_Discount_Resolver::resolve( $cart, $user_id, $email, $promo_code );

		// Store result in session
		WC()->session->set( 'cm_active_discount', $result );

		$has_coupon = in_array( self::COUPON_CODE, $cart->get_applied_coupons(), true );

		if ( $result['type'] !== 'none' && $result['amount'] > 0 ) {
			// Store coupon data for the virtual_coupon_data filter
			WC()->session->set( 'cm_coupon_data', array(
				'amount' => $result['amount'],
				'label'  => $result['label'],
			) );

			if ( ! $has_coupon ) {
				$cart->apply_coupon( self::COUPON_CODE );
			}
		} else {
			// No discount — remove coupon if present
			WC()->session->set( 'cm_coupon_data', null );
			if ( $has_coupon ) {
				$cart->remove_coupon( self::COUPON_CODE );
			}
		}

		$running = false;
	}

	/**
	 * Save discount metadata to the order.
	 */
	public static function save_order_meta( $order, $data ) {
		if ( ! WC()->session ) {
			return;
		}

		$discount = WC()->session->get( 'cm_active_discount' );

		if ( $discount && $discount['type'] !== 'none' ) {
			$order->update_meta_data( 'cm_discount_type', $discount['type'] );
			$order->update_meta_data( 'cm_discount_rate', $discount['rate'] );
			$order->update_meta_data( 'cm_discount_amount', $discount['amount'] );

			if ( ! empty( $discount['promo_id'] ) ) {
				$order->update_meta_data( 'cm_promo_id', $discount['promo_id'] );
			}
		}

		// Check if any cart item is a subscription (regardless of which discount won).
		// This ensures subscription is saved even when promo code beats subscription discount.
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( ! empty( $item['_cm_subscription'] ) && $item['_cm_subscription'] === 'yes' ) {
					$order->update_meta_data( 'cm_is_subscription', 'yes' );
					break;
				}
			}
		}
	}

	/**
	 * Save subscription data to order line items.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param string                $cart_item_key
	 * @param array                 $values Cart item data
	 * @param WC_Order              $order
	 */
	public static function save_subscription_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['_cm_subscription'] ) && $values['_cm_subscription'] === 'yes' ) {
			$item->update_meta_data( '_cm_subscription', 'yes' );

			if ( ! empty( $values['_cm_subscription_period'] ) ) {
				$item->update_meta_data( '_cm_subscription_period', $values['_cm_subscription_period'] );
			}
			if ( ! empty( $values['_cm_subscription_qty'] ) ) {
				$item->update_meta_data( '_cm_subscription_qty', $values['_cm_subscription_qty'] );
			}
			if ( ! empty( $values['_cm_subscription_format'] ) ) {
				$item->update_meta_data( '_cm_subscription_format', $values['_cm_subscription_format'] );
			}
		}
	}

	/**
	 * After order processing: save subscription to user meta.
	 */
	public static function on_order_processing( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Save subscription data to user meta (idempotent)
		if ( $order->get_meta( 'cm_is_subscription' ) === 'yes' ) {
			if ( ! $order->get_meta( '_cm_subscription_saved' ) ) {
				CM_Subscription_Manager::save_subscription_from_order( $order_id, $order );
				$order->update_meta_data( '_cm_subscription_saved', 'yes' );
				$order->save();
			}
		}
	}

	/**
	 * After order completed: mark first order used, increment promo usage, save subscription.
	 */
	public static function on_order_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$discount_type = $order->get_meta( 'cm_discount_type' );

		// Mark first order as used
		$user_id = $order->get_customer_id();
		if ( $user_id > 0 && $discount_type === 'first_order' ) {
			update_user_meta( $user_id, 'cm_first_order_used', 1 );
		}

		// Even if first_order wasn't the winner, mark it as used if this is their first order
		if ( $user_id > 0 && ! get_user_meta( $user_id, 'cm_first_order_used', true ) ) {
			update_user_meta( $user_id, 'cm_first_order_used', 1 );
		}

		// Increment promo code usage (only once per order)
		$promo_id = $order->get_meta( 'cm_promo_id' );
		if ( $promo_id && ! $order->get_meta( '_cm_promo_usage_counted' ) ) {
			CM_Promo_Codes::increment_usage( (int) $promo_id );
			$order->update_meta_data( '_cm_promo_usage_counted', 'yes' );
			$order->save();
		}

		// Save subscription data to user meta (also on completed, in case processing was skipped)
		if ( $order->get_meta( 'cm_is_subscription' ) === 'yes' ) {
			if ( ! $order->get_meta( '_cm_subscription_saved' ) ) {
				CM_Subscription_Manager::save_subscription_from_order( $order_id, $order );
				$order->update_meta_data( '_cm_subscription_saved', 'yes' );
				$order->save();
			}
		}
	}

	/**
	 * Custom label for our auto-discount coupon in cart totals.
	 */
	public static function coupon_label( $label, $coupon ) {
		if ( strtolower( $coupon->get_code() ) === self::COUPON_CODE ) {
			$session_data = WC()->session ? WC()->session->get( 'cm_coupon_data' ) : null;
			if ( $session_data && ! empty( $session_data['label'] ) ) {
				return $session_data['label'];
			}
			return __( 'Discount', 'cm-discount-engine' );
		}
		return $label;
	}

	/**
	 * Hide the dummy zero-value promo code coupon line in cart totals.
	 * The real discount is shown via cm-auto-discount coupon.
	 */
	public static function hide_dummy_coupon( $coupon_html, $coupon, $discount_amount_html ) {
		$code = strtolower( $coupon->get_code() );

		// Don't hide our main auto-discount coupon
		if ( $code === self::COUPON_CODE ) {
			return $coupon_html;
		}

		// Hide dummy promo code coupons (zero-value intercepts)
		$promo = CM_Promo_Codes::validate_code( $code );
		if ( $promo && $coupon->get_amount() == 0 ) {
			// Output inline <style> to hide the entire <tr> row.
			// This works in AJAX cart fragments where wp_footer doesn't fire.
			$slug = sanitize_title( $code );
			self::$hidden_coupon_codes[] = $slug;
			return '<style>.coupon-' . esc_attr( $slug ) . '{display:none!important}</style>';
		}

		return $coupon_html;
	}

	/**
	 * Output CSS in wp_footer to hide dummy promo coupon rows.
	 */
	public static function hide_dummy_coupon_css() {
		if ( empty( self::$hidden_coupon_codes ) ) {
			return;
		}
		$selectors = array();
		foreach ( array_unique( self::$hidden_coupon_codes ) as $slug ) {
			$selectors[] = '.coupon-' . esc_attr( $slug );
		}
		echo '<style>' . implode( ',', $selectors ) . '{display:none!important}</style>';
	}

	/**
	 * Always validate our virtual coupon as valid.
	 */
	public static function validate_coupon( $valid, $coupon ) {
		if ( strtolower( $coupon->get_code() ) === self::COUPON_CODE ) {
			return true;
		}

		// Also validate promo codes intercepted as dummy coupons
		$code  = strtolower( $coupon->get_code() );
		$promo = CM_Promo_Codes::validate_code( $code );
		if ( $promo ) {
			return true;
		}

		return $valid;
	}
}
