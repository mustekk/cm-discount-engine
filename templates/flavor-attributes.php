<?php
/**
 * Template: Flavor attributes visual bars.
 *
 * Override: copy to yourtheme/cm-discount-engine/flavor-attributes.php
 *
 * @var array $data    Array of [ 'label' => string, 'value' => int (1-5) ]
 * @var bool  $compact Whether to render compact (listing) or full (product page) version.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $data ) ) {
	return;
}

$wrapper_class = $compact ? 'cm-flavor cm-flavor--compact' : 'cm-flavor';
?>
<div class="<?php echo esc_attr( $wrapper_class ); ?>">
	<?php foreach ( $data as $attr ) :
		$width = ( $attr['value'] / 5 ) * 100;
	?>
		<div class="cm-flavor__row">
			<span class="cm-flavor__label"><?php echo esc_html( $attr['label'] ); ?></span>
			<span class="cm-flavor__bar">
				<span class="cm-flavor__fill" style="width: <?php echo esc_attr( $width ); ?>%;"></span>
			</span>
			<?php if ( ! $compact ) : ?>
				<span class="cm-flavor__value"><?php echo esc_html( $attr['value'] ); ?>/5</span>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
