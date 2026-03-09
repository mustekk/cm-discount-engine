<?php
/**
 * CM Auto Renewal — automatic subscription renewal via WP-Cron + Revolut saved cards.
 *
 * Flow:
 * 1. WP-Cron (twice daily) → process_renewals()
 * 2. For each active subscriber with auto-renewal due:
 *    a. Create WC order from last order items
 *    b. Apply subscription discount (−20% or floor rate)
 *    c. Charge via Revolut saved card token
 *    d. On success: update next_date, send confirmation
 *    e. On failure: retry (up to 3), then auto-pause + notify
 *
 * Uses Action Scheduler (bundled with WooCommerce) for per-user retry scheduling.
 */

defined( 'ABSPATH' ) || exit;

class CM_Auto_Renewal {

	const CRON_HOOK         = 'cm_process_subscription_renewals';
	const RETRY_HOOK        = 'cm_retry_subscription_renewal';
	const REMINDER_HOOK     = 'cm_subscription_renewal_reminder';
	const LOG_SOURCE        = 'cm-auto-renewal';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Main cron event.
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_renewals' ) );

		// Per-user retry via Action Scheduler.
		add_action( self::RETRY_HOOK, array( __CLASS__, 'process_single_renewal' ), 10, 1 );

		// Upcoming renewal reminder.
		add_action( self::REMINDER_HOOK, array( __CLASS__, 'send_renewal_reminder' ), 10, 1 );

		// Capture payment token when subscription order is paid.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'capture_payment_token' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'capture_payment_token' ), 20, 1 );

		// Schedule cron if not scheduled.
		if ( 'yes' === get_option( 'cm_auto_renewal_enabled', 'no' ) ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time(), 'twicedaily', self::CRON_HOOK );
			}
		}
	}

	/**
	 * Schedule cron on plugin activation.
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule cron on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// ─── Main Cron Handler ──────────────────────────────────────

	/**
	 * Process all due subscription renewals.
	 */
	public static function process_renewals() {
		if ( 'yes' !== get_option( 'cm_auto_renewal_enabled', 'no' ) ) {
			return;
		}

		// Transient lock to prevent overlap.
		$lock_key = 'cm_renewal_processing_lock';
		if ( get_transient( $lock_key ) ) {
			self::log( 'Skipping: another renewal process is running.' );
			return;
		}
		set_transient( $lock_key, time(), 600 ); // 10 min TTL

		try {
			$today = gmdate( 'Y-m-d' );

			$users = get_users( array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => 'cm_subscription_status',
						'value' => 'active',
					),
					array(
						'key'   => 'cm_subscription_auto_renewal',
						'value' => 'yes',
					),
					array(
						'key'     => 'cm_subscription_next_date',
						'value'   => $today,
						'compare' => '<=',
						'type'    => 'DATE',
					),
				),
			) );

			self::log( sprintf( 'Found %d subscriptions due for renewal.', count( $users ) ) );

			foreach ( $users as $user ) {
				// Per-user transient lock.
				$user_lock = 'cm_renewal_user_' . $user->ID;
				if ( get_transient( $user_lock ) ) {
					continue;
				}
				set_transient( $user_lock, time(), 300 );

				try {
					self::process_single_renewal( $user->ID );
				} catch ( Exception $e ) {
					self::log( sprintf( 'Error renewing user %d: %s', $user->ID, $e->getMessage() ) );
				} finally {
					delete_transient( $user_lock );
				}
			}
		} finally {
			delete_transient( $lock_key );
		}
	}

	// ─── Single Renewal ─────────────────────────────────────────

	/**
	 * Process renewal for a single user.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function process_single_renewal( $user_id ) {
		$subscription = CM_Subscription_Manager::get_subscription( $user_id );

		// Verify subscription is still active with auto-renewal.
		if ( $subscription['status'] !== 'active' ) {
			self::log( sprintf( 'User %d: subscription not active, skipping.', $user_id ) );
			return false;
		}

		if ( $subscription['auto_renewal'] !== 'yes' ) {
			self::log( sprintf( 'User %d: auto-renewal disabled, skipping.', $user_id ) );
			return false;
		}

		// Check payment token.
		$token_id = (int) get_user_meta( $user_id, 'cm_subscription_payment_token_id', true );
		if ( ! $token_id ) {
			self::log( sprintf( 'User %d: no payment token, pausing subscription.', $user_id ) );
			self::handle_no_payment_method( $user_id );
			return false;
		}

		$wc_token = WC_Payment_Tokens::get( $token_id );
		if ( ! $wc_token || $wc_token->get_user_id() !== $user_id ) {
			self::log( sprintf( 'User %d: payment token %d invalid or belongs to another user.', $user_id, $token_id ) );
			self::handle_no_payment_method( $user_id );
			return false;
		}

		// Create the renewal order.
		$order = self::create_renewal_order( $user_id, $subscription );
		if ( ! $order ) {
			self::log( sprintf( 'User %d: failed to create renewal order.', $user_id ) );
			return false;
		}

		// Charge via Revolut.
		$charged = self::charge_order( $order, $user_id, $wc_token );

		if ( $charged ) {
			self::on_renewal_success( $user_id, $order, $subscription );
			return true;
		} else {
			self::on_renewal_failure( $user_id, $order );
			return false;
		}
	}

	// ─── Order Creation ─────────────────────────────────────────

	/**
	 * Create a WC renewal order programmatically.
	 *
	 * @param int   $user_id
	 * @param array $subscription
	 * @return WC_Order|false
	 */
	public static function create_renewal_order( $user_id, $subscription ) {
		$last_order_id = $subscription['last_order'];
		if ( ! $last_order_id ) {
			self::log( sprintf( 'User %d: no last order to replicate.', $user_id ) );
			return false;
		}

		$last_order = wc_get_order( $last_order_id );
		if ( ! $last_order ) {
			self::log( sprintf( 'User %d: last order %d not found.', $user_id, $last_order_id ) );
			return false;
		}

		try {
			$order = wc_create_order( array(
				'customer_id' => $user_id,
				'created_via' => 'cm_auto_renewal',
			) );

			if ( is_wp_error( $order ) ) {
				self::log( 'Failed to create order: ' . $order->get_error_message() );
				return false;
			}

			// Copy products from last order.
			$eligible_total = 0;
			$pack_count     = 0;

			foreach ( $last_order->get_items() as $item ) {
				$product_id = $item->get_product_id();
				$product    = wc_get_product( $product_id );

				if ( ! $product || ! $product->is_in_stock() ) {
					self::log( sprintf( 'Product %d out of stock, skipping.', $product_id ) );
					continue;
				}

				$quantity = $item->get_quantity();
				$order->add_product( $product, $quantity );

				// Check eligibility for discount.
				$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
				$is_eligible = false;
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term_id ) {
						if ( get_term_meta( $term_id, 'cm_discount_eligible', true ) === 'yes' ) {
							$is_eligible = true;
							break;
						}
					}
				}

				if ( $is_eligible ) {
					$eligible_total += (float) $product->get_price() * $quantity;
					$pack_count     += $quantity;
				}
			}

			if ( ! $order->get_item_count() ) {
				self::log( sprintf( 'User %d: no items could be added to renewal order.', $user_id ) );
				$order->delete( true );
				return false;
			}

			// Copy addresses from last order.
			$order->set_address( self::get_address_from_order( $last_order, 'billing' ), 'billing' );
			$order->set_address( self::get_address_from_order( $last_order, 'shipping' ), 'shipping' );

			// Set payment method.
			$order->set_payment_method( 'revolut_cc' );
			$order->set_payment_method_title( __( 'Credit Card (Revolut)', 'cm-discount-engine' ) );

			// Calculate subscription discount.
			$base_rate = (float) get_option( 'cm_subscription_rate', 20 );
			$qty_tier  = CM_Discount_Types::get_quantity_tier( $pack_count );
			$qty_rate  = $qty_tier ? (float) $qty_tier['rate'] : 0;
			$rate      = max( $base_rate, $qty_rate );
			$discount  = round( $eligible_total * ( $rate / 100 ), 2 );

			// Copy shipping from last order.
			foreach ( $last_order->get_shipping_methods() as $shipping_item ) {
				$new_shipping = new WC_Order_Item_Shipping();
				$new_shipping->set_method_title( $shipping_item->get_method_title() );
				$new_shipping->set_method_id( $shipping_item->get_method_id() );
				$new_shipping->set_instance_id( $shipping_item->get_instance_id() );
				$new_shipping->set_total( $shipping_item->get_total() );
				$order->add_item( $new_shipping );
			}

			// Apply subscription discount by adjusting line item totals.
			// WC_Order::calculate_totals() doesn't process coupon items —
			// we must reduce item totals and set discount_total manually.
			if ( $discount > 0 ) {
				$remaining = $discount;
				foreach ( $order->get_items() as $item ) {
					$item_total = (float) $item->get_total();
					if ( $item_total <= 0 || $remaining <= 0 ) {
						continue;
					}
					$item_discount = min( $remaining, $item_total );
					$item->set_total( $item_total - $item_discount );
					$item->set_subtotal( (float) $item->get_subtotal() ); // Keep subtotal as original.
					$item->save();
					$remaining -= $item_discount;
				}

				$coupon_item = new WC_Order_Item_Coupon();
				$coupon_item->set_code( 'cm-auto-discount' );
				$coupon_item->set_discount( $discount );
				$coupon_item->set_discount_tax( 0 );
				$order->add_item( $coupon_item );

				$order->set_discount_total( $discount );
			}

			// Set order meta.
			$order->update_meta_data( 'cm_is_subscription', 'yes' );
			$order->update_meta_data( 'cm_is_auto_renewal', 'yes' );
			$order->update_meta_data( 'cm_discount_type', 'subscription' );
			$order->update_meta_data( 'cm_discount_rate', $rate );
			$order->update_meta_data( 'cm_discount_amount', $discount );

			// Set payment token meta (required by Revolut for charging).
			$token_id = (int) get_user_meta( $user_id, 'cm_subscription_payment_token_id', true );
			$order->update_meta_data( '_payment_token_id', $token_id );
			$order->update_meta_data( '_wc_customer_id', $user_id );

			// Calculate totals (with line items already adjusted for discount).
			$order->calculate_totals();
			$order->save();

			$order->add_order_note(
				sprintf(
					__( 'Auto-renewal order created from subscription (last order #%d). Discount: −%s%% (€%s)', 'cm-discount-engine' ),
					$last_order_id,
					$rate,
					$discount
				)
			);

			self::log( sprintf( 'Created renewal order #%d for user %d (total: €%s).', $order->get_id(), $user_id, $order->get_total() ) );

			return $order;

		} catch ( Exception $e ) {
			self::log( sprintf( 'Error creating renewal order for user %d: %s', $user_id, $e->getMessage() ) );
			return false;
		}
	}

	// ─── Payment ────────────────────────────────────────────────

	/**
	 * Charge a renewal order via Revolut saved card.
	 *
	 * Replicates the flow from WC_Gateway_Revolut_CC::scheduled_subscription_payment()
	 * without depending on WC_Subscriptions plugin.
	 *
	 * @param WC_Order         $order
	 * @param int              $user_id
	 * @param WC_Payment_Token $wc_token
	 * @return bool
	 */
	public static function charge_order( $order, $user_id, $wc_token ) {
		try {
			$gateway = new WC_Gateway_Revolut_CC();

			// Get Revolut customer ID.
			$revolut_customer_id = $gateway->get_revolut_customer_id( $user_id );
			if ( empty( $revolut_customer_id ) ) {
				throw new Exception( 'Revolut customer ID not found for user ' . $user_id );
			}

			$amount   = $order->get_total();
			$currency = $order->get_currency();

			// Create a Revolut order with automatic capture.
			$descriptor = new WC_Revolut_Order_Descriptor( $amount, $currency, $revolut_customer_id );
			$revolut_payment_public_id = $gateway->create_subscription_order( $descriptor );

			// Resolve public ID to internal Revolut order ID.
			$revolut_order_id = $gateway->get_revolut_order_by_public_id( $revolut_payment_public_id );
			if ( empty( $revolut_order_id ) ) {
				throw new Exception( 'Failed to resolve Revolut order ID.' );
			}

			// Link WC order to Revolut order in their internal table.
			// save_wc_order_id is protected, use action_revolut_order to track.
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $wpdb->prefix . 'wc_revolut_orders SET wc_order_id = %d WHERE order_id = UNHEX(REPLACE(%s, \'-\', \'\'))',
					$order->get_id(),
					$revolut_order_id
				)
			);

			// Charge the saved card.
			$payment_method_id = $wc_token->get_token();
			$gateway->action_revolut_order( $revolut_order_id, 'confirm', array(
				'payment_method_id' => $payment_method_id,
			) );

			// Save Revolut order ID to WC order.
			$order->update_meta_data( 'revolut_payment_order_id', $revolut_order_id );
			$order->update_meta_data( 'is_subscription_order', true );
			$order->save();

			// Verify payment was processed.
			// Poll for up to 10 seconds.
			$is_paid = false;
			for ( $i = 0; $i < 5; $i++ ) {
				$revolut_order = self::get_revolut_order_status( $revolut_order_id );
				if ( $revolut_order && in_array( $revolut_order['state'], array( 'COMPLETED', 'PROCESSING' ), true ) ) {
					$is_paid = true;
					break;
				}
				if ( $revolut_order && $revolut_order['state'] === 'FAILED' ) {
					throw new Exception( 'Payment failed: Revolut order state is FAILED.' );
				}
				sleep( 2 );
			}

			if ( ! $is_paid ) {
				// Mark on-hold — Revolut webhook will update later.
				$order->update_status( 'on-hold', __( 'Auto-renewal: awaiting Revolut payment confirmation.', 'cm-discount-engine' ) );
				$order->add_order_note(
					sprintf( 'Auto-renewal payment initiated via Revolut (Order ID: %s). Awaiting confirmation.', $revolut_order_id )
				);
				self::log( sprintf( 'Order #%d: payment pending, set to on-hold.', $order->get_id() ) );
				return true; // Consider it a success — webhook will finalize.
			}

			// Payment confirmed.
			$order->update_status( 'processing', __( 'Auto-renewal payment completed via Revolut.', 'cm-discount-engine' ) );
			$order->add_order_note(
				sprintf( 'Auto-renewal charge successful via Revolut (Order ID: %s).', $revolut_order_id )
			);

			self::log( sprintf( 'Order #%d: payment successful.', $order->get_id() ) );
			return true;

		} catch ( Exception $e ) {
			$order->update_status( 'failed', sprintf(
				__( 'Auto-renewal payment failed: %s', 'cm-discount-engine' ),
				$e->getMessage()
			) );

			self::log( sprintf( 'Payment failed for order #%d: %s', $order->get_id(), $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Get Revolut order status via API.
	 *
	 * @param string $revolut_order_id
	 * @return array|null
	 */
	private static function get_revolut_order_status( $revolut_order_id ) {
		try {
			$json = \Revolut\Plugin\Infrastructure\Api\MerchantApi::privateLegacy()->get( '/orders/' . $revolut_order_id );
			return $json;
		} catch ( Exception $e ) {
			self::log( 'Error checking Revolut order status: ' . $e->getMessage() );
			return null;
		}
	}

	// ─── Success / Failure Handlers ─────────────────────────────

	/**
	 * Handle successful renewal.
	 *
	 * @param int      $user_id
	 * @param WC_Order $order
	 * @param array    $subscription
	 */
	private static function on_renewal_success( $user_id, $order, $subscription ) {
		// Reset failure counter.
		update_user_meta( $user_id, 'cm_subscription_renewal_failures', 0 );
		delete_user_meta( $user_id, 'cm_subscription_last_failure' );

		// Update last order and next date.
		update_user_meta( $user_id, 'cm_subscription_last_order', $order->get_id() );
		update_user_meta( $user_id, 'cm_subscription_last_renewal', gmdate( 'Y-m-d' ) );

		$period    = get_user_meta( $user_id, 'cm_subscription_period', true ) ?: 'monthly';
		$next_date = self::calculate_next_date( $period );
		update_user_meta( $user_id, 'cm_subscription_next_date', $next_date );

		// Schedule renewal reminder (3 days before next renewal).
		$reminder_days = (int) get_option( 'cm_auto_renewal_reminder_days', 3 );
		if ( $reminder_days > 0 ) {
			$reminder_time = strtotime( $next_date . ' -' . $reminder_days . ' days' );
			if ( $reminder_time > time() ) {
				as_schedule_single_action( $reminder_time, self::REMINDER_HOOK, array( $user_id ), 'cm-subscriptions' );
			}
		}

		// Send confirmation email.
		self::send_renewal_email( $user_id, $order, 'success' );

		self::log( sprintf( 'User %d: renewal successful. Next renewal: %s.', $user_id, $next_date ) );
	}

	/**
	 * Handle failed renewal.
	 *
	 * @param int      $user_id
	 * @param WC_Order $order
	 */
	private static function on_renewal_failure( $user_id, $order ) {
		$failures    = (int) get_user_meta( $user_id, 'cm_subscription_renewal_failures', true );
		$max_retries = (int) get_option( 'cm_auto_renewal_max_retries', 3 );

		$failures++;
		update_user_meta( $user_id, 'cm_subscription_renewal_failures', $failures );
		update_user_meta( $user_id, 'cm_subscription_last_failure', gmdate( 'Y-m-d' ) );

		if ( $failures >= $max_retries ) {
			// Max retries reached — pause subscription.
			CM_Subscription_Manager::pause_subscription( $user_id );
			update_user_meta( $user_id, 'cm_subscription_pause_reason', 'payment_failed' );

			self::send_renewal_email( $user_id, $order, 'paused' );
			self::log( sprintf( 'User %d: max retries reached (%d), subscription paused.', $user_id, $failures ) );
		} else {
			// Schedule retry.
			$retry_schedule = self::get_retry_schedule();
			$retry_delay    = isset( $retry_schedule[ $failures - 1 ] ) ? $retry_schedule[ $failures - 1 ] : DAY_IN_SECONDS;

			as_schedule_single_action(
				time() + $retry_delay,
				self::RETRY_HOOK,
				array( $user_id ),
				'cm-subscriptions'
			);

			self::send_renewal_email( $user_id, $order, 'failed' );
			self::log( sprintf(
				'User %d: payment failed (attempt %d/%d). Retry in %d seconds.',
				$user_id, $failures, $max_retries, $retry_delay
			) );
		}
	}

	/**
	 * Handle missing payment method.
	 *
	 * @param int $user_id
	 */
	private static function handle_no_payment_method( $user_id ) {
		update_user_meta( $user_id, 'cm_subscription_auto_renewal', 'no' );
		self::send_renewal_email( $user_id, null, 'no_payment_method' );
	}

	// ─── Token Capture ──────────────────────────────────────────

	/**
	 * Capture Revolut payment token from a subscription order to user meta.
	 * Hooked to order status processing/completed.
	 *
	 * @param int $order_id
	 */
	public static function capture_payment_token( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Only for subscription orders.
		if ( $order->get_meta( 'cm_is_subscription' ) !== 'yes' ) {
			return;
		}

		// Only for Revolut CC payments.
		if ( $order->get_payment_method() !== 'revolut_cc' ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		// Skip auto-renewal orders (they already have the token).
		if ( $order->get_meta( 'cm_is_auto_renewal' ) === 'yes' ) {
			return;
		}

		// Idempotency check.
		if ( $order->get_meta( '_cm_payment_token_captured' ) === 'yes' ) {
			return;
		}

		// Get WC payment tokens for this user from Revolut.
		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'revolut_cc' );
		if ( empty( $tokens ) ) {
			return;
		}

		// Use the most recently added token (or default).
		$token = WC_Payment_Tokens::get_customer_default_token( $user_id );
		if ( ! $token || $token->get_gateway_id() !== 'revolut_cc' ) {
			$token = end( $tokens );
		}

		update_user_meta( $user_id, 'cm_subscription_payment_token_id', $token->get_id() );

		// Enable auto-renewal by default when token is captured.
		if ( 'yes' === get_option( 'cm_auto_renewal_enabled', 'no' ) ) {
			$current_auto = get_user_meta( $user_id, 'cm_subscription_auto_renewal', true );
			if ( empty( $current_auto ) ) {
				update_user_meta( $user_id, 'cm_subscription_auto_renewal', 'yes' );
			}
		}

		$order->update_meta_data( '_cm_payment_token_captured', 'yes' );
		$order->save();

		self::log( sprintf(
			'Captured payment token %d (****%s) for user %d from order #%d.',
			$token->get_id(),
			$token->get_last4(),
			$user_id,
			$order_id
		) );
	}

	// ─── Emails ─────────────────────────────────────────────────

	/**
	 * Send renewal notification email.
	 *
	 * @param int           $user_id
	 * @param WC_Order|null $order
	 * @param string        $type success|failed|paused|no_payment_method|reminder
	 */
	public static function send_renewal_email( $user_id, $order, $type ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$email   = $user->user_email;
		$name    = $user->display_name ?: $user->first_name;
		$site    = get_bloginfo( 'name' );
		$account = wc_get_account_endpoint_url( 'subscription' );
		$payment = wc_get_account_endpoint_url( 'payment-methods' );

		switch ( $type ) {
			case 'success':
				$subject = sprintf( __( '%s — Your subscription order has been placed', 'cm-discount-engine' ), $site );
				$message = sprintf(
					__( "Hi %s,\n\nYour Coffee Club subscription has been automatically renewed.\n\nOrder #%d — Total: €%s\n\nYou can view your order details in your account:\n%s\n\nThank you!\n%s", 'cm-discount-engine' ),
					$name,
					$order ? $order->get_id() : 0,
					$order ? $order->get_total() : '0.00',
					$account,
					$site
				);
				break;

			case 'failed':
				$subject = sprintf( __( '%s — Subscription payment failed', 'cm-discount-engine' ), $site );
				$message = sprintf(
					__( "Hi %s,\n\nWe were unable to process your Coffee Club subscription payment.\n\nWe will retry automatically. If the issue persists, please update your payment method:\n%s\n\nThank you!\n%s", 'cm-discount-engine' ),
					$name,
					$payment,
					$site
				);
				break;

			case 'paused':
				$subject = sprintf( __( '%s — Subscription paused due to payment failure', 'cm-discount-engine' ), $site );
				$message = sprintf(
					__( "Hi %s,\n\nAfter multiple attempts, we were unable to charge your payment method for your Coffee Club subscription.\n\nYour subscription has been paused. To resume, please update your payment method and reactivate:\n%s\n\nThank you!\n%s", 'cm-discount-engine' ),
					$name,
					$payment,
					$site
				);
				break;

			case 'no_payment_method':
				$subject = sprintf( __( '%s — Payment method needed for subscription', 'cm-discount-engine' ), $site );
				$message = sprintf(
					__( "Hi %s,\n\nYour Coffee Club subscription cannot be auto-renewed because no saved payment method was found.\n\nPlease add a payment method:\n%s\n\nThank you!\n%s", 'cm-discount-engine' ),
					$name,
					$payment,
					$site
				);
				break;

			case 'reminder':
				$next_date = get_user_meta( $user_id, 'cm_subscription_next_date', true );
				$subject = sprintf( __( '%s — Upcoming subscription renewal', 'cm-discount-engine' ), $site );
				$message = sprintf(
					__( "Hi %s,\n\nYour Coffee Club subscription will be automatically renewed on %s.\n\nIf you'd like to make any changes, you can manage your subscription here:\n%s\n\nThank you!\n%s", 'cm-discount-engine' ),
					$name,
					date_i18n( get_option( 'date_format' ), strtotime( $next_date ) ),
					$account,
					$site
				);
				break;

			default:
				return;
		}

		wp_mail( $email, $subject, $message );
	}

	/**
	 * Send upcoming renewal reminder (Action Scheduler callback).
	 *
	 * @param int $user_id
	 */
	public static function send_renewal_reminder( $user_id ) {
		$subscription = CM_Subscription_Manager::get_subscription( $user_id );
		if ( $subscription['status'] !== 'active' || $subscription['auto_renewal'] !== 'yes' ) {
			return;
		}

		self::send_renewal_email( $user_id, null, 'reminder' );
	}

	// ─── Helpers ────────────────────────────────────────────────

	/**
	 * Get address array from an existing order.
	 *
	 * @param WC_Order $order
	 * @param string   $type 'billing' or 'shipping'
	 * @return array
	 */
	private static function get_address_from_order( $order, $type ) {
		$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		$address = array();

		foreach ( $fields as $field ) {
			$method = "get_{$type}_{$field}";
			if ( method_exists( $order, $method ) ) {
				$address[ $field ] = $order->$method();
			}
		}

		return $address;
	}

	/**
	 * Calculate next renewal date.
	 *
	 * @param string $period '2weeks' or 'monthly'
	 * @return string Y-m-d
	 */
	private static function calculate_next_date( $period ) {
		$interval = $period === '2weeks' ? '+2 weeks' : '+1 month';
		return gmdate( 'Y-m-d', strtotime( $interval ) );
	}

	/**
	 * Get retry schedule (delay in seconds for each retry attempt).
	 *
	 * @return array
	 */
	private static function get_retry_schedule() {
		$days = get_option( 'cm_auto_renewal_retry_days', '1,3,7' );
		$parts = array_map( 'trim', explode( ',', $days ) );
		$schedule = array();

		foreach ( $parts as $d ) {
			$schedule[] = (int) $d * DAY_IN_SECONDS;
		}

		return $schedule ?: array( DAY_IN_SECONDS, 3 * DAY_IN_SECONDS, 7 * DAY_IN_SECONDS );
	}

	/**
	 * Get payment token info for display.
	 *
	 * @param int $user_id
	 * @return array|null [ 'last4', 'brand', 'expiry' ]
	 */
	public static function get_payment_method_info( $user_id ) {
		$token_id = (int) get_user_meta( $user_id, 'cm_subscription_payment_token_id', true );
		if ( ! $token_id ) {
			return null;
		}

		$token = WC_Payment_Tokens::get( $token_id );
		if ( ! $token || $token->get_user_id() !== $user_id ) {
			return null;
		}

		return array(
			'last4'  => $token->get_last4(),
			'brand'  => $token->get_card_type(),
			'expiry' => $token->get_expiry_month() . '/' . $token->get_expiry_year(),
		);
	}

	/**
	 * Log a message to WooCommerce logger.
	 *
	 * @param string $message
	 */
	private static function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( $message, array( 'source' => self::LOG_SOURCE ) );
		}
	}
}
