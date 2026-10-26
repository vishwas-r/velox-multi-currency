/**
 * SettleX Admin JS: Interactive matrix toggles, dynamic currency adding and AJAX rate sync
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		// 1. Sync Rates AJAX handler with multi-provider support.
		$('#settlex-sync-rates-btn').on('click', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var originalHtml = $btn.html();
			var selectedProvider = $('#settlex-rate-provider-select').val();

			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin" style="font-size:16px; width:16px; height:16px; vertical-align:middle; margin-right:4px;"></span> Syncing rates...');

			$.ajax({
				url: settlex_admin_params.ajax_url,
				type: 'POST',
				data: {
					action: 'settlex_admin_sync_rates',
					nonce: settlex_admin_params.nonce,
					provider: selectedProvider
				},
				success: function(response) {
					$btn.prop('disabled', false).html(originalHtml);
					if (response.success) {
						alert(response.data.message);
						window.location.reload();
					} else {
						alert('Sync error: ' + (response.data || 'Unknown error.'));
					}
				},
				error: function() {
					$btn.prop('disabled', false).html(originalHtml);
					alert('Network error while syncing rates.');
				}
			});
		});

		// 2. Dynamic Add Currency to Table.
		$('#settlex-add-currency-btn').on('click', function() {
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
				'<td style="text-align:center;"><button type="button" class="settlex-btn-delete delete-currency-row" title="Remove currency" aria-label="Remove"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="bi bi-trash3" viewBox="0 0 16 16"><path d="M6.5 1h3a.5.5 0 0 1 .5.5v1H6v-1a.5.5 0 0 1 .5-.5M11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3A1.5 1.5 0 0 0 5 1.5v1H1.5a.5.5 0 0 0 0 1h.538l.853 10.66A2 2 0 0 0 4.885 16h6.23a2 2 0 0 0 1.994-1.84l.853-10.66h.538a.5.5 0 0 0 0-1zm1.958 1-.846 10.58a1 1 0 0 1-.997.92h-6.23a1 1 0 0 1-.997-.92L3.042 3.5zm-7.487 1a.5.5 0 0 1 .528.47l.5 8.5a.5.5 0 0 1-.998.06L5 5a.5.5 0 0 1 .47-.528M8 4.5a.5.5 0 0 1 .5.5v8.5a.5.5 0 0 1-1 0V5a.5.5 0 0 1 .5-.5m2.472.5a.5.5 0 0 1 .528.47l.5 8.5a.5.5 0 0 1-.998.06L10 5a.5.5 0 0 1 .47-.528"/></svg></button></td>' +
			'</tr>';

			$('#settlex-currencies-table tbody').append(rowHtml);
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
		$(document).on('change', '.settlex-mode-select', function() {
			var mode = $(this).val();
			var $wrap = $(this).siblings('.settlex-convert-to-wrap');

			if (mode === 'convert_disclose') {
				$wrap.slideDown(150);
			} else {
				$wrap.slideUp(150);
			}
		});
	});
})(jQuery);
