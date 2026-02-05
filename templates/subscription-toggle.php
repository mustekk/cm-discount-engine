<?php
/**
 * Template: Subscription toggle on product page.
 *
 * Override: copy to yourtheme/cm-discount-engine/subscription-toggle.php
 *
 * @var int $rate Subscription discount rate (e.g. 20).
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $rate ) || $rate <= 0 ) {
	return;
}
?>
<div class="cm-subscription-toggle" id="cm-subscription-toggle">
	<div class="cm-subscription-toggle__header">
		<span class="cm-subscription-toggle__badge">Save <?php echo esc_html( $rate ); ?>%</span>
		<span class="cm-subscription-toggle__title"><?php esc_html_e( 'Coffee Club', 'cm-discount-engine' ); ?></span>
	</div>
	<div class="cm-subscription-toggle__options">
		<label class="cm-subscription-toggle__option cm-subscription-toggle__option--active">
			<input type="radio" name="cm_subscription_mode" value="onetime" checked>
			<span class="cm-subscription-toggle__radio"></span>
			<span class="cm-subscription-toggle__label"><?php esc_html_e( 'One-time purchase', 'cm-discount-engine' ); ?></span>
		</label>
		<label class="cm-subscription-toggle__option">
			<input type="radio" name="cm_subscription_mode" value="subscribe">
			<span class="cm-subscription-toggle__radio"></span>
			<span class="cm-subscription-toggle__label">
				<?php
				printf(
					esc_html__( 'Subscribe & Save %s%%', 'cm-discount-engine' ),
					esc_html( $rate )
				);
				?>
			</span>
		</label>
	</div>
	<div class="cm-subscription-toggle__details" id="cm-subscription-details" style="display:none;">
		<div class="cm-subscription-toggle__row">
			<span class="cm-subscription-toggle__row-label"><?php esc_html_e( 'Quantity:', 'cm-discount-engine' ); ?></span>
			<div class="cm-subscription-toggle__qty-options">
				<label class="cm-subscription-toggle__qty-btn cm-subscription-toggle__qty-btn--active">
					<input type="radio" name="cm_subscription_qty" value="2" checked>
					<span>2 <?php esc_html_e( 'packs', 'cm-discount-engine' ); ?></span>
				</label>
				<label class="cm-subscription-toggle__qty-btn">
					<input type="radio" name="cm_subscription_qty" value="4">
					<span>4 <?php esc_html_e( 'packs', 'cm-discount-engine' ); ?></span>
				</label>
			</div>
		</div>
		<div class="cm-subscription-toggle__row">
			<label for="cm_subscription_format" class="cm-subscription-toggle__row-label"><?php esc_html_e( 'Format:', 'cm-discount-engine' ); ?></label>
			<select name="cm_subscription_format" id="cm_subscription_format">
				<option value="single"><?php esc_html_e( 'Single variety', 'cm-discount-engine' ); ?></option>
				<option value="mix"><?php esc_html_e( 'Mix', 'cm-discount-engine' ); ?></option>
				<option value="roaster_choice"><?php esc_html_e( "Roaster's choice", 'cm-discount-engine' ); ?></option>
			</select>
		</div>
		<div class="cm-subscription-toggle__row">
			<label for="cm_subscription_period" class="cm-subscription-toggle__row-label"><?php esc_html_e( 'Delivery:', 'cm-discount-engine' ); ?></label>
			<select name="cm_subscription_period" id="cm_subscription_period">
				<option value="2weeks"><?php esc_html_e( 'Every 2 weeks', 'cm-discount-engine' ); ?></option>
				<option value="monthly" selected><?php esc_html_e( 'Every month', 'cm-discount-engine' ); ?></option>
			</select>
		</div>
	</div>
</div>
