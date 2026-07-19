<?php
namespace LocalInstagramFeed\Cli;

use LocalInstagramFeed\Api\TokenService;
use LocalInstagramFeed\Config;
use LocalInstagramFeed\Frontend\FeedRenderer;
use LocalInstagramFeed\Sync\InstagramSyncService;

final class Commands {
	public function __construct(private readonly InstagramSyncService $sync, private readonly TokenService $tokens) {}
	/** Run a complete synchronization. */
	public function sync(array $args, array $assocArgs): void { $result=$this->sync->sync(); \WP_CLI::line((string)wp_json_encode($result->toArray(),JSON_PRETTY_PRINT)); if($result->failed){\WP_CLI::warning(sprintf('%d item(s) failed.',$result->failed));}else{\WP_CLI::success('Instagram feed synchronized.');} }
	/** Show connection and synchronization status without exposing secrets. */
	public function status(array $args, array $assocArgs): void { \WP_CLI::line((string)wp_json_encode(array('connected'=>$this->tokens->isConnected(),'user_id'=>$this->mask($this->tokens->userId()),'expires_at'=>$this->tokens->expiresAt()?gmdate(DATE_ATOM,$this->tokens->expiresAt()):null,'sync'=>get_option(Config::STATUS_OPTION,array())),JSON_PRETTY_PRINT)); }
	/** Refresh the long-lived token now. */
	public function refresh_token(array $args, array $assocArgs): void { $this->tokens->refresh(true)?\WP_CLI::success('Token refreshed.'):\WP_CLI::error('Token refresh failed.'); }
	/** Invalidate all rendered feed caches. */
	public function clear_cache(array $args, array $assocArgs): void { FeedRenderer::clearCache();\WP_CLI::success('Feed cache cleared.'); }
	private function mask(string $id): string { return strlen($id)>6?substr($id,0,3).'***'.substr($id,-3):'***'; }
}
