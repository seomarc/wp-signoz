/**
 * WP SigNoz — Admin JavaScript
 *
 * Handles the "Test Connection" button via AJAX.
 *
 * @package WP_SigNoz
 */

/* global jQuery, wpSignoz */
(function ($) {
	'use strict';

	$(document).ready(function () {
		var $btn = $('#signoz-test-connection');
		var $result = $('#signoz-test-result');
		var $resultText = $result.find('p');

		$btn.on('click', function () {
			$btn.prop('disabled', true).text(wpSignoz.i18n.testing);
			$result.hide().removeClass('notice-success notice-error');

			$.ajax({
				url: wpSignoz.ajaxUrl,
				type: 'POST',
				data: {
					action: 'signoz_test_connection',
					nonce: wpSignoz.nonce,
				},
				success: function (response) {
					if (response.success) {
						$result
							.addClass('notice-success')
							.show();
						$resultText.text(wpSignoz.i18n.success);
					} else {
						$result
							.addClass('notice-error')
							.show();
						$resultText.text(wpSignoz.i18n.error + (response.data || ''));
					}
				},
				error: function () {
					$result
						.addClass('notice-error')
						.show();
					$resultText.text(wpSignoz.i18n.error + 'Network error');
				},
				complete: function () {
					$btn.prop('disabled', false).html(
						'<span class="dashicons dashicons-networking" style="vertical-align: middle; margin-right: 4px;"></span>' +
						wpSignoz.i18n.testButton
					);
				},
			});
		});
	});
})(jQuery);
