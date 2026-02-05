<?php
/**
 * CM Upsell Engine — product page hints, cart upsell messages, shipping threshold.
 */

defined( 'ABSPATH' ) || exit;

class CM_Upsell_Engine {

	public static function init() {
		// Product page: discount hint after add-to-cart form
		add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'product_page_hint' ) );

		// Cart page: upsell message before cart table
		add_action( 'woocommerce_before_cart_table', array( __CLASS__, 'cart_upsell_message' ) );

		// Cart totals: shipping threshold hint
		add_action( 'woocommerce_cart_totals_before_shipping', array( __CLASS__, 'shipping_hint' ) );

		// Cart: applied discount explanation
		add_action( 'woocommerce_before_cart_table', array( __CLASS__, 'cart_discount_notice' ), 5 );
	}

	/**
	 * Show discount hint on product page.
	 */
	public static function product_page_hint() {
		global $product;

		if ( ! $product || 'yes' !== get_option( 'cm_quantity_enabled', 'yes' ) ) {
			return;
		}

		// Check if this product is in an eligible category
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

		$tiers = self::get_product_hint_tiers();

		if ( ! empty( $tiers ) ) {
			$template = self::locate_template( 'product-discount-hint.php' );
			if ( $template ) {
				include $template;
			}
		}
	}

	/**
	 * Get sorted tiers array for the product page hint.
	 *
	 * @return array Sorted tiers [ ['min_packs' => int, 'rate' => float], ... ]
	 */
	public static function get_product_hint_tiers() {
		$tiers = get_option( 'cm_quantity_tiers', array() );
		if ( empty( $tiers ) || ! is_array( $tiers ) ) {
			return array();
		}

		// Sort ascending
		usort( $tiers, function ( $a, $b ) {
			return (int) $a['min_packs'] - (int) $b['min_packs'];
		} );

		return $tiers;
	}

	/**
	 * Get product page hint text (legacy, used by get_cart_upsell for compatibility).
	 *
	 * @param WC_Product $product
	 * @return string|null Hint text or null.
	 */
	public static function get_product_hint( $product ) {
		$tiers = self::get_product_hint_tiers();
		if ( empty( $tiers ) ) {
			return null;
		}

		$parts = array();
		foreach ( $tiers as $tier ) {
			$parts[] = sprintf(
				'%d+ packs → -%s%%',
				$tier['min_packs'],
				$tier['rate']
			);
		}

		return implode( ' | ', $parts );
	}

	/**
	 * Show upsell message in cart.
	 */
	public static function cart_upsell_message() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$upsell = self::get_cart_upsell( WC()->cart );

		if ( $upsell ) {
			$message  = $upsell;
			$shop_url = self::get_eligible_shop_url();
			$template = self::locate_template( 'upsell-message.php' );
			if ( $template ) {
				include $template;
			}
		}
	}

	/**
	 * Get URL to the first eligible product category, or fall back to the shop page.
	 *
	 * @return string
	 */
	private static function get_eligible_shop_url() {
		$eligible_cats = get_terms( array(
			'taxonomy'   => 'product_cat',
			'meta_key'   => 'cm_discount_eligible',
			'meta_value' => 'yes',
			'number'     => 1,
			'fields'     => 'ids',
		) );

		if ( ! empty( $eligible_cats ) && ! is_wp_error( $eligible_cats ) ) {
			$link = get_term_link( (int) $eligible_cats[0], 'product_cat' );
			if ( ! is_wp_error( $link ) ) {
				return $link;
			}
		}

		return wc_get_page_permalink( 'shop' );
	}

	/**
	 * Get cart upsell message.
	 *
	 * @param WC_Cart $cart
	 * @return string|null Message or null.
	 */
	public static function get_cart_upsell( $cart ) {
		if ( 'yes' !== get_option( 'cm_quantity_enabled', 'yes' ) ) {
			return null;
		}

		$current_packs = CM_Discount_Types::count_eligible_packs( $cart );
		$next_tier     = CM_Discount_Types::get_next_tier( $current_packs );

		if ( ! $next_tier ) {
			return null;
		}

		$packs_needed    = (int) $next_tier['min_packs'] - $current_packs;
		$eligible_total  = CM_Discount_Types::get_eligible_total( $cart );

		// Current saving
		$current_tier   = CM_Discount_Types::get_quantity_tier( $current_packs );
		$current_rate   = $current_tier ? (float) $current_tier['rate'] : 0;
		$current_saving = $eligible_total * ( $current_rate / 100 );

		// Projected saving
		$avg_price      = $current_packs > 0 ? $eligible_total / $current_packs : 0;
		$future_total   = $eligible_total + ( $avg_price * $packs_needed );
		$future_rate    = (float) $next_tier['rate'];
		$future_saving  = $future_total * ( $future_rate / 100 );
		$extra_saving   = $future_saving - $current_saving;

		if ( $packs_needed <= 0 || $extra_saving <= 0 ) {
			return null;
		}

		return sprintf(
			'Add %d more pack%s for %s%% discount — save €%s extra!',
			$packs_needed,
			$packs_needed > 1 ? 's' : '',
			$future_rate,
			number_format( $extra_saving, 2 )
		);
	}

	/**
	 * Show shipping threshold hint.
	 */
	public static function shipping_hint() {
		if ( ! WC()->cart ) {
			return;
		}

		$hint = self::get_shipping_hint( WC()->cart );

		if ( $hint ) {
			echo '<tr class="cm-shipping-hint"><td colspan="2"><div class="cm-upsell-message cm-shipping-upsell">';
			echo wp_kses_post( $hint );
			echo '</div></td></tr>';
		}
	}

	/**
	 * Get shipping threshold hint.
	 *
	 * @param WC_Cart $cart
	 * @return string|null
	 */
	public static function get_shipping_hint( $cart ) {
		$threshold = (float) get_option( 'cm_free_shipping_threshold', 30 );

		if ( $threshold <= 0 ) {
			return null;
		}

		$cart_total = (float) $cart->get_subtotal() - (float) $cart->get_discount_total();

		if ( $cart_total >= $threshold ) {
			return null;
		}

		$remaining = $threshold - $cart_total;

		return sprintf(
			'Add €%s more for free shipping!',
			number_format( $remaining, 2 )
		);
	}

	/**
	 * Show applied discount explanation in cart.
	 * If a promo code was entered but lost to a better discount, show a "replaced" note.
	 */
	public static function cart_discount_notice() {
		if ( ! WC()->session ) {
			return;
		}

		$discount = WC()->session->get( 'cm_active_discount' );

		if ( ! $discount || $discount['type'] === 'none' ) {
			return;
		}

		$notice        = $discount['label'];
		$replaced_info = null;

		// Check if a promo code was entered but lost to a better discount
		if ( $discount['type'] !== 'promo' && ! empty( $discount['all'] ) ) {
			foreach ( $discount['all'] as $candidate ) {
				if ( $candidate['type'] === 'promo' ) {
					$replaced_info = $candidate;
					break;
				}
			}
		}

		$template = self::locate_template( 'cart-discount-notice.php' );
		if ( $template ) {
			include $template;
		}
	}

	/**
	 * Get the count of eligible packs currently in the cart.
	 *
	 * @return int
	 */
	public static function get_cart_eligible_packs() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return 0;
		}
		return CM_Discount_Types::count_eligible_packs( WC()->cart );
	}

	/**
	 * Locate template: check theme first, then plugin templates.
	 *
	 * @param string $template_name Template file name.
	 * @return string|false Full path to template or false.
	 */
	private static function locate_template( $template_name ) {
		// Check theme/child theme override
		$theme_path = locate_template( 'cm-discount-engine/' . $template_name );
		if ( $theme_path ) {
			return $theme_path;
		}

		$plugin_path = CM_DE_PLUGIN_DIR . 'templates/' . $template_name;
		if ( file_exists( $plugin_path ) ) {
			return $plugin_path;
		}

		return false;
	}
}
