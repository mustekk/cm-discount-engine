<?php
/**
 * Template: Cart upsell message.
 *
 * Override: copy to yourtheme/cm-discount-engine/upsell-message.php
 *
 * @var string $message  The upsell message text.
 * @var string $shop_url URL to the coffee shop / eligible category page.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $message ) ) {
	return;
}
?>
<div class="cm-upsell-message">
	<span class="cm-upsell-message__icon">&#128176;</span>
	<span class="cm-upsell-message__text">
		<?php echo wp_kses_post( $message ); ?>
		<?php if ( ! empty( $shop_url ) ) : ?>
			<a href="<?php echo esc_url( $shop_url ); ?>" class="cm-upsell-message__action">Browse coffee &rarr;</a>
		<?php endif; ?>
	</span>
</div>
