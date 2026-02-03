<?php
/**
 * CM Discount Types — eligibility checks and calculation for each discount type.
 */

defined( 'ABSPATH' ) || exit;

class CM_Discount_Types {

	// ─── Eligibility ───────────────────────────────────────────

	/**
	 * Check if the customer is eligible for first-order discount.
	 *
	 * @param int    $user_id Current user ID (0 for guests).
	 * @param string $email   Billing email for guest check.
	 * @return bool
	 */
	public static function is_eligible_first_order( $user_id, $email = '' ) {
		if ( 'yes' !== get_option( 'cm_first_order_enabled', 'yes' ) ) {
			return false;
		}

		// Logged-in user: check meta flag
		if ( $user_id > 0 ) {
			if ( get_user_meta( $user_id, 'cm_first_order_used', true ) ) {
				return false;
			}
			// Also check for completed/processing orders
			$orders = wc_get_orders( array(
				'customer_id' => $user_id,
				'status'      => array( 'completed', 'processing' ),
				'limit'       => 1,
				'return'      => 'ids',
			) );
			return empty( $orders );
		}

		// Guest: check by email
		if ( ! empty( $email ) ) {
			$orders = wc_get_orders( array(
				'billing_email' => $email,
				'status'        => array( 'completed', 'processing' ),
				'limit'         => 1,
				'return'        => 'ids',
			) );
			return empty( $orders );
		}

		// No user and no email — assume eligible (first visit)
		return true;
	}

	/**
	 * Count eligible packs in cart.
	 *
	 * @param WC_Cart $cart
	 * @return int Total quantity of eligible items.
	 */
	public static function count_eligible_packs( $cart ) {
		$count = 0;
		foreach ( $cart->get_cart() as $item ) {
			if ( self::is_item_eligible( $item ) ) {
				$count += $item['quantity'];
			}
		}
		return $count;
	}

	/**
	 * Check if quantity discount is eligible.
	 *
	 * @param WC_Cart $cart
	 * @return int Pack count (0 means not eligible for any tier).
	 */
	public static function is_eligible_quantity( $cart ) {
		if ( 'yes' !== get_option( 'cm_quantity_enabled', 'yes' ) ) {
			return 0;
		}

		$pack_count = self::count_eligible_packs( $cart );
		$tier       = self::get_quantity_tier( $pack_count );

		return $tier ? $pack_count : 0;
	}

	/**
	 * Check if a promo code is eligible.
	 *
	 * @param string $code
	 * @return array|false Promo data or false.
	 */
	public static function is_eligible_promo( $code ) {
		if ( empty( $code ) ) {
			return false;
		}
		return CM_Promo_Codes::validate_code( $code );
	}

	// ─── Calculation ───────────────────────────────────────────

	/**
	 * Calculate first-order discount amount.
	 *
	 * @param float $eligible_total Total of eligible items.
	 * @return array [ 'rate' => float, 'amount' => float ]
	 */
	public static function calculate_first_order( $eligible_total ) {
		$rate   = (float) get_option( 'cm_first_order_rate', 10 );
		$amount = $eligible_total * ( $rate / 100 );

		return array(
			'rate'   => $rate,
			'amount' => round( $amount, 2 ),
		);
	}

	/**
	 * Calculate quantity tier discount amount.
	 *
	 * @param float $eligible_total Total of eligible items.
	 * @param int   $pack_count     Number of eligible packs.
	 * @return array [ 'rate' => float, 'amount' => float ]
	 */
	public static function calculate_quantity( $eligible_total, $pack_count ) {
		$tier = self::get_quantity_tier( $pack_count );

		if ( ! $tier ) {
			return array( 'rate' => 0, 'amount' => 0 );
		}

		$rate   = (float) $tier['rate'];
		$amount = $eligible_total * ( $rate / 100 );

		return array(
			'rate'   => $rate,
			'amount' => round( $amount, 2 ),
		);
	}

	/**
	 * Calculate promo code discount amount.
	 *
	 * @param float $eligible_total Total of eligible items.
	 * @param array $promo          Promo data from validate_code().
	 * @return array [ 'rate' => float|null, 'amount' => float ]
	 */
	public static function calculate_promo( $eligible_total, $promo ) {
		if ( $promo['type'] === 'fixed' ) {
			$amount = min( $promo['value'], $eligible_total );
			return array(
				'rate'   => null,
				'amount' => round( $amount, 2 ),
			);
		}

		// Percent
		$rate   = $promo['value'];
		$amount = $eligible_total * ( $rate / 100 );

		return array(
			'rate'   => $rate,
			'amount' => round( $amount, 2 ),
		);
	}

	// ─── Helpers ───────────────────────────────────────────────

	/**
	 * Get the total of eligible items in the cart (items in eligible categories).
	 *
	 * @param WC_Cart $cart
	 * @return float
	 */
	public static function get_eligible_total( $cart ) {
		$total = 0;
		foreach ( $cart->get_cart() as $item ) {
			if ( self::is_item_eligible( $item ) ) {
				$total += (float) $item['data']->get_price() * $item['quantity'];
			}
		}
		return $total;
	}

	/**
	 * Check if a single cart item belongs to an eligible category.
	 *
	 * @param array $cart_item
	 * @return bool
	 */
	public static function is_item_eligible( $cart_item ) {
		$product_id = $cart_item['product_id'];
		$terms      = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return false;
		}

		foreach ( $terms as $term_id ) {
			if ( get_term_meta( $term_id, 'cm_discount_eligible', true ) === 'yes' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Look up the applicable quantity tier for a given pack count.
	 * Tiers are sorted descending; first match where pack_count >= min_packs.
	 *
	 * @param int $pack_count
	 * @return array|null Tier array [ 'min_packs' => int, 'rate' => float ] or null.
	 */
	public static function get_quantity_tier( $pack_count ) {
		$tiers = get_option( 'cm_quantity_tiers', array() );

		if ( empty( $tiers ) || ! is_array( $tiers ) ) {
			return null;
		}

		// Sort descending by min_packs
		usort( $tiers, function ( $a, $b ) {
			return (int) $b['min_packs'] - (int) $a['min_packs'];
		} );

		foreach ( $tiers as $tier ) {
			if ( $pack_count >= (int) $tier['min_packs'] ) {
				return $tier;
			}
		}

		return null;
	}

	/**
	 * Get the next tier above the current pack count (for upsell).
	 *
	 * @param int $pack_count Current pack count.
	 * @return array|null Next tier or null if already at max.
	 */
	public static function get_next_tier( $pack_count ) {
		$tiers = get_option( 'cm_quantity_tiers', array() );

		if ( empty( $tiers ) || ! is_array( $tiers ) ) {
			return null;
		}

		// Sort ascending by min_packs
		usort( $tiers, function ( $a, $b ) {
			return (int) $a['min_packs'] - (int) $b['min_packs'];
		} );

		foreach ( $tiers as $tier ) {
			if ( (int) $tier['min_packs'] > $pack_count ) {
				return $tier;
			}
		}

		return null;
	}
}
