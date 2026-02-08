/**
 * CM Discount Engine — Banner visibility JS.
 *
 * Handles cache-compatible banner visibility:
 * 1. Fetches user context from REST API
 * 2. Checks conditions for each hidden banner
 * 3. Reveals banners that match conditions
 */
(function() {
	'use strict';

	var CACHE_KEY = 'cm_banner_context';
	var CACHE_TTL = 5 * 60 * 1000; // 5 minutes

	/**
	 * Get cached context from sessionStorage.
	 */
	function getCachedContext() {
		try {
			var cached = sessionStorage.getItem(CACHE_KEY);
			if (!cached) return null;

			var data = JSON.parse(cached);
			if (!data.timestamp || Date.now() - data.timestamp > CACHE_TTL) {
				sessionStorage.removeItem(CACHE_KEY);
				return null;
			}
			return data.context;
		} catch (e) {
			return null;
		}
	}

	/**
	 * Cache context to sessionStorage.
	 */
	function setCachedContext(context) {
		try {
			sessionStorage.setItem(CACHE_KEY, JSON.stringify({
				context: context,
				timestamp: Date.now()
			}));
		} catch (e) {
			// Storage full or disabled
		}
	}

	/**
	 * Fetch user context from REST API.
	 */
	function fetchContext(callback) {
		// Check cache first
		var cached = getCachedContext();
		if (cached) {
			callback(cached);
			return;
		}

		// Fetch from API
		var xhr = new XMLHttpRequest();
		xhr.open('GET', cmBannersData.restUrl + 'cm/v1/banner-context', true);
		xhr.setRequestHeader('X-WP-Nonce', cmBannersData.nonce);

		xhr.onreadystatechange = function() {
			if (xhr.readyState === 4) {
				if (xhr.status === 200) {
					try {
						var context = JSON.parse(xhr.responseText);
						setCachedContext(context);
						callback(context);
					} catch (e) {
						callback(null);
					}
				} else {
					callback(null);
				}
			}
		};

		xhr.send();
	}

	/**
	 * Check if banner should be visible based on conditions.
	 */
	function checkConditions(banner, context) {
		if (!context) return false;

		var conditionsStr = banner.getAttribute('data-conditions');
		var conditions = {};
		try {
			conditions = JSON.parse(conditionsStr) || {};
		} catch (e) {
			return true; // No conditions = show
		}

		var type = banner.getAttribute('data-banner-type');

		// Check user state
		var userState = conditions.user_state || 'any';
		if (userState !== 'any') {
			if (userState === 'logged_in' && !context.is_logged_in) return false;
			if (userState === 'logged_out' && context.is_logged_in) return false;
			if (userState === 'new_visitor' && (context.is_logged_in || !context.is_first_order)) return false;
		}

		// Check cart state
		var cartState = conditions.cart_state || 'any';
		if (cartState !== 'any') {
			var cartCount = context.cart_count || 0;
			if (cartState === 'empty' && cartCount > 0) return false;
			if (cartState === 'not_empty' && cartCount === 0) return false;
		}

		// Check hide for subscribers
		if (conditions.hide_for_subscribers === 'yes' && context.is_subscriber) {
			return false;
		}

		// Check hide after first order
		if (conditions.hide_after_first_order === 'yes' && !context.is_first_order) {
			return false;
		}

		// Type-specific checks
		if (type === 'first_order' && !context.is_first_order) {
			return false;
		}

		if (type === 'subscription' && context.is_subscriber) {
			return false;
		}

		return true;
	}

	/**
	 * Reveal a banner with animation.
	 */
	function revealBanner(banner) {
		banner.classList.remove('cm-banner--hidden');
		banner.classList.add('cm-banner--reveal');
	}

	/**
	 * Process all hidden banners.
	 */
	function processBanners(context) {
		var banners = document.querySelectorAll('.cm-banner.cm-banner--hidden');
		if (!banners.length) return;

		banners.forEach(function(banner) {
			if (checkConditions(banner, context)) {
				revealBanner(banner);
			}
		});
	}

	/**
	 * Initialize.
	 */
	function init() {
		// Check if we have any hidden banners that need processing
		var hiddenBanners = document.querySelectorAll('.cm-banner.cm-banner--hidden');
		if (!hiddenBanners.length) return;

		// Fetch context and process
		fetchContext(function(context) {
			if (context) {
				processBanners(context);
			}
		});
	}

	/**
	 * Invalidate cache (e.g., after cart update).
	 */
	function invalidateCache() {
		try {
			sessionStorage.removeItem(CACHE_KEY);
		} catch (e) {}
	}

	// Listen for cart updates to invalidate cache
	document.body.addEventListener('added_to_cart', invalidateCache);
	document.body.addEventListener('removed_from_cart', invalidateCache);
	document.body.addEventListener('updated_cart_totals', invalidateCache);

	// Also listen for WC cart fragments
	if (typeof jQuery !== 'undefined') {
		jQuery(document.body).on('wc_fragments_refreshed', invalidateCache);
	}

	// Run on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
