<?php
namespace LocalInstagramFeed\Api;

use LocalInstagramFeed\Config;
use LocalInstagramFeed\Repository\LogRepository;
use LocalInstagramFeed\Security\SecretStoreInterface;

final class TokenService {
	public function __construct(private readonly SecretStoreInterface $secrets, private readonly LogRepository $logs) {}
	public function accessToken(): string { return (string) ($this->secrets->get('access_token') ?? ''); }
	public function userId(): string { $meta = get_option(Config::TOKEN_OPTION, array()); return sanitize_text_field((string) ($meta['user_id'] ?? '')); }
	public function expiresAt(): int { $meta = get_option(Config::TOKEN_OPTION, array()); return (int) ($meta['expires_at'] ?? 0); }
	public function isConnected(): bool { return '' !== $this->accessToken() && '' !== $this->userId(); }
	public function needsRefresh(): bool { return $this->isConnected() && $this->expiresAt() <= time() + (7 * DAY_IN_SECONDS); }

	/** @param array{access_token:string,user_id:string,expires_in:int} $short */
	public function acceptShortLived(array $short): void {
		$long = $this->exchangeLongLived($short['access_token']);
		if (! $this->secrets->store('access_token', $long['access_token'])) { throw new \RuntimeException(__('The access token could not be encrypted.', 'local-instagram-feed')); }
		update_option(Config::TOKEN_OPTION, array('user_id' => $short['user_id'], 'expires_at' => time() + $long['expires_in'], 'refresh_failures' => 0, 'last_refresh' => time()), false);
		$this->logs->add('info', 'Instagram account connected.');
	}

	/** @param array{access_token:string,user_id:string,expires_in:int} $token */
	public function acceptLongLived(array $token): void {
		if (! $this->secrets->store('access_token', $token['access_token'])) { throw new \RuntimeException(__('The access token could not be encrypted.', 'local-instagram-feed')); }
		update_option(Config::TOKEN_OPTION, array('user_id' => $token['user_id'], 'expires_at' => time() + max(3600, $token['expires_in']), 'refresh_failures' => 0, 'last_refresh' => time(), 'provider' => 'vemoro'), false);
		$this->logs->add('info', 'Instagram account connected through Vemoro.');
	}

	public function refresh(bool $force = false): bool {
		if (! $this->isConnected() || (! $force && ! $this->needsRefresh())) { return true; }
		$url = 'https://graph.instagram.com/refresh_access_token?' . http_build_query(array('grant_type' => 'ig_refresh_token', 'access_token' => $this->accessToken()), '', '&', PHP_QUERY_RFC3986);
		try {
			$data = $this->getToken($url);
			if (! $this->secrets->store('access_token', $data['access_token'])) { throw new \RuntimeException('Token encryption failed.'); }
			$meta = (array) get_option(Config::TOKEN_OPTION, array()); $meta['expires_at'] = time() + $data['expires_in']; $meta['last_refresh'] = time(); $meta['refresh_failures'] = 0;
			update_option(Config::TOKEN_OPTION, $meta, false); $this->logs->add('info', 'Instagram token refreshed.'); return true;
		} catch (\Throwable $e) {
			$meta = (array) get_option(Config::TOKEN_OPTION, array()); $failures = (int) ($meta['refresh_failures'] ?? 0) + 1; $meta['refresh_failures'] = $failures; $meta['last_refresh_error'] = sanitize_text_field($e->getMessage());
			update_option(Config::TOKEN_OPTION, $meta, false); $this->scheduleRetry($failures); $this->logs->add('error', 'Instagram token refresh failed.', array('error' => $e->getMessage())); return false;
		}
	}

	public function disconnect(): void { $this->secrets->delete('access_token'); delete_option(Config::TOKEN_OPTION); $this->logs->add('info', 'Instagram account disconnected.'); }

	/** @return array{access_token:string,expires_in:int} */
	private function exchangeLongLived(string $short): array {
		$url = 'https://graph.instagram.com/access_token?' . http_build_query(array('grant_type' => 'ig_exchange_token', 'client_secret' => Config::appSecret(), 'access_token' => $short), '', '&', PHP_QUERY_RFC3986);
		return $this->getToken($url);
	}

	/** @return array{access_token:string,expires_in:int} */
	private function getToken(string $url): array {
		$response = wp_remote_get($url, array('timeout' => 20, 'redirection' => 0));
		if (is_wp_error($response)) { throw new ApiException($response->get_error_message(), 0, 0, true); }
		$status = wp_remote_retrieve_response_code($response); $data = json_decode(wp_remote_retrieve_body($response), true);
		if ($status < 200 || $status >= 300 || ! is_array($data) || empty($data['access_token'])) { throw new ApiException(sanitize_text_field((string) ($data['error']['message'] ?? 'Token request failed.')), $status); }
		return array('access_token' => (string) $data['access_token'], 'expires_in' => max(3600, (int) ($data['expires_in'] ?? 60 * DAY_IN_SECONDS)));
	}

	private function scheduleRetry(int $failures): void {
		$delay = array(900, 3600, 21600, 86400)[min(3, max(0, $failures - 1))];
		if (! wp_next_scheduled('lif_refresh_token_retry')) { wp_schedule_single_event(time() + $delay, 'lif_refresh_token_retry'); }
	}
}
