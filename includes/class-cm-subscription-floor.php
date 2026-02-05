<?php
/**
 * CM Subscription Floor — "Coffee Club" subscribe & save toggle + floor rate logic.
 *
 * Products remain simple products. A "Subscribe & Save" toggle on the product page
 * sets a cart item flag `_cm_subscription`. The Resolver treats flagged carts as
 * subscription-eligible, applying a floor rate of max(subscription_rate, quantity_tier_rate).
 */

defined( 'ABSPATH' ) || exit;

class CM_Subscription_Floor {

	public static function init() {
		// Product page: render toggle INSIDE the form (before add-to-cart button)
		// Must be inside <form> so inputs are submitted with the add-to-cart POST.
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_toggle' ) );

		// Store subscription data in cart item
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_subscription_data' ), 10, 3 );

		// Display subscription mode in cart item meta
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_subscription_in_cart' ), 10, 2 );

		// Add CSS class to subscription cart items
		add_filter( 'woocommerce_cart_item_class', array( __CLASS__, 'add_subscription_class' ), 10, 3 );
	}

	/**
	 * Render the subscribe/one-time toggle on eligible product pages.
	 */
	public static function render_toggle() {
		if ( 'yes' !== get_option( 'cm_subscription_enabled', 'no' ) ) {
			return;
		}

		global $product;
		if ( ! $product ) {
			return;
		}

		// Check if product is in an eligible category
		$terms = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
		$eligible = false;
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term_id ) {
				if ( get_term_meta( $term_id, 'cm_discount_eligible', true ) === 'yes' ) {
					$eligible = true;
					break;
				}
			}
		}

		if ( ! $eligible ) {
			return;
		}

		$rate     = (int) get_option( 'cm_subscription_rate', 20 );
		$template = CM_DE_PLUGIN_DIR . 'templates/subscription-toggle.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Add subscription flag to cart item data.
	 */
	public static function add_subscription_data( $cart_item_data, $product_id, $variation_id ) {
		if ( isset( $_POST['cm_subscription_mode'] ) && $_POST['cm_subscription_mode'] === 'subscribe' ) {
			$cart_item_data['_cm_subscription'] = 'yes';

			// Validate period against allowlist
			$allowed_periods = array( '2weeks', 'monthly' );
			$period = isset( $_POST['cm_subscription_period'] ) ? sanitize_text_field( $_POST['cm_subscription_period'] ) : 'monthly';
			$cart_item_data['_cm_subscription_period'] = in_array( $period, $allowed_periods, true ) ? $period : 'monthly';

			// Validate qty against allowlist
			$allowed_qty = array( 2, 4 );
			$qty = isset( $_POST['cm_subscription_qty'] ) ? (int) $_POST['cm_subscription_qty'] : 2;
			$cart_item_data['_cm_subscription_qty'] = in_array( $qty, $allowed_qty, true ) ? $qty : 2;

			// Validate format against allowlist
			$allowed_formats = array( 'single', 'mix', 'roaster_choice' );
			$format = isset( $_POST['cm_subscription_format'] ) ? sanitize_text_field( $_POST['cm_subscription_format'] ) : 'single';
			$cart_item_data['_cm_subscription_format'] = in_array( $format, $allowed_formats, true ) ? $format : 'single';
		}
		return $cart_item_data;
	}

	/**
	 * Display subscription info in cart item meta.
	 */
	public static function display_subscription_in_cart( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['_cm_subscription'] ) && $cart_item['_cm_subscription'] === 'yes' ) {
			$period = isset( $cart_item['_cm_subscription_period'] ) ? $cart_item['_cm_subscription_period'] : 'monthly';
			$period_label = $period === '2weeks' ? __( 'Every 2 weeks', 'cm-discount-engine' ) : __( 'Every month', 'cm-discount-engine' );

			$format_labels = array(
				'single'         => __( 'Single variety', 'cm-discount-engine' ),
				'mix'            => __( 'Mix', 'cm-discount-engine' ),
				'roaster_choice' => __( "Roaster's choice", 'cm-discount-engine' ),
			);
			$format       = isset( $cart_item['_cm_subscription_format'] ) ? $cart_item['_cm_subscription_format'] : 'single';
			$format_label = isset( $format_labels[ $format ] ) ? $format_labels[ $format ] : $format;

			$item_data[] = array(
				'key'   => __( 'Subscription', 'cm-discount-engine' ),
				'value' => $period_label,
			);
			$item_data[] = array(
				'key'   => __( 'Format', 'cm-discount-engine' ),
				'value' => $format_label,
			);
		}
		return $item_data;
	}

	/**
	 * Add CSS class to subscription cart items.
	 */
	public static function add_subscription_class( $class, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['_cm_subscription'] ) && $cart_item['_cm_subscription'] === 'yes' ) {
			$class .= ' cm-subscription-item';
		}
		return $class;
	}

	/**
	 * Check if any item in the cart is a subscription.
	 *
	 * @param WC_Cart $cart
	 * @return bool
	 */
	public static function is_subscription_cart( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['_cm_subscription'] ) && $item['_cm_subscription'] === 'yes' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the subscription floor rate: max(base_rate, quantity_tier_rate).
	 *
	 * @param WC_Cart $cart
	 * @return float Rate as percentage (e.g. 20).
	 */
	public static function get_subscription_rate( $cart ) {
		$pack_count = CM_Discount_Types::count_eligible_packs( $cart );
		$qty_tier   = CM_Discount_Types::get_quantity_tier( $pack_count );
		$qty_rate   = $qty_tier ? (float) $qty_tier['rate'] : 0;
		$base_rate  = (float) get_option( 'cm_subscription_rate', 20 );

		return max( $base_rate, $qty_rate );
	}
}
