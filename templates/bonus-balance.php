<?php
/**
 * Template: Bonus balance card on My Account dashboard.
 *
 * Override: copy to yourtheme/cm-discount-engine/bonus-balance.php
 *
 * @var float  $balance Bonus balance amount.
 * @var string $expires Expiry date (Y-m-d) or empty.
 */

defined( 'ABSPATH' ) || exit;

if ( $balance <= 0 ) {
	return;
}
?>
<div class="cm-bonus-balance">
	<div class="cm-bonus-balance__header">
		<span class="cm-bonus-balance__icon">&#127873;</span>
		<span class="cm-bonus-balance__title"><?php esc_html_e( 'Your Bonus Balance', 'cm-discount-engine' ); ?></span>
	</div>
	<div class="cm-bonus-balance__amount">
		&euro;<?php echo esc_html( number_format( $balance, 2 ) ); ?>
	</div>
	<?php if ( ! empty( $expires ) ) : ?>
		<div class="cm-bonus-balance__expires">
			<?php
			printf(
				esc_html__( 'Valid until %s', 'cm-discount-engine' ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $expires ) ) )
			);
			?>
		</div>
	<?php endif; ?>
	<div class="cm-bonus-balance__info">
		<?php esc_html_e( 'Your bonus will be automatically applied to your next order.', 'cm-discount-engine' ); ?>
	</div>
</div>
