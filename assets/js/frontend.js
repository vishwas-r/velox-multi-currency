/**
 * SettleX Frontend JS: Clean AJAX currency switching and dynamic checkout disclosures
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		// 1. Currency switcher dropdown change handler.
		$(document).on('change', '.settlex-currency-select', function() {
			var selectedCurrency = $(this).val();
			if (!selectedCurrency) {
				return;
			}

			var $select = $(this);
			$select.prop('disabled', true);

			$.ajax({
				url: settlex_params.ajax_url,
				type: 'POST',
				data: {
					action: 'settlex_switch_currency',
					nonce: settlex_params.nonce,
					currency: selectedCurrency
				},
				success: function(response) {
					if (response.success) {
						// Clean reload without modifying URL search params (full cache-friendly).
						window.location.reload();
					} else {
						alert(response.data.message || 'Error switching currency.');
						$select.prop('disabled', false);
					}
				},
				error: function() {
					$select.prop('disabled', false);
				}
			});
		});

		// 2. Checkout Payment Gateway switch listener.
		$(document.body).on('change', 'input[name="payment_method"]', function() {
			var chosenGateway = $(this).val();
			var $wrapper = $('#settlex-disclosure-wrapper');

			if (!chosenGateway || !$wrapper.length) {
				return;
			}

			$.ajax({
				url: settlex_params.ajax_url,
				type: 'POST',
				data: {
					action: 'settlex_get_gateway_disclosure',
					nonce: settlex_params.nonce,
					gateway_id: chosenGateway
				},
				success: function(response) {
					if (response.success && response.data.required) {
						$wrapper.find('.settlex-disclosure-notice').text(response.data.message);
						$wrapper.slideDown(200);
					} else {
						$wrapper.slideUp(200);
					}
				}
			});
		});
	});
})(jQuery);
