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

final class MediaDownloadServiceTest extends WP_UnitTestCase {
	private array $files = array();

	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}vemoro_instagram_media" );
		parent::tear_down();
	}

	/** @dataProvider attachmentParents */
	public function test_reuses_images_and_videos_independently_of_parent_status( string $status, bool $video ): void {
		$parent = 'unattached' === $status ? 0 : self::factory()->post->create( array( 'post_status' => $status ) );
		$attachment = $this->attachment( $video, $parent );
		$this->assertSame( 'inherit', get_post( $attachment )->post_status );
		$this->assertSame( 'unattached' === $status ? 'publish' : $status, get_post_status( $attachment ) );
		$media = Media::fromArray( array( 'id' => 'reuse-test', 'media_type' => $video ? 'VIDEO' : 'IMAGE' ) );
		$posts = new PostRepository();
		$posts->save( $media, $video ? 0 : $attachment, $video ? $attachment : 0 );
		$this->assertNotNull( $posts->index( $media->id ) );
		$this->assertFileExists( get_attached_file( $attachment ) );
		update_post_meta( $attachment, '_wp_attachment_image_alt', 'Manually maintained alternative text' );
		$downloads = new MediaDownloadService( $posts );
		// An expired or unavailable source must never be needed for a complete local attachment.
		$result = $video ? $downloads->video( $media, '' ) : $downloads->image( $media, '' );
		$this->assertSame( $attachment, $result );
		$this->assertSame( 'Manually maintained alternative text', get_post_meta( $attachment, '_wp_attachment_image_alt', true ) );
	}

	public static function attachmentParents(): array {
		return array(
			'unattached image' => array( 'unattached', false ),
			'published image' => array( 'publish', false ),
			'draft image' => array( 'draft', false ),
			'private image' => array( 'private', false ),
			'unattached video' => array( 'unattached', true ),
			'published video' => array( 'publish', true ),
		);
	}

	/** @dataProvider invalidAttachments */
	public function test_does_not_reuse_invalid_index_entries( string $kind ): void {
		$attachment = $this->attachment();
		if ( 'trash' === $kind ) {
			wp_update_post( array( 'ID' => $attachment, 'post_status' => 'trash' ) );
		} elseif ( 'missing file' === $kind ) {
			wp_delete_file( get_attached_file( $attachment ) );
		} elseif ( 'non-attachment' === $kind ) {
			$file = get_attached_file( $attachment );
			$attachment = self::factory()->post->create( array( 'post_status' => 'inherit' ) );
			update_attached_file( $attachment, $file );
		} else {
			wp_delete_attachment( $attachment, true );
		}
		$media = Media::fromArray( array( 'id' => 'invalid-reuse-test', 'media_type' => 'IMAGE' ) );
		$posts = new PostRepository();
		$posts->save( $media, $attachment );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Instagram returned an unsafe media URL.' );
		( new MediaDownloadService( $posts ) )->image( $media, '' );
	}

	public static function invalidAttachments(): array {
		return array_map( static fn( string $kind ): array => array( $kind ), array( 'trash', 'missing file', 'non-attachment', 'deleted' ) );
	}

	/** @dataProvider mediaTypes */
	public function test_metrics_changes_and_full_refresh_reuse_all_local_media( string $type ): void {
		$posts = new PostRepository();
		$image = $this->attachment();
		$video = 'VIDEO' === $type ? $this->attachment( true ) : 0;
		$data = array(
			'id' => 'resync-test',
			'media_type' => $type,
			'media_url' => 'https://expired.example/old',
			'thumbnail_url' => 'https://expired.example/poster',
			'caption' => 'An existing Instagram post',
			'like_count' => 1,
		);
		if ( 'CAROUSEL_ALBUM' === $type ) {
			$data['children'] = array( 'data' => array(
				array( 'id' => 'child-first', 'media_type' => 'IMAGE', 'media_url' => 'https://expired.example/first' ),
				array( 'id' => 'child-second', 'media_type' => 'VIDEO', 'thumbnail_url' => 'https://expired.example/second' ),
			) );
		}
		$media = Media::fromArray( $data );
		$saved = $posts->save( $media, $image, $video );
		wp_update_post( array( 'ID' => $image, 'post_parent' => $saved['post_id'] ) );
		if ( $video ) {
			wp_update_post( array( 'ID' => $video, 'post_parent' => $saved['post_id'] ) );
		}
		foreach ( $media->children as $position => $child ) {
			$posts->saveChild( $saved['post_id'], $media->id, $child, $position ? $this->attachment( false, $saved['post_id'] ) : $image, $position );
		}
		$before = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
		$logs = new LogRepository();
		$tokens = new TokenService( new SecretStore(), $logs );
		$sync = new InstagramSyncService( new InstagramApiClient( $tokens ), $tokens, $posts, new MediaDownloadService( $posts ), new SyncLock(), $logs );
		$method = new ReflectionMethod( $sync, 'syncMedia' );
		$data['like_count'] = 2;
		$data['media_url'] = 'https://expired.example/new-signed-url';
		$changed = Media::fromArray( $data );
		$this->assertNotSame( $media->semanticHash(), $changed->semanticHash() );
		$result = new SyncResult();
		$method->invoke( $sync, $changed, $result, Config::defaults(), false );
		$method->invoke( $sync, $changed, $result, Config::defaults(), true );
		$this->assertSame( 0, $result->failed );
		$this->assertSame( 2, $result->updated );
		$this->assertSame( $image, (int) $posts->index( $media->id )['attachment_id'] );
		$this->assertSame( $video, (int) $posts->index( $media->id )['video_attachment_id'] );
		$this->assertSame( $before, get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) ) );
	}

	public static function mediaTypes(): array {
		return array( array( 'IMAGE' ), array( 'VIDEO' ), array( 'CAROUSEL_ALBUM' ) );
	}

	private function attachment( bool $video = false, int $parent = 0 ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$uploads = wp_upload_dir();
		$file = wp_tempnam( 'vemoro-media-reuse-test', $uploads['path'] );
		$this->files[] = $file;
		$id = wp_insert_attachment( array( 'post_status' => 'inherit', 'post_mime_type' => $video ? 'video/mp4' : 'image/jpeg', 'post_parent' => $parent ) );
		update_attached_file( $id, $file );
		$this->assertFileExists( $file );
		return $id;
	}
}
