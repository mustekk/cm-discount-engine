<?php
/**
 * CM Admin Subscriptions — WooCommerce admin page for subscription management.
 */

defined( 'ABSPATH' ) || exit;

class CM_Admin_Subscriptions {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
	}

	/**
	 * Add submenu page under WooCommerce.
	 */
	public static function add_menu_page() {
		if ( 'yes' !== get_option( 'cm_subscription_enabled', 'no' ) ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Coffee Club Subscribers', 'cm-discount-engine' ),
			__( 'Coffee Club', 'cm-discount-engine' ),
			'manage_woocommerce',
			'cm-subscriptions',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the admin page.
	 */
	public static function render_page() {
		$status = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : 'all';
		$subscribers = CM_Subscription_Manager::get_all_subscribers( $status );

		// Count by status
		$all_subscribers = CM_Subscription_Manager::get_all_subscribers( 'all' );
		$counts = array(
			'all'       => count( $all_subscribers ),
			'active'    => 0,
			'paused'    => 0,
			'cancelled' => 0,
		);
		foreach ( $all_subscribers as $sub ) {
			$s = $sub['subscription']['status'];
			if ( isset( $counts[ $s ] ) ) {
				$counts[ $s ]++;
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Coffee Club Subscribers', 'cm-discount-engine' ); ?></h1>

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cm-subscriptions&status=all' ) ); ?>" <?php echo $status === 'all' ? 'class="current"' : ''; ?>>
						<?php esc_html_e( 'All', 'cm-discount-engine' ); ?> <span class="count">(<?php echo esc_html( $counts['all'] ); ?>)</span>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cm-subscriptions&status=active' ) ); ?>" <?php echo $status === 'active' ? 'class="current"' : ''; ?>>
						<?php esc_html_e( 'Active', 'cm-discount-engine' ); ?> <span class="count">(<?php echo esc_html( $counts['active'] ); ?>)</span>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cm-subscriptions&status=paused' ) ); ?>" <?php echo $status === 'paused' ? 'class="current"' : ''; ?>>
						<?php esc_html_e( 'Paused', 'cm-discount-engine' ); ?> <span class="count">(<?php echo esc_html( $counts['paused'] ); ?>)</span>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cm-subscriptions&status=cancelled' ) ); ?>" <?php echo $status === 'cancelled' ? 'class="current"' : ''; ?>>
						<?php esc_html_e( 'Cancelled', 'cm-discount-engine' ); ?> <span class="count">(<?php echo esc_html( $counts['cancelled'] ); ?>)</span>
					</a>
				</li>
			</ul>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Customer', 'cm-discount-engine' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'cm-discount-engine' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Period', 'cm-discount-engine' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Quantity', 'cm-discount-engine' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Format', 'cm-discount-engine' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last Order', 'cm-discount-engine' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Next Date', 'cm-discount-engine' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Member Since', 'cm-discount-engine' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $subscribers ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'No subscribers found.', 'cm-discount-engine' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $subscribers as $data ) :
							$user = $data['user'];
							$sub  = $data['subscription'];
							$order = $sub['last_order'] ? wc_get_order( $sub['last_order'] ) : null;
						?>
							<tr>
								<td>
									<strong>
										<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>">
											<?php echo esc_html( $user->display_name ); ?>
										</a>
									</strong>
									<br>
									<small><?php echo esc_html( $user->user_email ); ?></small>
								</td>
								<td>
									<span class="cm-status cm-status--<?php echo esc_attr( $sub['status'] ); ?>">
										<?php echo esc_html( CM_Subscription_Manager::get_status_label( $sub['status'] ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( CM_Subscription_Manager::get_period_label( $sub['period'] ) ); ?></td>
								<td>
									<?php
									printf(
										esc_html( _n( '%d pack', '%d packs', $sub['qty'], 'cm-discount-engine' ) ),
										$sub['qty']
									);
									?>
								</td>
								<td><?php echo esc_html( CM_Subscription_Manager::get_format_label( $sub['format'] ) ); ?></td>
								<td>
									<?php if ( $order ) : ?>
										<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
											#<?php echo esc_html( $order->get_order_number() ); ?>
										</a>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td>
									<?php
									if ( $sub['next_date'] && $sub['status'] === 'active' ) {
										echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $sub['next_date'] ) ) );
									} else {
										echo '&mdash;';
									}
									?>
								</td>
								<td>
									<?php
									if ( $sub['started'] ) {
										echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $sub['started'] ) ) );
									} else {
										echo '&mdash;';
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<style>
			.cm-status {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 12px;
				font-weight: 500;
			}
			.cm-status--active {
				background: #d4edda;
				color: #155724;
			}
			.cm-status--paused {
				background: #fff3cd;
				color: #856404;
			}
			.cm-status--cancelled {
				background: #f8d7da;
				color: #721c24;
			}
			.cm-status--none {
				background: #e2e3e5;
				color: #383d41;
			}
		</style>
		<?php
	}
}
