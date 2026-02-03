<?php
/**
 * Template: Product page discount hint.
 *
 * Override: copy to yourtheme/cm-discount-engine/product-discount-hint.php
 *
 * @var array $tiers Sorted tiers [ ['min_packs' => int, 'rate' => float], ... ]
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $tiers ) ) {
	return;
}
?>
<div class="cm-discount-hint">
	<div class="cm-discount-hint__header">
		<span class="cm-discount-hint__icon">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
		</span>
		<span class="cm-discount-hint__title"><?php esc_html_e( 'Volume discount', 'cm-discount-engine' ); ?></span>
	</div>
	<div class="cm-discount-hint__tiers">
		<?php foreach ( $tiers as $i => $tier ) : ?>
			<div class="cm-discount-hint__tier <?php echo $i === count( $tiers ) - 1 ? 'cm-discount-hint__tier--best' : ''; ?>">
				<span class="cm-discount-hint__tier-packs"><?php echo esc_html( $tier['min_packs'] ); ?>+</span>
				<span class="cm-discount-hint__tier-label"><?php esc_html_e( 'packs', 'cm-discount-engine' ); ?></span>
				<span class="cm-discount-hint__tier-rate">-<?php echo esc_html( $tier['rate'] ); ?>%</span>
			</div>
		<?php endforeach; ?>
	</div>
</div>
