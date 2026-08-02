<?php
namespace LocalInstagramFeed\Frontend;

use LocalInstagramFeed\Config;
use LocalInstagramFeed\Repository\PostRepository;

final class FeedRenderer {
	public function __construct( private readonly PostRepository $posts ) {}

	/** @param array<string,mixed> $args */
	public function render( array $args = array() ): string {
		$options = $this->normalize( $args );
		$this->enqueue();
		$version = (int) get_option( 'lif_cache_version', 1 );
		$key     = 'lif_feed_' . md5( (string) wp_json_encode( array( VEMORO_SOCIALFEED_VERSION, $version, $options, get_locale() ) ) );
		$cached  = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached; }
		$queryArgs = array(
			'post_type'      => Config::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $options['posts'],
			'orderby'        => 'date',
			'order'          => $options['order'],
			'no_found_rows'  => true,
			// The bounded feed query must filter the plugin CPT by its local active flag.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => '_lif_status',
					'value' => 'active',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => '_lif_exists',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_lif_exists',
						'value' => '1',
					),
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => '_lif_display_enabled',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_lif_display_enabled',
						'value' => '1',
					),
				),
			),
		);
		if ( $options['post_id'] > 0 ) {
			$queryArgs['p']              = $options['post_id'];
			$queryArgs['posts_per_page'] = 1; }
		$query = new \WP_Query( $queryArgs );
		if ( ! $query->have_posts() ) {
			return '<p class="lif-feed-empty">' . esc_html__( 'No Instagram posts are available locally yet.', 'vemoro-socialfeed' ) . '</p>'; }
		$style        = sprintf( '--lif-columns:%d;--lif-columns-tablet:%d;--lif-columns-mobile:%d;--lif-aspect-ratio:%s', $options['columns'], $options['columns_tablet'], $options['columns_mobile'], esc_attr( $options['aspect_ratio'] ) );
		$html         = '<section class="lif-feed ' . esc_attr( $options['class'] ) . '" style="' . esc_attr( $style ) . '" aria-label="' . esc_attr__( 'Instagram posts', 'vemoro-socialfeed' ) . '" data-lif-feed>';
		$initialCount = 2 * max( $options['columns'], $options['columns_tablet'], $options['columns_mobile'] );
		$deferred     = '';
		foreach ( $query->posts as $index => $post ) {
			$postHtml = $this->post( (int) $post->ID, $options );
			if ( $index < $initialCount ) {
				$html .= $postHtml;
			} else {
				$deferred .= $postHtml; }
		}
		if ( '' !== $deferred ) {
			$html .= '<template data-lif-feed-deferred>' . $deferred . '</template>'; }
		$html .= '<div class="lif-feed__reveal" data-lif-feed-reveal hidden><button type="button" data-lif-feed-more aria-expanded="false">' . esc_html__( 'Show more', 'vemoro-socialfeed' ) . '</button></div>';
		$html .= '<div class="lif-feed__close" data-lif-feed-close hidden><button type="button" data-lif-feed-close-button>' . esc_html__( 'Close feed', 'vemoro-socialfeed' ) . '</button></div>';
		if ( $options['show_link'] ) {
			$html .= $this->externalDialog(); }
		$html .= '</section>';
		wp_reset_postdata();
		set_transient( $key, $html, (int) Config::settings()['cache_ttl'] );
		return $html;
	}

	/** @param array<string,mixed> $o */
	private function post( int $postId, array $o ): string {
		$type            = (string) get_post_meta( $postId, '_lif_media_type', true );
		$product         = (string) get_post_meta( $postId, '_lif_product_type', true );
		$mediaId         = (string) get_post_meta( $postId, '_lif_media_id', true );
		$attachmentId    = (int) get_post_meta( $postId, '_lif_attachment_id', true );
		$caption         = (string) get_post_meta( $postId, '_lif_caption', true );
		$permalink       = $this->instagramUrl( (string) get_post_meta( $postId, '_lif_permalink', true ) );
		$externalEnabled = $o['show_link'] && '' !== $permalink;
		$isVideo         = 'VIDEO' === $type || 'REELS' === $product;
		$videoId         = (int) get_post_meta( $postId, '_lif_video_attachment_id', true );
		$videoReady      = $isVideo && ! empty( Config::settings()['mirror_videos'] ) && '' !== $this->localUrl( $videoId );
		$mediaRatio      = $this->mediaRatio( $attachmentId );
		$html            = '<article class="lif-post">';
		$html           .= '<div class="lif-post__stage" style="--lif-media-ratio:' . esc_attr( $mediaRatio ) . '"' . ( $videoReady ? ' data-lif-video-stage data-lif-hover-autoplay="' . ( ! empty( Config::settings()['video_autoplay'] ) ? '1' : '0' ) . '"' : '' ) . '>';
		if ( 'CAROUSEL_ALBUM' === $type ) {
			$html .= $this->carousel( $mediaId, $attachmentId ); } else {
			$html .= $this->media( $attachmentId, $postId );
			if ( $videoReady && $o['show_video_indicator'] ) {
				$html .= '<button type="button" class="lif-post__video" data-lif-video-toggle aria-label="' . esc_attr__( 'Play video', 'vemoro-socialfeed' ) . '" data-play-label="' . esc_attr__( 'Play video', 'vemoro-socialfeed' ) . '" data-pause-label="' . esc_attr__( 'Pause video', 'vemoro-socialfeed' ) . '">' . $this->icon( 'play' ) . '</button>'; }
			}
			$html .= '</div>';
			if ( $o['show_metrics'] || $externalEnabled ) {
				$html .= '<div class="lif-post__actions">';
				if ( $o['show_metrics'] ) {
						$html .= $this->metrics( $postId );
				} if ( $externalEnabled ) {
					$html .= '<a class="lif-post__instagram" ' . $this->linkAttributes( $permalink, true, $o ) . ' aria-label="' . esc_attr__( 'View post on Instagram', 'vemoro-socialfeed' ) . '">' . $this->icon( 'instagram' ) . '</a>';
				} $html .= '</div>'; }
			if ( $o['show_caption'] || $o['show_date'] || $o['show_username'] ) {
				$html .= '<div class="lif-post__content">';
				if ( $o['show_username'] ) {
					$username     = (string) get_post_meta( $postId, '_lif_username', true );
					$usernameText = '@' . esc_html( $username );
					if ( $o['show_link'] && '' !== $username ) {
						$profileUrl           = 'https://www.instagram.com/' . rawurlencode( $username ) . '/';
								$usernameText = '<a ' . $this->linkAttributes( $profileUrl, true, $o ) . '>' . $usernameText . '</a>';
					}$html .= '<p class="lif-post__username">' . $usernameText . '</p>'; }
				if ( $o['show_caption'] && $caption ) {
					$html .= $this->caption( $postId, $caption, (int) $o['caption_length'] ); }
				if ( $o['show_date'] ) {
					$timestamp = (string) get_post_meta( $postId, '_lif_timestamp', true );
					if ( $timestamp ) {
								$html .= '<time datetime="' . esc_attr( gmdate( DATE_ATOM, strtotime( $timestamp ) ) ) . '">' . esc_html( wp_date( 'j. F Y', strtotime( $timestamp ) ) ) . '</time>'; }
				}
				$html .= '</div>';
			}
			return $html . '</article>';
	}

	private function media( int $attachmentId, int $postId ): string {
		$videoId  = (int) get_post_meta( $postId, '_lif_video_attachment_id', true );
		$settings = Config::settings();
		if ( $videoId && ! empty( $settings['mirror_videos'] ) ) {
			$video  = $this->localUrl( $videoId );
			$poster = $this->localUrl( $attachmentId );
			if ( $video ) {
				return '<video class="lif-post__media" data-lif-video controls controlslist="nodownload" preload="metadata" playsinline muted loop' . ( $poster ? ' poster="' . esc_url( $poster ) . '"' : '' ) . '><source src="' . esc_url( $video ) . '" type="video/mp4"></video>'; }
		}
		return $this->image( $attachmentId );
	}

	private function caption( int $postId, string $caption, int $limit ): string {
		$id    = 'lif-caption-' . $postId;
		$html  = '<p id="' . esc_attr( $id ) . '" class="lif-post__caption" data-lif-caption data-collapsible="1">' . esc_html( $caption ) . '</p>';
		$html .= '<button type="button" class="lif-caption-toggle" data-lif-caption-toggle aria-controls="' . esc_attr( $id ) . '" aria-expanded="false" data-more-label="' . esc_attr__( 'Show more', 'vemoro-socialfeed' ) . '" data-less-label="' . esc_attr__( 'Show less', 'vemoro-socialfeed' ) . '" hidden>' . esc_html__( 'Show more', 'vemoro-socialfeed' ) . '</button>';
		return $html;
	}

	private function metrics( int $postId ): string {
		$items = array(
			array( 'heart', (int) get_post_meta( $postId, '_lif_like_count', true ), __( 'Likes', 'vemoro-socialfeed' ) ),
			array( 'comment', (int) get_post_meta( $postId, '_lif_comments_count', true ), __( 'Comments', 'vemoro-socialfeed' ) ),
		);
		$html  = '<ul class="lif-post__metrics" aria-label="' . esc_attr__( 'Instagram interactions', 'vemoro-socialfeed' ) . '">';
		foreach ( $items as [$icon, $count, $label] ) {
			/* translators: 1: interaction type such as likes, 2: localized interaction count. */
			$accessible_label = sprintf( __( '%1$s: %2$s', 'vemoro-socialfeed' ), $label, number_format_i18n( $count ) );
			$html            .= '<li title="' . esc_attr( $label ) . '">' . $this->icon( $icon ) . '<span aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="screen-reader-text">' . esc_html( $accessible_label ) . '</span></li>';
		}
		return $html . '</ul>';
	}

	private function externalDialog(): string {
		return '<dialog class="lif-external-dialog" data-lif-external-dialog aria-label="' . esc_attr__( 'Continue to Instagram?', 'vemoro-socialfeed' ) . '">'
			. '<h2>' . esc_html__( 'Continue to Instagram?', 'vemoro-socialfeed' ) . '</h2>'
			. '<p>' . esc_html__( 'You are now leaving this website and opening Instagram. Instagram may process personal data and set cookies.', 'vemoro-socialfeed' ) . '</p>'
			. '<div class="lif-external-dialog__actions"><button type="button" data-lif-external-cancel>' . esc_html__( 'Cancel', 'vemoro-socialfeed' ) . '</button><button type="button" class="lif-external-dialog__confirm" data-lif-external-confirm-button>' . esc_html__( 'Continue to Instagram', 'vemoro-socialfeed' ) . '</button></div>'
			. '</dialog>';
	}

	private function icon( string $name ): string {
		$paths = array(
			'play'      => '<path d="M8 5v14l11-7z" fill="currentColor"/>',
			'heart'     => '<path d="M12 21.35 10.55 20.03C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09A6.02 6.02 0 0 1 16.5 3C19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54z"/>',
			'comment'   => '<path d="M21 11.5a8.5 8.5 0 0 1-9 8.48A9.7 9.7 0 0 1 7.5 19L3 21l1.3-4.2A8.5 8.5 0 1 1 21 11.5z"/>',
			'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>',
		);
		return '<svg class="lif-icon lif-icon--' . esc_attr( $name ) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	private function carousel( string $mediaId, int $fallback ): string {
		$children = $this->posts->children( $mediaId );
		if ( ! $children ) {
			return $this->image( $fallback ); }
		$html   = '<div class="lif-carousel" data-lif-carousel><div class="lif-carousel__track">';
		$usable = 0;
		foreach ( $children as $child ) {
			if ( ! (int) $child->attachment_id ) {
				continue;
			} ++$usable;
			$html .= '<div class="lif-carousel__slide" aria-hidden="' . ( 1 === $usable ? 'false' : 'true' ) . '">' . $this->image( (int) $child->attachment_id ) . '</div>'; }
		$html .= '</div>';
		if ( $usable > 1 ) {
			$html .= '<button type="button" class="lif-carousel__previous" aria-label="' . esc_attr__( 'Previous image', 'vemoro-socialfeed' ) . '">‹</button><button type="button" class="lif-carousel__next" aria-label="' . esc_attr__( 'Next image', 'vemoro-socialfeed' ) . '">›</button><span class="lif-carousel__indicator" aria-label="' . esc_attr__( 'Carousel post', 'vemoro-socialfeed' ) . '">1/' . (int) $usable . '</span>'; }
		return $html . '</div>';
	}

	private function image( int $attachmentId ): string {
		$src = $this->localUrl( $attachmentId );
		if ( ! $src ) {
			return '<span class="lif-post__missing">' . esc_html__( 'Local image unavailable', 'vemoro-socialfeed' ) . '</span>'; }
		$meta   = wp_get_attachment_metadata( $attachmentId );
		$width  = (int) ( $meta['width'] ?? 1 );
		$height = (int) ( $meta['height'] ?? 1 );
		$alt    = (string) get_post_meta( $attachmentId, '_wp_attachment_image_alt', true );
		$srcset = wp_get_attachment_image_srcset( $attachmentId, 'lif-feed' );
		$sizes  = wp_get_attachment_image_sizes( $attachmentId, 'lif-feed' );
		if ( $srcset && ! $this->srcsetIsLocal( $srcset ) ) {
			$srcset = false; }
		return '<img class="lif-post__image" src="' . esc_url( $src ) . '"' . ( $srcset ? ' srcset="' . esc_attr( $srcset ) . '" sizes="' . esc_attr( (string) $sizes ) . '"' : '' ) . ' alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async" width="' . $width . '" height="' . $height . '">';
	}

	private function mediaRatio( int $attachmentId ): string {
		$meta   = wp_get_attachment_metadata( $attachmentId );
		$width  = (int) ( $meta['width'] ?? 0 );
		$height = (int) ( $meta['height'] ?? 0 );
		return $width > 0 && $height > 0 ? $width . '/' . $height : 'var(--lif-aspect-ratio)';
	}

	private function linkAttributes( string $url, bool $external, array $o ): string {
		return 'href="' . esc_url( $url ) . '"' . ( $external && $o['new_tab'] ? ' target="_blank"' : '' ) . ( $external ? ' rel="noopener noreferrer external" data-lif-external-confirm' : '' );
	}

	private function instagramUrl( string $url ): string {
		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		return 'https' === ( $parts['scheme'] ?? '' ) && in_array( $host, array( 'instagram.com', 'www.instagram.com' ), true ) ? esc_url_raw( $url ) : '';
	}

	private function localUrl( int $attachmentId ): string {
		$url = wp_get_attachment_url( $attachmentId );
		if ( ! is_string( $url ) ) {
			return ''; }
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		return $host && hash_equals( $home, $host ) ? $url : '';
	}

	private function srcsetIsLocal( string $srcset ): bool {
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$url = trim( explode( ' ', trim( $candidate ) )[0] );
			if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
				return false; }
		}
		return true;
	}

	/** @param array<string,mixed> $args @return array<string,mixed> */
	public function normalize( array $args ): array {
		$s              = Config::settings();
		$bool           = static fn( mixed $v, bool $default ): bool => is_bool( $v ) ? $v : ( null === filter_var( $v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ? $default : (bool) filter_var( $v, FILTER_VALIDATE_BOOLEAN ) );
		$ratio          = in_array( (string) ( $args['aspect_ratio'] ?? $s['aspect_ratio'] ), array( '9/16', '1/1', '4/5', '16/9', 'auto' ), true ) ? (string) ( $args['aspect_ratio'] ?? $s['aspect_ratio'] ) : '9/16';
		$globalLinks    = $bool( $s['show_link'], false );
		$requestedLinks = $bool( $args['show_link'] ?? true, true );
		return array(
			'posts'                => max( 1, min( 100, (int) ( $args['posts'] ?? $s['post_limit'] ) ) ),
			'columns'              => max( 1, min( 6, (int) ( $args['columns'] ?? $s['columns'] ) ) ),
			'columns_tablet'       => max( 1, min( 6, (int) ( $args['columns_tablet'] ?? $s['columns_tablet'] ) ) ),
			'columns_mobile'       => max( 1, min( 4, (int) ( $args['columns_mobile'] ?? $s['columns_mobile'] ) ) ),
			'show_caption'         => $bool( $args['show_caption'] ?? $s['show_caption'], true ),
			'show_date'            => $bool( $args['show_date'] ?? $s['show_date'], true ),
			'show_username'        => $bool( $args['show_username'] ?? $s['show_username'], true ),
			'show_link'            => $globalLinks && $requestedLinks,
			'new_tab'              => $bool( $s['new_tab'], true ),
			'show_video_indicator' => $bool( $args['show_video_indicator'] ?? true, true ),
			'show_metrics'         => $bool( $args['show_metrics'] ?? $s['show_metrics'], true ),
			'caption_length'       => max( 0, min( 5000, (int) ( $args['caption_length'] ?? $s['caption_length'] ) ) ),
			'aspect_ratio'         => $ratio,
			'order'                => 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC',
			'class'                => sanitize_html_class( (string) ( $args['class'] ?? '' ) ),
			'post_id'              => max( 0, (int) ( $args['post_id'] ?? 0 ) ),
			'detail_context'       => ! empty( $args['detail_context'] ),
		);
	}

	public static function clearCache(): void {
		update_option( 'lif_cache_version', (int) get_option( 'lif_cache_version', 1 ) + 1, false ); }
	private function truncate( string $text, int $length ): string {
		if ( 0 === $length ) {
			return '';
		} return mb_strlen( $text ) <= $length ? $text : rtrim( mb_substr( $text, 0, $length - 1 ) ) . '…'; }
	private function enqueue(): void {
		wp_enqueue_style( 'lif-frontend', VEMORO_SOCIALFEED_PLUGIN_URL . 'assets/css/frontend.css', array(), VEMORO_SOCIALFEED_VERSION );
		wp_enqueue_script( 'lif-frontend', VEMORO_SOCIALFEED_PLUGIN_URL . 'assets/js/frontend.js', array(), VEMORO_SOCIALFEED_VERSION, true ); }
}
