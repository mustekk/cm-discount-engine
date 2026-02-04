<?php
/**
 * Plugin Name: CM Discount Engine
 * Description: Coffee Madman — автоматическая система скидок (first order, quantity tiers, promo codes) с выбором лучшей скидки.
 * Version: 1.2.0
 * Author: Coffee Madman
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.6
 * Text Domain: cm-discount-engine
 */

defined( 'ABSPATH' ) || exit;

define( 'CM_DE_VERSION', '1.2.0' );
define( 'CM_DE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CM_DE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CM_DE_PLUGIN_FILE', __FILE__ );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Activation hook — set default options.
 */
function cm_de_activate() {
	if ( false === get_option( 'cm_first_order_enabled' ) ) {
		add_option( 'cm_first_order_enabled', 'yes' );
	}
	if ( false === get_option( 'cm_first_order_rate' ) ) {
		add_option( 'cm_first_order_rate', 10 );
	}
	if ( false === get_option( 'cm_quantity_enabled' ) ) {
		add_option( 'cm_quantity_enabled', 'yes' );
	}
	if ( false === get_option( 'cm_quantity_tiers' ) ) {
		add_option( 'cm_quantity_tiers', array(
			array( 'min_packs' => 3, 'rate' => 5 ),
			array( 'min_packs' => 4, 'rate' => 10 ),
			array( 'min_packs' => 8, 'rate' => 15 ),
		) );
	}
	if ( false === get_option( 'cm_promo_enabled' ) ) {
		add_option( 'cm_promo_enabled', 'yes' );
	}
	if ( false === get_option( 'cm_free_shipping_threshold' ) ) {
		add_option( 'cm_free_shipping_threshold', 30 );
	}
}
register_activation_hook( __FILE__, 'cm_de_activate' );

/**
 * Bootstrap — load classes after plugins_loaded to ensure WooCommerce is available.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="error"><p><strong>CM Discount Engine</strong> requires WooCommerce to be installed and active.</p></div>';
		} );
		return;
	}

	// Core
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-promo-codes.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-discount-types.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-discount-resolver.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-virtual-coupon.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-upsell-engine.php';

	// Admin — promo codes UI (doesn't depend on WC_Settings_Page)
	if ( is_admin() ) {
		require_once CM_DE_PLUGIN_DIR . 'admin/class-cm-admin-promo-codes.php';
	}

	// Initialize
	CM_Promo_Codes::init();
	CM_Virtual_Coupon::init();
	CM_Upsell_Engine::init();

	if ( is_admin() ) {
		CM_Admin_Promo_Codes::init();
	}

	// Admin settings — load via WC filter so WC_Settings_Page is available
	add_filter( 'woocommerce_get_settings_pages', function ( $settings ) {
		require_once CM_DE_PLUGIN_DIR . 'admin/class-cm-admin-settings.php';
		$settings[] = new CM_Admin_Settings();
		return $settings;
	} );

	// Enqueue frontend CSS & JS
	add_action( 'wp_enqueue_scripts', function () {
		if ( is_product() || is_cart() || is_checkout() ) {
			wp_enqueue_style(
				'cm-discount-frontend',
				CM_DE_PLUGIN_URL . 'assets/css/cm-discount-frontend.css',
				array(),
				CM_DE_VERSION
			);
		}

		// Product page: dynamic upsell JS
		if ( is_product() && 'yes' === get_option( 'cm_quantity_enabled', 'yes' ) ) {
			wp_enqueue_script(
				'cm-product-upsell',
				CM_DE_PLUGIN_URL . 'assets/js/cm-product-upsell.js',
				array(),
				CM_DE_VERSION,
				true
			);

			$tiers = CM_Upsell_Engine::get_product_hint_tiers();
			$js_tiers = array();
			foreach ( $tiers as $tier ) {
				$js_tiers[] = array(
					'min_packs' => (int) $tier['min_packs'],
					'rate'      => (float) $tier['rate'],
				);
			}

			wp_localize_script( 'cm-product-upsell', 'cmUpsellData', array(
				'tiers'     => $js_tiers,
				'cartPacks' => CM_Upsell_Engine::get_cart_eligible_packs(),
			) );
		}
	} );
} );
