<?php
/**
 * Template: Cart upsell message.
 *
 * Override: copy to yourtheme/cm-discount-engine/upsell-message.php
 *
 * @var string $message The upsell message text.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $message ) ) {
	return;
}
?>
<div class="cm-upsell-message">
	<span class="cm-upsell-message__icon">&#128176;</span>
	<span class="cm-upsell-message__text"><?php echo wp_kses_post( $message ); ?></span>
</div>
