(function () {
	'use strict';

	var sync = document.getElementById('lif-sync-now');
	var progress = document.getElementById('lif-sync-progress');
	var result = document.getElementById('lif-sync-result');

	function request(action) {
		var data = new URLSearchParams({ action: action, nonce: lifAdmin.nonce });
		return fetch(lifAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: data.toString()
		}).then(function (response) {
			return response.json();
		});
	}

	if (sync) {
		sync.addEventListener('click', function () {
			sync.disabled = true;
			progress.hidden = false;
			result.textContent = '';
			var timer = setInterval(function () {
				request('lif_sync_progress').then(function (reply) {
					if (reply.success && reply.data) {
						progress.querySelector('span').style.width = (reply.data.percent || 0) + '%';
						progress.setAttribute('aria-label', (reply.data.phase || '') + ' ' + (reply.data.percent || 0) + '%');
					}
				});
			}, 750);
			request('lif_sync').then(function (reply) {
				clearInterval(timer);
				progress.querySelector('span').style.width = '100%';
				result.textContent = JSON.stringify(reply.data, null, 2);
				sync.disabled = false;
			}).catch(function () {
				clearInterval(timer);
				result.textContent = lifAdmin.syncError;
				sync.disabled = false;
			});
		});
	}

	var clear = document.getElementById('lif-clear-cache');
	if (clear) {
		clear.addEventListener('click', function () {
			request('lif_clear_cache').then(function (reply) {
				clear.textContent = reply.data.message;
			});
		});
	}

	var providers = document.querySelectorAll('.lif-oauth-provider');
	var hostedSettings = document.getElementById('lif-hosted-oauth-settings');
	var customSettings = document.getElementById('lif-custom-app-settings');
	function toggleOAuthSettings() {
		var selected = document.querySelector('.lif-oauth-provider:checked');
		var provider = selected ? selected.value : 'vemoro';
		if (hostedSettings) {
			hostedSettings.hidden = provider !== 'vemoro';
		}
		if (customSettings) {
			customSettings.hidden = provider !== 'custom';
		}
	}
	providers.forEach(function (provider) {
		provider.addEventListener('change', toggleOAuthSettings);
	});
	toggleOAuthSettings();
}());
