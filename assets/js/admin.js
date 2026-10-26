/**
 * NammaWP Admin JS: Interactive matrix toggles, dynamic currency adding and AJAX rate sync
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		// 1. Sync Rates AJAX handler with multi-provider support.
		$(document).on('click', '#nammawp-sync-rates-btn, #nammawp-sync-rates-btn-2', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var originalHtml = $btn.html();
			var selectedProvider = ($btn.attr('id') === 'nammawp-sync-rates-btn-2') ?
				$('#nammawp_rate_provider_card').val() :
				$('#nammawp-rate-provider-select').val();
			var params = window.nammawp_admin_params || {};

			$btn.prop('disabled', true).addClass('is-loading').html(
				'<svg class="nammawp-spin" width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">' +
					'<circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2" stroke-opacity="0.3"/>' +
					'<path d="M14 8A6 6 0 0 0 8 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
				'</svg>' +
				'<span>Syncing rates...</span>'
			);

			$.ajax({
				url: params.ajax_url,
				type: 'POST',
				data: {
					action: 'nammawp_admin_sync_rates',
					nonce: params.nonce,
					provider: selectedProvider
				},
				success: function(response) {
					$btn.prop('disabled', false).removeClass('is-loading').html(originalHtml);
					if (response.success) {
						alert(response.data.message);
						window.location.reload();
					} else {
						alert('Sync error: ' + (response.data || 'Unknown error.'));
					}
				},
				error: function() {
					$btn.prop('disabled', false).removeClass('is-loading').html(originalHtml);
					alert('Network error while syncing rates.');
				}
			});
		});

		// 1b. Auto-sync interval custom duration toggle.
		$(document).on('change', '#auto_sync_interval_select', function() {
			if ($(this).val() === 'custom') {
				$('#nammawp-custom-hours-wrapper').css('display', 'inline-flex');
			} else {
				$('#nammawp-custom-hours-wrapper').hide();
			}
		});

		// 2. Dynamic Add Currency to Table.
		$('#nammawp-add-currency-btn').on('click', function() {
			var $select = $('#new_currency_select');
			var code = $select.val();
			if (!code) {
				return;
			}

			var $opt = $select.find(':selected');
			var name = $opt.data('name');
			var symbol = $opt.data('symbol') || '$';

			var rowHtml = '<tr data-currency="' + code + '">' +
				'<td><strong>' + code + '</strong></td>' +
				'<td>' + name + '</td>' +
				'<td><input type="text" name="currencies[' + code + '][symbol]" value="' + symbol + '" class="small-text"></td>' +
				'<td><input type="number" step="0.000001" min="0" name="currencies[' + code + '][rate]" value="1.0" class="regular-text" style="width:100px;"></td>' +
				'<td><input type="number" step="0.1" min="0" name="currencies[' + code + '][markup]" value="0.0" class="small-text" style="width:70px;"> %</td>' +
				'<td>' +
					'<select name="currencies[' + code + '][rounding]">' +
						'<option value="none">None (Exact)</option>' +
						'<option value="charm_99">.99 (Charm)</option>' +
						'<option value="charm_95">.95 (Charm)</option>' +
						'<option value="ceil_int">Round Up to Integer</option>' +
						'<option value="round_5">Nearest 5</option>' +
						'<option value="round_10">Nearest 10</option>' +
					'</select>' +
				'</td>' +
				'<td style="text-align:center;"><button type="button" class="nammawp-btn-delete delete-currency-row" title="Remove currency" aria-label="Remove"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-trash3" viewBox="0 0 16 16"><path d="M6.5 1h3a.5.5 0 0 1 .5.5v1H6v-1a.5.5 0 0 1 .5-.5M11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3A1.5 1.5 0 0 0 5 1.5v1H1.5a.5.5 0 0 0 0 1h.538l.853 10.66A2 2 0 0 0 4.885 16h6.23a2 2 0 0 0 1.994-1.84l.853-10.66h.538a.5.5 0 0 0 0-1zm1.958 1-.846 10.58a1 1 0 0 1-.997.92h-6.23a1 1 0 0 1-.997-.92L3.042 3.5zm-7.487 1a.5.5 0 0 1 .528.47l.5 8.5a.5.5 0 0 1-.998.06L5 5a.5.5 0 0 1 .47-.528M8 4.5a.5.5 0 0 1 .5.5v8.5a.5.5 0 0 1-1 0V5a.5.5 0 0 1 .5-.5m2.472.5a.5.5 0 0 1 .528.47l.5 8.5a.5.5 0 0 1-.998.06L10 5a.5.5 0 0 1 .47-.528"/></svg></button></td>' +
			'</tr>';

			$('#nammawp-currencies-table tbody').append(rowHtml);
			$opt.remove();
			$select.val('');
		});

		// 3. Remove currency row.
		$(document).on('click', '.delete-currency-row', function() {
			var $row = $(this).closest('tr');
			var code = $row.data('currency');
			if (confirm('Are you sure you want to remove ' + code + '?')) {
				$row.remove();
			}
		});

		// 4. Matrix Mode select toggle.
		$(document).on('change', '.nammawp-mode-select', function() {
			var mode = $(this).val();
			var $wrap = $(this).siblings('.nammawp-convert-to-wrap');

			if (mode === 'convert_disclose') {
				$wrap.slideDown(150);
			} else {
				$wrap.slideUp(150);
			}
		});

		// 5. Dedicated Routing Mode toggle.
		$(document).on('change', '.nammawp-routing-mode-toggle', function() {
			var $radio = $(this);
			var curr = $radio.data('currency');
			var mode = $radio.val();
			var $card = $radio.closest('.nammawp-routing-card');
			var $exclusiveContainer = $('#exclusive-gateways-' + curr);

			if (mode === 'exclusive') {
				$card.addClass('is-exclusive');
				$exclusiveContainer.slideDown(180);
			} else {
				$card.removeClass('is-exclusive');
				$exclusiveContainer.slideUp(180);
			}
		});
	});
})(jQuery);
