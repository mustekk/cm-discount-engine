<?php
/**
 * Template: My Account — Subscription management page.
 *
 * Override: copy to yourtheme/cm-discount-engine/my-account/subscription.php
 *
 * @var array $subscription Subscription data from CM_Subscription_Manager::get_subscription()
 */

defined( 'ABSPATH' ) || exit;

$rate       = (int) get_option( 'cm_subscription_rate', 20 );
$has_sub    = $subscription['active'] || in_array( $subscription['status'], array( 'active', 'paused' ), true );
$is_active  = $subscription['status'] === 'active';
$is_paused  = $subscription['status'] === 'paused';
$reorder_url = CM_Subscription_Manager::get_reorder_url( get_current_user_id() );
?>

<div class="cm-subscription-page">
	<?php if ( $has_sub ) : ?>
		<div class="cm-subscription-card">
			<div class="cm-subscription-card__header">
				<span class="cm-subscription-card__icon">&#9749;</span>
				<span class="cm-subscription-card__title"><?php esc_html_e( 'Your Coffee Club Subscription', 'cm-discount-engine' ); ?></span>
			</div>

			<div class="cm-subscription-card__status cm-subscription-card__status--<?php echo esc_attr( $subscription['status'] ); ?>">
				<span class="cm-subscription-card__status-dot"></span>
				<span class="cm-subscription-card__status-text">
					<?php echo esc_html( CM_Subscription_Manager::get_status_label( $subscription['status'] ) ); ?>
				</span>
			</div>

			<div class="cm-subscription-card__details">
				<div class="cm-subscription-card__row">
					<span class="cm-subscription-card__label"><?php esc_html_e( 'Delivery:', 'cm-discount-engine' ); ?></span>
					<span class="cm-subscription-card__value"><?php echo esc_html( CM_Subscription_Manager::get_period_label( $subscription['period'] ) ); ?></span>
				</div>
				<div class="cm-subscription-card__row">
					<span class="cm-subscription-card__label"><?php esc_html_e( 'Quantity:', 'cm-discount-engine' ); ?></span>
					<span class="cm-subscription-card__value">
						<?php
						printf(
							esc_html( _n( '%d pack', '%d packs', $subscription['qty'], 'cm-discount-engine' ) ),
							$subscription['qty']
						);
						?>
					</span>
				</div>
				<div class="cm-subscription-card__row">
					<span class="cm-subscription-card__label"><?php esc_html_e( 'Format:', 'cm-discount-engine' ); ?></span>
					<span class="cm-subscription-card__value"><?php echo esc_html( CM_Subscription_Manager::get_format_label( $subscription['format'] ) ); ?></span>
				</div>
				<?php if ( $subscription['next_date'] && $is_active ) : ?>
					<div class="cm-subscription-card__row">
						<span class="cm-subscription-card__label"><?php esc_html_e( 'Next reminder:', 'cm-discount-engine' ); ?></span>
						<span class="cm-subscription-card__value">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription['next_date'] ) ) ); ?>
						</span>
					</div>
				<?php endif; ?>
				<?php if ( $subscription['started'] ) : ?>
					<div class="cm-subscription-card__row cm-subscription-card__row--muted">
						<span class="cm-subscription-card__label"><?php esc_html_e( 'Member since:', 'cm-discount-engine' ); ?></span>
						<span class="cm-subscription-card__value">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription['started'] ) ) ); ?>
						</span>
					</div>
				<?php endif; ?>
			</div>

			<div class="cm-subscription-card__actions">
				<?php if ( $is_active ) : ?>
					<form method="post" class="cm-subscription-form cm-subscription-form--inline">
						<?php wp_nonce_field( 'cm_subscription_action' ); ?>
						<input type="hidden" name="cm_subscription_action" value="pause">
						<button type="submit" class="button cm-subscription-btn cm-subscription-btn--secondary">
							<?php esc_html_e( 'Pause Subscription', 'cm-discount-engine' ); ?>
						</button>
					</form>
				<?php elseif ( $is_paused ) : ?>
					<form method="post" class="cm-subscription-form cm-subscription-form--inline">
						<?php wp_nonce_field( 'cm_subscription_action' ); ?>
						<input type="hidden" name="cm_subscription_action" value="resume">
						<button type="submit" class="button cm-subscription-btn cm-subscription-btn--primary">
							<?php esc_html_e( 'Resume Subscription', 'cm-discount-engine' ); ?>
						</button>
					</form>
				<?php endif; ?>

				<?php if ( $is_active || $is_paused ) : ?>
					<button type="button" class="button cm-subscription-btn cm-subscription-btn--secondary" id="cm-change-settings-btn">
						<?php esc_html_e( 'Change Settings', 'cm-discount-engine' ); ?>
					</button>

					<form method="post" class="cm-subscription-form cm-subscription-form--inline" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to cancel your subscription?', 'cm-discount-engine' ) ); ?>');">
						<?php wp_nonce_field( 'cm_subscription_action' ); ?>
						<input type="hidden" name="cm_subscription_action" value="cancel">
						<button type="submit" class="button cm-subscription-btn cm-subscription-btn--danger">
							<?php esc_html_e( 'Cancel', 'cm-discount-engine' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>

			<!-- Change Settings Form (hidden by default) -->
			<div class="cm-subscription-card__change-form" id="cm-change-form" style="display: none;">
				<form method="post" class="cm-subscription-form">
					<?php wp_nonce_field( 'cm_subscription_action' ); ?>
					<input type="hidden" name="cm_subscription_action" value="change">

					<div class="cm-subscription-form__row">
						<label for="cm_subscription_period"><?php esc_html_e( 'Delivery frequency:', 'cm-discount-engine' ); ?></label>
						<select name="cm_subscription_period" id="cm_subscription_period">
							<option value="2weeks" <?php selected( $subscription['period'], '2weeks' ); ?>><?php esc_html_e( 'Every 2 weeks', 'cm-discount-engine' ); ?></option>
							<option value="monthly" <?php selected( $subscription['period'], 'monthly' ); ?>><?php esc_html_e( 'Every month', 'cm-discount-engine' ); ?></option>
						</select>
					</div>

					<div class="cm-subscription-form__row">
						<label><?php esc_html_e( 'Quantity:', 'cm-discount-engine' ); ?></label>
						<div class="cm-subscription-form__qty-options">
							<label class="cm-subscription-form__qty-btn">
								<input type="radio" name="cm_subscription_qty" value="2" <?php checked( $subscription['qty'], 2 ); ?>>
								<span>2 <?php esc_html_e( 'packs', 'cm-discount-engine' ); ?></span>
							</label>
							<label class="cm-subscription-form__qty-btn">
								<input type="radio" name="cm_subscription_qty" value="4" <?php checked( $subscription['qty'], 4 ); ?>>
								<span>4 <?php esc_html_e( 'packs', 'cm-discount-engine' ); ?></span>
							</label>
						</div>
					</div>

					<div class="cm-subscription-form__row">
						<label for="cm_subscription_format"><?php esc_html_e( 'Format:', 'cm-discount-engine' ); ?></label>
						<select name="cm_subscription_format" id="cm_subscription_format">
							<option value="single" <?php selected( $subscription['format'], 'single' ); ?>><?php esc_html_e( 'Single variety', 'cm-discount-engine' ); ?></option>
							<option value="mix" <?php selected( $subscription['format'], 'mix' ); ?>><?php esc_html_e( 'Mix', 'cm-discount-engine' ); ?></option>
							<option value="roaster_choice" <?php selected( $subscription['format'], 'roaster_choice' ); ?>><?php esc_html_e( "Roaster's choice", 'cm-discount-engine' ); ?></option>
						</select>
					</div>

					<div class="cm-subscription-form__actions">
						<button type="submit" class="button cm-subscription-btn cm-subscription-btn--primary">
							<?php esc_html_e( 'Save Changes', 'cm-discount-engine' ); ?>
						</button>
						<button type="button" class="button cm-subscription-btn cm-subscription-btn--secondary" id="cm-cancel-change-btn">
							<?php esc_html_e( 'Cancel', 'cm-discount-engine' ); ?>
						</button>
					</div>
				</form>
			</div>

			<!-- Order Now CTA -->
			<?php if ( $is_active ) : ?>
				<div class="cm-subscription-card__cta">
					<div class="cm-subscription-card__cta-text">
						<?php esc_html_e( 'Ready to order?', 'cm-discount-engine' ); ?>
					</div>
					<a href="<?php echo esc_url( $reorder_url ); ?>" class="button cm-subscription-btn cm-subscription-btn--cta">
						<?php
						printf(
							esc_html__( 'Order Now with -%s%% discount', 'cm-discount-engine' ),
							esc_html( $rate )
						);
						?>
						<span class="cm-subscription-btn__arrow">&rarr;</span>
					</a>
				</div>
			<?php endif; ?>
		</div>

	<?php else : ?>
		<!-- No subscription -->
		<div class="cm-subscription-empty">
			<div class="cm-subscription-empty__icon">&#9749;</div>
			<h3 class="cm-subscription-empty__title"><?php esc_html_e( 'Join Coffee Club', 'cm-discount-engine' ); ?></h3>
			<p class="cm-subscription-empty__text">
				<?php
				printf(
					esc_html__( 'Subscribe and save %s%% on every order. No commitment, pause or cancel anytime.', 'cm-discount-engine' ),
					esc_html( $rate )
				);
				?>
			</p>
			<div class="cm-subscription-empty__features">
				<div class="cm-subscription-empty__feature">
					<span class="cm-subscription-empty__feature-icon">&#10003;</span>
					<span><?php esc_html_e( 'Flexible delivery (every 2 weeks or monthly)', 'cm-discount-engine' ); ?></span>
				</div>
				<div class="cm-subscription-empty__feature">
					<span class="cm-subscription-empty__feature-icon">&#10003;</span>
					<span><?php esc_html_e( 'Choose your quantity and format', 'cm-discount-engine' ); ?></span>
				</div>
				<div class="cm-subscription-empty__feature">
					<span class="cm-subscription-empty__feature-icon">&#10003;</span>
					<span><?php esc_html_e( 'Skip, pause or cancel anytime', 'cm-discount-engine' ); ?></span>
				</div>
			</div>
			<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="button cm-subscription-btn cm-subscription-btn--cta">
				<?php esc_html_e( 'Start Shopping', 'cm-discount-engine' ); ?>
				<span class="cm-subscription-btn__arrow">&rarr;</span>
			</a>
			<p class="cm-subscription-empty__hint">
				<?php esc_html_e( 'Select "Subscribe & Save" on any coffee product to join Coffee Club.', 'cm-discount-engine' ); ?>
			</p>
		</div>
	<?php endif; ?>
</div>

<script>
(function() {
	var changeBtn = document.getElementById('cm-change-settings-btn');
	var cancelBtn = document.getElementById('cm-cancel-change-btn');
	var changeForm = document.getElementById('cm-change-form');

	if (changeBtn && changeForm) {
		changeBtn.addEventListener('click', function() {
			changeForm.style.display = 'block';
			changeBtn.style.display = 'none';
		});
	}

	if (cancelBtn && changeForm && changeBtn) {
		cancelBtn.addEventListener('click', function() {
			changeForm.style.display = 'none';
			changeBtn.style.display = '';
		});
	}

	// Qty button styling
	var qtyBtns = document.querySelectorAll('.cm-subscription-form__qty-btn');
	qtyBtns.forEach(function(btn) {
		var input = btn.querySelector('input[type="radio"]');
		if (input && input.checked) {
			btn.classList.add('cm-subscription-form__qty-btn--active');
		}
		btn.addEventListener('click', function() {
			qtyBtns.forEach(function(b) { b.classList.remove('cm-subscription-form__qty-btn--active'); });
			btn.classList.add('cm-subscription-form__qty-btn--active');
		});
	});
})();
</script>
