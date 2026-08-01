<?php
namespace LocalInstagramFeed\Domain;

final class Media {
	/** @param array<int,Media> $children */
	public function __construct(
		public readonly string $id,
		public readonly string $caption,
		public readonly string $mediaType,
		public readonly string $productType,
		public readonly string $mediaUrl,
		public readonly string $thumbnailUrl,
		public readonly string $permalink,
		public readonly string $timestamp,
		public readonly string $username,
		public readonly string $altText = '',
		public readonly array $children = array(),
		public readonly int $likeCount = 0,
		public readonly int $commentsCount = 0
	) {}

	/** @param array<string,mixed> $data */
	public static function fromArray( array $data ): self {
		if ( empty( $data['id'] ) || empty( $data['media_type'] ) ) {
			throw new \InvalidArgumentException( 'Instagram media is missing required fields.' );
		}
		$children = array();
		foreach ( (array) ( $data['children']['data'] ?? $data['children'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				$children[] = self::fromArray(
					$child + array(
						'caption'   => '',
						'permalink' => '',
						'timestamp' => '',
						'username'  => '',
					)
				);
			}
		}
		return new self(
			sanitize_text_field( (string) $data['id'] ),
			sanitize_textarea_field( (string) ( $data['caption'] ?? '' ) ),
			strtoupper( sanitize_key( (string) $data['media_type'] ) ),
			strtoupper( sanitize_key( (string) ( $data['media_product_type'] ?? '' ) ) ),
			esc_url_raw( (string) ( $data['media_url'] ?? '' ) ),
			esc_url_raw( (string) ( $data['thumbnail_url'] ?? '' ) ),
			esc_url_raw( (string) ( $data['permalink'] ?? '' ) ),
			sanitize_text_field( (string) ( $data['timestamp'] ?? '' ) ),
			sanitize_text_field( (string) ( $data['username'] ?? '' ) ),
			sanitize_text_field( (string) ( $data['accessibility_caption'] ?? '' ) ),
			$children,
			max( 0, (int) ( $data['like_count'] ?? 0 ) ),
			max( 0, (int) ( $data['comments_count'] ?? 0 ) )
		);
	}

	public function semanticHash(): string {
		$children = array_map( static fn( self $child ): string => $child->semanticHash(), $this->children );
		return hash( 'sha256', (string) wp_json_encode( array( $this->id, $this->caption, $this->mediaType, $this->productType, $this->timestamp, $children, $this->likeCount, $this->commentsCount ) ) );
	}
}
