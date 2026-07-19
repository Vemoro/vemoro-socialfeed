<?php
namespace LocalInstagramFeed\Sync;

use LocalInstagramFeed\Api\InstagramApiClient;
use LocalInstagramFeed\Api\TokenService;
use LocalInstagramFeed\Config;
use LocalInstagramFeed\Domain\Media;
use LocalInstagramFeed\Domain\SyncResult;
use LocalInstagramFeed\Frontend\FeedRenderer;
use LocalInstagramFeed\Repository\LogRepository;
use LocalInstagramFeed\Repository\PostRepository;

final class InstagramSyncService {
	public function __construct(private readonly InstagramApiClient $api, private readonly TokenService $tokens, private readonly PostRepository $posts, private readonly MediaDownloadService $downloads, private readonly SyncLock $lock, private readonly LogRepository $logs) {}

	public function sync(): SyncResult {
		$result = new SyncResult();
		if (! $this->lock->acquire()) { $result->errors[] = __('A synchronization is already running.', 'local-instagram-feed'); $result->failed = 1; return $result; }
		$started = microtime(true); $this->logs->add('info', 'Instagram synchronization started.');
		set_transient('lif_sync_progress', array('phase'=>'starting','current'=>0,'total'=>0,'percent'=>1), 20 * MINUTE_IN_SECONDS);
		try {
			if (! $this->tokens->isConnected()) { throw new \RuntimeException(__('No Instagram account is connected.', 'local-instagram-feed')); }
			if (! $this->tokens->refresh()) { throw new \RuntimeException(__('The Instagram token could not be refreshed.', 'local-instagram-feed')); }
			$settings = Config::settings(); $profile = $this->api->profile(); $accountUsername = sanitize_text_field((string)($profile['username']??''));
			$tokenMeta=(array)get_option(Config::TOKEN_OPTION,array());$tokenMeta['username']=$accountUsername;update_option(Config::TOKEN_OPTION,$tokenMeta,false);
			$refreshGeneration = (int) get_option(Config::REFRESH_GENERATION_OPTION, 0);
			$forceRefresh = $refreshGeneration > (int) get_option(Config::APPLIED_REFRESH_GENERATION_OPTION, 0);
			$result->fullRefresh = $forceRefresh;
			$limit = (int) $settings['post_limit'];
			if ($forceRefresh) { $limit = max($limit, min(100, $this->posts->count())); }
			$batch = $this->api->media($limit, (int) $settings['max_api_pages']);
			$result->fetched = count($batch['items']); $seen = array(); $current = 0;
			set_transient('lif_sync_progress', array('phase'=>'media','current'=>0,'total'=>$result->fetched,'percent'=>10), 20 * MINUTE_IN_SECONDS);
			foreach ($batch['items'] as $media) {
				$this->lock->refresh(); $seen[] = $media->id; ++$current;
				if ($media->isRepost($accountUsername)) { $this->posts->setDisplayEnabled($media->id,false); ++$result->skipped; continue; }
				if ((! $settings['show_reels'] && 'REELS' === $media->productType) || (! $settings['show_carousels'] && 'CAROUSEL_ALBUM' === $media->mediaType)) { $this->posts->setDisplayEnabled($media->id, false); ++$result->skipped; continue; }
				try { $this->syncMedia($media, $result, $settings, $forceRefresh); } catch (\Throwable $e) { ++$result->failed; $result->errors[] = $media->id . ': ' . sanitize_text_field($e->getMessage()); $this->logs->add('error', 'Instagram media synchronization failed.', array('media_id'=>$media->id,'error'=>$e->getMessage())); }
				set_transient('lif_sync_progress', array('phase'=>'media','current'=>$current,'total'=>$result->fetched,'percent'=>10 + (int) floor(80 * $current / max(1,$result->fetched))), 20 * MINUTE_IN_SECONDS);
			}
			$result->complete = (bool) $batch['complete'];
			if ($result->complete) { $result->deleted = $this->posts->markMissing($seen, (string) $batch['oldest'], (string) $settings['deleted_behavior']); }
			if ($result->complete && 0 === $result->failed) { $pruned=$this->posts->pruneExcess((int)$settings['post_limit'],(int)$settings['excess_retention_days']);$result->pruned=$pruned['posts'];if($pruned['posts']>0){$this->logs->add('info','Excess Instagram posts pruned.',$pruned);} }
			if ($forceRefresh && $result->complete && 0 === $result->failed) { update_option(Config::APPLIED_REFRESH_GENERATION_OPTION, $refreshGeneration, false); }
			FeedRenderer::clearCache();
			$status = array('last_run' => time(), 'last_success' => time(), 'result' => $result->toArray(), 'duration' => round(microtime(true)-$started, 3)); update_option(Config::STATUS_OPTION, $status, false);
			$this->logs->add('info', 'Instagram synchronization completed.', $result->toArray());
			set_transient('lif_sync_progress', array('phase'=>'complete','current'=>$result->fetched,'total'=>$result->fetched,'percent'=>100), 5 * MINUTE_IN_SECONDS);
		} catch (\Throwable $e) {
			++$result->failed; $result->errors[] = sanitize_text_field($e->getMessage()); $status = (array) get_option(Config::STATUS_OPTION, array()); $status['last_run'] = time(); $status['last_failure'] = time(); $status['last_error'] = sanitize_text_field($e->getMessage()); update_option(Config::STATUS_OPTION, $status, false); $this->logs->add('error', 'Instagram synchronization aborted.', array('error'=>$e->getMessage())); set_transient('lif_sync_progress', array('phase'=>'failed','current'=>0,'total'=>0,'percent'=>100,'message'=>sanitize_text_field($e->getMessage())), 5 * MINUTE_IN_SECONDS);
		} finally { $this->lock->release(); }
		return $result;
	}

	/** @param array<string,mixed> $settings */
	private function syncMedia(Media $media, SyncResult $result, array $settings, bool $forceRefresh = false): void {
		$index = $this->posts->index($media->id); $postId = $index ? (int) $index['post_id'] : 0;
		if (! $forceRefresh && $index && hash_equals((string) $index['data_hash'], $media->semanticHash()) && (int) $index['attachment_id'] > 0 && file_exists((string) get_attached_file((int) $index['attachment_id']))) { $this->posts->touch($postId); ++$result->skipped; return; }
		$attachmentId = 0; $videoId = 0;
		if ('CAROUSEL_ALBUM' === $media->mediaType) {
			if (! $media->children) { throw new \RuntimeException(__('Carousel has no usable child media.', 'local-instagram-feed')); }
			$first = $media->children[0]; $url = 'VIDEO' === $first->mediaType ? $first->thumbnailUrl : $first->mediaUrl; $attachmentId = $this->downloads->image($first, $url, $postId);
		} elseif ('VIDEO' === $media->mediaType) {
			$attachmentId = $this->downloads->image($media, $media->thumbnailUrl, $postId);
			if (! empty($settings['mirror_videos']) && $media->mediaUrl) { $videoId = $this->downloads->video($media, $media->mediaUrl, $postId); }
		} elseif ('IMAGE' === $media->mediaType) { $attachmentId = $this->downloads->image($media, $media->mediaUrl, $postId); }
		else { throw new \RuntimeException(__('Unsupported Instagram media type.', 'local-instagram-feed')); }
		$saved = $this->posts->save($media, $attachmentId, $videoId); $postId = (int) $saved['post_id'];
		if ('CAROUSEL_ALBUM' === $media->mediaType) {
			foreach ($media->children as $position => $child) {
				try { $url = 'VIDEO' === $child->mediaType ? $child->thumbnailUrl : $child->mediaUrl; $childAttachment = 0 === $position ? $attachmentId : $this->downloads->image($child, $url, $postId); $this->posts->saveChild($postId, $media->id, $child, $childAttachment, $position); }
				catch (\Throwable $e) { $this->posts->saveChild($postId, $media->id, $child, 0, $position, sanitize_text_field($e->getMessage())); ++$result->failed; $result->errors[] = $child->id . ': ' . sanitize_text_field($e->getMessage()); }
			}
		}
		if ($saved['created']) { ++$result->created; } else { ++$result->updated; }
	}
}
