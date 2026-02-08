<?php
/**
 * CM Banner API — REST endpoint for user context (cache-compatible).
 */

defined( 'ABSPATH' ) || exit;

class CM_Banner_API {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {
		register_rest_route( 'cm/v1', '/banner-context', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_context' ),
			'permission_callback' => '__return_true', // Public endpoint
		) );
	}

	/**
	 * Get user context for banner visibility.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_context( $request ) {
		$context = array(
			'is_logged_in'  => is_user_logged_in(),
			'is_first_order' => true,
			'is_subscriber' => false,
			'cart_count'    => 0,
		);

		// Check first order status
		if ( is_user_logged_in() ) {
			$user_id        = get_current_user_id();
			$first_order_used = get_user_meta( $user_id, 'cm_first_order_used', true );
			$context['is_first_order'] = empty( $first_order_used ) || $first_order_used !== '1';

			// Check subscription status
			$sub_status = get_user_meta( $user_id, 'cm_subscription_status', true );
			$context['is_subscriber'] = $sub_status === 'active';
		} else {
			// For guests, check by session/cookie if available
			// For now, assume first order for guests
			$context['is_first_order'] = true;
		}

		// Get cart count
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$context['cart_count'] = WC()->cart->get_cart_contents_count();
		}

		return rest_ensure_response( $context );
	}

	/**
	 * Build context array (non-REST, for internal use).
	 *
	 * @return array User context.
	 */
	public static function build_context() {
		$context = array(
			'is_logged_in'   => is_user_logged_in(),
			'is_first_order' => true,
			'is_subscriber'  => false,
			'cart_count'     => 0,
		);

		if ( is_user_logged_in() ) {
			$user_id          = get_current_user_id();
			$first_order_used = get_user_meta( $user_id, 'cm_first_order_used', true );
			$context['is_first_order'] = empty( $first_order_used ) || $first_order_used !== '1';

			$sub_status = get_user_meta( $user_id, 'cm_subscription_status', true );
			$context['is_subscriber'] = $sub_status === 'active';
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			$context['cart_count'] = WC()->cart->get_cart_contents_count();
		}

		return $context;
	}
}
