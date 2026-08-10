<?php
namespace Vemoro\SocialFeed\Sync;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never rendered directly and are escaped by their presentation boundary.

use Vemoro\SocialFeed\Api\InstagramApiClient;
use Vemoro\SocialFeed\Api\ApiException;
use Vemoro\SocialFeed\Api\TokenService;
use Vemoro\SocialFeed\Config;
use Vemoro\SocialFeed\Diagnostics\FailureTracker;
use Vemoro\SocialFeed\Domain\Media;
use Vemoro\SocialFeed\Domain\SyncResult;
use Vemoro\SocialFeed\Frontend\FeedRenderer;
use Vemoro\SocialFeed\Repository\LogRepository;
use Vemoro\SocialFeed\Repository\PostRepository;

final class InstagramSyncService {
	public function __construct( private readonly InstagramApiClient $api, private readonly TokenService $tokens, private readonly PostRepository $posts, private readonly MediaDownloadService $downloads, private readonly SyncLock $lock, private readonly LogRepository $logs ) {}

	public function sync(): SyncResult {
		$result = new SyncResult();
		if ( ! $this->lock->acquire() ) {
			$result->errors[] = __( 'A synchronization is already running.', 'vemoro-socialfeed' );
			$result->failed   = 1;
			return $result; }
		$started = microtime( true );
		$phase   = 'starting';
		$this->logs->add( 'info', 'Instagram synchronization started.', array( 'plugin_version' => VEMORO_VERSION ) );
		set_transient(
			'vemoro_sync_progress',
			array(
				'phase'   => 'starting',
				'current' => 0,
				'total'   => 0,
				'percent' => 1,
			),
			20 * MINUTE_IN_SECONDS
		);
		try {
			$phase = 'configuration';
			if ( ! $this->tokens->isConnected() ) {
				throw new \RuntimeException( __( 'No Instagram account is connected.', 'vemoro-socialfeed' ) ); }
			$phase = 'token_refresh';
			if ( ! $this->tokens->refresh() ) {
				throw new \RuntimeException( __( 'The Instagram token could not be refreshed.', 'vemoro-socialfeed' ) ); }
			$phase                 = 'profile';
			$settings              = Config::settings();
			$profile               = $this->api->profile();
			$accountUsername       = sanitize_text_field( (string) ( $profile['username'] ?? '' ) );
			$tokenMeta             = (array) get_option( Config::TOKEN_OPTION, array() );
			$tokenMeta['username'] = $accountUsername;
			update_option( Config::TOKEN_OPTION, $tokenMeta, false );
			$refreshGeneration   = (int) get_option( Config::REFRESH_GENERATION_OPTION, 0 );
			$forceRefresh        = $refreshGeneration > (int) get_option( Config::APPLIED_REFRESH_GENERATION_OPTION, 0 );
			$result->fullRefresh = $forceRefresh;
			$limit               = (int) $settings['post_limit'];
			if ( $forceRefresh ) {
				$limit = max( $limit, min( 100, $this->posts->count() ) ); }
			$phase           = 'media_list';
			$batch           = $this->api->media( $limit, (int) $settings['max_api_pages'] );
			$result->fetched = count( $batch['items'] );
			$seen            = array();
			$current         = 0;
			set_transient(
				'vemoro_sync_progress',
				array(
					'phase'   => 'media',
					'current' => 0,
					'total'   => $result->fetched,
					'percent' => 10,
				),
				20 * MINUTE_IN_SECONDS
			);
			foreach ( $batch['items'] as $media ) {
				$phase = 'media_processing';
				$this->lock->refresh();
				$seen[] = $media->id;
				++$current;
				if ( ( ! $settings['show_reels'] && 'REELS' === $media->productType ) || ( ! $settings['show_carousels'] && 'CAROUSEL_ALBUM' === $media->mediaType ) ) {
					$this->posts->setDisplayEnabled( $media->id, false );
					++$result->skipped;
					continue; }
				try {
					$this->syncMedia( $media, $result, $settings, $forceRefresh );
				} catch ( \Throwable $e ) {
					++$result->failed;
					$result->errors[] = $media->id . ': ' . sanitize_text_field( $e->getMessage() );
					$this->logs->add(
						'error',
						'Instagram media synchronization failed.',
						array(
							'media_id' => $media->id,
							'error'    => $e->getMessage(),
						)
					); }
				set_transient(
					'vemoro_sync_progress',
					array(
						'phase'   => 'media',
						'current' => $current,
						'total'   => $result->fetched,
						'percent' => 10 + (int) floor( 80 * $current / max( 1, $result->fetched ) ),
					),
					20 * MINUTE_IN_SECONDS
				);
			}
			$result->complete = (bool) $batch['complete'];
			if ( $result->complete ) {
				$result->deleted = $this->posts->markMissing( $seen, (string) $batch['oldest'], (string) $settings['deleted_behavior'], (int) $settings['missing_grace_hours'] ); }
			if ( $result->complete && 0 === $result->failed ) {
				$pruned         = $this->posts->pruneExcess( (int) $settings['post_limit'], (int) $settings['excess_retention_days'] );
				$result->pruned = $pruned['posts'];
				if ( $pruned['posts'] > 0 ) {
					$this->logs->add( 'info', 'Excess Instagram posts pruned.', $pruned );}
			}
			if ( $forceRefresh && $result->complete && 0 === $result->failed ) {
				update_option( Config::APPLIED_REFRESH_GENERATION_OPTION, $refreshGeneration, false ); }
			FeedRenderer::clearCache();
			$status                     = FailureTracker::clear( (array) get_option( Config::STATUS_OPTION, array() ) );
			$status['last_run']         = time();
			$status['last_success']     = time();
			$status['result']           = $result->toArray();
			$status['duration']         = round( microtime( true ) - $started, 3 );
			$status['successful_syncs'] = (int) ( $status['successful_syncs'] ?? 0 ) + 1;
			update_option( Config::STATUS_OPTION, $status, false );
			$this->logs->add( 'info', 'Instagram synchronization completed.', $result->toArray() );
			set_transient(
				'vemoro_sync_progress',
				array(
					'phase'   => 'complete',
					'current' => $result->fetched,
					'total'   => $result->fetched,
					'percent' => 100,
				),
				5 * MINUTE_IN_SECONDS
			);
		} catch ( \Throwable $e ) {
			++$result->failed;
			$result->errors[]       = sanitize_text_field( $e->getMessage() );
			$status                 = FailureTracker::record( (array) get_option( Config::STATUS_OPTION, array() ), $e->getMessage() );
			$status['last_run']     = time();
			update_option( Config::STATUS_OPTION, $status, false );
			$context = $e instanceof ApiException ? $e->diagnosticContext( $phase ) : array(
				'phase' => $phase,
				'error' => $e->getMessage(),
				'type'  => get_class( $e ),
			);
			$context['consecutive_failures'] = (int) $status['consecutive_failures'];
			$this->logs->add( 'error', 'Instagram synchronization aborted.', $context );
			set_transient(
				'vemoro_sync_progress',
				array(
					'phase'   => 'failed',
					'current' => 0,
					'total'   => 0,
					'percent' => 100,
					'message' => sanitize_text_field( $e->getMessage() ),
				),
				5 * MINUTE_IN_SECONDS
			);
		} finally {
			$this->lock->release(); }
		return $result;
	}

	/** @param array<string,mixed> $settings */
	private function syncMedia( Media $media, SyncResult $result, array $settings, bool $forceRefresh = false ): void {
		$index  = $this->posts->index( $media->id );
		$postId = $index ? (int) $index['post_id'] : 0;
		if ( ! $forceRefresh && $index && hash_equals( (string) $index['data_hash'], $media->semanticHash() ) && (int) $index['attachment_id'] > 0 && file_exists( (string) get_attached_file( (int) $index['attachment_id'] ) ) ) {
			$this->posts->touch( $postId );
			++$result->skipped;
			return; }
		$attachmentId = 0;
		$videoId      = 0;
		if ( 'CAROUSEL_ALBUM' === $media->mediaType ) {
			if ( ! $media->children ) {
				throw new \RuntimeException( __( 'Carousel has no usable child media.', 'vemoro-socialfeed' ) ); }
			$first        = $media->children[0];
			$url          = 'VIDEO' === $first->mediaType ? $first->thumbnailUrl : $first->mediaUrl;
			$attachmentId = $this->downloads->image( $first, $url, $postId );
		} elseif ( 'VIDEO' === $media->mediaType ) {
			$attachmentId = $this->downloads->image( $media, $media->thumbnailUrl, $postId );
			if ( ! empty( $settings['mirror_videos'] ) && $media->mediaUrl ) {
				$videoId = $this->downloads->video( $media, $media->mediaUrl, $postId ); }
		} elseif ( 'IMAGE' === $media->mediaType ) {
			$attachmentId = $this->downloads->image( $media, $media->mediaUrl, $postId ); } else {
			throw new \RuntimeException( __( 'Unsupported Instagram media type.', 'vemoro-socialfeed' ) ); }
			$saved  = $this->posts->save( $media, $attachmentId, $videoId );
			$postId = (int) $saved['post_id'];
			if ( 'CAROUSEL_ALBUM' === $media->mediaType ) {
				foreach ( $media->children as $position => $child ) {
					try {
						$url             = 'VIDEO' === $child->mediaType ? $child->thumbnailUrl : $child->mediaUrl;
						$childAttachment = 0 === $position ? $attachmentId : $this->downloads->image( $child, $url, $postId );
						$this->posts->saveChild( $postId, $media->id, $child, $childAttachment, $position ); } catch ( \Throwable $e ) {
										$this->posts->saveChild( $postId, $media->id, $child, 0, $position, sanitize_text_field( $e->getMessage() ) );
										++$result->failed;
										$result->errors[] = $child->id . ': ' . sanitize_text_field( $e->getMessage() ); }
				}
			}
			if ( $saved['created'] ) {
				++$result->created;
			} else {
				++$result->updated; }
	}
}
