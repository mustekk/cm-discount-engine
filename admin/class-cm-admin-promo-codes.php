<?php
/**
 * CM Admin Promo Codes — CPT columns, meta boxes, save handlers.
 */

defined( 'ABSPATH' ) || exit;

class CM_Admin_Promo_Codes {

	public static function init() {
		add_filter( 'manage_cm_promo_code_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_cm_promo_code_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_cm_promo_code', array( __CLASS__, 'save_meta' ), 10, 2 );

		// Make title field label say "Promo Code"
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
	}

	/**
	 * Custom columns for the CPT list table.
	 */
	public static function columns( $columns ) {
		$new = array();
		$new['cb']          = $columns['cb'];
		$new['title']       = __( 'Code', 'cm-discount-engine' );
		$new['cm_type']     = __( 'Type', 'cm-discount-engine' );
		$new['cm_value']    = __( 'Value', 'cm-discount-engine' );
		$new['cm_referral'] = __( 'Referral', 'cm-discount-engine' );
		$new['cm_expires']  = __( 'Expires', 'cm-discount-engine' );
		$new['cm_usage']    = __( 'Usage', 'cm-discount-engine' );
		$new['date']        = $columns['date'];
		return $new;
	}

	/**
	 * Content for custom columns.
	 */
	public static function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'cm_type':
				$type = get_post_meta( $post_id, '_cm_promo_type', true );
				echo esc_html( $type === 'fixed' ? 'Fixed (€)' : 'Percent (%)' );
				break;

			case 'cm_value':
				$type  = get_post_meta( $post_id, '_cm_promo_type', true );
				$value = get_post_meta( $post_id, '_cm_promo_value', true );
				if ( $type === 'fixed' ) {
					echo '€' . esc_html( number_format( (float) $value, 2 ) );
				} else {
					echo esc_html( $value ) . '%';
				}
				break;

			case 'cm_referral':
				$is_referral = get_post_meta( $post_id, '_cm_is_referral', true );
				if ( $is_referral === 'yes' ) {
					$referrer_id = get_post_meta( $post_id, '_cm_referrer_user_id', true );
					$user = $referrer_id ? get_userdata( $referrer_id ) : null;
					$name = $user ? $user->display_name : '#' . $referrer_id;
					echo '<span style="color:#2563eb; font-weight:600;">&#9733; ' . esc_html( $name ) . '</span>';
				} else {
					echo '—';
				}
				break;

			case 'cm_expires':
				$expires = get_post_meta( $post_id, '_cm_promo_expires', true );
				if ( $expires ) {
					$expired = strtotime( $expires . ' 23:59:59' ) < time();
					echo esc_html( $expires );
					if ( $expired ) {
						echo ' <span style="color:red;">(expired)</span>';
					}
				} else {
					echo '—';
				}
				break;

			case 'cm_usage':
				$limit = (int) get_post_meta( $post_id, '_cm_promo_usage_limit', true );
				$count = (int) get_post_meta( $post_id, '_cm_promo_usage_count', true );
				if ( $limit > 0 ) {
					echo esc_html( "$count / $limit" );
				} else {
					echo esc_html( $count ) . ' / ∞';
				}
				break;
		}
	}

	/**
	 * Add meta boxes.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'cm_promo_details',
			__( 'Promo Code Details', 'cm-discount-engine' ),
			array( __CLASS__, 'render_meta_box' ),
			'cm_promo_code',
			'normal',
			'high'
		);
	}

	/**
	 * Render the promo code meta box.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'cm_promo_save', 'cm_promo_nonce' );

		$type        = get_post_meta( $post->ID, '_cm_promo_type', true ) ?: 'percent';
		$value       = get_post_meta( $post->ID, '_cm_promo_value', true ) ?: '';
		$expires     = get_post_meta( $post->ID, '_cm_promo_expires', true ) ?: '';
		$usage_limit = get_post_meta( $post->ID, '_cm_promo_usage_limit', true ) ?: '';
		$usage_count   = (int) get_post_meta( $post->ID, '_cm_promo_usage_count', true );
		$is_referral   = get_post_meta( $post->ID, '_cm_is_referral', true ) ?: 'no';
		$referrer_user = get_post_meta( $post->ID, '_cm_referrer_user_id', true ) ?: '';
		?>
		<table class="form-table">
			<tr>
				<th><label for="cm_promo_type"><?php esc_html_e( 'Discount Type', 'cm-discount-engine' ); ?></label></th>
				<td>
					<select name="cm_promo_type" id="cm_promo_type">
						<option value="percent" <?php selected( $type, 'percent' ); ?>><?php esc_html_e( 'Percentage (%)', 'cm-discount-engine' ); ?></option>
						<option value="fixed" <?php selected( $type, 'fixed' ); ?>><?php esc_html_e( 'Fixed amount (€)', 'cm-discount-engine' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="cm_promo_value"><?php esc_html_e( 'Value', 'cm-discount-engine' ); ?></label></th>
				<td>
					<input type="number" name="cm_promo_value" id="cm_promo_value" value="<?php echo esc_attr( $value ); ?>" min="0" step="0.01" style="width:100px;">
					<p class="description"><?php esc_html_e( 'For percent: enter the percentage (e.g. 15 for 15%). For fixed: enter the amount in €.', 'cm-discount-engine' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="cm_promo_expires"><?php esc_html_e( 'Expiry Date', 'cm-discount-engine' ); ?></label></th>
				<td>
					<input type="date" name="cm_promo_expires" id="cm_promo_expires" value="<?php echo esc_attr( $expires ); ?>">
					<p class="description"><?php esc_html_e( 'Leave empty for no expiry.', 'cm-discount-engine' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="cm_promo_usage_limit"><?php esc_html_e( 'Usage Limit', 'cm-discount-engine' ); ?></label></th>
				<td>
					<input type="number" name="cm_promo_usage_limit" id="cm_promo_usage_limit" value="<?php echo esc_attr( $usage_limit ); ?>" min="0" step="1" style="width:100px;">
					<p class="description"><?php esc_html_e( 'Maximum number of times this code can be used. 0 or empty = unlimited.', 'cm-discount-engine' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Used', 'cm-discount-engine' ); ?></th>
				<td>
					<strong><?php echo esc_html( $usage_count ); ?></strong> <?php esc_html_e( 'times', 'cm-discount-engine' ); ?>
				</td>
			</tr>
			<tr>
				<th><label for="cm_is_referral"><?php esc_html_e( 'Referral Code', 'cm-discount-engine' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="cm_is_referral" id="cm_is_referral" value="yes" <?php checked( $is_referral, 'yes' ); ?>>
						<?php esc_html_e( 'This is a referral/influencer promo code', 'cm-discount-engine' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><label for="cm_referrer_user_id"><?php esc_html_e( 'Referrer User ID', 'cm-discount-engine' ); ?></label></th>
				<td>
					<input type="number" name="cm_referrer_user_id" id="cm_referrer_user_id" value="<?php echo esc_attr( $referrer_user ); ?>" min="0" step="1" style="width:100px;">
					<p class="description"><?php esc_html_e( 'WordPress user ID of the referrer/influencer who owns this code.', 'cm-discount-engine' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save meta box data.
	 */
	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['cm_promo_nonce'] ) || ! wp_verify_nonce( $_POST['cm_promo_nonce'], 'cm_promo_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'cm_promo_type'        => '_cm_promo_type',
			'cm_promo_value'       => '_cm_promo_value',
			'cm_promo_expires'     => '_cm_promo_expires',
			'cm_promo_usage_limit' => '_cm_promo_usage_limit',
		);

		foreach ( $fields as $post_key => $meta_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $_POST[ $post_key ] ) );
			}
		}

		// Referral fields
		$is_referral = isset( $_POST['cm_is_referral'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_cm_is_referral', $is_referral );

		if ( isset( $_POST['cm_referrer_user_id'] ) ) {
			$referrer_id = absint( $_POST['cm_referrer_user_id'] );
			if ( $referrer_id > 0 ) {
				update_post_meta( $post_id, '_cm_referrer_user_id', $referrer_id );
			} else {
				delete_post_meta( $post_id, '_cm_referrer_user_id' );
			}
		}

		// Ensure usage count exists
		if ( get_post_meta( $post_id, '_cm_promo_usage_count', true ) === '' ) {
			update_post_meta( $post_id, '_cm_promo_usage_count', 0 );
		}

		// Force title to lowercase for consistent matching
		if ( $post->post_title !== strtolower( $post->post_title ) ) {
			remove_action( 'save_post_cm_promo_code', array( __CLASS__, 'save_meta' ), 10 );
			wp_update_post( array(
				'ID'         => $post_id,
				'post_title' => strtolower( $post->post_title ),
			) );
			add_action( 'save_post_cm_promo_code', array( __CLASS__, 'save_meta' ), 10, 2 );
		}
	}

	/**
	 * Placeholder text for title field.
	 */
	public static function title_placeholder( $placeholder, $post ) {
		if ( $post->post_type === 'cm_promo_code' ) {
			return __( 'Enter promo code (e.g. summer20)', 'cm-discount-engine' );
		}
		return $placeholder;
	}
}
