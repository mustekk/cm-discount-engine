<?php
/**
 * Template: Cart discount notice (applied discount explanation).
 *
 * Override: copy to yourtheme/cm-discount-engine/cart-discount-notice.php
 *
 * @var string $notice The discount description.
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
