<?php
use Vemoro\SocialFeed\Api\InstagramApiClient;
use Vemoro\SocialFeed\Api\TokenService;
use Vemoro\SocialFeed\Config;
use Vemoro\SocialFeed\Domain\Media;
use Vemoro\SocialFeed\Domain\SyncResult;
use Vemoro\SocialFeed\Repository\LogRepository;
use Vemoro\SocialFeed\Repository\PostRepository;
use Vemoro\SocialFeed\Security\SecretStore;
use Vemoro\SocialFeed\Sync\InstagramSyncService;
use Vemoro\SocialFeed\Sync\MediaDownloadService;
use Vemoro\SocialFeed\Sync\SyncLock;

final class SyncRecoveryTest extends WP_UnitTestCase {
	private array $files = array();
	private $http = null;

	public function tear_down(): void {
		if ( $this->http ) {
			remove_filter( 'pre_http_request', $this->http );
		}
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
		parent::tear_down();
	}

	public function test_reappearing_post_is_reactivated_even_when_content_is_unchanged(): void {
		$media = $this->media();
		$posts = new PostRepository();
		$saved = $posts->save( $media, $this->attachment() );
		for ( $i = 0; $i < 3; ++$i ) {
			$posts->markMissing( array( 'another-post' ), '1970-01-01T00:00:00+0000', 'inactive' );
		}
		$this->assertSame( 'draft', get_post_status( $saved['post_id'] ) );
		$this->process( $media );
		$this->assertSame( 'publish', get_post_status( $saved['post_id'] ) );
		$this->assertSame( 'active', get_post_meta( $saved['post_id'], '_vemoro_status', true ) );
		$this->assertSame( '0', get_post_meta( $saved['post_id'], '_vemoro_removed_handled', true ) );
	}

	public function test_deleted_local_post_is_recreated_from_its_existing_media(): void {
		$media = $this->media();
		$posts = new PostRepository();
		$image = $this->attachment();
		$saved = $posts->save( $media, $image );
		wp_delete_post( $saved['post_id'], true );
		$this->process( $media );
		$newPost = $posts->findPostId( $media->id );
		$this->assertNotSame( $saved['post_id'], $newPost );
		$this->assertSame( Config::POST_TYPE, get_post_type( $newPost ) );
		$this->assertSame( $image, (int) $posts->index( $media->id )['attachment_id'] );
	}

	public function test_missing_video_is_retried_without_a_content_change(): void {
		$media = $this->media( 'VIDEO' );
		$posts = new PostRepository();
		$posts->save( $media, $this->attachment(), 0 );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Instagram returned an unsafe media URL.' );
		$this->process( $media );
	}

	public function test_failed_carousel_child_is_retried_without_a_content_change(): void {
		$media = $this->media( 'CAROUSEL_ALBUM' );
		$posts = new PostRepository();
		$image = $this->attachment();
		$saved = $posts->save( $media, $image );
		$posts->saveChild( $saved['post_id'], $media->id, $media->children[0], $image, 0 );
		$posts->saveChild( $saved['post_id'], $media->id, $media->children[1], 0, 1, 'Temporary download failure' );
		$result = $this->process( $media );
		$this->assertSame( 0, $result->skipped );
		$this->assertSame( 1, $result->failed );
		$this->assertStringContainsString( 'recovery-second', $result->errors[0] );
	}

	public function test_interrupted_download_reuses_owned_media_without_an_index(): void {
		$media = $this->media();
		$image = $this->attachment();
		update_post_meta( $image, '_vemoro_owned', '1' );
		update_post_meta( $image, '_vemoro_media_id', $media->id );
		$downloads = new MediaDownloadService( new PostRepository() );
		$this->assertSame( $image, $downloads->image( $media, '' ) );
	}

	public function test_confirmed_empty_account_marks_existing_posts_missing(): void {
		$posts = new PostRepository();
		$saved = $posts->save( $this->media(), $this->attachment() );
		$this->assertSame( 0, $posts->markMissing( array(), '1970-01-01T00:00:00+0000', 'inactive' ) );
		$this->assertSame( 0, $posts->markMissing( array(), '1970-01-01T00:00:00+0000', 'inactive' ) );
		$this->assertSame( 1, $posts->markMissing( array(), '1970-01-01T00:00:00+0000', 'inactive' ) );
		$this->assertSame( 'draft', get_post_status( $saved['post_id'] ) );
	}

	public function test_captions_preserve_literal_backslashes(): void {
		$media = Media::fromArray( array( 'id' => 'caption-slashes', 'media_type' => 'IMAGE', 'caption' => 'Photos in C:\\Photos\\2026' ) );
		$saved = ( new PostRepository() )->save( $media, $this->attachment() );
		$this->assertSame( $media->caption, get_post_meta( $saved['post_id'], '_vemoro_caption', true ) );
	}

	public function test_automatic_alt_text_is_updated_on_reused_media(): void {
		$media = Media::fromArray( array( 'id' => 'alt-recovery', 'media_type' => 'IMAGE', 'accessibility_caption' => 'Updated description' ) );
		$image = $this->attachment();
		update_post_meta( $image, '_wp_attachment_image_alt', 'Old description' );
		update_post_meta( $image, '_vemoro_managed_alt', 'Old description' );
		$posts = new PostRepository();
		$posts->save( $media, $image );
		$this->assertSame( $image, ( new MediaDownloadService( $posts ) )->image( $media, '' ) );
		$this->assertSame( 'Updated description', get_post_meta( $image, '_wp_attachment_image_alt', true ) );
	}

	public function test_hidden_recent_post_is_not_pruned_as_excess(): void {
		$posts = new PostRepository();
		$recent = $posts->save( $this->media(), $this->attachment() )['post_id'];
		$old = self::factory()->post->create( array( 'post_type' => Config::POST_TYPE, 'post_status' => 'publish', 'post_date' => '2025-01-01 12:00:00' ) );
		update_post_meta( $old, '_vemoro_status', 'active' );
		update_post_meta( $recent, '_vemoro_display_enabled', '0' );
		$posts->pruneExcess( 1, 0 );
		$this->assertNotNull( get_post( $recent ) );
		$this->assertNull( get_post( $old ) );
	}

	public function test_partial_media_failure_does_not_report_a_successful_sync(): void {
		$this->connect();
		update_option( Config::STATUS_OPTION, array( 'last_success' => 17, 'successful_syncs' => 5 ) );
		$this->mockApi( array( array( 'id' => 'failed-image', 'media_type' => 'IMAGE', 'media_url' => '', 'timestamp' => '2026-01-01T00:00:00+0000' ) ) );
		$result = $this->service()->sync();
		$this->assertSame( 1, $result->failed );
		$status = get_option( Config::STATUS_OPTION );
		$this->assertSame( 17, $status['last_success'] );
		$this->assertSame( 5, $status['successful_syncs'] );
		$this->assertSame( 1, $status['consecutive_failures'] );
		$this->assertSame( 'failed', get_transient( 'vemoro_sync_progress' )['phase'] );
	}

	/** @dataProvider malformedMedia */
	public function test_malformed_media_responses_are_not_authoritative( array $body ): void {
		$this->connect();
		$this->http = static fn() => array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $body ) );
		add_filter( 'pre_http_request', $this->http );
		$tokens = new TokenService( new SecretStore(), new LogRepository() );
		try {
			$batch = ( new InstagramApiClient( $tokens ) )->media( 12, 10 );
			$this->assertFalse( $batch['complete'] );
		} catch ( Vemoro\SocialFeed\Api\ApiException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
	}

	public static function malformedMedia(): array {
		return array(
			'missing data' => array( array( 'unexpected' => true ) ),
			'non-array data' => array( array( 'data' => 'invalid' ) ),
			'non-array row' => array( array( 'data' => array( array( 'id' => 'valid', 'media_type' => 'IMAGE' ), false ) ) ),
		);
	}

	private function media( string $type = 'IMAGE' ): Media {
		$data = array( 'id' => 'recovery-test', 'media_type' => $type, 'caption' => 'Recovery test', 'timestamp' => '2026-01-01T00:00:00+0000', 'media_url' => 'https://expired.example/media', 'thumbnail_url' => '' );
		if ( 'CAROUSEL_ALBUM' === $type ) {
			$data['children'] = array( 'data' => array(
				array( 'id' => 'recovery-first', 'media_type' => 'IMAGE', 'media_url' => '' ),
				array( 'id' => 'recovery-second', 'media_type' => 'IMAGE', 'media_url' => '' ),
			) );
		}
		return Media::fromArray( $data );
	}

	private function attachment(): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$uploads = wp_upload_dir();
		$file = wp_tempnam( 'vemoro-recovery-test', $uploads['path'] );
		$this->files[] = $file;
		$id = wp_insert_attachment( array( 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' ) );
		update_attached_file( $id, $file );
		return $id;
	}

	private function service(): InstagramSyncService {
		$logs = new LogRepository();
		$posts = new PostRepository();
		$tokens = new TokenService( new SecretStore(), $logs );
		return new InstagramSyncService( new InstagramApiClient( $tokens ), $tokens, $posts, new MediaDownloadService( $posts ), new SyncLock(), $logs );
	}

	private function process( Media $media ): SyncResult {
		$method = new ReflectionMethod( InstagramSyncService::class, 'syncMedia' );
		$result = new SyncResult();
		$method->invoke( $this->service(), $media, $result, Config::defaults() );
		return $result;
	}

	private function connect(): void {
		( new SecretStore() )->store( 'access_token', 'test-token-only' );
		update_option( Config::TOKEN_OPTION, array( 'user_id' => 'test-account', 'expires_at' => time() + 30 * DAY_IN_SECONDS ) );
	}

	private function mockApi( array $items ): void {
		$this->http = static fn( $pre, $args, $url ) => array(
			'response' => array( 'code' => 200 ), 'headers' => array(),
			'body' => wp_json_encode( str_contains( $url, '/me/media?' ) ? array( 'data' => $items ) : array( 'user_id' => 'test-account', 'username' => 'test_account' ) ),
		);
		add_filter( 'pre_http_request', $this->http, 10, 3 );
	}
}
