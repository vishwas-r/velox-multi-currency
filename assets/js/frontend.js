/**
 * VeloX Frontend JS: Pure AJAX real-time currency switching without page reload,
 * dynamic price updating, floating switcher interaction, and checkout recalculation.
 */
(function($) {
	'use strict';

	/**
	 * Format a numeric amount with standard decimals, decimal point, and thousand separator.
	 */
	function formatPriceNumber(number, decimals, decPoint, thousandsSep) {
		decimals = (typeof decimals === 'undefined' || decimals === null) ? 2 : parseInt(decimals, 10);
		decPoint = decPoint || '.';
		thousandsSep = (typeof thousandsSep === 'undefined' || thousandsSep === null) ? ',' : thousandsSep;

		var n = !isFinite(+number) ? 0 : +number;
		var prec = !isFinite(+decimals) ? 0 : Math.abs(decimals);
		var toFixedFix = function(val, precision) {
			var k = Math.pow(10, precision);
			return '' + (Math.round(val * k) / k).toFixed(precision);
		};

		var parts = toFixedFix(n, prec).split('.');
		if (parts[0].length > 3) {
			parts[0] = parts[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, thousandsSep);
		}
		return parts.join(decPoint);
	}

	/**
	 * Parse raw numeric price float from an element's text.
	 */
	function parseRawPriceText(text, thousandSep, decimalSep) {
		if (!text) {
			return 0;
		}
		var clean = text.toString().trim();
		// Remove currency symbols, entities, spaces, non-digits/dots/commas
		clean = clean.replace(/[^\d.,]/g, '');
		if (!clean) {
			return 0;
		}

		// Handle separators
		if (thousandSep && decimalSep) {
			clean = clean.split(thousandSep).join('');
			if (decimalSep !== '.') {
				clean = clean.split(decimalSep).join('.');
			}
		} else {
			// Auto heuristic
			if (clean.indexOf(',') !== -1 && clean.indexOf('.') !== -1) {
				if (clean.lastIndexOf(',') > clean.lastIndexOf('.')) {
					// Comma is decimal separator (e.g. 1.250,50)
					clean = clean.replace(/\./g, '').replace(',', '.');
				} else {
					// Dot is decimal separator (e.g. 1,250.50)
					clean = clean.replace(/,/g, '');
				}
			} else if (clean.indexOf(',') !== -1) {
				// Only comma
				var commaParts = clean.split(',');
				if (commaParts.length === 2 && commaParts[1].length <= 2) {
					clean = clean.replace(',', '.');
				} else {
					clean = clean.replace(/,/g, '');
				}
			}
		}

		var parsed = parseFloat(clean);
		return isNaN(parsed) ? 0 : parsed;
	}

	/**
	 * Initialize and cache original base prices on all visible price amount tags.
	 */
	function initPriceCache() {
		var params = window.velox_params || {};
		var currentRate = parseFloat(params.rate) || 1.0;
		var thousandSep = params.thousand_separator || ',';
		var decimalSep = params.decimal_separator || '.';

		$('.woocommerce-Price-amount, .wc-block-formatted-money-amount').each(function() {
			var $el = $(this);
			if ($el.attr('data-velox-base-amount')) {
				return;
			}

			// Clone element and strip currency symbol tags to isolate numeric string
			var $clone = $el.clone();
			$clone.find('.woocommerce-Price-currencySymbol, .wc-block-components-formatted-money-amount__currency').remove();
			var text = $clone.text();
			var num = parseRawPriceText(text, thousandSep, decimalSep);

			if (num > 0) {
				// Base price = current active currency price / current active currency rate
				var baseAmount = num / currentRate;
				$el.attr('data-velox-base-amount', baseAmount);
			}
		});
	}

	/**
	 * Collect all visible product IDs on the current page across all themes and blocks.
	 */
	function getVisibleProductIds() {
		var ids = [];

		function addId(val) {
			var id = parseInt(val, 10);
			if (id > 0 && ids.indexOf(id) === -1) {
				ids.push(id);
			}
		}

		var params = window.velox_params || {};

		// 1. Single product page current ID from localized params
		if (params.current_product_id) {
			addId(params.current_product_id);
		}

		// 2. Check body postid class on single product pages
		var bodyClass = $('body').attr('class') || '';
		var bodyMatch = bodyClass.match(/\bpostid-(\d+)\b/);
		if (bodyMatch && bodyMatch[1]) {
			addId(bodyMatch[1]);
		}

		// 3. Check data-product_id attribute
		$('[data-product_id]').each(function() {
			addId($(this).attr('data-product_id') || $(this).data('product_id'));
		});

		// 4. Check data-product-id attribute (Gutenberg blocks & query loops)
		$('[data-product-id]').each(function() {
			addId($(this).attr('data-product-id') || $(this).data('product-id'));
		});

		// 5. Check any element with post-(\d+) class
		$('[class*="post-"]').each(function() {
			var cls = $(this).attr('class') || '';
			var match = cls.match(/\bpost-(\d+)\b/);
			if (match && match[1]) {
				addId(match[1]);
			}
		});

		// 6. Check element IDs (id="post-XX")
		$('[id^="post-"]').each(function() {
			var idAttr = $(this).attr('id') || '';
			var match = idAttr.match(/^post-(\d+)$/);
			if (match && match[1]) {
				addId(match[1]);
			}
		});

		// 7. Check add-to-cart in links or buttons
		$('a[href*="add-to-cart="], button[data-product_id]').each(function() {
			var href = $(this).attr('href') || '';
			var match = href.match(/add-to-cart=(\d+)/);
			if (match && match[1]) {
				addId(match[1]);
			}
		});

		// 8. Check single product page hidden input or add to cart button
		$('input[name="add-to-cart"], button[name="add-to-cart"], input[name="product_id"]').each(function() {
			addId($(this).val());
		});

		return ids;
	}

	/**
	 * Perform pure AJAX currency switch with real-time DOM price updates.
	 */
	function switchCurrency(targetCurrency, $triggerElement) {
		if (!targetCurrency) {
			return;
		}

		var params = window.velox_params || {};
		if (!params.ajax_url) {
			return;
		}

		var $floatingWidget = $('#velox-floating-widget');

		// 1. Instant 0ms visual feedback: Add loading spinners
		$floatingWidget.addClass('is-loading');

		// 2. Add top progress bar and dim prices
		$('body').addClass('velox-currency-updating');
		if (!$('#velox-top-progress').length) {
			$('body').append('<div id="velox-top-progress" class="velox-top-progress"></div>');
		}

		// 3. Immediately update checkmark and active state in dropdown
		$floatingWidget.find('.velox-floating-item').removeClass('is-active is-switching');
		var $clickedItem = $floatingWidget.find('.velox-floating-item[data-currency="' + targetCurrency + '"]');
		if ($clickedItem.length) {
			$clickedItem.addClass('is-active is-switching');
			var newSym = $clickedItem.find('.velox-item-symbol').html();
			if (newSym) {
				$floatingWidget.find('.velox-trigger-symbol').html(newSym);
			}
		}
		$floatingWidget.find('.velox-trigger-code').text(targetCurrency);

		// 4. Immediately highlight header buttons
		$('.velox-header-curr-btn').removeClass('is-active');
		$('.velox-header-curr-btn[data-currency="' + targetCurrency + '"]').addClass('is-active is-loading');

		// 5. Cache current prices before AJAX if not done yet
		initPriceCache();

		var productIds = getVisibleProductIds();

		$.ajax({
			url: params.ajax_url,
			type: 'POST',
			data: {
				action: 'velox_switch_currency',
				nonce: params.nonce,
				currency: targetCurrency,
				current_product_id: params.current_product_id || 0,
				product_ids: productIds
			},
			success: function(response) {
				$('body').removeClass('velox-currency-updating');
				$('#velox-top-progress').remove();
				$floatingWidget.removeClass('is-loading');
				$('.velox-floating-item').removeClass('is-switching');
				$('.velox-header-curr-btn').removeClass('is-loading');

				if (response.success && response.data) {
					var data = response.data;
					var newCurrency = data.currency;
					var newSymbol = data.symbol;

					// 1. Update dropdown active checkmark and triggers
					$floatingWidget.find('.velox-floating-item').removeClass('is-active');
					$floatingWidget.find('.velox-floating-item[data-currency="' + newCurrency + '"]').addClass('is-active');
					$floatingWidget.find('.velox-trigger-code').text(newCurrency);
					$floatingWidget.find('.velox-trigger-symbol').html(newSymbol);

					// Close floating dropdown smoothly after selection
					setTimeout(function() {
						$floatingWidget.removeClass('is-open');
					}, 220);

					// Update header buttons
					$('.velox-header-curr-btn').removeClass('is-active');
					$('.velox-header-curr-btn[data-currency="' + newCurrency + '"]').addClass('is-active');

					// Update standard dropdowns
					$('.velox-currency-select').val(newCurrency);

					// 2. Real-time update product prices on page via server-rendered HTML
					var replacedElements = [];

					if (data.prices && typeof data.prices === 'object') {
						$.each(data.prices, function(productId, priceData) {
							if (!priceData || !priceData.price_html) {
								return;
							}

							productId = parseInt(productId, 10);
							var priceHtml = priceData.price_html;

							// A. Single Product Page main price (Classic + Block Themes)
							var isSingleProduct = (
								(params.current_product_id && parseInt(params.current_product_id, 10) === productId) ||
								$('body').hasClass('postid-' + productId) ||
								($('body').hasClass('single-product') && (Object.keys(data.prices).length <= 2))
							);

							if (isSingleProduct) {
								var $singlePriceEls = $(
									'.wp-block-woocommerce-product-price, ' +
									'.wc-block-components-product-price, ' +
									'.single-product .summary .price, ' +
									'.summary .price, ' +
									'.entry-summary .price, ' +
									'.product-summary .price, ' +
									'.single-product p.price, ' +
									'.single-product .price'
								);
								if ($singlePriceEls.length) {
									$singlePriceEls.html(priceHtml);
									$singlePriceEls.each(function() {
										replacedElements.push(this);
									});
								}
							}

							// B. Loop product items, Gutenberg query loops, block grids
							var $holders = $(
								'.post-' + productId + ', ' +
								'#post-' + productId + ', ' +
								'[data-product_id="' + productId + '"], ' +
								'[data-product-id="' + productId + '"]'
							);

							$holders.each(function() {
								var $el = $(this);
								var $card = $el.closest('li, article, .wc-block-grid__product, .wp-block-post, .product, .wc-block-product-template__item, .type-product');
								if (!$card.length) {
									$card = $el;
								}

								var $priceEl = $card.find(
									'.wp-block-woocommerce-product-price, ' +
									'.wc-block-components-product-price, ' +
									'.wc-block-grid__product-price, ' +
									'.price, ' +
									'p.price'
								).first();

								if ($priceEl.length) {
									$priceEl.html(priceHtml);
									$priceEl.each(function() {
										replacedElements.push(this);
									});
								}
							});

							// C. Cart table items
							if (priceData.formatted) {
								$('[data-product_id="' + productId + '"]').closest('tr.cart_item').find('.product-subtotal .amount, .product-price .amount').html(priceData.formatted);
							}
						});
					}

					// 3. Fallback math update for ANY remaining price tags on the page
					var newRate = parseFloat(data.rate) || 1.0;
					var newDecimals = (typeof data.decimals !== 'undefined') ? data.decimals : 2;
					var decSep = data.decimal_separator || '.';
					var thouSep = data.thousand_separator || ',';

					$('.woocommerce-Price-amount, .wc-block-formatted-money-amount').each(function() {
						var $amount = $(this);

						// Skip if this element is inside an already replaced price block
						var isAlreadyReplaced = false;
						for (var i = 0; i < replacedElements.length; i++) {
							if ($.contains(replacedElements[i], this) || replacedElements[i] === this) {
								isAlreadyReplaced = true;
								break;
							}
						}
						if (isAlreadyReplaced) {
							return;
						}

						var baseAmount = parseFloat($amount.attr('data-velox-base-amount'));
						if (!baseAmount || isNaN(baseAmount)) {
							// If not cached yet, compute base from previous rate
							var $clone = $amount.clone();
							$clone.find('.woocommerce-Price-currencySymbol, .wc-block-components-formatted-money-amount__currency').remove();
							var prevRate = parseFloat(params.rate) || 1.0;
							var currentNum = parseRawPriceText($clone.text(), params.thousand_separator, params.decimal_separator);
							if (currentNum > 0) {
								baseAmount = currentNum / prevRate;
								$amount.attr('data-velox-base-amount', baseAmount);
							}
						}

						if (baseAmount && !isNaN(baseAmount)) {
							var convertedNum = baseAmount * newRate;
							var formattedNum = formatPriceNumber(convertedNum, newDecimals, decSep, thouSep);

							// Replace the numeric text inside the element
							var $bdi = $amount.find('bdi');
							var $target = $bdi.length ? $bdi : $amount;

							// Update symbol inside
							$target.find('.woocommerce-Price-currencySymbol, .wc-block-components-formatted-money-amount__currency').html(newSymbol);

							// Update digits in text node
							$target.contents().each(function() {
								if (this.nodeType === 3) {
									var t = this.nodeValue.replace(/[\d.,]/g, '').trim();
									if (t === '') {
										this.nodeValue = formattedNum;
									}
								}
							});
						}
					});

					// 4. Update all currency symbols everywhere
					$('.woocommerce-Price-currencySymbol, .wc-block-components-formatted-money-amount__currency').html(newSymbol);

					// 5. Update localized params with new active currency and rate
					params.rate = newRate;
					params.decimals = newDecimals;
					params.active_currency = newCurrency;
					params.decimal_separator = decSep;
					params.thousand_separator = thouSep;
					params.currency_symbol = newSymbol;

					// 6. Cart totals
					if (data.cart_subtotal) {
						$('.cart-subtotal .amount, .wc-block-components-totals-item__value:contains("Subtotal") .wc-block-formatted-money-amount').html(data.cart_subtotal);
					}
					if (data.cart_total) {
						$('.order-total .amount, .wc-block-components-totals-footer-item .wc-block-formatted-money-amount').html(data.cart_total);
					}

					// 7. Mini-cart fragments update
					if (data.fragments) {
						$.each(data.fragments, function(key, value) {
							$(key).replaceWith(value);
						});
					}

					// 8. Gutenberg Blocks (Cart & Checkout Blocks) Store API sync
					if (window.wp && window.wp.data && typeof window.wp.data.dispatch === 'function') {
						try {
							var cartDispatch = window.wp.data.dispatch('wc/store/cart');
							if (cartDispatch) {
								if (typeof cartDispatch.invalidateResolutionForStoreSelector === 'function') {
									cartDispatch.invalidateResolutionForStoreSelector('getCartData');
								}
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

					// 9. Classic WooCommerce events without full reload
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
				$('body').removeClass('velox-currency-updating');
				$('#velox-top-progress').remove();
				$floatingWidget.removeClass('is-loading');
				$('.velox-floating-item').removeClass('is-switching');
				$('.velox-header-curr-btn').removeClass('is-loading');
				alert('Network error while switching currency.');
			}
		});
	}

	$(document).ready(function() {
		// Initialize price cache on load
		initPriceCache();

		// 1. Standard dropdown switch handler
		$(document).on('change', '.velox-currency-select', function() {
			var selected = $(this).val();
			switchCurrency(selected, $(this));
		});

		// 2. Header bar button switch handler
		$(document).on('click', '.velox-header-curr-btn', function(e) {
			e.preventDefault();
			var selected = $(this).data('currency');
			switchCurrency(selected, $(this));
		});

		// 3. Floating widget trigger toggle
		$(document).on('click', '.velox-floating-trigger', function(e) {
			e.stopPropagation();
			$('#velox-floating-widget').toggleClass('is-open');
		});

		// 4. Floating widget item select
		$(document).on('click', '.velox-floating-item', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var selected = $(this).data('currency');
			switchCurrency(selected, $(this));
		});

		// Close floating widget on outside click
		$(document).on('click', function(e) {
			if (!$(e.target).closest('#velox-floating-widget').length) {
				$('#velox-floating-widget').removeClass('is-open');
			}
		});

		// 5. Checkout Payment Gateway switch listener
		$(document.body).on('change', 'input[name="payment_method"]', function() {
			var chosenGateway = $(this).val();
			var $wrapper = $('#velox-disclosure-wrapper');
			var params = window.velox_params || {};

			if (!chosenGateway || !$wrapper.length || !params.ajax_url) {
				return;
			}

			$.ajax({
				url: params.ajax_url,
				type: 'POST',
				data: {
					action: 'velox_get_gateway_disclosure',
					nonce: params.nonce,
					gateway_id: chosenGateway
				},
				success: function(response) {
					if (response.success && response.data.required) {
						$wrapper.find('.velox-disclosure-notice').text(response.data.message);
						$wrapper.slideDown(200);
					} else {
						$wrapper.slideUp(200);
					}
				}
			});
		});
	});
})(jQuery);
