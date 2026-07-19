<?php
namespace LocalInstagramFeed\Api;

use LocalInstagramFeed\Config;
use LocalInstagramFeed\Domain\Media;

final class InstagramApiClient {
	private const GRAPH_HOST = 'https://graph.instagram.com';
	public function __construct(private readonly TokenService $tokens) {}

	/** @return array{items:array<int,Media>,complete:bool,oldest:string,pages:int} */
	public function media(int $limit, int $maxPages): array {
		$version = preg_match('/^v\d+\.\d+$/', (string) Config::settings()['api_version']) ? (string) Config::settings()['api_version'] : Config::DEFAULT_API_VERSION;
		$userId  = $this->tokens->userId();
		if (! $userId) { throw new ApiException(__('No Instagram account is connected.', 'local-instagram-feed')); }
		$fields = 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,username,accessibility_caption,like_count,comments_count,children{id,media_type,media_product_type,media_url,thumbnail_url,accessibility_caption}';
		$url = self::GRAPH_HOST . '/' . rawurlencode($version) . '/' . rawurlencode($userId) . '/media?' . http_build_query(array('fields' => $fields, 'limit' => min(100, $limit)));
		$items = array(); $seenCursors = array(); $pages = 0; $complete = false; $validResponse = true;
		while ($url && count($items) < $limit && $pages < $maxPages) {
			++$pages; $body = $this->request($url);
			foreach ((array) ($body['data'] ?? array()) as $row) {
				if (! is_array($row)) { continue; }
				try { $items[] = Media::fromArray($row); } catch (\InvalidArgumentException $e) { $validResponse = false; continue; }
				if (count($items) >= $limit) { break; }
			}
			$next = esc_url_raw((string) ($body['paging']['next'] ?? ''));
			$after = sanitize_text_field((string) ($body['paging']['cursors']['after'] ?? ''));
			if (! $next) { $complete = true; break; }
			if (! $after || isset($seenCursors[$after]) || ! $this->isGraphUrl($next)) { break; }
			$seenCursors[$after] = true; $url = $next;
		}
		$oldest = '';
		foreach ($items as $item) { if ($item->timestamp && (! $oldest || strtotime($item->timestamp) < strtotime($oldest))) { $oldest = $item->timestamp; } }
		$isComplete = ($complete || count($items) >= $limit) && $validResponse;
		if ($isComplete && ! $items) { $oldest = '1970-01-01T00:00:00+0000'; }
		return array('items' => array_slice($items, 0, $limit), 'complete' => $isComplete, 'oldest' => $oldest, 'pages' => $pages);
	}

	/** @return array<string,mixed> */
	public function profile(): array {
		$version = (string) Config::settings()['api_version'];
		$url = self::GRAPH_HOST . '/' . rawurlencode($version) . '/' . rawurlencode($this->tokens->userId()) . '?fields=id,username,account_type,media_count';
		return $this->request($url);
	}

	/** @return array<string,mixed> */
	private function request(string $url): array {
		$response = wp_remote_get($url, array('timeout' => 20, 'redirection' => 2, 'headers' => array('Authorization' => 'Bearer ' . $this->tokens->accessToken(), 'Accept' => 'application/json'), 'user-agent' => 'LocalInstagramFeed/' . LIF_VERSION . '; ' . home_url('/')));
		if (is_wp_error($response)) { throw new ApiException(sanitize_text_field($response->get_error_message()), 0, 0, true); }
		$status = wp_remote_retrieve_response_code($response); $raw = wp_remote_retrieve_body($response); $data = json_decode($raw, true);
		if (! is_array($data)) { throw new ApiException(__('Instagram returned invalid JSON.', 'local-instagram-feed'), $status, 0, $status >= 500); }
		if ($status < 200 || $status >= 300 || isset($data['error'])) {
			$error = is_array($data['error'] ?? null) ? $data['error'] : array();
			$code = (int) ($error['code'] ?? 0); $message = sanitize_text_field((string) ($error['message'] ?? __('Instagram API request failed.', 'local-instagram-feed')));
			throw new ApiException($message, $status, $code, 429 === $status || $status >= 500 || in_array($code, array(1,2,4,17,32,613), true));
		}
		return $data;
	}

	private function isGraphUrl(string $url): bool {
		$parts = wp_parse_url($url); return 'https' === ($parts['scheme'] ?? '') && 'graph.instagram.com' === strtolower((string) ($parts['host'] ?? ''));
	}
}
