/**
 * CM Discount Engine — Dynamic product page upsell.
 *
 * Reads cmUpsellData (via wp_localize_script):
 *   .tiers     — array of { min_packs: int, rate: number }
 *   .cartPacks — int (eligible packs already in cart)
 */
(function () {
	'use strict';

	var data = window.cmUpsellData;
	if ( ! data || ! data.tiers || ! data.tiers.length ) {
		return;
	}

	var tiers            = data.tiers;
	var cartPacks        = parseInt( data.cartPacks, 10 ) || 0;
	var subscriptionRate = parseFloat( data.subscriptionRate ) || 0;
	var isSubscribe      = false;
	var qtyInput         = document.querySelector( 'input[name="quantity"]' );
	var hint             = document.querySelector( '.cm-discount-hint' );

	if ( ! qtyInput || ! hint ) {
		return;
	}

	var upsellBox = hint.querySelector( '.cm-discount-hint__upsell' );
	var tierEls   = hint.querySelectorAll( '.cm-discount-hint__tier' );

	// Sort tiers ascending by min_packs.
	tiers.sort( function ( a, b ) {
		return a.min_packs - b.min_packs;
	} );

	function findCurrentTier( total ) {
		var found = null;
		for ( var i = 0; i < tiers.length; i++ ) {
			if ( total >= tiers[ i ].min_packs ) {
				found = tiers[ i ];
			}
		}
		return found;
	}

	function findNextTier( total ) {
		for ( var i = 0; i < tiers.length; i++ ) {
			if ( tiers[ i ].min_packs > total ) {
				return tiers[ i ];
			}
		}
		return null;
	}

	var targetQty = 0; // updated by update(), used by click handler

	function update() {
		var inputQty   = parseInt( qtyInput.value, 10 ) || 1;
		var totalPacks = cartPacks + inputQty;

		var currentTier = findCurrentTier( totalPacks );
		var nextTier    = findNextTier( totalPacks );

		// --- Highlight tiers ---
		for ( var i = 0; i < tierEls.length; i++ ) {
			tierEls[ i ].classList.remove( 'cm-discount-hint__tier--current', 'cm-discount-hint__tier--next' );
		}

		if ( currentTier ) {
			for ( var i = 0; i < tierEls.length; i++ ) {
				if ( parseInt( tierEls[ i ].getAttribute( 'data-min-packs' ), 10 ) === currentTier.min_packs ) {
					tierEls[ i ].classList.add( 'cm-discount-hint__tier--current' );
				}
			}
		}

		if ( nextTier ) {
			for ( var i = 0; i < tierEls.length; i++ ) {
				if ( parseInt( tierEls[ i ].getAttribute( 'data-min-packs' ), 10 ) === nextTier.min_packs ) {
					tierEls[ i ].classList.add( 'cm-discount-hint__tier--next' );
				}
			}
		}

		// --- Upsell message ---
		if ( ! upsellBox ) {
			return;
		}

		if ( ! nextTier ) {
			upsellBox.style.display = 'none';
			upsellBox.innerHTML = '';
			return;
		}

		var packsNeeded  = nextTier.min_packs - totalPacks;
		var packWord     = packsNeeded === 1 ? 'pack' : 'packs';
		var currentRate  = currentTier ? currentTier.rate : 0;

		// Target qty for the action link
		targetQty = nextTier.min_packs - cartPacks;

		var msg;
		if ( currentRate > 0 ) {
			msg = 'Add ' + packsNeeded + ' more ' + packWord + ' and get &minus;' + nextTier.rate + '% instead of &minus;' + currentRate + '%';
		} else {
			msg = 'Add ' + packsNeeded + ' more ' + packWord + ' and get &minus;' + nextTier.rate + '% discount';
		}

		var linkLabel = 'Set ' + targetQty + ' pack' + ( targetQty !== 1 ? 's' : '' ) + ' →';
		var linkHtml  = ' <a href="#" class="cm-discount-hint__upsell-action">' + linkLabel + '</a>';

		// If subscribe mode and subscription rate is higher, show floor info
		var subNote = '';
		if ( isSubscribe && subscriptionRate > 0 ) {
			var effectiveRate = Math.max( subscriptionRate, currentRate );
			if ( effectiveRate > currentRate || ( currentRate === 0 && subscriptionRate > 0 ) ) {
				subNote = '<br><span class="cm-discount-hint__upsell-icon">&#9733;</span> Coffee Club: &minus;' + effectiveRate + '% guaranteed';
			}
		}

		upsellBox.innerHTML = '<span class="cm-discount-hint__upsell-icon">&#9650;</span> ' + msg + linkHtml + subNote;
		upsellBox.style.display = '';
	}

	// Click handler for the action link (event delegation).
	if ( upsellBox ) {
		upsellBox.addEventListener( 'click', function ( e ) {
			var link = e.target.closest( '.cm-discount-hint__upsell-action' );
			if ( ! link ) {
				return;
			}
			e.preventDefault();
			qtyInput.value = targetQty;
			qtyInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			qtyInput.focus();
		} );
	}

	// Listen for subscription toggle changes.
	document.addEventListener( 'cm_subscription_changed', function ( e ) {
		isSubscribe = e.detail && e.detail.subscribe;
		update();
	} );

	// Listen for changes (native events — manual typing, etc.).
	qtyInput.addEventListener( 'input', update );
	qtyInput.addEventListener( 'change', update );

	// ShopLentor / Royal Addons +/- buttons (.fas.fa-plus, .fas.fa-minus)
	// sit in .wpr-add-to-cart-icons-wrap — a sibling of .quantity, not a child.
	// They set the input value directly without firing any DOM events.
	// Listen on the common ancestor that contains both input and buttons.
	var qtyWrap = qtyInput.closest( '.wpr-quantity-wrapper' )
		|| qtyInput.closest( '.wpr-simple-qty-wrap' )
		|| qtyInput.closest( '.quantity' )
		|| qtyInput.parentElement;
	if ( qtyWrap ) {
		qtyWrap.addEventListener( 'click', function () {
			setTimeout( update, 10 );
		} );
	}

	// Initial render.
	update();
})();
