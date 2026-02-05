<?php
/**
 * CM My Account — reorder button, bonus balance card on dashboard.
 */

defined( 'ABSPATH' ) || exit;

class CM_My_Account {

	public static function init() {
		// Add "Reorder" button to order actions
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'add_reorder_action' ), 10, 2 );

		// Handle the reorder action
		add_action( 'template_redirect', array( __CLASS__, 'handle_reorder' ) );

		// Show bonus balance on dashboard
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'show_bonus_balance' ) );
	}

	/**
	 * Add "Reorder" button to the orders list.
	 *
	 * @param array    $actions
	 * @param WC_Order $order
	 * @return array
	 */
	public static function add_reorder_action( $actions, $order ) {
		if ( $order->has_status( array( 'completed', 'processing' ) ) ) {
			$actions['cm_reorder'] = array(
				'url'  => wp_nonce_url(
					add_query_arg( 'cm_reorder', $order->get_id(), wc_get_cart_url() ),
					'cm_reorder_' . $order->get_id()
				),
				'name' => __( 'Reorder', 'cm-discount-engine' ),
			);
		}
		return $actions;
	}

	/**
	 * Handle the reorder action: load order items into cart.
	 */
	public static function handle_reorder() {
		if ( ! isset( $_GET['cm_reorder'] ) ) {
			return;
		}

		$order_id = absint( $_GET['cm_reorder'] );

		if ( ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? $_GET['_wpnonce'] : '', 'cm_reorder_' . $order_id ) ) {
			wc_add_notice( __( 'Invalid reorder link.', 'cm-discount-engine' ), 'error' );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_customer_id() !== get_current_user_id() ) {
			wc_add_notice( __( 'Order not found.', 'cm-discount-engine' ), 'error' );
			return;
		}

		$added = 0;
		foreach ( $order->get_items() as $item ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$quantity     = $item->get_quantity();

			$product = wc_get_product( $variation_id ?: $product_id );

			if ( $product && $product->is_in_stock() && $product->is_purchasable() ) {
				WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );
				$added++;
			}
		}

		if ( $added > 0 ) {
			wc_add_notice(
				sprintf(
					__( '%d item(s) from your previous order have been added to the cart.', 'cm-discount-engine' ),
					$added
				),
				'success'
			);
		} else {
			wc_add_notice(
				__( 'No items could be added to the cart (out of stock or unavailable).', 'cm-discount-engine' ),
				'notice'
			);
		}

		wp_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Show bonus balance card on My Account dashboard.
	 */
	public static function show_bonus_balance() {
		if ( 'yes' !== get_option( 'cm_bonus_enabled', 'no' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$balance = CM_Bonus_Program::get_available_bonus( $user_id );

		if ( $balance <= 0 ) {
			return;
		}

		$expires = get_user_meta( $user_id, 'cm_bonus_expires', true );

		$template = CM_DE_PLUGIN_DIR . 'templates/bonus-balance.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}
}
