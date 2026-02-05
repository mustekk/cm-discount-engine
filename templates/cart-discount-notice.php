<?php
/**
 * Template: Cart discount notice (applied discount explanation).
 *
 * Override: copy to yourtheme/cm-discount-engine/cart-discount-notice.php
 *
 * @var string     $notice        The discount description.
 * @var array|null $replaced_info Promo candidate that lost to a better discount, or null.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $notice ) ) {
	return;
}
?>
<div class="cm-cart-notice">
	<span class="cm-cart-notice__icon">&#10003;</span>
	<span class="cm-cart-notice__text"><?php echo wp_kses_post( $notice ); ?></span>
</div>
<?php if ( ! empty( $replaced_info ) ) : ?>
	<div class="cm-cart-notice cm-cart-notice__replaced">
		<span class="cm-cart-notice__icon">&#8505;</span>
		<span class="cm-cart-notice__text">
			<?php
			printf(
				/* translators: %1$s = promo label, %2$s = winning discount label */
				esc_html__( 'Your %1$s was not applied — %2$s saves you more.', 'cm-discount-engine' ),
				'<strong>' . esc_html( $replaced_info['label'] ) . '</strong>',
				'<strong>' . esc_html( $notice ) . '</strong>'
			);
			?>
		</span>
	</div>
<?php endif; ?>
