/**
 * SettleX Admin JS: Interactive matrix toggles, dynamic currency adding and AJAX rate sync
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		// 1. Sync Rates Now AJAX handler.
		$('#settlex-sync-rates-btn').on('click', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var originalText = $btn.text();

			$btn.prop('disabled', true).text('⏳ Syncing with ECB...');

			$.ajax({
				url: settlex_admin_params.ajax_url,
				type: 'POST',
				data: {
					action: 'settlex_admin_sync_rates',
					nonce: settlex_admin_params.nonce
				},
				success: function(response) {
					$btn.prop('disabled', false).text(originalText);
					if (response.success) {
						alert(response.data.message);
						window.location.reload();
					} else {
						alert('Sync error: ' + (response.data || 'Unknown error.'));
					}
				},
				error: function() {
					$btn.prop('disabled', false).text(originalText);
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
				'<td><button type="button" class="button-link delete-currency-row text-danger" title="Remove">&times;</button></td>' +
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
