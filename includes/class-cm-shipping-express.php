<?php
/**
 * CM Shipping Express — fixed-rate Express shipping for Europe/UK zones.
 *
 * Simple flat cost with a max weight limit.
 */

defined( 'ABSPATH' ) || exit;

class CM_Shipping_Express extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'cm_express_shipping';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Express Shipping', 'cm-discount-engine' );
		$this->method_description = __( 'Fixed-rate express shipping with a maximum weight limit.', 'cm-discount-engine' );
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
			'title'         => array(
				'title'   => __( 'Method title', 'cm-discount-engine' ),
				'type'    => 'text',
				'default' => __( 'Express Shipping', 'cm-discount-engine' ),
				'desc_tip' => true,
				'description' => __( 'Shown to customers at checkout.', 'cm-discount-engine' ),
			),
			'tax_status'    => array(
				'title'   => __( 'Tax status', 'cm-discount-engine' ),
				'type'    => 'select',
				'default' => 'taxable',
				'options' => array(
					'taxable' => __( 'Taxable', 'cm-discount-engine' ),
					'none'    => _x( 'None', 'Tax status', 'cm-discount-engine' ),
				),
			),
			'cost'          => array(
				'title'       => __( 'Cost (€)', 'cm-discount-engine' ),
				'type'        => 'text',
				'default'     => '30.00',
				'desc_tip'    => true,
				'description' => __( 'Fixed shipping cost.', 'cm-discount-engine' ),
				'class'       => 'wc-shipping-modal-price',
			),
			'max_weight'    => array(
				'title'       => __( 'Max weight (kg)', 'cm-discount-engine' ),
				'type'        => 'text',
				'default'     => '2',
				'desc_tip'    => true,
				'description' => __( 'Packages above this weight cannot use this method.', 'cm-discount-engine' ),
			),
			'delivery_time' => array(
				'title'   => __( 'Delivery time', 'cm-discount-engine' ),
				'type'    => 'text',
				'default' => __( '2–5 business days', 'cm-discount-engine' ),
				'desc_tip' => true,
				'description' => __( 'Displayed to customers next to the method name.', 'cm-discount-engine' ),
			),
		);

		$this->title      = $this->get_option( 'title' );
		$this->tax_status = $this->get_option( 'tax_status' );
	}

	/**
	 * Calculate shipping — fixed cost if weight is within limit.
	 *
	 * @param array $package Shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		$weight = $this->get_package_weight( $package );
		$max    = (float) $this->get_option( 'max_weight', 2 );

		if ( $weight > $max ) {
			return; // Package too heavy — method unavailable.
		}

		$this->add_rate( array(
			'id'        => $this->get_rate_id(),
			'label'     => $this->title,
			'cost'      => (float) $this->get_option( 'cost', 30.00 ),
			'package'   => $package,
			'meta_data' => array(
				'delivery_time' => $this->get_option( 'delivery_time', '' ),
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
		$weight  = 0;
		$wc_unit = get_option( 'woocommerce_weight_unit', 'kg' );

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
