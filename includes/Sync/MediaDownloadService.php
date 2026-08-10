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

	private function download( Media $media, string $url, bool $video, int $parentPostId ): int {
		$existing = $this->posts->index( $media->id );
		$key      = $video ? 'video_attachment_id' : 'attachment_id';
		if ( $existing && ! empty( $existing[ $key ] ) && 'inherit' === get_post_status( (int) $existing[ $key ] ) && get_attached_file( (int) $existing[ $key ] ) && file_exists( (string) get_attached_file( (int) $existing[ $key ] ) ) ) {
			return (int) $existing[ $key ];
		}
		if ( ! $this->validSource( $url ) ) {
			throw new \RuntimeException( __( 'Instagram returned an unsafe media URL.', 'vemoro-socialfeed' ) ); }
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
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachmentId = media_handle_sideload( $file, $parentPostId, wp_trim_words( $media->caption, 12, '…' ) );
			if ( is_wp_error( $attachmentId ) ) {
				throw new \RuntimeException( $attachmentId->get_error_message() ); }
			$tmp = '';
			update_post_meta( (int) $attachmentId, '_vemoro_owned', '1' );
			update_post_meta( (int) $attachmentId, '_vemoro_media_id', $media->id );
			update_post_meta( (int) $attachmentId, '_vemoro_source_host', (string) wp_parse_url( $finalUrl, PHP_URL_HOST ) );
			if ( ! $video ) {
				$alt = $media->altText ?: wp_trim_words( wp_strip_all_tags( $media->caption ), 20, '…' );
				if ( $alt ) {
					update_post_meta( (int) $attachmentId, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
					update_post_meta( (int) $attachmentId, '_vemoro_managed_alt', sanitize_text_field( $alt ) ); }
			}
			return (int) $attachmentId;
		} finally {
			if ( $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp ); }
		}
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
