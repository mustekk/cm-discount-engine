<?php
/**
 * CM Shipping Weight Rate — weight-based Standard shipping for Europe/UK zones.
 *
 * Two tiers: light (≤ threshold) and heavy (> threshold, ≤ max).
 * Packages exceeding max_weight get no rate (method unavailable).
 * All settings are per-instance (editable in WC Admin → Shipping → Zone → Method).
 */

defined( 'ABSPATH' ) || exit;

class CM_Shipping_Weight_Rate extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'cm_weight_shipping';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Standard Shipping (Weight)', 'cm-discount-engine' );
		$this->method_description = __( 'Weight-based shipping with two tiers: light and heavy packages.', 'cm-discount-engine' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init();

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	private function init() {
		$this->instance_form_fields = array(
			'title'            => array(
				'title'   => __( 'Method title', 'cm-discount-engine' ),
				'type'    => 'text',
				'default' => __( 'Standard Shipping', 'cm-discount-engine' ),
				'desc_tip' => true,
				'description' => __( 'Shown to customers at checkout.', 'cm-discount-engine' ),
			),
			'tax_status'       => array(
				'title'   => __( 'Tax status', 'cm-discount-engine' ),
				'type'    => 'select',
				'default' => 'taxable',
				'options' => array(
					'taxable' => __( 'Taxable', 'cm-discount-engine' ),
					'none'    => _x( 'None', 'Tax status', 'cm-discount-engine' ),
				),
			),
			'cost_light'       => array(
				'title'             => __( 'Light package cost (€)', 'cm-discount-engine' ),
				'type'              => 'text',
				'default'           => '17.50',
				'desc_tip'          => true,
				'description'       => __( 'Cost when package weight ≤ weight threshold.', 'cm-discount-engine' ),
				'class'             => 'wc-shipping-modal-price',
			),
			'cost_heavy'       => array(
				'title'             => __( 'Heavy package cost (€)', 'cm-discount-engine' ),
				'type'              => 'text',
				'default'           => '21.00',
				'desc_tip'          => true,
				'description'       => __( 'Cost when package weight > threshold but ≤ max weight.', 'cm-discount-engine' ),
				'class'             => 'wc-shipping-modal-price',
			),
			'weight_threshold' => array(
				'title'             => __( 'Weight threshold (kg)', 'cm-discount-engine' ),
				'type'              => 'text',
				'default'           => '1',
				'desc_tip'          => true,
				'description'       => __( 'Packages at or below this weight use the light cost.', 'cm-discount-engine' ),
			),
			'max_weight'       => array(
				'title'             => __( 'Max weight (kg)', 'cm-discount-engine' ),
				'type'              => 'text',
				'default'           => '2',
				'desc_tip'          => true,
				'description'       => __( 'Packages above this weight cannot use this method.', 'cm-discount-engine' ),
			),
			'delivery_time'    => array(
				'title'   => __( 'Delivery time', 'cm-discount-engine' ),
				'type'    => 'text',
				'default' => __( '7–12 business days', 'cm-discount-engine' ),
				'desc_tip' => true,
				'description' => __( 'Displayed to customers next to the method name.', 'cm-discount-engine' ),
			),
		);

		$this->title      = $this->get_option( 'title' );
		$this->tax_status = $this->get_option( 'tax_status' );
	}

	/**
	 * Calculate shipping cost based on package weight.
	 *
	 * @param array $package Shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		$weight    = $this->get_package_weight( $package );
		$threshold = (float) $this->get_option( 'weight_threshold', 1 );
		$max       = (float) $this->get_option( 'max_weight', 2 );

		if ( $weight > $max ) {
			return; // Package too heavy — method unavailable.
		}

		$cost = $weight <= $threshold
			? (float) $this->get_option( 'cost_light', 17.50 )
			: (float) $this->get_option( 'cost_heavy', 21.00 );

		$this->add_rate( array(
			'id'        => $this->get_rate_id(),
			'label'     => $this->title,
			'cost'      => $cost,
			'package'   => $package,
			'meta_data' => array(
				'delivery_time'    => $this->get_option( 'delivery_time', '' ),
				'weight_threshold' => $threshold,
				'max_weight'       => $max,
				'package_weight'   => $weight,
				'cost_light'       => (float) $this->get_option( 'cost_light', 17.50 ),
				'cost_heavy'       => (float) $this->get_option( 'cost_heavy', 21.00 ),
			),
		) );
	}

	/**
	 * Calculate total package weight in kg.
	 *
	 * @param array $package Shipping package.
	 * @return float Weight in kg.
	 */
	private function get_package_weight( $package ) {
		$weight    = 0;
		$wc_unit   = get_option( 'woocommerce_weight_unit', 'kg' );

		foreach ( $package['contents'] as $item ) {
			if ( $item['data']->needs_shipping() ) {
				$item_weight = (float) $item['data']->get_weight();
				if ( $item_weight > 0 ) {
					$weight += wc_get_weight( $item_weight, 'kg', $wc_unit ) * $item['quantity'];
				}
			}
		}

		return $weight;
	}
}
