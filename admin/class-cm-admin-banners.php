<?php
/**
 * CM Admin Banners — CPT columns, meta boxes, save handlers.
 */

defined( 'ABSPATH' ) || exit;

class CM_Admin_Banners {

	public static function init() {
		add_filter( 'manage_cm_banner_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_cm_banner_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_cm_banner', array( __CLASS__, 'save_meta' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Custom columns for the CPT list table.
	 */
	public static function columns( $columns ) {
		$new = array();
		$new['cb']             = $columns['cb'];
		$new['cm_preview']     = __( 'Preview', 'cm-discount-engine' );
		$new['title']          = __( 'Title', 'cm-discount-engine' );
		$new['cm_type']        = __( 'Type', 'cm-discount-engine' );
		$new['cm_status']      = __( 'Status', 'cm-discount-engine' );
		$new['cm_dates']       = __( 'Dates', 'cm-discount-engine' );
		$new['cm_priority']    = __( 'Priority', 'cm-discount-engine' );
		$new['cm_impressions'] = __( 'Impressions', 'cm-discount-engine' );
		return $new;
	}

	/**
	 * Content for custom columns.
	 */
	public static function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'cm_preview':
				$image_id = get_post_meta( $post_id, '_cm_banner_image_desktop', true );
				if ( $image_id ) {
					$thumb = wp_get_attachment_image_url( $image_id, array( 80, 40 ) );
					if ( $thumb ) {
						echo '<img src="' . esc_url( $thumb ) . '" alt="" style="max-width:80px; max-height:40px; border-radius:4px;">';
					} else {
						echo '<span style="color:#9ca3af;">—</span>';
					}
				} else {
					echo '<span style="color:#9ca3af;">—</span>';
				}
				break;

			case 'cm_type':
				$type = get_post_meta( $post_id, '_cm_banner_type', true ) ?: 'generic';
				$badges = array(
					'first_order'  => array( 'label' => 'First Order', 'bg' => '#dcfce7', 'color' => '#166534' ),
					'subscription' => array( 'label' => 'Subscription', 'bg' => '#dbeafe', 'color' => '#1e40af' ),
					'promo'        => array( 'label' => 'Promo', 'bg' => '#fef3c7', 'color' => '#92400e' ),
					'generic'      => array( 'label' => 'Generic', 'bg' => '#f3f4f6', 'color' => '#4b5563' ),
				);
				$badge = isset( $badges[ $type ] ) ? $badges[ $type ] : $badges['generic'];
				echo '<span style="display:inline-block; padding:2px 8px; background:' . esc_attr( $badge['bg'] ) . '; color:' . esc_attr( $badge['color'] ) . '; border-radius:4px; font-size:12px; font-weight:600;">' . esc_html( $badge['label'] ) . '</span>';
				break;

			case 'cm_status':
				$enabled    = get_post_meta( $post_id, '_cm_banner_enabled', true );
				$start_date = get_post_meta( $post_id, '_cm_banner_start_date', true );
				$end_date   = get_post_meta( $post_id, '_cm_banner_end_date', true );
				$now        = current_time( 'Y-m-d' );

				if ( $enabled === 'no' ) {
					echo '<span style="color:#991b1b; font-weight:600;">● Disabled</span>';
				} elseif ( $end_date && $now > $end_date ) {
					echo '<span style="color:#6b7280;">● Expired</span>';
				} elseif ( $start_date && $now < $start_date ) {
					echo '<span style="color:#2563eb;">● Scheduled</span>';
				} else {
					echo '<span style="color:#16a34a; font-weight:600;">● Active</span>';
				}
				break;

			case 'cm_dates':
				$start = get_post_meta( $post_id, '_cm_banner_start_date', true );
				$end   = get_post_meta( $post_id, '_cm_banner_end_date', true );
				if ( $start || $end ) {
					echo esc_html( ( $start ?: '—' ) . ' → ' . ( $end ?: '∞' ) );
				} else {
					echo '<span style="color:#9ca3af;">Always</span>';
				}
				break;

			case 'cm_priority':
				$priority = (int) get_post_meta( $post_id, '_cm_banner_priority', true ) ?: 10;
				echo esc_html( $priority );
				break;

			case 'cm_impressions':
				$impressions = (int) get_post_meta( $post_id, '_cm_banner_impressions', true );
				echo esc_html( number_format( $impressions ) );
				break;
		}
	}

	/**
	 * Add meta boxes.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'cm_banner_content',
			__( 'Banner Content', 'cm-discount-engine' ),
			array( __CLASS__, 'render_content_box' ),
			'cm_banner',
			'normal',
			'high'
		);

		add_meta_box(
			'cm_banner_settings',
			__( 'Display Settings', 'cm-discount-engine' ),
			array( __CLASS__, 'render_settings_box' ),
			'cm_banner',
			'side',
			'high'
		);

		add_meta_box(
			'cm_banner_type',
			__( 'Discount Integration', 'cm-discount-engine' ),
			array( __CLASS__, 'render_type_box' ),
			'cm_banner',
			'side',
			'default'
		);

		add_meta_box(
			'cm_banner_conditions',
			__( 'Visibility Conditions', 'cm-discount-engine' ),
			array( __CLASS__, 'render_conditions_box' ),
			'cm_banner',
			'normal',
			'default'
		);
	}

	/**
	 * Render Banner Content meta box.
	 */
	public static function render_content_box( $post ) {
		wp_nonce_field( 'cm_banner_save', 'cm_banner_nonce' );

		$image_desktop = (int) get_post_meta( $post->ID, '_cm_banner_image_desktop', true );
		$image_mobile  = (int) get_post_meta( $post->ID, '_cm_banner_image_mobile', true );
		$link_url      = get_post_meta( $post->ID, '_cm_banner_link_url', true );
		$link_target   = get_post_meta( $post->ID, '_cm_banner_link_target', true ) ?: '_self';
		$cta_text      = get_post_meta( $post->ID, '_cm_banner_cta_text', true );
		?>
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Desktop Image', 'cm-discount-engine' ); ?></label></th>
				<td>
					<div class="cm-banner-image-field" data-target="cm_banner_image_desktop">
						<input type="hidden" name="cm_banner_image_desktop" id="cm_banner_image_desktop" value="<?php echo esc_attr( $image_desktop ); ?>">
						<div class="cm-banner-image-preview" style="margin-bottom:8px;">
							<?php if ( $image_desktop ) : ?>
								<?php echo wp_get_attachment_image( $image_desktop, 'medium' ); ?>
							<?php endif; ?>
						</div>
						<button type="button" class="button cm-banner-image-select"><?php esc_html_e( 'Select Image', 'cm-discount-engine' ); ?></button>
						<button type="button" class="button cm-banner-image-remove" <?php echo $image_desktop ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'cm-discount-engine' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'Recommended: 1200x400px or similar aspect ratio.', 'cm-discount-engine' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Mobile Image (optional)', 'cm-discount-engine' ); ?></label></th>
				<td>
					<div class="cm-banner-image-field" data-target="cm_banner_image_mobile">
						<input type="hidden" name="cm_banner_image_mobile" id="cm_banner_image_mobile" value="<?php echo esc_attr( $image_mobile ); ?>">
						<div class="cm-banner-image-preview" style="margin-bottom:8px;">
							<?php if ( $image_mobile ) : ?>
								<?php echo wp_get_attachment_image( $image_mobile, 'medium' ); ?>
							<?php endif; ?>
						</div>
						<button type="button" class="button cm-banner-image-select"><?php esc_html_e( 'Select Image', 'cm-discount-engine' ); ?></button>
						<button type="button" class="button cm-banner-image-remove" <?php echo $image_mobile ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'cm-discount-engine' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'For mobile devices. Falls back to desktop if empty.', 'cm-discount-engine' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="cm_banner_link_url"><?php esc_html_e( 'Link URL', 'cm-discount-engine' ); ?></label></th>
				<td>
					<input type="url" name="cm_banner_link_url" id="cm_banner_link_url" value="<?php echo esc_url( $link_url ); ?>" class="large-text">
				</td>
			</tr>
			<tr>
				<th><label for="cm_banner_link_target"><?php esc_html_e( 'Link Target', 'cm-discount-engine' ); ?></label></th>
				<td>
					<select name="cm_banner_link_target" id="cm_banner_link_target">
						<option value="_self" <?php selected( $link_target, '_self' ); ?>><?php esc_html_e( 'Same window', 'cm-discount-engine' ); ?></option>
						<option value="_blank" <?php selected( $link_target, '_blank' ); ?>><?php esc_html_e( 'New tab', 'cm-discount-engine' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="cm_banner_cta_text"><?php esc_html_e( 'CTA Button Text', 'cm-discount-engine' ); ?></label></th>
				<td>
					<input type="text" name="cm_banner_cta_text" id="cm_banner_cta_text" value="<?php echo esc_attr( $cta_text ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'Optional. Leave empty for no button overlay.', 'cm-discount-engine' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render Display Settings meta box.
	 */
	public static function render_settings_box( $post ) {
		$enabled    = get_post_meta( $post->ID, '_cm_banner_enabled', true );
		$priority   = get_post_meta( $post->ID, '_cm_banner_priority', true );
		$start_date = get_post_meta( $post->ID, '_cm_banner_start_date', true );
		$end_date   = get_post_meta( $post->ID, '_cm_banner_end_date', true );

		// Default enabled to 'yes' for new posts
		if ( $enabled === '' && $post->post_status === 'auto-draft' ) {
			$enabled = 'yes';
		}
		?>
		<p>
			<label>
				<input type="checkbox" name="cm_banner_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?>>
				<strong><?php esc_html_e( 'Enabled', 'cm-discount-engine' ); ?></strong>
			</label>
		</p>
		<p>
			<label for="cm_banner_priority"><?php esc_html_e( 'Priority', 'cm-discount-engine' ); ?></label><br>
			<input type="number" name="cm_banner_priority" id="cm_banner_priority" value="<?php echo esc_attr( $priority ?: 10 ); ?>" min="1" max="100" step="1" style="width:60px;">
			<span class="description"><?php esc_html_e( 'Lower = higher priority', 'cm-discount-engine' ); ?></span>
		</p>
		<p>
			<label for="cm_banner_start_date"><?php esc_html_e( 'Start Date', 'cm-discount-engine' ); ?></label><br>
			<input type="date" name="cm_banner_start_date" id="cm_banner_start_date" value="<?php echo esc_attr( $start_date ); ?>">
		</p>
		<p>
			<label for="cm_banner_end_date"><?php esc_html_e( 'End Date', 'cm-discount-engine' ); ?></label><br>
			<input type="date" name="cm_banner_end_date" id="cm_banner_end_date" value="<?php echo esc_attr( $end_date ); ?>">
		</p>
		<p class="description"><?php esc_html_e( 'Leave dates empty for always-on.', 'cm-discount-engine' ); ?></p>
		<?php
	}

	/**
	 * Render Discount Integration meta box.
	 */
	public static function render_type_box( $post ) {
		$type     = get_post_meta( $post->ID, '_cm_banner_type', true ) ?: 'generic';
		$promo_id = (int) get_post_meta( $post->ID, '_cm_banner_promo_id', true );

		$types = array(
			'first_order'  => __( 'First Order', 'cm-discount-engine' ),
			'subscription' => __( 'Subscription', 'cm-discount-engine' ),
			'promo'        => __( 'Promo Code', 'cm-discount-engine' ),
			'generic'      => __( 'Generic (no integration)', 'cm-discount-engine' ),
		);

		// Get promo codes
		$promos = get_posts( array(
			'post_type'      => 'cm_promo_code',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		?>
		<p>
			<label for="cm_banner_type"><?php esc_html_e( 'Banner Type', 'cm-discount-engine' ); ?></label><br>
			<select name="cm_banner_type" id="cm_banner_type" style="width:100%;">
				<?php foreach ( $types as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">
			<?php esc_html_e( 'First Order: shown only to users without orders.', 'cm-discount-engine' ); ?><br>
			<?php esc_html_e( 'Subscription: hidden from active subscribers.', 'cm-discount-engine' ); ?><br>
			<?php esc_html_e( 'Promo: auto-hides when promo expires.', 'cm-discount-engine' ); ?>
		</p>
		<p id="cm_banner_promo_row" style="<?php echo $type === 'promo' ? '' : 'display:none;'; ?>">
			<label for="cm_banner_promo_id"><?php esc_html_e( 'Linked Promo Code', 'cm-discount-engine' ); ?></label><br>
			<select name="cm_banner_promo_id" id="cm_banner_promo_id" style="width:100%;">
				<option value=""><?php esc_html_e( '— Select —', 'cm-discount-engine' ); ?></option>
				<?php foreach ( $promos as $promo ) : ?>
					<option value="<?php echo esc_attr( $promo->ID ); ?>" <?php selected( $promo_id, $promo->ID ); ?>><?php echo esc_html( strtoupper( $promo->post_title ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<script>
		jQuery(function($) {
			$('#cm_banner_type').on('change', function() {
				$('#cm_banner_promo_row').toggle($(this).val() === 'promo');
			});
		});
		</script>
		<?php
	}

	/**
	 * Render Visibility Conditions meta box.
	 */
	public static function render_conditions_box( $post ) {
		$conditions = get_post_meta( $post->ID, '_cm_banner_conditions', true ) ?: array();
		$config     = CM_Banner_Visibility::get_conditions_config();

		$user_state             = isset( $conditions['user_state'] ) ? $conditions['user_state'] : 'any';
		$cart_state             = isset( $conditions['cart_state'] ) ? $conditions['cart_state'] : 'any';
		$hide_for_subscribers   = isset( $conditions['hide_for_subscribers'] ) ? $conditions['hide_for_subscribers'] : '';
		$hide_after_first_order = isset( $conditions['hide_after_first_order'] ) ? $conditions['hide_after_first_order'] : '';
		$locations              = isset( $conditions['locations'] ) ? $conditions['locations'] : array();
		?>
		<table class="form-table">
			<tr>
				<th><label for="cm_banner_user_state"><?php echo esc_html( $config['user_state']['label'] ); ?></label></th>
				<td>
					<select name="cm_banner_conditions[user_state]" id="cm_banner_user_state">
						<?php foreach ( $config['user_state']['options'] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $user_state, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="cm_banner_cart_state"><?php echo esc_html( $config['cart_state']['label'] ); ?></label></th>
				<td>
					<select name="cm_banner_conditions[cart_state]" id="cm_banner_cart_state">
						<?php foreach ( $config['cart_state']['options'] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $cart_state, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th></th>
				<td>
					<label>
						<input type="checkbox" name="cm_banner_conditions[hide_for_subscribers]" value="yes" <?php checked( $hide_for_subscribers, 'yes' ); ?>>
						<?php echo esc_html( $config['hide_for_subscribers']['label'] ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th></th>
				<td>
					<label>
						<input type="checkbox" name="cm_banner_conditions[hide_after_first_order]" value="yes" <?php checked( $hide_after_first_order, 'yes' ); ?>>
						<?php echo esc_html( $config['hide_after_first_order']['label'] ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><label><?php echo esc_html( $config['locations']['label'] ); ?></label></th>
				<td>
					<?php foreach ( $config['locations']['options'] as $value => $label ) : ?>
						<label style="display:block; margin-bottom:4px;">
							<input type="checkbox" name="cm_banner_conditions[locations][]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $locations, true ) ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Leave all unchecked to show everywhere.', 'cm-discount-engine' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save meta box data.
	 */
	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['cm_banner_nonce'] ) || ! wp_verify_nonce( $_POST['cm_banner_nonce'], 'cm_banner_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Content fields
		$fields = array(
			'cm_banner_image_desktop' => '_cm_banner_image_desktop',
			'cm_banner_image_mobile'  => '_cm_banner_image_mobile',
			'cm_banner_link_url'      => '_cm_banner_link_url',
			'cm_banner_link_target'   => '_cm_banner_link_target',
			'cm_banner_cta_text'      => '_cm_banner_cta_text',
			'cm_banner_type'          => '_cm_banner_type',
			'cm_banner_promo_id'      => '_cm_banner_promo_id',
			'cm_banner_priority'      => '_cm_banner_priority',
			'cm_banner_start_date'    => '_cm_banner_start_date',
			'cm_banner_end_date'      => '_cm_banner_end_date',
		);

		foreach ( $fields as $post_key => $meta_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				$value = sanitize_text_field( $_POST[ $post_key ] );
				if ( $meta_key === '_cm_banner_link_url' ) {
					$value = esc_url_raw( $_POST[ $post_key ] );
				}
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		// Enabled toggle
		$enabled = isset( $_POST['cm_banner_enabled'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_cm_banner_enabled', $enabled );

		// Conditions (array)
		$conditions = array();
		if ( isset( $_POST['cm_banner_conditions'] ) && is_array( $_POST['cm_banner_conditions'] ) ) {
			$raw = $_POST['cm_banner_conditions'];

			$conditions['user_state'] = isset( $raw['user_state'] ) ? sanitize_key( $raw['user_state'] ) : 'any';
			$conditions['cart_state'] = isset( $raw['cart_state'] ) ? sanitize_key( $raw['cart_state'] ) : 'any';
			$conditions['hide_for_subscribers']   = isset( $raw['hide_for_subscribers'] ) ? 'yes' : '';
			$conditions['hide_after_first_order'] = isset( $raw['hide_after_first_order'] ) ? 'yes' : '';

			$locations = array();
			if ( isset( $raw['locations'] ) && is_array( $raw['locations'] ) ) {
				foreach ( $raw['locations'] as $loc ) {
					$locations[] = sanitize_key( $loc );
				}
			}
			$conditions['locations'] = $locations;
		}
		update_post_meta( $post_id, '_cm_banner_conditions', $conditions );
	}

	/**
	 * Placeholder text for title field.
	 */
	public static function title_placeholder( $placeholder, $post ) {
		if ( $post->post_type === 'cm_banner' ) {
			return __( 'Banner name (internal use only)', 'cm-discount-engine' );
		}
		return $placeholder;
	}

	/**
	 * Enqueue admin scripts for media uploader.
	 */
	public static function enqueue_scripts( $hook ) {
		global $post_type;

		if ( $post_type !== 'cm_banner' ) {
			return;
		}

		if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
			return;
		}

		wp_enqueue_media();

		$script = "
		jQuery(function($) {
			$('.cm-banner-image-field').each(function() {
				var field = $(this);
				var targetId = field.data('target');
				var input = field.find('input[type=\"hidden\"]');
				var preview = field.find('.cm-banner-image-preview');
				var selectBtn = field.find('.cm-banner-image-select');
				var removeBtn = field.find('.cm-banner-image-remove');

				selectBtn.on('click', function(e) {
					e.preventDefault();

					var frame = wp.media({
						title: 'Select Banner Image',
						button: { text: 'Use this image' },
						multiple: false,
						library: { type: 'image' }
					});

					frame.on('select', function() {
						var attachment = frame.state().get('selection').first().toJSON();
						input.val(attachment.id);
						var imgUrl = attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
						preview.html('<img src=\"' + imgUrl + '\" style=\"max-width:100%; height:auto;\">');
						removeBtn.show();
					});

					frame.open();
				});

				removeBtn.on('click', function(e) {
					e.preventDefault();
					input.val('');
					preview.html('');
					removeBtn.hide();
				});
			});
		});
		";

		wp_add_inline_script( 'jquery', $script );
	}
}
