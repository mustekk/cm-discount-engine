<?php
/**
 * CM Banner Visibility — Conditions engine for banner display.
 */

defined( 'ABSPATH' ) || exit;

class CM_Banner_Visibility {

	/**
	 * Check if banner should be visible for current user context.
	 *
	 * This method is called via AJAX/REST to evaluate user-dependent conditions.
	 * Cache-safe conditions (dates, enabled) are checked in CM_Banners::get_banner().
	 *
	 * @param array $banner     Banner data.
	 * @param array $context    User context from CM_Banner_API.
	 * @return bool True if banner should be shown.
	 */
	public static function is_visible( $banner, $context ) {
		$conditions = $banner['conditions'];
		if ( empty( $conditions ) ) {
			return true;
		}

		// Check user state
		if ( ! empty( $conditions['user_state'] ) ) {
			if ( ! self::check_user_state( $conditions['user_state'], $context ) ) {
				return false;
			}
		}

		// Check cart state
		if ( ! empty( $conditions['cart_state'] ) ) {
			if ( ! self::check_cart_state( $conditions['cart_state'], $context ) ) {
				return false;
			}
		}

		// Check "hide for subscribers"
		if ( ! empty( $conditions['hide_for_subscribers'] ) && $conditions['hide_for_subscribers'] === 'yes' ) {
			if ( ! empty( $context['is_subscriber'] ) ) {
				return false;
			}
		}

		// Check "hide after first order"
		if ( ! empty( $conditions['hide_after_first_order'] ) && $conditions['hide_after_first_order'] === 'yes' ) {
			if ( empty( $context['is_first_order'] ) ) {
				return false; // User has already made orders
			}
		}

		// Type-specific checks
		$type = $banner['type'];

		// First order banner: only show to users without orders
		if ( $type === 'first_order' ) {
			if ( empty( $context['is_first_order'] ) ) {
				return false;
			}
		}

		// Subscription banner: don't show to active subscribers
		if ( $type === 'subscription' ) {
			if ( ! empty( $context['is_subscriber'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check user state condition.
	 *
	 * @param string $state    Required state (logged_in, logged_out, new_visitor).
	 * @param array  $context  User context.
	 * @return bool
	 */
	private static function check_user_state( $state, $context ) {
		switch ( $state ) {
			case 'logged_in':
				return ! empty( $context['is_logged_in'] );
			case 'logged_out':
				return empty( $context['is_logged_in'] );
			case 'new_visitor':
				// New visitor = not logged in AND first order candidate
				return empty( $context['is_logged_in'] ) && ! empty( $context['is_first_order'] );
			case 'any':
			default:
				return true;
		}
	}

	/**
	 * Check cart state condition.
	 *
	 * @param string $state    Required state (empty, not_empty, any).
	 * @param array  $context  User context.
	 * @return bool
	 */
	private static function check_cart_state( $state, $context ) {
		$cart_count = isset( $context['cart_count'] ) ? (int) $context['cart_count'] : 0;

		switch ( $state ) {
			case 'empty':
				return $cart_count === 0;
			case 'not_empty':
				return $cart_count > 0;
			case 'any':
			default:
				return true;
		}
	}

	/**
	 * Get all available conditions for admin UI.
	 *
	 * @return array Conditions configuration.
	 */
	public static function get_conditions_config() {
		return array(
			'user_state' => array(
				'label'   => __( 'User State', 'cm-discount-engine' ),
				'options' => array(
					'any'         => __( 'Any', 'cm-discount-engine' ),
					'logged_in'   => __( 'Logged in', 'cm-discount-engine' ),
					'logged_out'  => __( 'Not logged in', 'cm-discount-engine' ),
					'new_visitor' => __( 'New visitor (no orders)', 'cm-discount-engine' ),
				),
			),
			'cart_state' => array(
				'label'   => __( 'Cart State', 'cm-discount-engine' ),
				'options' => array(
					'any'       => __( 'Any', 'cm-discount-engine' ),
					'empty'     => __( 'Cart is empty', 'cm-discount-engine' ),
					'not_empty' => __( 'Cart has items', 'cm-discount-engine' ),
				),
			),
			'hide_for_subscribers' => array(
				'label' => __( 'Hide for Coffee Club subscribers', 'cm-discount-engine' ),
				'type'  => 'checkbox',
			),
			'hide_after_first_order' => array(
				'label' => __( 'Hide after first order', 'cm-discount-engine' ),
				'type'  => 'checkbox',
			),
			'locations' => array(
				'label'   => __( 'Show on pages', 'cm-discount-engine' ),
				'type'    => 'multi',
				'options' => array(
					'home'     => __( 'Home page', 'cm-discount-engine' ),
					'shop'     => __( 'Shop / Category pages', 'cm-discount-engine' ),
					'product'  => __( 'Product pages', 'cm-discount-engine' ),
					'cart'     => __( 'Cart', 'cm-discount-engine' ),
					'checkout' => __( 'Checkout', 'cm-discount-engine' ),
				),
			),
		);
	}
}
