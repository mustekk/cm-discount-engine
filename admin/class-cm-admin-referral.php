<?php
/**
 * CM Admin Referral — WooCommerce submenu page for referral commissions.
 */

defined( 'ABSPATH' ) || exit;

class CM_Admin_Referral {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
		add_action( 'admin_post_cm_mark_commission_paid', array( __CLASS__, 'handle_mark_paid' ) );
	}

	/**
	 * Add submenu page under WooCommerce.
	 */
	public static function add_submenu() {
		add_submenu_page(
			'woocommerce',
			__( 'Referral Commissions', 'cm-discount-engine' ),
			__( 'Referral Commissions', 'cm-discount-engine' ),
			'manage_woocommerce',
			'cm-referral-commissions',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the commissions listing page.
	 */
	public static function render_page() {
		global $wpdb;

		$table = $wpdb->prefix . 'cm_referral_commissions';

		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$table
		) );

		if ( ! $table_exists ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Referral Commissions', 'cm-discount-engine' ) . '</h1>';
			echo '<p>' . esc_html__( 'Commissions table not found. Please deactivate and reactivate the plugin.', 'cm-discount-engine' ) . '</p>';
			echo '</div>';
			return;
		}

		// Pagination
		$per_page     = 25;
		$current_page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$offset       = ( $current_page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$items = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		) );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Referral Commissions', 'cm-discount-engine' ); ?></h1>

			<?php if ( empty( $items ) ) : ?>
				<p><?php esc_html_e( 'No commissions recorded yet.', 'cm-discount-engine' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:60px;"><?php esc_html_e( 'ID', 'cm-discount-engine' ); ?></th>
							<th><?php esc_html_e( 'Referrer', 'cm-discount-engine' ); ?></th>
							<th><?php esc_html_e( 'Order', 'cm-discount-engine' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Amount', 'cm-discount-engine' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Status', 'cm-discount-engine' ); ?></th>
							<th><?php esc_html_e( 'Date', 'cm-discount-engine' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Actions', 'cm-discount-engine' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $item ) :
							$user = get_userdata( $item->referrer_id );
							$referrer_name = $user ? $user->display_name . ' (#' . $item->referrer_id . ')' : '#' . $item->referrer_id;
							$order_url = admin_url( 'post.php?post=' . $item->order_id . '&action=edit' );
						?>
							<tr>
								<td><?php echo esc_html( $item->id ); ?></td>
								<td><?php echo esc_html( $referrer_name ); ?></td>
								<td><a href="<?php echo esc_url( $order_url ); ?>">#<?php echo esc_html( $item->order_id ); ?></a></td>
								<td>&euro;<?php echo esc_html( number_format( $item->amount, 2 ) ); ?></td>
								<td>
									<?php if ( $item->status === 'paid' ) : ?>
										<span style="color:green; font-weight:600;"><?php esc_html_e( 'Paid', 'cm-discount-engine' ); ?></span>
									<?php else : ?>
										<span style="color:#b45309; font-weight:600;"><?php esc_html_e( 'Pending', 'cm-discount-engine' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $item->created_at ); ?></td>
								<td>
									<?php if ( $item->status === 'pending' ) : ?>
										<a href="<?php echo esc_url( wp_nonce_url(
											admin_url( 'admin-post.php?action=cm_mark_commission_paid&id=' . $item->id ),
											'cm_mark_paid_' . $item->id
										) ); ?>" class="button button-small">
											<?php esc_html_e( 'Mark Paid', 'cm-discount-engine' ); ?>
										</a>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php
				$total_pages = ceil( $total / $per_page );
				if ( $total_pages > 1 ) {
					echo '<div class="tablenav"><div class="tablenav-pages">';
					echo paginate_links( array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $current_page,
						'total'   => $total_pages,
					) );
					echo '</div></div>';
				}
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle "Mark Paid" action.
	 */
	public static function handle_mark_paid() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( ! $id || ! check_admin_referer( 'cm_mark_paid_' . $id ) ) {
			wp_die( __( 'Invalid request.', 'cm-discount-engine' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'Access denied.', 'cm-discount-engine' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cm_referral_commissions';

		$wpdb->update(
			$table,
			array( 'status' => 'paid' ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		wp_redirect( admin_url( 'admin.php?page=cm-referral-commissions' ) );
		exit;
	}
}
