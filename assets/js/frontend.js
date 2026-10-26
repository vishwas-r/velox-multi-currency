/**
 * NammaWP Frontend JS: Pure AJAX real-time currency switching without page reload,
 * dynamic price updating, floating switcher interaction, and checkout recalculation.
 */
(function($) {
	'use strict';

	/**
	 * Collect all visible product IDs on the current page.
	 */
	function getVisibleProductIds() {
		var ids = [];

		// 1. Check data-product_id attribute
		$('[data-product_id]').each(function() {
			var id = parseInt($(this).attr('data-product_id') || $(this).data('product_id'), 10);
			if (id > 0 && ids.indexOf(id) === -1) {
				ids.push(id);
			}
		});

		// 2. Check data-product-id attribute (common in blocks)
		$('[data-product-id]').each(function() {
			var id = parseInt($(this).attr('data-product-id') || $(this).data('product-id'), 10);
			if (id > 0 && ids.indexOf(id) === -1) {
				ids.push(id);
			}
		});

		// 3. Check standard WooCommerce loop product items (.post-XX)
		$('li.product, .product').each(function() {
			var classAttr = $(this).attr('class') || '';
			var match = classAttr.match(/post-(\d+)/);
			if (match && match[1]) {
				var id = parseInt(match[1], 10);
				if (id > 0 && ids.indexOf(id) === -1) {
					ids.push(id);
				}
			}
		});

		// 4. Check add-to-cart in links or buttons
		$('a[href*="add-to-cart="]').each(function() {
			var match = ($(this).attr('href') || '').match(/add-to-cart=(\d+)/);
			if (match && match[1]) {
				var id = parseInt(match[1], 10);
				if (id > 0 && ids.indexOf(id) === -1) {
					ids.push(id);
				}
			}
		});

		// 5. Check single product page hidden input or add to cart button
		var singleId = parseInt($('input[name="add-to-cart"]').val() || $('button[name="add-to-cart"]').val(), 10);
		if (singleId > 0 && ids.indexOf(singleId) === -1) {
			ids.push(singleId);
		}

		return ids;
	}

	/**
	 * Perform pure AJAX currency switch with real-time DOM price updates.
	 */
	function switchCurrency(targetCurrency, $triggerElement) {
		if (!targetCurrency) {
			return;
		}

		var params = window.nammawp_params || {};
		if (!params.ajax_url) {
			return;
		}

		var $floatingWidget = $('#nammawp-floating-widget');

		// 1. Instant 0ms visual feedback: Add loading spinners
		$floatingWidget.addClass('is-loading');

		// 2. Add top progress bar and dim prices
		$('body').addClass('nammawp-currency-updating');
		if (!$('#nammawp-top-progress').length) {
			$('body').append('<div id="nammawp-top-progress" class="nammawp-top-progress"></div>');
		}

		// 3. Immediately update checkmark and active state in dropdown
		$floatingWidget.find('.nammawp-floating-item').removeClass('is-active is-switching');
		var $clickedItem = $floatingWidget.find('.nammawp-floating-item[data-currency="' + targetCurrency + '"]');
		if ($clickedItem.length) {
			$clickedItem.addClass('is-active is-switching');
			var newSym = $clickedItem.find('.nammawp-item-symbol').html();
			if (newSym) {
				$floatingWidget.find('.nammawp-trigger-symbol').html(newSym);
			}
		}
		$floatingWidget.find('.nammawp-trigger-code').text(targetCurrency);

		// 4. Immediately highlight header buttons
		$('.nammawp-header-curr-btn').removeClass('is-active');
		$('.nammawp-header-curr-btn[data-currency="' + targetCurrency + '"]').addClass('is-active is-loading');

		var productIds = getVisibleProductIds();

		$.ajax({
			url: params.ajax_url,
			type: 'POST',
			data: {
				action: 'nammawp_switch_currency',
				nonce: params.nonce,
				currency: targetCurrency,
				product_ids: productIds
			},
			success: function(response) {
				$('body').removeClass('nammawp-currency-updating');
				$('#nammawp-top-progress').remove();
				$floatingWidget.removeClass('is-loading');
				$('.nammawp-floating-item').removeClass('is-switching');
				$('.nammawp-header-curr-btn').removeClass('is-loading');

				if (response.success && response.data) {
					var data = response.data;
					var newCurrency = data.currency;
					var newSymbol = data.symbol;

					// 1. Update dropdown active checkmark and triggers
					$floatingWidget.find('.nammawp-floating-item').removeClass('is-active');
					$floatingWidget.find('.nammawp-floating-item[data-currency="' + newCurrency + '"]').addClass('is-active');
					$floatingWidget.find('.nammawp-trigger-code').text(newCurrency);
					$floatingWidget.find('.nammawp-trigger-symbol').html(newSymbol);

					// Close floating dropdown smoothly after selection
					setTimeout(function() {
						$floatingWidget.removeClass('is-open');
					}, 220);

					// Update header buttons
					$('.nammawp-header-curr-btn').removeClass('is-active');
					$('.nammawp-header-curr-btn[data-currency="' + newCurrency + '"]').addClass('is-active');

					// Update standard dropdowns
					$('.nammawp-currency-select').val(newCurrency);

					// 2. Real-time update product prices on page
					if (data.prices && typeof data.prices === 'object') {
						$.each(data.prices, function(productId, priceData) {
							if (!priceData) {
								return;
							}

							// Target loop product items
							var $productContainer = $('.post-' + productId + ', [data-product_id="' + productId + '"]').closest('li.product, .product');
							if ($productContainer.length && priceData.price_html) {
								var $priceEl = $productContainer.find('.price').first();
								if ($priceEl.length) {
									$priceEl.html(priceData.price_html);
								}
							}

							// Target Gutenberg block products
							var $blockProduct = $('.wc-block-grid__product[data-product-id="' + productId + '"], .wc-block-grid__product .wp-block-button__link[data-product_id="' + productId + '"]').closest('.wc-block-grid__product');
							if ($blockProduct.length && priceData.price_html) {
								$blockProduct.find('.wc-block-grid__product-price, .price').html(priceData.price_html);
							}

							// Target single product page price
							if ($('body').hasClass('single-product')) {
								var $singlePrice = $('.summary .price').first();
								if ($singlePrice.length && priceData.price_html) {
									$singlePrice.html(priceData.price_html);
								}
							}

							// Target cart table item price
							if (priceData.formatted) {
								$('[data-product_id="' + productId + '"]').closest('tr.cart_item').find('.product-subtotal .amount, .product-price .amount').html(priceData.formatted);
							}
						});
					}

					// 3. Real-time update all currency symbols and totals on page
					$('.woocommerce-Price-currencySymbol, .wc-block-components-formatted-money-amount__currency').html(newSymbol);

					if (data.cart_subtotal) {
						$('.cart-subtotal .amount, .wc-block-components-totals-item__value:contains("Subtotal") .wc-block-formatted-money-amount').html(data.cart_subtotal);
					}
					if (data.cart_total) {
						$('.order-total .amount, .wc-block-components-totals-footer-item .wc-block-formatted-money-amount').html(data.cart_total);
					}

					// 4. Mini-cart fragments update
					if (data.fragments) {
						$.each(data.fragments, function(key, value) {
							$(key).replaceWith(value);
						});
					}

					// 5. Gutenberg Blocks (Cart & Checkout Blocks) Store API sync
					if (window.wp && window.wp.data && typeof window.wp.data.dispatch === 'function') {
						try {
							var cartDispatch = window.wp.data.dispatch('wc/store/cart');
							if (cartDispatch) {
								if (typeof cartDispatch.invalidateResolutionForStoreSelector === 'function') {
									cartDispatch.invalidateResolutionForStoreSelector('getCartData');
								}
								// Re-fetch cart from Store API REST endpoint with new currency
								$.ajax({
									url: '/wp-json/wc/store/v1/cart',
									type: 'GET',
									cache: false,
									dataType: 'json',
									success: function(storeCart) {
										if (storeCart && typeof cartDispatch.receiveCart === 'function') {
											cartDispatch.receiveCart(storeCart);
										}
									}
								});
							}
						} catch (e) {
							// Silent catch for blocks dispatch
						}
					}

					// 6. Classic WooCommerce events without full reload
					$(document.body).trigger('wc_fragment_refresh');
					$(document.body).trigger('update_checkout');

					// If on cart page, trigger cart totals update
					if ($('body').hasClass('woocommerce-cart')) {
						var $updateCartBtn = $('button[name="update_cart"]');
						if ($updateCartBtn.length) {
							$updateCartBtn.prop('disabled', false).trigger('click');
						} else {
							$(document.body).trigger('wc_update_cart');
						}
					}
				} else {
					alert((response.data && response.data.message) ? response.data.message : 'Error switching currency.');
				}
			},
			error: function() {
				$('body').removeClass('nammawp-currency-updating');
				$('#nammawp-top-progress').remove();
				$floatingWidget.removeClass('is-loading');
				$('.nammawp-floating-item').removeClass('is-switching');
				$('.nammawp-header-curr-btn').removeClass('is-loading');
				alert('Network error while switching currency.');
			}
		});
	}

	$(document).ready(function() {
		// 1. Standard dropdown switch handler
		$(document).on('change', '.nammawp-currency-select', function() {
			var selected = $(this).val();
			switchCurrency(selected, $(this));
		});

		// 2. Header bar button switch handler
		$(document).on('click', '.nammawp-header-curr-btn', function(e) {
			e.preventDefault();
			var selected = $(this).data('currency');
			switchCurrency(selected, $(this));
		});

		// 3. Floating widget trigger toggle
		$(document).on('click', '.nammawp-floating-trigger', function(e) {
			e.stopPropagation();
			$('#nammawp-floating-widget').toggleClass('is-open');
		});

		// 4. Floating widget item select
		$(document).on('click', '.nammawp-floating-item', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var selected = $(this).data('currency');
			switchCurrency(selected, $(this));
		});

		// Close floating widget on outside click
		$(document).on('click', function(e) {
			if (!$(e.target).closest('#nammawp-floating-widget').length) {
				$('#nammawp-floating-widget').removeClass('is-open');
			}
		});

		// 5. Checkout Payment Gateway switch listener
		$(document.body).on('change', 'input[name="payment_method"]', function() {
			var chosenGateway = $(this).val();
			var $wrapper = $('#nammawp-disclosure-wrapper');
			var params = window.nammawp_params || {};

			if (!chosenGateway || !$wrapper.length || !params.ajax_url) {
				return;
			}

			$.ajax({
				url: params.ajax_url,
				type: 'POST',
				data: {
					action: 'nammawp_get_gateway_disclosure',
					nonce: params.nonce,
					gateway_id: chosenGateway
				},
				success: function(response) {
					if (response.success && response.data.required) {
						$wrapper.find('.nammawp-disclosure-notice').text(response.data.message);
						$wrapper.slideDown(200);
					} else {
						$wrapper.slideUp(200);
					}
				}
			});
		});
	});
})(jQuery);
