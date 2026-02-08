<?php
/**
 * Template: Single Banner
 *
 * Variables available:
 * - $banner (array) - Banner data from CM_Banners::get_banner()
 * - $conditions_json (string) - JSON-encoded conditions for JS
 *
 * @package CM_Discount_Engine
 */

defined( 'ABSPATH' ) || exit;

// Get image URLs
$desktop_url = $banner['image_desktop'] ? wp_get_attachment_image_url( $banner['image_desktop'], 'full' ) : '';
$mobile_url  = $banner['image_mobile'] ? wp_get_attachment_image_url( $banner['image_mobile'], 'full' ) : '';

// Fallback mobile to desktop
if ( ! $mobile_url ) {
	$mobile_url = $desktop_url;
}

// No image = no banner
if ( ! $desktop_url ) {
	return;
}

// Get alt text
$alt = $banner['title'];

// Type class
$type_class = 'cm-banner--' . esc_attr( $banner['type'] );

// Has conditions that require JS check?
$conditions   = $banner['conditions'];
$needs_js     = false;
$hidden_class = '';

if ( ! empty( $conditions['user_state'] ) && $conditions['user_state'] !== 'any' ) {
	$needs_js = true;
}
if ( ! empty( $conditions['cart_state'] ) && $conditions['cart_state'] !== 'any' ) {
	$needs_js = true;
}
if ( ! empty( $conditions['hide_for_subscribers'] ) ) {
	$needs_js = true;
}
if ( ! empty( $conditions['hide_after_first_order'] ) ) {
	$needs_js = true;
}
if ( in_array( $banner['type'], array( 'first_order', 'subscription' ), true ) ) {
	$needs_js = true;
}

if ( $needs_js ) {
	$hidden_class = 'cm-banner--hidden';
}
?>
<div class="cm-banner <?php echo esc_attr( $type_class ); ?> <?php echo esc_attr( $hidden_class ); ?>"
     data-banner-id="<?php echo esc_attr( $banner['id'] ); ?>"
     data-banner-type="<?php echo esc_attr( $banner['type'] ); ?>"
     data-conditions="<?php echo esc_attr( $conditions_json ); ?>">
	<?php if ( $banner['link_url'] ) : ?>
		<a href="<?php echo esc_url( $banner['link_url'] ); ?>"
		   target="<?php echo esc_attr( $banner['link_target'] ); ?>"
		   class="cm-banner__link"
		   <?php echo $banner['link_target'] === '_blank' ? 'rel="noopener noreferrer"' : ''; ?>>
	<?php endif; ?>

	<picture class="cm-banner__picture">
		<?php if ( $mobile_url !== $desktop_url ) : ?>
			<source media="(max-width: 767px)" srcset="<?php echo esc_url( $mobile_url ); ?>">
		<?php endif; ?>
		<img src="<?php echo esc_url( $desktop_url ); ?>"
		     alt="<?php echo esc_attr( $alt ); ?>"
		     class="cm-banner__image"
		     loading="lazy">
	</picture>

	<?php if ( $banner['cta_text'] ) : ?>
		<span class="cm-banner__cta"><?php echo esc_html( $banner['cta_text'] ); ?></span>
	<?php endif; ?>

	<?php if ( $banner['link_url'] ) : ?>
		</a>
	<?php endif; ?>
</div>
