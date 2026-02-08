<?php
/**
 * CM Subscription Manager — Coffee Club subscription management (without auto-payments).
 *
 * Handles subscription state in user meta:
 * - cm_subscription_active    : 'yes' | empty
 * - cm_subscription_status    : 'active' | 'paused' | 'cancelled'
 * - cm_subscription_period    : '2weeks' | 'monthly'
 * - cm_subscription_qty       : 2 | 4
 * - cm_subscription_format    : 'single' | 'mix' | 'roaster_choice'
 * - cm_subscription_last_order: order_id
 * - cm_subscription_started   : Y-m-d
 * - cm_subscription_next_date : Y-m-d (next reminder date)
 */

defined( 'ABSPATH' ) || exit;

class CM_Subscription_Manager {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Register My Account endpoint
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );

		// Add query var for endpoint
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );

		// Add menu item
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ) );

		// Endpoint content
		add_action( 'woocommerce_account_subscription_endpoint', array( __CLASS__, 'endpoint_content' ) );

		// Handle POST actions (pause, resume, change, cancel)
		add_action( 'template_redirect', array( __CLASS__, 'handle_actions' ) );

		// Flush rewrite rules on activation (via main plugin)
		add_action( 'cm_de_flush_rewrite_rules', array( __CLASS__, 'flush_rules' ) );
	}

	/**
	 * Register the /subscription endpoint.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( 'subscription', EP_ROOT | EP_PAGES );
	}

	/**
	 * Flush rewrite rules (call on plugin activation).
	 */
	public static function flush_rules() {
		self::add_endpoint();
		flush_rewrite_rules();
	}

	/**
	 * Add query var for the subscription endpoint.
	 */
	public static function add_query_var( $vars ) {
		$vars['subscription'] = 'subscription';
		return $vars;
	}

	/**
	 * Add "Subscription" menu item to My Account.
	 */
	public static function add_menu_item( $items ) {
		if ( 'yes' !== get_option( 'cm_subscription_enabled', 'no' ) ) {
			return $items;
		}

		// Insert after dashboard or orders
		$new_items = array();
		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;
			if ( $key === 'orders' ) {
				$new_items['subscription'] = __( 'Subscription', 'cm-discount-engine' );
			}
		}

		// Fallback if 'orders' not found
		if ( ! isset( $new_items['subscription'] ) ) {
			$new_items['subscription'] = __( 'Subscription', 'cm-discount-engine' );
		}

		return $new_items;
	}

	/**
	 * Render the subscription endpoint content.
	 */
	public static function endpoint_content() {
		if ( 'yes' !== get_option( 'cm_subscription_enabled', 'no' ) ) {
			echo '<p>' . esc_html__( 'Subscriptions are not enabled.', 'cm-discount-engine' ) . '</p>';
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$subscription = self::get_subscription( $user_id );

		$template = CM_DE_PLUGIN_DIR . 'templates/my-account/subscription.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Handle POST actions for subscription management.
	 */
	public static function handle_actions() {
		if ( ! isset( $_POST['cm_subscription_action'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'cm_subscription_action' ) ) {
			wc_add_notice( __( 'Security check failed.', 'cm-discount-engine' ), 'error' );
			return;
		}

		$user_id = get_current_user_id();
		$action  = sanitize_text_field( $_POST['cm_subscription_action'] );

		// Check if user has a subscription for actions that require it
		$subscription = self::get_subscription( $user_id );
		if ( in_array( $action, array( 'pause', 'resume', 'cancel', 'change' ), true ) ) {
			if ( $subscription['status'] === 'none' ) {
				wc_add_notice( __( 'No subscription found.', 'cm-discount-engine' ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'subscription' ) );
				exit;
			}
		}

		switch ( $action ) {
			case 'pause':
				if ( self::pause_subscription( $user_id ) ) {
					wc_add_notice( __( 'Your subscription has been paused.', 'cm-discount-engine' ), 'success' );
				} else {
					wc_add_notice( __( 'Only active subscriptions can be paused.', 'cm-discount-engine' ), 'error' );
				}
				break;

			case 'resume':
				if ( self::resume_subscription( $user_id ) ) {
					wc_add_notice( __( 'Your subscription has been resumed.', 'cm-discount-engine' ), 'success' );
				} else {
					wc_add_notice( __( 'Only paused subscriptions can be resumed. To reactivate a cancelled subscription, please place a new subscription order.', 'cm-discount-engine' ), 'error' );
				}
				break;

			case 'cancel':
				self::cancel_subscription( $user_id );
				wc_add_notice( __( 'Your subscription has been cancelled.', 'cm-discount-engine' ), 'success' );
				break;

			case 'change':
				$period = isset( $_POST['cm_subscription_period'] ) ? sanitize_text_field( $_POST['cm_subscription_period'] ) : '';
				$qty    = isset( $_POST['cm_subscription_qty'] ) ? (int) $_POST['cm_subscription_qty'] : 0;
				$format = isset( $_POST['cm_subscription_format'] ) ? sanitize_text_field( $_POST['cm_subscription_format'] ) : '';

				self::update_subscription( $user_id, $period, $qty, $format );
				wc_add_notice( __( 'Your subscription settings have been updated.', 'cm-discount-engine' ), 'success' );
				break;
		}

		wp_safe_redirect( wc_get_account_endpoint_url( 'subscription' ) );
		exit;
	}

	/**
	 * Get subscription data for a user.
	 *
	 * @param int $user_id
	 * @return array
	 */
	public static function get_subscription( $user_id ) {
		return array(
			'active'     => get_user_meta( $user_id, 'cm_subscription_active', true ) === 'yes',
			'status'     => get_user_meta( $user_id, 'cm_subscription_status', true ) ?: 'none',
			'period'     => get_user_meta( $user_id, 'cm_subscription_period', true ) ?: 'monthly',
			'qty'        => (int) get_user_meta( $user_id, 'cm_subscription_qty', true ) ?: 2,
			'format'     => get_user_meta( $user_id, 'cm_subscription_format', true ) ?: 'single',
			'last_order' => (int) get_user_meta( $user_id, 'cm_subscription_last_order', true ),
			'started'    => get_user_meta( $user_id, 'cm_subscription_started', true ),
			'next_date'  => get_user_meta( $user_id, 'cm_subscription_next_date', true ),
		);
	}

	/**
	 * Save subscription data when order is placed.
	 *
	 * @param int      $order_id
	 * @param WC_Order $order
	 */
	public static function save_subscription_from_order( $order_id, $order ) {
		if ( $order->get_meta( 'cm_is_subscription' ) !== 'yes' ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		// Extract subscription data from order items
		// Find first item with subscription flag to get period/qty/format
		$period = 'monthly';
		$qty    = 2;
		$format = 'single';

		foreach ( $order->get_items() as $item ) {
			// Skip non-subscription items
			if ( $item->get_meta( '_cm_subscription' ) !== 'yes' ) {
				continue;
			}

			$item_period = $item->get_meta( '_cm_subscription_period' );
			$item_qty    = $item->get_meta( '_cm_subscription_qty' );
			$item_format = $item->get_meta( '_cm_subscription_format' );

			if ( $item_period ) {
				$period = $item_period;
			}
			if ( $item_qty ) {
				$qty = (int) $item_qty;
			}
			if ( $item_format ) {
				$format = $item_format;
			}
			break; // Use first subscription item's data
		}

		// Calculate next reminder date
		$next_date = self::calculate_next_date( $period );

		// Check if this is first subscription or update
		$existing = get_user_meta( $user_id, 'cm_subscription_active', true );

		update_user_meta( $user_id, 'cm_subscription_active', 'yes' );
		update_user_meta( $user_id, 'cm_subscription_status', 'active' );
		update_user_meta( $user_id, 'cm_subscription_period', $period );
		update_user_meta( $user_id, 'cm_subscription_qty', $qty );
		update_user_meta( $user_id, 'cm_subscription_format', $format );
		update_user_meta( $user_id, 'cm_subscription_last_order', $order_id );
		update_user_meta( $user_id, 'cm_subscription_next_date', $next_date );

		if ( $existing !== 'yes' ) {
			update_user_meta( $user_id, 'cm_subscription_started', gmdate( 'Y-m-d' ) );
		}
	}

	/**
	 * Pause subscription.
	 * Only active subscriptions can be paused.
	 *
	 * @param int $user_id
	 * @return bool True if paused, false if not allowed.
	 */
	public static function pause_subscription( $user_id ) {
		$current_status = get_user_meta( $user_id, 'cm_subscription_status', true );

		// Only allow pausing active subscriptions
		if ( $current_status !== 'active' ) {
			return false;
		}

		update_user_meta( $user_id, 'cm_subscription_status', 'paused' );
		return true;
	}

	/**
	 * Resume subscription.
	 * Only paused subscriptions can be resumed. Cancelled subscriptions require a new order.
	 *
	 * @param int $user_id
	 * @return bool True if resumed, false if not allowed.
	 */
	public static function resume_subscription( $user_id ) {
		$current_status = get_user_meta( $user_id, 'cm_subscription_status', true );

		// Only allow resuming paused subscriptions
		if ( $current_status !== 'paused' ) {
			return false;
		}

		update_user_meta( $user_id, 'cm_subscription_status', 'active' );
		update_user_meta( $user_id, 'cm_subscription_active', 'yes' );

		// Recalculate next date from today
		$period    = get_user_meta( $user_id, 'cm_subscription_period', true ) ?: 'monthly';
		$next_date = self::calculate_next_date( $period );
		update_user_meta( $user_id, 'cm_subscription_next_date', $next_date );

		return true;
	}

	/**
	 * Cancel subscription.
	 *
	 * @param int $user_id
	 */
	public static function cancel_subscription( $user_id ) {
		update_user_meta( $user_id, 'cm_subscription_status', 'cancelled' );
		delete_user_meta( $user_id, 'cm_subscription_active' );
	}

	/**
	 * Update subscription settings.
	 *
	 * @param int    $user_id
	 * @param string $period
	 * @param int    $qty
	 * @param string $format
	 */
	public static function update_subscription( $user_id, $period, $qty, $format ) {
		$allowed_periods = array( '2weeks', 'monthly' );
		$allowed_qty     = array( 2, 4 );
		$allowed_formats = array( 'single', 'mix', 'roaster_choice' );

		if ( in_array( $period, $allowed_periods, true ) ) {
			update_user_meta( $user_id, 'cm_subscription_period', $period );

			// Recalculate next date based on new period
			$next_date = self::calculate_next_date( $period );
			update_user_meta( $user_id, 'cm_subscription_next_date', $next_date );
		}

		if ( in_array( $qty, $allowed_qty, true ) ) {
			update_user_meta( $user_id, 'cm_subscription_qty', $qty );
		}

		if ( in_array( $format, $allowed_formats, true ) ) {
			update_user_meta( $user_id, 'cm_subscription_format', $format );
		}
	}

	/**
	 * Calculate next reminder date based on period.
	 *
	 * @param string $period '2weeks' or 'monthly'
	 * @return string Y-m-d format
	 */
	private static function calculate_next_date( $period ) {
		$interval = $period === '2weeks' ? '+2 weeks' : '+1 month';
		return gmdate( 'Y-m-d', strtotime( $interval ) );
	}

	/**
	 * Check if user has active subscription.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function has_active_subscription( $user_id ) {
		return get_user_meta( $user_id, 'cm_subscription_active', true ) === 'yes'
			&& get_user_meta( $user_id, 'cm_subscription_status', true ) === 'active';
	}

	/**
	 * Get reorder URL for subscription (last order items).
	 *
	 * @param int $user_id
	 * @return string
	 */
	public static function get_reorder_url( $user_id ) {
		$last_order_id = get_user_meta( $user_id, 'cm_subscription_last_order', true );
		if ( ! $last_order_id ) {
			return wc_get_cart_url();
		}

		return wp_nonce_url(
			add_query_arg( 'cm_reorder', $last_order_id, wc_get_cart_url() ),
			'cm_reorder_' . $last_order_id
		);
	}

	/**
	 * Get human-readable period label.
	 *
	 * @param string $period
	 * @return string
	 */
	public static function get_period_label( $period ) {
		$labels = array(
			'2weeks'  => __( 'Every 2 weeks', 'cm-discount-engine' ),
			'monthly' => __( 'Every month', 'cm-discount-engine' ),
		);
		return $labels[ $period ] ?? $period;
	}

	/**
	 * Get human-readable format label.
	 *
	 * @param string $format
	 * @return string
	 */
	public static function get_format_label( $format ) {
		$labels = array(
			'single'         => __( 'Single variety', 'cm-discount-engine' ),
			'mix'            => __( 'Mix', 'cm-discount-engine' ),
			'roaster_choice' => __( "Roaster's choice", 'cm-discount-engine' ),
		);
		return $labels[ $format ] ?? $format;
	}

	/**
	 * Get human-readable status label.
	 *
	 * @param string $status
	 * @return string
	 */
	public static function get_status_label( $status ) {
		$labels = array(
			'active'    => __( 'Active', 'cm-discount-engine' ),
			'paused'    => __( 'Paused', 'cm-discount-engine' ),
			'cancelled' => __( 'Cancelled', 'cm-discount-engine' ),
			'none'      => __( 'Not subscribed', 'cm-discount-engine' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Get all subscribers (for admin page).
	 *
	 * @param string $status Filter by status ('all', 'active', 'paused', 'cancelled')
	 * @return array Array of user objects with subscription data
	 */
	public static function get_all_subscribers( $status = 'all' ) {
		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => 'cm_subscription_started',
				'compare' => 'EXISTS',
			),
		);

		if ( $status !== 'all' ) {
			$meta_query[] = array(
				'key'     => 'cm_subscription_status',
				'value'   => $status,
				'compare' => '=',
			);
		}

		$args = array(
			'meta_query' => $meta_query,
			'orderby'    => 'meta_value',
			'meta_key'   => 'cm_subscription_started',
			'order'      => 'DESC',
		);

		$users = get_users( $args );

		$subscribers = array();
		foreach ( $users as $user ) {
			$subscribers[] = array(
				'user'         => $user,
				'subscription' => self::get_subscription( $user->ID ),
			);
		}

		return $subscribers;
	}
}
