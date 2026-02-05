<?php
/**
 * CM Admin Settings — WooCommerce Settings tab "CM Discounts".
 */

defined( 'ABSPATH' ) || exit;

class CM_Admin_Settings extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'cm_discounts';
		$this->label = __( 'CM Discounts', 'cm-discount-engine' );
		parent::__construct();

		// Save quantity tiers custom field
		add_action( 'woocommerce_update_options_cm_discounts', array( $this, 'save_quantity_tiers' ) );
		add_action( 'woocommerce_update_options_cm_discounts', array( $this, 'save_category_eligibility' ) );
	}

	// No static init needed — instantiated via woocommerce_get_settings_pages filter.

	/**
	 * Get sections.
	 */
	public function get_sections() {
		return array(
			''             => __( 'General', 'cm-discount-engine' ),
			'quantity'     => __( 'Quantity Tiers', 'cm-discount-engine' ),
			'categories'   => __( 'Category Eligibility', 'cm-discount-engine' ),
			'subscription' => __( 'Subscription (Coffee Club)', 'cm-discount-engine' ),
			'bonus'        => __( 'Bonus Program', 'cm-discount-engine' ),
			'referral'     => __( 'Referral Program', 'cm-discount-engine' ),
			'catalog'      => __( 'Catalog Tiers', 'cm-discount-engine' ),
		);
	}

	/**
	 * Get settings for the current section.
	 */
	public function get_settings_for_section_core( $section_id ) {
		switch ( $section_id ) {
			case 'quantity':
				return $this->get_quantity_settings();
			case 'categories':
				return $this->get_category_settings();
			case 'subscription':
				return $this->get_subscription_settings();
			case 'bonus':
				return $this->get_bonus_settings();
			case 'referral':
				return $this->get_referral_settings();
			case 'catalog':
				return $this->get_catalog_settings();
			default:
				return $this->get_general_settings();
		}
	}

	/**
	 * General settings.
	 */
	private function get_general_settings() {
		return array(
			array(
				'title' => __( 'Discount Engine — General', 'cm-discount-engine' ),
				'type'  => 'title',
				'desc'  => __( 'Configure the main discount settings. Only the best discount is applied (discounts do not stack).', 'cm-discount-engine' ),
				'id'    => 'cm_general_options',
			),
			array(
				'title'   => __( 'First Order Discount', 'cm-discount-engine' ),
				'desc'    => __( 'Enable first order discount', 'cm-discount-engine' ),
				'id'      => 'cm_first_order_enabled',
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'title'             => __( 'First Order Rate (%)', 'cm-discount-engine' ),
				'desc'              => __( 'Discount percentage for first-time buyers.', 'cm-discount-engine' ),
				'id'                => 'cm_first_order_rate',
				'default'           => '10',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '100',
					'step' => '1',
				),
				'css'               => 'width: 80px;',
			),
			array(
				'title'   => __( 'Quantity Discount', 'cm-discount-engine' ),
				'desc'    => __( 'Enable quantity-based discount tiers', 'cm-discount-engine' ),
				'id'      => 'cm_quantity_enabled',
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'title'   => __( 'Promo Codes', 'cm-discount-engine' ),
				'desc'    => __( 'Enable promo code discounts', 'cm-discount-engine' ),
				'id'      => 'cm_promo_enabled',
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'title'             => __( 'Free Shipping Threshold (€)', 'cm-discount-engine' ),
				'desc'              => __( 'Order total (after discounts) required for free shipping.', 'cm-discount-engine' ),
				'id'                => 'cm_free_shipping_threshold',
				'default'           => '30',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'css'               => 'width: 80px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cm_general_options',
			),
		);
	}

	/**
	 * Quantity tier settings (custom rendering).
	 */
	private function get_quantity_settings() {
		return array(
			array(
				'title' => __( 'Quantity Tiers', 'cm-discount-engine' ),
				'type'  => 'title',
				'desc'  => __( 'Define discount tiers based on the number of eligible packs in the cart.', 'cm-discount-engine' ),
				'id'    => 'cm_quantity_tier_options',
			),
			array(
				'type' => 'cm_quantity_tiers_table',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cm_quantity_tier_options',
			),
		);
	}

	/**
	 * Category eligibility settings (custom rendering).
	 */
	private function get_category_settings() {
		return array(
			array(
				'title' => __( 'Category Eligibility', 'cm-discount-engine' ),
				'type'  => 'title',
				'desc'  => __( 'Select which product categories are eligible for automatic discounts.', 'cm-discount-engine' ),
				'id'    => 'cm_category_options',
			),
			array(
				'type' => 'cm_category_checkboxes',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cm_category_options',
			),
		);
	}

	/**
	 * Subscription settings.
	 */
	private function get_subscription_settings() {
		return array(
			array(
				'title' => __( 'Subscription (Coffee Club)', 'cm-discount-engine' ),
				'type'  => 'title',
				'desc'  => __( 'Configure the subscription discount. Floor rate = max(subscription rate, quantity tier rate).', 'cm-discount-engine' ),
				'id'    => 'cm_subscription_options',
			),
			array(
				'title'   => __( 'Enable Subscription Discount', 'cm-discount-engine' ),
				'desc'    => __( 'Enable "Subscribe & Save" toggle on product pages', 'cm-discount-engine' ),
				'id'      => 'cm_subscription_enabled',
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'title'             => __( 'Subscription Rate (%)', 'cm-discount-engine' ),
				'desc'              => __( 'Base subscription discount rate. The actual rate will be the max of this and the applicable quantity tier.', 'cm-discount-engine' ),
				'id'                => 'cm_subscription_rate',
				'default'           => '20',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '100',
					'step' => '1',
				),
				'css'               => 'width: 80px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cm_subscription_options',
			),
		);
	}

	/**
	 * Bonus program settings.
	 */
	private function get_bonus_settings() {
		return array(
			array(
				'title' => __( 'Bonus Program', 'cm-discount-engine' ),
				'type'  => 'title',
				'desc'  => __( 'Accrues a bonus after each completed order. Bonus is applied as a separate coupon (stacks with Resolver discount).', 'cm-discount-engine' ),
				'id'    => 'cm_bonus_options',
			),
			array(
				'title'   => __( 'Enable Bonus Program', 'cm-discount-engine' ),
				'desc'    => __( 'Enable bonus accrual and application', 'cm-discount-engine' ),
				'id'      => 'cm_bonus_enabled',
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'title'             => __( 'Bonus Rate (%)', 'cm-discount-engine' ),
				'desc'              => __( 'Percentage of net order amount (after discounts, excl. shipping) accrued as bonus.', 'cm-discount-engine' ),
				'id'                => 'cm_bonus_rate',
				'default'           => '5',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '100',
					'step' => '1',
				),
				'css'               => 'width: 80px;',
			),
			array(
				'title'             => __( 'Bonus Expiry (days)', 'cm-discount-engine' ),
				'desc'              => __( 'Number of days before bonus balance expires. 0 = no expiry.', 'cm-discount-engine' ),
				'id'                => 'cm_bonus_expiry_days',
				'default'           => '60',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'css'               => 'width: 80px;',
			),
			array(
				'title'             => __( 'Minimum Order for Bonus (€)', 'cm-discount-engine' ),
				'desc'              => __( 'Minimum order total to accrue bonus. 0 = no minimum.', 'cm-discount-engine' ),
				'id'                => 'cm_bonus_min_order',
				'default'           => '0',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'css'               => 'width: 80px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cm_bonus_options',
			),
		);
	}

	/**
	 * Referral program settings.
	 */
	private function get_referral_settings() {
		return array(
			array(
				'title' => __( 'Referral Program', 'cm-discount-engine' ),
				'type'  => 'title',
				'desc'  => __( 'Track referral promo codes and commissions. Referral attribution is saved even when the promo loses to a better discount.', 'cm-discount-engine' ),
				'id'    => 'cm_referral_options',
			),
			array(
				'title'   => __( 'Enable Referral Program', 'cm-discount-engine' ),
				'desc'    => __( 'Enable referral tracking and commissions', 'cm-discount-engine' ),
				'id'      => 'cm_referral_enabled',
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'title'             => __( 'Commission Rate (%)', 'cm-discount-engine' ),
				'desc'              => __( 'Percentage of net order revenue paid as referral commission.', 'cm-discount-engine' ),
				'id'                => 'cm_referral_rate',
				'default'           => '10',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '100',
					'step' => '1',
				),
				'css'               => 'width: 80px;',
			),
			array(
				'title'             => __( 'Subscription Payment Limit', 'cm-discount-engine' ),
				'desc'              => __( 'For subscription orders, number of recurring payments that generate commission. 0 = unlimited.', 'cm-discount-engine' ),
				'id'                => 'cm_referral_sub_payments',
				'default'           => '3',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'css'               => 'width: 80px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cm_referral_options',
			),
		);
	}

	/**
	 * Catalog tier settings.
	 */
	private function get_catalog_settings() {
		return array(
			array(
				'title' => __( 'Catalog Tiers', 'cm-discount-engine' ),
				'type'  => 'title',
				'desc'  => __( 'Define price thresholds for visual tier grouping on shop/category pages (gap between price groups).', 'cm-discount-engine' ),
				'id'    => 'cm_catalog_options',
			),
			array(
				'title'             => __( 'Entry Max Price (€)', 'cm-discount-engine' ),
				'desc'              => __( 'Products at or below this price are "Entry" tier.', 'cm-discount-engine' ),
				'id'                => 'cm_entry_max_price',
				'default'           => '12',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'css'               => 'width: 80px;',
			),
			array(
				'title'             => __( 'Core Max Price (€)', 'cm-discount-engine' ),
				'desc'              => __( 'Products above Entry and at or below this price are "Core" tier. Above this = "Premium".', 'cm-discount-engine' ),
				'id'                => 'cm_core_max_price',
				'default'           => '18',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'css'               => 'width: 80px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cm_catalog_options',
			),
		);
	}

	/**
	 * Output custom admin fields.
	 */
	public function output() {
		global $current_section;

		$settings = $this->get_settings_for_section_core( $current_section );

		foreach ( $settings as $setting ) {
			if ( isset( $setting['type'] ) && $setting['type'] === 'cm_quantity_tiers_table' ) {
				$this->render_quantity_tiers_table();
			} elseif ( isset( $setting['type'] ) && $setting['type'] === 'cm_category_checkboxes' ) {
				$this->render_category_checkboxes();
			}
		}

		WC_Admin_Settings::output_fields( $settings );
	}

	/**
	 * Render the quantity tiers table.
	 */
	private function render_quantity_tiers_table() {
		$tiers = get_option( 'cm_quantity_tiers', array() );
		if ( ! is_array( $tiers ) ) {
			$tiers = array();
		}
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Discount Tiers', 'cm-discount-engine' ); ?></th>
			<td class="forminp">
				<table class="widefat" id="cm-quantity-tiers" style="max-width: 400px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Min Packs', 'cm-discount-engine' ); ?></th>
							<th><?php esc_html_e( 'Discount %', 'cm-discount-engine' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tiers as $i => $tier ) : ?>
							<tr>
								<td><input type="number" name="cm_tiers_min[]" value="<?php echo esc_attr( $tier['min_packs'] ); ?>" min="1" step="1" style="width:80px;"></td>
								<td><input type="number" name="cm_tiers_rate[]" value="<?php echo esc_attr( $tier['rate'] ); ?>" min="0" max="100" step="1" style="width:80px;"></td>
								<td><button type="button" class="button cm-remove-tier">&times;</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="3">
								<button type="button" class="button" id="cm-add-tier">+ <?php esc_html_e( 'Add Tier', 'cm-discount-engine' ); ?></button>
							</td>
						</tr>
					</tfoot>
				</table>
				<script>
					jQuery(function($) {
						$('#cm-add-tier').on('click', function() {
							$('#cm-quantity-tiers tbody').append(
								'<tr>' +
								'<td><input type="number" name="cm_tiers_min[]" value="" min="1" step="1" style="width:80px;"></td>' +
								'<td><input type="number" name="cm_tiers_rate[]" value="" min="0" max="100" step="1" style="width:80px;"></td>' +
								'<td><button type="button" class="button cm-remove-tier">&times;</button></td>' +
								'</tr>'
							);
						});
						$(document).on('click', '.cm-remove-tier', function() {
							$(this).closest('tr').remove();
						});
					});
				</script>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render category eligibility checkboxes.
	 */
	private function render_category_checkboxes() {
		$categories = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		) );

		if ( empty( $categories ) || is_wp_error( $categories ) ) {
			echo '<tr><td colspan="2">' . esc_html__( 'No product categories found.', 'cm-discount-engine' ) . '</td></tr>';
			return;
		}

		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Eligible Categories', 'cm-discount-engine' ); ?></th>
			<td class="forminp">
				<fieldset>
					<?php foreach ( $categories as $cat ) :
						$checked = get_term_meta( $cat->term_id, 'cm_discount_eligible', true ) === 'yes';
					?>
						<label style="display:block; margin-bottom:4px;">
							<input type="checkbox" name="cm_eligible_cats[]" value="<?php echo esc_attr( $cat->term_id ); ?>" <?php checked( $checked ); ?>>
							<?php echo esc_html( $cat->name ); ?> (<?php echo esc_html( $cat->count ); ?>)
						</label>
					<?php endforeach; ?>
				</fieldset>
				<p class="description"><?php esc_html_e( 'Only products in selected categories will be eligible for automatic discounts.', 'cm-discount-engine' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save quantity tiers from POST data.
	 */
	public function save_quantity_tiers() {
		if ( ! isset( $_POST['cm_tiers_min'] ) ) {
			return;
		}

		$mins  = array_map( 'intval', $_POST['cm_tiers_min'] );
		$rates = array_map( 'floatval', $_POST['cm_tiers_rate'] );
		$tiers = array();

		foreach ( $mins as $i => $min ) {
			if ( $min > 0 && isset( $rates[ $i ] ) && $rates[ $i ] > 0 ) {
				$tiers[] = array(
					'min_packs' => $min,
					'rate'      => $rates[ $i ],
				);
			}
		}

		// Sort ascending
		usort( $tiers, function ( $a, $b ) {
			return $a['min_packs'] - $b['min_packs'];
		} );

		update_option( 'cm_quantity_tiers', $tiers );
	}

	/**
	 * Save category eligibility from POST data.
	 */
	public function save_category_eligibility() {
		$eligible_ids = isset( $_POST['cm_eligible_cats'] ) ? array_map( 'intval', $_POST['cm_eligible_cats'] ) : array();

		// Get all product categories
		$categories = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'fields'     => 'ids',
		) );

		if ( is_wp_error( $categories ) ) {
			return;
		}

		foreach ( $categories as $cat_id ) {
			if ( in_array( $cat_id, $eligible_ids, true ) ) {
				update_term_meta( $cat_id, 'cm_discount_eligible', 'yes' );
			} else {
				delete_term_meta( $cat_id, 'cm_discount_eligible' );
			}
		}
	}
}
