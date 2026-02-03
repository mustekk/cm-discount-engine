<?php
/**
 * CM Promo Codes — CPT registration and validation.
 */

defined( 'ABSPATH' ) || exit;

class CM_Promo_Codes {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
	}

	/**
	 * Register the cm_promo_code CPT.
	 */
	public static function register_cpt() {
		register_post_type( 'cm_promo_code', array(
			'labels'       => array(
				'name'               => 'Promo Codes',
				'singular_name'      => 'Promo Code',
				'add_new'            => 'Add Promo Code',
				'add_new_item'       => 'Add New Promo Code',
				'edit_item'          => 'Edit Promo Code',
				'new_item'           => 'New Promo Code',
				'view_item'          => 'View Promo Code',
				'search_items'       => 'Search Promo Codes',
				'not_found'          => 'No promo codes found',
				'not_found_in_trash' => 'No promo codes found in Trash',
				'menu_name'          => 'Promo Codes',
			),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'woocommerce',
			'supports'     => array( 'title' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		) );
	}

	/**
	 * Validate a promo code.
	 *
	 * @param string $code The promo code (case-insensitive).
	 * @return array|false Array with promo data on success, false on failure.
	 */
	public static function validate_code( $code ) {
		if ( 'yes' !== get_option( 'cm_promo_enabled', 'yes' ) ) {
			return false;
		}

		$code = strtolower( trim( $code ) );
		if ( empty( $code ) ) {
			return false;
		}

		$posts = get_posts( array(
			'post_type'      => 'cm_promo_code',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'title'          => $code,
			// title search is not exact by default, we filter below
		) );

		$promo_post = null;
		foreach ( $posts as $post ) {
			if ( strtolower( $post->post_title ) === $code ) {
				$promo_post = $post;
				break;
			}
		}

		if ( ! $promo_post ) {
			return false;
		}

		$promo_id    = $promo_post->ID;
		$type        = get_post_meta( $promo_id, '_cm_promo_type', true );   // percent or fixed
		$value       = (float) get_post_meta( $promo_id, '_cm_promo_value', true );
		$expires     = get_post_meta( $promo_id, '_cm_promo_expires', true );
		$usage_limit = (int) get_post_meta( $promo_id, '_cm_promo_usage_limit', true );
		$usage_count = (int) get_post_meta( $promo_id, '_cm_promo_usage_count', true );

		// Check expiry
		if ( ! empty( $expires ) ) {
			$expires_ts = strtotime( $expires . ' 23:59:59' );
			if ( $expires_ts && time() > $expires_ts ) {
				return false;
			}
		}

		// Check usage limit
		if ( $usage_limit > 0 && $usage_count >= $usage_limit ) {
			return false;
		}

		if ( $value <= 0 ) {
			return false;
		}

		return array(
			'id'    => $promo_id,
			'code'  => $code,
			'type'  => $type ?: 'percent',
			'value' => $value,
		);
	}

	/**
	 * Increment usage count for a promo code.
	 *
	 * @param int $promo_id Post ID of the promo code.
	 */
	public static function increment_usage( $promo_id ) {
		$count = (int) get_post_meta( $promo_id, '_cm_promo_usage_count', true );
		update_post_meta( $promo_id, '_cm_promo_usage_count', $count + 1 );
	}
}
