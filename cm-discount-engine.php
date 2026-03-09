<?php
/**
 * Plugin Name: CM Discount Engine
 * Description: Coffee Madman — система скидок (first order, quantity tiers, promo codes, subscription, bonus, referral) + flavor attributes, reorder, catalog tiers.
 * Version: 2.3.2
 * Author: Coffee Madman
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.6
 * Text Domain: cm-discount-engine
 */

defined( 'ABSPATH' ) || exit;

define( 'CM_DE_VERSION', '2.4.0' );
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
 * Activation hook — set default options + create tables.
 */
function cm_de_activate() {
	// Phase 1 defaults
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

	// Phase 2 defaults — Subscription
	if ( false === get_option( 'cm_subscription_enabled' ) ) {
		add_option( 'cm_subscription_enabled', 'no' );
	}
	if ( false === get_option( 'cm_subscription_rate' ) ) {
		add_option( 'cm_subscription_rate', 20 );
	}

	// Phase 2 defaults — Bonus
	if ( false === get_option( 'cm_bonus_enabled' ) ) {
		add_option( 'cm_bonus_enabled', 'no' );
	}
	if ( false === get_option( 'cm_bonus_rate' ) ) {
		add_option( 'cm_bonus_rate', 5 );
	}
	if ( false === get_option( 'cm_bonus_expiry_days' ) ) {
		add_option( 'cm_bonus_expiry_days', 60 );
	}
	if ( false === get_option( 'cm_bonus_min_order' ) ) {
		add_option( 'cm_bonus_min_order', 0 );
	}

	// Phase 2 defaults — Referral
	if ( false === get_option( 'cm_referral_enabled' ) ) {
		add_option( 'cm_referral_enabled', 'no' );
	}
	if ( false === get_option( 'cm_referral_rate' ) ) {
		add_option( 'cm_referral_rate', 10 );
	}
	if ( false === get_option( 'cm_referral_sub_payments' ) ) {
		add_option( 'cm_referral_sub_payments', 3 );
	}

	// Phase 2 defaults — Catalog tiers
	if ( false === get_option( 'cm_entry_max_price' ) ) {
		add_option( 'cm_entry_max_price', 12 );
	}
	if ( false === get_option( 'cm_core_max_price' ) ) {
		add_option( 'cm_core_max_price', 18 );
	}

	// Phase 2 defaults — Auto-Renewal
	if ( false === get_option( 'cm_auto_renewal_enabled' ) ) {
		add_option( 'cm_auto_renewal_enabled', 'no' );
	}
	if ( false === get_option( 'cm_auto_renewal_max_retries' ) ) {
		add_option( 'cm_auto_renewal_max_retries', 3 );
	}
	if ( false === get_option( 'cm_auto_renewal_retry_days' ) ) {
		add_option( 'cm_auto_renewal_retry_days', '1,3,7' );
	}
	if ( false === get_option( 'cm_auto_renewal_reminder_days' ) ) {
		add_option( 'cm_auto_renewal_reminder_days', 3 );
	}

	// Create referral commissions table
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-referral-tracker.php';
	CM_Referral_Tracker::create_table();

	// Flush rewrite rules for subscription endpoint
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-subscription-manager.php';
	CM_Subscription_Manager::flush_rules();
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

	// ─── Core (Phase 1) ──────────────────────────────────────
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-promo-codes.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-discount-types.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-discount-resolver.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-virtual-coupon.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-upsell-engine.php';

	// ─── Phase 2 includes ────────────────────────────────────
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-subscription-floor.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-subscription-manager.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-bonus-program.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-referral-tracker.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-flavor-attributes.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-my-account.php';

	// ─── Shipping (Europe/UK) ────────────────────────────────────
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-shipping-weight-rate.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-shipping-express.php';

	// ─── Auto-Renewal ───────────────────────────────────────────
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-auto-renewal.php';

	// ─── Banners ────────────────────────────────────────────────
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-banners.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-banner-visibility.php';
	require_once CM_DE_PLUGIN_DIR . 'includes/class-cm-banner-api.php';

	// Admin
	if ( is_admin() ) {
		require_once CM_DE_PLUGIN_DIR . 'admin/class-cm-admin-promo-codes.php';
		require_once CM_DE_PLUGIN_DIR . 'admin/class-cm-admin-referral.php';
		require_once CM_DE_PLUGIN_DIR . 'admin/class-cm-admin-subscriptions.php';
		require_once CM_DE_PLUGIN_DIR . 'admin/class-cm-admin-banners.php';
	}

	// ─── Initialize ──────────────────────────────────────────
	CM_Promo_Codes::init();
	CM_Virtual_Coupon::init();
	CM_Upsell_Engine::init();
	CM_Subscription_Floor::init();
	CM_Subscription_Manager::init();
	CM_Bonus_Program::init();
	CM_Referral_Tracker::init();
	CM_Flavor_Attributes::init();
	CM_My_Account::init();
	CM_Auto_Renewal::init();
	CM_Banners::init();
	CM_Banner_API::init();

	if ( is_admin() ) {
		CM_Admin_Promo_Codes::init();
		CM_Admin_Referral::init();
		CM_Admin_Subscriptions::init();
		CM_Admin_Banners::init();
	}

	// Admin settings — load via WC filter so WC_Settings_Page is available
	add_filter( 'woocommerce_get_settings_pages', function ( $settings ) {
		require_once CM_DE_PLUGIN_DIR . 'admin/class-cm-admin-settings.php';
		$settings[] = new CM_Admin_Settings();
		return $settings;
	} );

	// ─── Shipping: hide flat rate when free shipping is available ───
	add_filter( 'woocommerce_package_rates', function ( $rates ) {
		$free = false;
		foreach ( $rates as $rate ) {
			if ( 'free_shipping' === $rate->method_id ) {
				$free = true;
				break;
			}
		}
		if ( $free ) {
			foreach ( $rates as $id => $rate ) {
				if ( 'free_shipping' !== $rate->method_id ) {
					unset( $rates[ $id ] );
				}
			}
		}
		return $rates;
	} );

	// ─── Register custom shipping methods (Europe/UK) ──────────
	add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
		$methods['cm_weight_shipping']  = 'CM_Shipping_Weight_Rate';
		$methods['cm_express_shipping'] = 'CM_Shipping_Express';
		return $methods;
	} );

	// ─── Delivery time label next to shipping rate ──────────────
	add_action( 'woocommerce_after_shipping_rate', function ( $method ) {
		$custom_ids = array( 'cm_weight_shipping', 'cm_express_shipping' );
		if ( in_array( $method->method_id, $custom_ids, true ) ) {
			$meta = $method->get_meta_data();
			if ( ! empty( $meta['delivery_time'] ) ) {
				echo '<br><small class="cm-delivery-time">' . esc_html( $meta['delivery_time'] ) . '</small>';
			}
		}
	} );

	// ─── Catalog tiers: add CSS classes based on price ──────────
	add_filter( 'woocommerce_post_class', function ( $classes, $product ) {
		if ( ! $product ) {
			return $classes;
		}

		$price     = (float) $product->get_price();
		$entry_max = (float) get_option( 'cm_entry_max_price', 12 );
		$core_max  = (float) get_option( 'cm_core_max_price', 18 );

		if ( $price <= $entry_max ) {
			$classes[] = 'cm-tier-entry';
		} elseif ( $price <= $core_max ) {
			$classes[] = 'cm-tier-core';
		} else {
			$classes[] = 'cm-tier-premium';
		}

		return $classes;
	}, 10, 2 );

	// ─── Cross-sell in cart ─────────────────────────────────────
	if ( ! has_action( 'woocommerce_after_cart_table', 'woocommerce_cross_sell_display' ) ) {
		add_action( 'woocommerce_after_cart_table', 'woocommerce_cross_sell_display' );
	}

	// ─── Enqueue frontend CSS & JS ──────────────────────────────
	add_action( 'wp_enqueue_scripts', function () {
		// CSS: always load — flavor bars, subscription toggle, etc. can appear on any page
		// (Elementor product grids, shortcodes, homepage widgets)
		wp_enqueue_style(
			'cm-discount-frontend',
			CM_DE_PLUGIN_URL . 'assets/css/cm-discount-frontend.css',
			array(),
			CM_DE_VERSION
		);

		// Banner CSS & JS (always load — shortcode can be on any page)
		wp_enqueue_style(
			'cm-banners',
			CM_DE_PLUGIN_URL . 'assets/css/cm-banners.css',
			array(),
			CM_DE_VERSION
		);

		wp_enqueue_script(
			'cm-banners',
			CM_DE_PLUGIN_URL . 'assets/js/cm-banners.js',
			array(),
			CM_DE_VERSION,
			true
		);

		wp_localize_script( 'cm-banners', 'cmBannersData', array(
			'restUrl' => esc_url_raw( rest_url() ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		) );

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
				'tiers'            => $js_tiers,
				'cartPacks'        => CM_Upsell_Engine::get_cart_eligible_packs(),
				'subscriptionRate' => ( 'yes' === get_option( 'cm_subscription_enabled', 'no' ) )
					? (int) get_option( 'cm_subscription_rate', 20 )
					: 0,
			) );
		}

		// Product page: subscription toggle JS
		if ( is_product() && 'yes' === get_option( 'cm_subscription_enabled', 'no' ) ) {
			wp_enqueue_script(
				'cm-subscription-toggle',
				CM_DE_PLUGIN_URL . 'assets/js/cm-subscription-toggle.js',
				array(),
				CM_DE_VERSION,
				true
			);

			wp_localize_script( 'cm-subscription-toggle', 'cmSubscriptionData', array(
				'rate' => (int) get_option( 'cm_subscription_rate', 20 ),
			) );
		}

		// Shop/category/any page with product grids: tier gap detection JS
		if ( is_shop() || is_product_category() || is_page() ) {
			wp_register_script( 'cm-tier-gap', '', array(), CM_DE_VERSION, true );
			wp_enqueue_script( 'cm-tier-gap' );
			wp_add_inline_script( 'cm-tier-gap', '
				(function(){
					var products = document.querySelectorAll(".products .product");
					if (!products.length) return;
					var prevTier = "";
					for (var i = 0; i < products.length; i++) {
						var el = products[i];
						var tier = "";
						if (el.classList.contains("cm-tier-entry")) tier = "entry";
						else if (el.classList.contains("cm-tier-core")) tier = "core";
						else if (el.classList.contains("cm-tier-premium")) tier = "premium";
						if (prevTier && tier && tier !== prevTier) {
							el.classList.add("cm-tier-gap");
						}
						if (tier) prevTier = tier;
					}
				})();
			' );
		}
	} );
} );
