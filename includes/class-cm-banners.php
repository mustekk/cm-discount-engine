<?php
/**
 * CM Banners — CPT registration and shortcode.
 */

defined( 'ABSPATH' ) || exit;

class CM_Banners {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_shortcode( 'cm_banner', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Register the cm_banner CPT.
	 */
	public static function register_cpt() {
		register_post_type( 'cm_banner', array(
			'labels'       => array(
				'name'               => 'Banners',
				'singular_name'      => 'Banner',
				'add_new'            => 'Add Banner',
				'add_new_item'       => 'Add New Banner',
				'edit_item'          => 'Edit Banner',
				'new_item'           => 'New Banner',
				'view_item'          => 'View Banner',
				'search_items'       => 'Search Banners',
				'not_found'          => 'No banners found',
				'not_found_in_trash' => 'No banners found in Trash',
				'menu_name'          => 'Banners',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'woocommerce',
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		) );
	}

	/**
	 * Get banner data.
	 *
	 * @param int $banner_id Banner post ID.
	 * @return array|false Banner data or false if not found/disabled.
	 */
	public static function get_banner( $banner_id ) {
		$banner = get_post( $banner_id );
		if ( ! $banner || $banner->post_type !== 'cm_banner' || $banner->post_status !== 'publish' ) {
			return false;
		}

		$enabled = get_post_meta( $banner_id, '_cm_banner_enabled', true );
		if ( $enabled === 'no' ) {
			return false;
		}

		// Check dates (cache-safe)
		$start_date = get_post_meta( $banner_id, '_cm_banner_start_date', true );
		$end_date   = get_post_meta( $banner_id, '_cm_banner_end_date', true );
		$now        = current_time( 'Y-m-d' );

		if ( $start_date && $now < $start_date ) {
			return false;
		}
		if ( $end_date && $now > $end_date ) {
			return false;
		}

		return array(
			'id'            => $banner_id,
			'title'         => $banner->post_title,
			'type'          => get_post_meta( $banner_id, '_cm_banner_type', true ) ?: 'generic',
			'image_desktop' => (int) get_post_meta( $banner_id, '_cm_banner_image_desktop', true ),
			'image_mobile'  => (int) get_post_meta( $banner_id, '_cm_banner_image_mobile', true ),
			'link_url'      => get_post_meta( $banner_id, '_cm_banner_link_url', true ),
			'link_target'   => get_post_meta( $banner_id, '_cm_banner_link_target', true ) ?: '_self',
			'cta_text'      => get_post_meta( $banner_id, '_cm_banner_cta_text', true ),
			'priority'      => (int) get_post_meta( $banner_id, '_cm_banner_priority', true ) ?: 10,
			'promo_id'      => (int) get_post_meta( $banner_id, '_cm_banner_promo_id', true ),
			'conditions'    => get_post_meta( $banner_id, '_cm_banner_conditions', true ) ?: array(),
		);
	}

	/**
	 * Get banners by type.
	 *
	 * @param string $type Banner type (first_order, subscription, promo, generic).
	 * @param int    $limit Maximum number of banners to return.
	 * @return array Array of banner data.
	 */
	public static function get_banners_by_type( $type, $limit = 1 ) {
		$args = array(
			'post_type'      => 'cm_banner',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_cm_banner_type',
					'value'   => $type,
					'compare' => '=',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => '_cm_banner_enabled',
						'value'   => 'yes',
						'compare' => '=',
					),
					array(
						'key'     => '_cm_banner_enabled',
						'compare' => 'NOT EXISTS',
					),
				),
			),
			'meta_key'  => '_cm_banner_priority',
			'orderby'   => 'meta_value_num',
			'order'     => 'ASC',
		);

		$posts   = get_posts( $args );
		$banners = array();

		foreach ( $posts as $post ) {
			$banner = self::get_banner( $post->ID );
			if ( $banner ) {
				// For promo type, check if promo is still valid
				if ( $type === 'promo' && $banner['promo_id'] ) {
					$promo = CM_Promo_Codes::validate_code( get_the_title( $banner['promo_id'] ) );
					if ( ! $promo ) {
						continue; // Promo expired or exhausted
					}
				}
				$banners[] = $banner;
			}
		}

		return $banners;
	}

	/**
	 * Get banners for a location.
	 *
	 * @param string $location Location slug (home, shop, product, cart, checkout).
	 * @return array Array of banner data.
	 */
	public static function get_banners_by_location( $location ) {
		$args = array(
			'post_type'      => 'cm_banner',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_cm_banner_enabled',
					'value'   => 'yes',
					'compare' => '=',
				),
				array(
					'key'     => '_cm_banner_enabled',
					'compare' => 'NOT EXISTS',
				),
			),
			'meta_key'  => '_cm_banner_priority',
			'orderby'   => 'meta_value_num',
			'order'     => 'ASC',
		);

		$posts   = get_posts( $args );
		$banners = array();

		foreach ( $posts as $post ) {
			$banner = self::get_banner( $post->ID );
			if ( ! $banner ) {
				continue;
			}

			// Check location in conditions
			$conditions = $banner['conditions'];
			$locations  = isset( $conditions['locations'] ) ? $conditions['locations'] : array();

			// Empty locations = show everywhere
			if ( empty( $locations ) || in_array( $location, $locations, true ) ) {
				// For promo type, check if promo is still valid
				if ( $banner['type'] === 'promo' && $banner['promo_id'] ) {
					$promo = CM_Promo_Codes::validate_code( get_the_title( $banner['promo_id'] ) );
					if ( ! $promo ) {
						continue;
					}
				}
				$banners[] = $banner;
			}
		}

		return $banners;
	}

	/**
	 * Shortcode handler.
	 *
	 * Usage:
	 *   [cm_banner id="123"]              - Specific banner by ID
	 *   [cm_banner type="first_order"]    - First active banner of type
	 *   [cm_banner type="subscription"]   - Subscription banner
	 *   [cm_banner location="cart"]       - Banners for cart location
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'id'       => 0,
			'type'     => '',
			'location' => '',
		), $atts, 'cm_banner' );

		$banners = array();

		if ( $atts['id'] ) {
			$banner = self::get_banner( (int) $atts['id'] );
			if ( $banner ) {
				$banners[] = $banner;
			}
		} elseif ( $atts['type'] ) {
			$banners = self::get_banners_by_type( sanitize_key( $atts['type'] ), 1 );
		} elseif ( $atts['location'] ) {
			$banners = self::get_banners_by_location( sanitize_key( $atts['location'] ) );
		}

		if ( empty( $banners ) ) {
			return '';
		}

		$output = '';
		foreach ( $banners as $banner ) {
			$output .= self::render_banner( $banner );
		}

		return $output;
	}

	/**
	 * Render a single banner.
	 *
	 * @param array $banner Banner data.
	 * @return string HTML output.
	 */
	public static function render_banner( $banner ) {
		$template = CM_DE_PLUGIN_DIR . 'templates/banner-single.php';
		if ( ! file_exists( $template ) ) {
			return '';
		}

		// Increment impressions
		self::increment_impressions( $banner['id'] );

		// Prepare conditions for JS (data attribute)
		$conditions_json = wp_json_encode( $banner['conditions'] );

		ob_start();
		include $template;
		return ob_get_clean();
	}

	/**
	 * Increment impressions counter.
	 *
	 * @param int $banner_id Banner post ID.
	 */
	public static function increment_impressions( $banner_id ) {
		$count = (int) get_post_meta( $banner_id, '_cm_banner_impressions', true );
		update_post_meta( $banner_id, '_cm_banner_impressions', $count + 1 );
	}

	/**
	 * Get impressions count.
	 *
	 * @param int $banner_id Banner post ID.
	 * @return int Impressions count.
	 */
	public static function get_impressions( $banner_id ) {
		return (int) get_post_meta( $banner_id, '_cm_banner_impressions', true );
	}
}
