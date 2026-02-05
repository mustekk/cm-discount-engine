/**
 * CM Discount Engine — Subscription toggle on product page.
 *
 * Shows/hides the details panel (qty, format, period) based on subscribe/one-time selection.
 * Syncs subscription qty (2/4) to the product quantity input.
 * Dispatches 'cm_subscription_changed' event for cm-product-upsell.js.
 *
 * Uses cmSubscriptionData (via wp_localize_script):
 *   .rate — subscription base rate (e.g. 20)
 */
(function () {
	'use strict';

	var toggle = document.getElementById( 'cm-subscription-toggle' );
	if ( ! toggle ) {
		return;
	}

	var detailsWrap = document.getElementById( 'cm-subscription-details' );
	var modeRadios  = toggle.querySelectorAll( 'input[name="cm_subscription_mode"]' );
	var qtyRadios   = toggle.querySelectorAll( 'input[name="cm_subscription_qty"]' );
	var options     = toggle.querySelectorAll( '.cm-subscription-toggle__option' );
	var qtyBtns     = toggle.querySelectorAll( '.cm-subscription-toggle__qty-btn' );
	var qtyInput    = document.querySelector( 'form.cart input[name="quantity"]' );

	function update() {
		var isSubscribe = false;

		for ( var i = 0; i < modeRadios.length; i++ ) {
			if ( modeRadios[ i ].checked && modeRadios[ i ].value === 'subscribe' ) {
				isSubscribe = true;
			}
		}

		// Show/hide details panel
		if ( detailsWrap ) {
			detailsWrap.style.display = isSubscribe ? '' : 'none';
		}

		// Update active class on mode options
		for ( var i = 0; i < options.length; i++ ) {
			var radio = options[ i ].querySelector( 'input[type="radio"]' );
			if ( radio && radio.checked ) {
				options[ i ].classList.add( 'cm-subscription-toggle__option--active' );
			} else {
				options[ i ].classList.remove( 'cm-subscription-toggle__option--active' );
			}
		}

		// Update active class on qty buttons
		for ( var i = 0; i < qtyBtns.length; i++ ) {
			var r = qtyBtns[ i ].querySelector( 'input[type="radio"]' );
			if ( r && r.checked ) {
				qtyBtns[ i ].classList.add( 'cm-subscription-toggle__qty-btn--active' );
			} else {
				qtyBtns[ i ].classList.remove( 'cm-subscription-toggle__qty-btn--active' );
			}
		}

		// Dispatch custom event FIRST so upsell JS has correct isSubscribe state
		// before the qty change event triggers its update().
		document.dispatchEvent( new CustomEvent( 'cm_subscription_changed', {
			detail: { subscribe: isSubscribe }
		} ) );

		// Sync product qty input with subscription qty
		if ( isSubscribe && qtyInput ) {
			var selectedQty = 2;
			for ( var i = 0; i < qtyRadios.length; i++ ) {
				if ( qtyRadios[ i ].checked ) {
					selectedQty = parseInt( qtyRadios[ i ].value, 10 );
				}
			}
			qtyInput.value = selectedQty;
			qtyInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	// Bind mode radios
	for ( var i = 0; i < modeRadios.length; i++ ) {
		modeRadios[ i ].addEventListener( 'change', update );
	}

	// Bind qty radios
	for ( var i = 0; i < qtyRadios.length; i++ ) {
		qtyRadios[ i ].addEventListener( 'change', update );
	}

	// Initial state
	update();
})();
