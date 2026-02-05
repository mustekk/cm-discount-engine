<?php
/**
 * CM Flavor Attributes — acidity, sweetness, bitterness (1-5 scale).
 *
 * Adds fields to product edit screen and renders visual bars on product/listing pages.
 */

defined( 'ABSPATH' ) || exit;

class CM_Flavor_Attributes {

	/**
	 * Attribute definitions.
	 */
	private static $attributes = array(
		'_cm_acidity'    => 'Acidity',
		'_cm_sweetness'  => 'Sweetness',
		'_cm_bitterness' => 'Bitterness',
	);

	public static function init() {
		// Admin: add fields to product edit
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_fields' ) );

		// Frontend: product page (before add-to-cart form — works with Royal Addons/Elementor)
		add_action( 'woocommerce_before_add_to_cart_form', array( __CLASS__, 'render_on_product' ), 5 );

		// Frontend: listing/archive page (after title, priority 15)
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'render_on_listing' ), 15 );
	}

	/**
	 * Add flavor fields to product General tab.
	 */
	public static function add_fields() {
		echo '<div class="options_group">';
		echo '<p class="form-field"><strong>' . esc_html__( 'Flavor Profile', 'cm-discount-engine' ) . '</strong></p>';

		foreach ( self::$attributes as $key => $label ) {
			woocommerce_wp_text_input( array(
				'id'                => $key,
				'label'             => __( $label, 'cm-discount-engine' ),
				'description'       => __( 'Scale 1-5 (leave empty to hide)', 'cm-discount-engine' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '5',
					'step' => '1',
				),
			) );
		}

		echo '</div>';
	}

	/**
	 * Save flavor fields.
	 */
	public static function save_fields( $post_id ) {
		foreach ( self::$attributes as $key => $label ) {
			if ( isset( $_POST[ $key ] ) ) {
				$value = absint( $_POST[ $key ] );
				if ( $value >= 1 && $value <= 5 ) {
					update_post_meta( $post_id, $key, $value );
				} else {
					delete_post_meta( $post_id, $key );
				}
			}
		}
	}

	/**
	 * Render flavor bars on single product page.
	 */
	public static function render_on_product() {
		global $product;
		if ( ! $product ) {
			return;
		}

		$data = self::get_flavor_data( $product->get_id() );
		if ( empty( $data ) ) {
			return;
		}

		$compact  = false;
		$template = CM_DE_PLUGIN_DIR . 'templates/flavor-attributes.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Render compact flavor indicators on listing page.
	 */
	public static function render_on_listing() {
		global $product;
		if ( ! $product ) {
			return;
		}

		$data = self::get_flavor_data( $product->get_id() );
		if ( empty( $data ) ) {
			return;
		}

		$compact  = true;
		$template = CM_DE_PLUGIN_DIR . 'templates/flavor-attributes.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Get flavor data for a product.
	 *
	 * @param int $product_id
	 * @return array [ ['label' => string, 'value' => int], ... ] or empty.
	 */
	private static function get_flavor_data( $product_id ) {
		$data = array();

		foreach ( self::$attributes as $key => $label ) {
			$value = (int) get_post_meta( $product_id, $key, true );
			if ( $value >= 1 && $value <= 5 ) {
				$data[] = array(
					'label' => $label,
					'value' => $value,
				);
			}
		}

		return $data;
	}
}
