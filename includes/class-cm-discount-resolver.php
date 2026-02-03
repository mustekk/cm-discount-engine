<?php
/**
 * CM Discount Resolver — 3-layer orchestrator: eligibility → calculation → selection.
 */

defined( 'ABSPATH' ) || exit;

class CM_Discount_Resolver {

	/**
	 * Priority map: lower number = higher priority (wins on tie).
	 */
	private static $priority = array(
		'quantity'    => 2,
		'promo'       => 3,
		'first_order' => 4,
	);

	/**
	 * Resolve the best discount for the current cart.
	 *
	 * @param WC_Cart $cart       The cart object.
	 * @param int     $user_id    Current user ID (0 for guests).
	 * @param string  $email      Billing email.
	 * @param string  $promo_code Promo code entered by user (optional).
	 * @return array Resolved discount data.
	 */
	public static function resolve( $cart, $user_id = 0, $email = '', $promo_code = null ) {
		$eligible_total = CM_Discount_Types::get_eligible_total( $cart );

		if ( $eligible_total <= 0 ) {
			return self::no_discount();
		}

		$candidates = array();

		// ─── Layer 1: Eligibility ─────────────────────────────

		// First order
		if ( CM_Discount_Types::is_eligible_first_order( $user_id, $email ) ) {
			$candidates[] = 'first_order';
		}

		// Quantity
		$pack_count = CM_Discount_Types::is_eligible_quantity( $cart );
		if ( $pack_count > 0 ) {
			$candidates[] = 'quantity';
		}

		// Promo code
		$promo_data = null;
		if ( ! empty( $promo_code ) ) {
			$promo_data = CM_Discount_Types::is_eligible_promo( $promo_code );
			if ( $promo_data ) {
				$candidates[] = 'promo';
			}
		}

		if ( empty( $candidates ) ) {
			return self::no_discount();
		}

		// ─── Layer 2: Calculation ─────────────────────────────

		$discounts = array();

		foreach ( $candidates as $type ) {
			switch ( $type ) {
				case 'first_order':
					$calc = CM_Discount_Types::calculate_first_order( $eligible_total );
					$discounts[] = array(
						'type'   => 'first_order',
						'rate'   => $calc['rate'],
						'amount' => $calc['amount'],
						'label'  => sprintf( 'First order discount (−%s%%)', $calc['rate'] ),
					);
					break;

				case 'quantity':
					$calc = CM_Discount_Types::calculate_quantity( $eligible_total, $pack_count );
					$discounts[] = array(
						'type'   => 'quantity',
						'rate'   => $calc['rate'],
						'amount' => $calc['amount'],
						'label'  => sprintf( 'Quantity discount: %d packs (−%s%%)', $pack_count, $calc['rate'] ),
					);
					break;

				case 'promo':
					$calc = CM_Discount_Types::calculate_promo( $eligible_total, $promo_data );
					if ( $promo_data['type'] === 'fixed' ) {
						$label = sprintf( 'Promo code "%s" (−€%s)', $promo_data['code'], number_format( $calc['amount'], 2 ) );
					} else {
						$label = sprintf( 'Promo code "%s" (−%s%%)', $promo_data['code'], $calc['rate'] );
					}
					$discounts[] = array(
						'type'     => 'promo',
						'rate'     => $calc['rate'],
						'amount'   => $calc['amount'],
						'label'    => $label,
						'promo_id' => $promo_data['id'],
					);
					break;
			}
		}

		// ─── Layer 3: Selection ───────────────────────────────

		// Sort: highest amount first, then by priority (lower = wins)
		usort( $discounts, function ( $a, $b ) {
			if ( abs( $b['amount'] - $a['amount'] ) > 0.001 ) {
				return $b['amount'] <=> $a['amount'];
			}
			return self::$priority[ $a['type'] ] <=> self::$priority[ $b['type'] ];
		} );

		$winner = $discounts[0];

		return array(
			'type'   => $winner['type'],
			'rate'   => $winner['rate'],
			'amount' => $winner['amount'],
			'label'  => $winner['label'],
			'all'    => $discounts,
			'promo_id' => isset( $winner['promo_id'] ) ? $winner['promo_id'] : null,
		);
	}

	/**
	 * Preview mode: simulate adding extra packs and return potential discount.
	 *
	 * @param WC_Cart $cart      Current cart.
	 * @param int     $extra_qty Additional packs to simulate.
	 * @param int     $user_id   Current user ID.
	 * @param string  $email     Billing email.
	 * @return array Preview discount data.
	 */
	public static function preview( $cart, $extra_qty, $user_id = 0, $email = '' ) {
		$current_packs = CM_Discount_Types::count_eligible_packs( $cart );
		$future_packs  = $current_packs + $extra_qty;

		$eligible_total = CM_Discount_Types::get_eligible_total( $cart );

		// Estimate future total: use average price per pack
		$avg_price    = $current_packs > 0 ? $eligible_total / $current_packs : 0;
		$future_total = $eligible_total + ( $avg_price * $extra_qty );

		if ( $future_total <= 0 ) {
			return self::no_discount();
		}

		$discounts = array();

		// First order
		if ( CM_Discount_Types::is_eligible_first_order( $user_id, $email ) ) {
			$calc = CM_Discount_Types::calculate_first_order( $future_total );
			$discounts[] = array(
				'type'   => 'first_order',
				'rate'   => $calc['rate'],
				'amount' => $calc['amount'],
				'label'  => sprintf( 'First order (−%s%%)', $calc['rate'] ),
			);
		}

		// Quantity with future packs
		$future_tier = CM_Discount_Types::get_quantity_tier( $future_packs );
		if ( $future_tier ) {
			$rate   = (float) $future_tier['rate'];
			$amount = round( $future_total * ( $rate / 100 ), 2 );
			$discounts[] = array(
				'type'   => 'quantity',
				'rate'   => $rate,
				'amount' => $amount,
				'label'  => sprintf( 'Quantity: %d packs (−%s%%)', $future_packs, $rate ),
			);
		}

		if ( empty( $discounts ) ) {
			return self::no_discount();
		}

		usort( $discounts, function ( $a, $b ) {
			if ( abs( $b['amount'] - $a['amount'] ) > 0.001 ) {
				return $b['amount'] <=> $a['amount'];
			}
			return self::$priority[ $a['type'] ] <=> self::$priority[ $b['type'] ];
		} );

		return $discounts[0];
	}

	/**
	 * Return a no-discount result.
	 */
	private static function no_discount() {
		return array(
			'type'     => 'none',
			'rate'     => 0,
			'amount'   => 0,
			'label'    => '',
			'all'      => array(),
			'promo_id' => null,
		);
	}
}
