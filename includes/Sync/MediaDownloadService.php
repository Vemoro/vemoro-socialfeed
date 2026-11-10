<?php
namespace Vemoro\SocialFeed\Sync;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never rendered directly and are escaped by their presentation boundary.

use Vemoro\SocialFeed\Config;
use Vemoro\SocialFeed\Domain\Media;
use Vemoro\SocialFeed\Repository\PostRepository;

final class MediaDownloadService {
	public function __construct( private readonly PostRepository $posts ) {}

	public function image( Media $media, string $url, int $parentPostId = 0 ): int {
		return $this->download( $media, $url, false, $parentPostId );
	}

	public function video( Media $media, string $url, int $parentPostId = 0 ): int {
		return $this->download( $media, $url, true, $parentPostId );
	}

	public function isAvailable( int $attachmentId, bool $video = false ): bool {
		$attachment = $attachmentId > 0 ? get_post( $attachmentId ) : null;
		if ( ! $attachment || 'attachment' !== $attachment->post_type || 'inherit' !== $attachment->post_status || ! str_starts_with( $attachment->post_mime_type, $video ? 'video/' : 'image/' ) ) {
			return false;
		}
		$file = get_attached_file( $attachmentId );
		return $file && is_file( $file );
	}

	private function download( Media $media, string $url, bool $video, int $parentPostId ): int {
		$existing = $this->posts->index( $media->id );
		$key      = $video ? 'video_attachment_id' : 'attachment_id';
		$attachmentId = (int) ( $existing[ $key ] ?? 0 );
		// get_post_status() resolves inherited attachment status to the parent post's status.
		if ( ! $this->isAvailable( $attachmentId, $video ) ) {
			$attachmentId = $this->findOwnedAttachment( $media->id, $video );
		}
		if ( $attachmentId ) {
			if ( ! $video ) {
				$this->updateAlt( $attachmentId, $media );
			}
			return $attachmentId;
		}
		if ( ! $this->validSource( $url ) ) {
			throw new \RuntimeException( __( 'Instagram returned an unsafe media URL.', 'vemoro-socialfeed' ) ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'vemoro-' . $media->id );
		if ( ! $tmp ) {
			throw new \RuntimeException( __( 'Could not create a temporary media file.', 'vemoro-socialfeed' ) ); }
		try {
			$finalUrl = $this->stream( $url, $tmp, $video );
			$finfo    = new \finfo( FILEINFO_MIME_TYPE );
			$mime     = (string) $finfo->file( $tmp );
			$allowed  = $video ? array( 'video/mp4' ) : array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
			if ( ! in_array( $mime, $allowed, true ) ) {
				throw new \RuntimeException( __( 'Downloaded media has an invalid MIME type.', 'vemoro-socialfeed' ) ); }
			$extensions = array(
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/webp' => 'webp',
				'image/gif'  => 'gif',
				'video/mp4'  => 'mp4',
			);
			$name       = sanitize_file_name( 'instagram-' . $media->id . '.' . $extensions[ $mime ] );
			$file       = array(
				'name'     => $name,
				'tmp_name' => $tmp,
				'error'    => 0,
				'size'     => filesize( $tmp ),
				'type'     => $mime,
			);
			$attachmentId = $this->storeDownload( $media, $file, (string) wp_parse_url( $finalUrl, PHP_URL_HOST ), $parentPostId );
			$tmp = '';
			if ( ! $video ) {
				$this->updateAlt( (int) $attachmentId, $media );
			}
			return (int) $attachmentId;
		} finally {
			if ( $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp ); }
		}
	}

	/** @param array<string,mixed> $file */
	private function storeDownload( Media $media, array $file, string $sourceHost, int $parentPostId ): int {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		// Store provenance at insertion, before thumbnail generation can exhaust time or memory.
		$attachmentId = media_handle_sideload(
			$file,
			$parentPostId,
			wp_trim_words( $media->caption, 12, '…' ),
			array(
				'meta_input' => array(
					'_vemoro_media_id'    => $media->id,
					'_vemoro_owned'       => '1',
					'_vemoro_source_host' => $sourceHost,
				),
			)
		);
		if ( is_wp_error( $attachmentId ) ) {
			throw new \RuntimeException( $attachmentId->get_error_message() );
		}
		return (int) $attachmentId;
	}

	private function findOwnedAttachment( string $mediaId, bool $video ): int {
		$ids = get_posts(
			array(
				'post_type' => 'attachment',
				'post_status' => 'inherit',
				'post_mime_type' => $video ? 'video' : 'image',
				'numberposts' => -1,
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'meta_query' => array(
					array(
						'key' => '_vemoro_owned',
						'value' => '1',
					),
					array(
						'key' => '_vemoro_media_id',
						'value' => $mediaId,
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			if ( $this->isAvailable( (int) $id, $video ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	private function updateAlt( int $attachmentId, Media $media ): void {
		$current = (string) get_post_meta( $attachmentId, '_wp_attachment_image_alt', true );
		$managed = (string) get_post_meta( $attachmentId, '_vemoro_managed_alt', true );
		if ( '' !== $current && $current !== $managed ) {
			return;
		}
		$alt = sanitize_text_field( $media->altText ?: wp_trim_words( wp_strip_all_tags( $media->caption ), 20, '…' ) );
		update_post_meta( $attachmentId, '_wp_attachment_image_alt', wp_slash( $alt ) );
		update_post_meta( $attachmentId, '_vemoro_managed_alt', wp_slash( $alt ) );
	}

	private function stream( string $url, string $target, bool $video ): string {
		$settings = Config::settings();
		$max      = (int) ( $video ? $settings['video_max_mb'] : $settings['image_max_mb'] ) * MB_IN_BYTES;
		$current  = $url;
		for ( $redirects = 0; $redirects <= 3; ++$redirects ) {
			if ( ! $this->validSource( $current ) ) {
				throw new \RuntimeException( __( 'A media redirect was rejected.', 'vemoro-socialfeed' ) ); }
			$response = wp_safe_remote_get(
				$current,
				array(
					'timeout'             => 30,
					'redirection'         => 0,
					'stream'              => true,
					'filename'            => $target,
					'limit_response_size' => $max + 1,
					'headers'             => array( 'Accept' => $video ? 'video/mp4,video/*;q=0.8' : 'image/*' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				throw new \RuntimeException( $response->get_error_message() ); }
			$status = wp_remote_retrieve_response_code( $response );
			if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( ! is_string( $location ) || ! $location ) {
					throw new \RuntimeException( 'Invalid media redirect.' ); }
				$current = $location;
				continue;
			}
			if ( $status < 200 || $status >= 300 ) {
				/* translators: %d is the HTTP response status returned by the media host. */
				throw new \RuntimeException( sprintf( __( 'Media download failed with HTTP %d.', 'vemoro-socialfeed' ), $status ) );
			}
			$size = filesize( $target );
			if ( false === $size || 0 === $size || $size > $max ) {
				throw new \RuntimeException( __( 'Downloaded media is empty or exceeds the configured limit.', 'vemoro-socialfeed' ) ); }
			return $current;
		}
		throw new \RuntimeException( __( 'Media download exceeded the redirect limit.', 'vemoro-socialfeed' ) );
	}

	private function validSource( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false; }
		$host    = strtolower( rtrim( (string) $parts['host'], '.' ) );
		$allowed = (bool) preg_match( '/(^|\.)(cdninstagram\.com|fbcdn\.net|instagram\.com)$/', $host );
		if ( ! $allowed ) {
			return false; }
		$records = dns_get_record( $host, DNS_A | DNS_AAAA );
		if ( false === $records || ! $records ) {
			return false; }
		foreach ( $records as $record ) {
			$ip = (string) ( $record['ip'] ?? $record['ipv6'] ?? '' );
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false; }
		}
		return true;
	}
}
