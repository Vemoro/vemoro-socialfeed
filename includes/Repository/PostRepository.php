<?php
namespace Vemoro\SocialFeed\Repository;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are never rendered directly and are escaped by their presentation boundary.

use Vemoro\SocialFeed\Config;
use Vemoro\SocialFeed\Domain\Media;

final class PostRepository {
	/** @return array<string,mixed>|null */
	public function index( string $mediaId ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'vemoro_instagram_media';
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE media_id = %s', $table, $mediaId ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function findPostId( string $mediaId ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'vemoro_instagram_media';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM %i WHERE media_id = %s AND parent_media_id = ''", $table, $mediaId ) );
	}

	public function save( Media $media, int $attachmentId, int $videoAttachmentId = 0 ): array {
		$postId = $this->findPostId( $media->id );
		$data   = array(
			'post_type'     => Config::POST_TYPE,
			'post_status'   => 'publish',
			/* translators: %s is the Instagram media ID used when a post has no caption. */
			'post_title'    => wp_trim_words( $media->caption ?: sprintf( __( 'Instagram post %s', 'vemoro-socialfeed' ), $media->id ), 12, '…' ),
			'post_content'  => $media->caption,
			'post_date_gmt' => $media->timestamp ? gmdate( 'Y-m-d H:i:s', strtotime( $media->timestamp ) ) : current_time( 'mysql', true ),
		);
		if ( $postId ) {
			$data['ID'] = $postId;
			$result     = wp_update_post( wp_slash( $data ), true ); } else {
			$result = wp_insert_post( wp_slash( $data ), true ); }
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() ); }
			$postId = (int) $result;
			$hash   = $media->semanticHash();
			$meta   = array(
				'_vemoro_media_id'            => $media->id,
				'_vemoro_media_type'          => $media->mediaType,
				'_vemoro_product_type'        => $media->productType,
				'_vemoro_username'            => $media->username,
				'_vemoro_caption'             => $media->caption,
				'_vemoro_timestamp'           => $media->timestamp,
				'_vemoro_permalink'           => $media->permalink,
				'_vemoro_synced_at'           => current_time( 'mysql', true ),
				'_vemoro_last_success'        => current_time( 'mysql', true ),
				'_vemoro_hash'                => $hash,
				'_vemoro_status'              => 'active',
				'_vemoro_exists'              => '1',
				'_vemoro_missing_count'       => 0,
				'_vemoro_removed_handled'     => '0',
				'_vemoro_attachment_id'       => $attachmentId,
				'_vemoro_video_attachment_id' => $videoAttachmentId,
				'_vemoro_display_enabled'     => '1',
				'_vemoro_like_count'          => $media->likeCount,
				'_vemoro_comments_count'      => $media->commentsCount,
			);
			foreach ( $meta as $key => $value ) {
				update_post_meta( $postId, $key, $value ); }
			delete_post_meta( $postId, '_vemoro_missing_since' );
			if ( $attachmentId ) {
				set_post_thumbnail( $postId, $attachmentId ); }
			$this->upsertIndex( $media->id, $postId, '', $attachmentId, $videoAttachmentId, 0, $media->mediaType, $hash, 'ok', '' );
			return array(
				'post_id' => $postId,
				'created' => ! isset( $data['ID'] ),
			);
	}

	public function touch( int $postId ): void {
		update_post_meta( $postId, '_vemoro_last_success', current_time( 'mysql', true ) );
		update_post_meta( $postId, '_vemoro_missing_count', 0 );
		update_post_meta( $postId, '_vemoro_exists', '1' );
		update_post_meta( $postId, '_vemoro_display_enabled', '1' );
		delete_post_meta( $postId, '_vemoro_missing_since' );
	}

	public function setDisplayEnabled( string $mediaId, bool $enabled ): void {
		$postId = $this->findPostId( $mediaId );
		if ( $postId > 0 ) {
			update_post_meta( $postId, '_vemoro_display_enabled', $enabled ? '1' : '0' ); }
	}

	public function saveChild( int $postId, string $parentId, Media $media, int $attachmentId, int $position, string $error = '' ): void {
		$this->upsertIndex( $media->id, $postId, $parentId, $attachmentId, 0, $position, $media->mediaType, $media->semanticHash(), $error ? 'error' : 'ok', $error );
	}

	/** @return array<int,object> */
	public function children( string $parentId ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'vemoro_instagram_media';
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE parent_media_id = %s ORDER BY position ASC', $table, $parentId ) );
	}

	/** @param array<int,string> $seen */
	public function markMissing( array $seen, string $oldestTimestamp, string $behavior, int $graceHours = 0 ): int {
		if ( ! $seen || ! $oldestTimestamp ) {
			return 0; }
		$query   = new \WP_Query(
			array(
				'post_type'      => Config::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_vemoro_timestamp',
						'value'   => $oldestTimestamp,
						'compare' => '>=',
					),
				),
			)
		);
		$removed = 0;
		foreach ( $query->posts as $postId ) {
			$id = (string) get_post_meta( $postId, '_vemoro_media_id', true );
			if ( in_array( $id, $seen, true ) || '1' === get_post_meta( $postId, '_vemoro_removed_handled', true ) ) {
				continue; }
			$count = (int) get_post_meta( $postId, '_vemoro_missing_count', true ) + 1;
			update_post_meta( $postId, '_vemoro_missing_count', $count );
			$missingSince = (int) get_post_meta( $postId, '_vemoro_missing_since', true );
			if ( $missingSince <= 0 ) {
				$missingSince = time();
				update_post_meta( $postId, '_vemoro_missing_since', $missingSince ); }
			if ( $count < 3 ) {
				continue; }
			$graceHours = max( 0, min( 48, $graceHours ) );
			if ( $graceHours > 0 && time() < $missingSince + ( $graceHours * HOUR_IN_SECONDS ) ) {
				continue; }
			update_post_meta( $postId, '_vemoro_exists', '0' );
			update_post_meta( $postId, '_vemoro_removed_handled', '1' );
			update_post_meta( $postId, '_vemoro_status', 'removed' );
			if ( 'trash' === $behavior ) {
				wp_trash_post( (int) $postId ); } elseif ( 'delete' === $behavior ) {
				$this->deleteOwnedAttachments( (int) $postId );
				wp_delete_post( (int) $postId, true ); } else {
					wp_update_post(
						array(
							'ID'          => (int) $postId,
							'post_status' => 'draft',
						)
					); }
				++$removed;
		}
		return $removed;
	}

	public function count(): int {
		$counts = wp_count_posts( Config::POST_TYPE );
		return (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 );
	}

	/** @return array{posts:int,attachments:int,retained_attachments:int} */
	public function pruneExcess( int $limit, int $retentionDays ): array {
		$allPostIds  = get_posts(
			array(
				'post_type'      => Config::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$limit       = max( 1, $limit );
		$keptPostIds = get_posts(
			array(
				'post_type'      => Config::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_vemoro_status',
						'value' => 'active',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_vemoro_exists',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'   => '_vemoro_exists',
							'value' => '1',
						),
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_vemoro_display_enabled',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'   => '_vemoro_display_enabled',
							'value' => '1',
						),
					),
				),
			)
		);
		$allPostIds  = array_values( array_map( 'intval', $allPostIds ) );
		$keptPostIds = array_values( array_map( 'intval', $keptPostIds ) );
		foreach ( $keptPostIds as $postId ) {
			delete_post_meta( $postId, '_vemoro_excess_since' );}
		$candidates = array_values( array_diff( $allPostIds, $keptPostIds ) );
		$result     = array(
			'posts'                => 0,
			'attachments'          => 0,
			'retained_attachments' => 0,
		);
		if ( $retentionDays < 0 ) {
			foreach ( $candidates as $postId ) {
				delete_post_meta( $postId, '_vemoro_excess_since' );
			}return $result;}
		$now    = time();
		$cutoff = $now - ( $retentionDays * DAY_IN_SECONDS );
		foreach ( $candidates as $postId ) {
			$since = (int) get_post_meta( $postId, '_vemoro_excess_since', true );
			if ( $since <= 0 ) {
				$since = $now;
				update_post_meta( $postId, '_vemoro_excess_since', $since );
				if ( $retentionDays > 0 ) {
					continue;}
			}
			if ( $retentionDays > 0 && $since > $cutoff ) {
				continue;}
			$attachments = $this->deleteOwnedAttachments( $postId );
			if ( wp_delete_post( $postId, true ) ) {
				++$result['posts'];
				$result['attachments']          += $attachments['attachments'];
				$result['retained_attachments'] += $attachments['retained_attachments'];}
		}
		return $result;
	}

	/** @return array{candidates:int,bytes:int} */
	public function orphanedOwnedMediaSummary(): array {
		$candidates = $this->orphanedOwnedAttachmentIds();
		$bytes      = 0;
		foreach ( $candidates as $attachmentId ) {
			$bytes += $this->attachmentDiskUsage( $attachmentId ); }
		return array(
			'candidates' => count( $candidates ),
			'bytes'      => $bytes,
		);
	}

	/** @return array{candidates:int,deleted:int,retained:int,failed:int,bytes:int} */
	public function cleanupOrphanedOwnedMedia(): array {
		$candidates        = $this->orphanedOwnedAttachmentIds();
			$pluginPostIds = get_posts(
				array(
					'post_type'      => Config::POST_TYPE,
					'post_status'    => array( 'publish', 'draft', 'trash' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
		$pluginPostIds     = array_values( array_unique( array_map( 'intval', $pluginPostIds ) ) );
		$result            = array(
			'candidates' => count( $candidates ),
			'deleted'    => 0,
			'retained'   => 0,
			'failed'     => 0,
			'bytes'      => 0,
		);
		foreach ( $candidates as $attachmentId ) {
			if ( $this->attachmentReferencedOutside( $attachmentId, $pluginPostIds ) ) {
				delete_post_meta( $attachmentId, '_vemoro_owned' );
				delete_post_meta( $attachmentId, '_vemoro_media_id' );
				delete_post_meta( $attachmentId, '_vemoro_source_host' );
				++$result['retained'];
				continue;
			}
			$bytes = $this->attachmentDiskUsage( $attachmentId );
			if ( wp_delete_attachment( $attachmentId, true ) ) {
				++$result['deleted'];
				$result['bytes'] += $bytes; } else {
				++$result['failed']; }
		}
		return $result;
	}

	/** @return array<int,int> */
	private function orphanedOwnedAttachmentIds(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'vemoro_instagram_media';
		$owned = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_vemoro_owned',
				'meta_value'     => '1',
			)
		);
		if ( $owned ) {
			update_meta_cache( 'post', array_map( 'intval', $owned ) ); }
		$referenced = $wpdb->get_col( $wpdb->prepare( 'SELECT attachment_id FROM %i WHERE attachment_id > 0 UNION SELECT video_attachment_id FROM %i WHERE video_attachment_id > 0', $table, $table ) );
		return array_values( array_diff( array_unique( array_map( 'intval', $owned ) ), array_unique( array_map( 'intval', $referenced ) ) ) );
	}

	private function attachmentDiskUsage( int $attachmentId ): int {
		$attachedFile = (string) get_attached_file( $attachmentId );
		if ( '' === $attachedFile ) {
			return 0; }
		$files     = array( $attachedFile );
		$directory = dirname( $attachedFile );
		$metadata  = wp_get_attachment_metadata( $attachmentId );
		if ( is_array( $metadata ) ) {
			foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $directory . DIRECTORY_SEPARATOR . basename( (string) $size['file'] ); }
			}
			if ( ! empty( $metadata['original_image'] ) ) {
				$files[] = $directory . DIRECTORY_SEPARATOR . basename( (string) $metadata['original_image'] ); }
		}
		foreach ( (array) get_post_meta( $attachmentId, '_wp_attachment_backup_sizes', true ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $directory . DIRECTORY_SEPARATOR . basename( (string) $size['file'] ); }
		}
		$bytes = 0;
		foreach ( array_unique( $files ) as $file ) {
			if ( is_file( $file ) ) {
				$size = filesize( $file );
				if ( false !== $size ) {
					$bytes += $size; }
			}
		}
		return $bytes;
	}

	/** @return array{posts:int,attachments:int,retained_attachments:int} */
	public function deleteAll(): array {
		global $wpdb;
		$table               = $wpdb->prefix . 'vemoro_instagram_media';
		$postIds             = get_posts(
			array(
				'post_type'      => Config::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$postIds             = array_values( array_unique( array_map( 'intval', $postIds ) ) );
		$attachmentIds       = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_vemoro_owned',
				'meta_value'     => '1',
			)
		);
		$attachmentIds       = array_values( array_unique( array_map( 'intval', $attachmentIds ) ) );
		$deletedAttachments  = 0;
		$retainedAttachments = 0;
		foreach ( $attachmentIds as $attachmentId ) {
			if ( $this->attachmentReferencedOutside( $attachmentId, $postIds ) ) {
				delete_post_meta( $attachmentId, '_vemoro_owned' );
				delete_post_meta( $attachmentId, '_vemoro_media_id' );
				delete_post_meta( $attachmentId, '_vemoro_source_host' );
				++$retainedAttachments;
				continue;
			}
			if ( wp_delete_attachment( $attachmentId, true ) ) {
				++$deletedAttachments; }
		}
		$deletedPosts = 0;
		foreach ( $postIds as $postId ) {
			if ( wp_delete_post( $postId, true ) ) {
				++$deletedPosts; }
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		return array(
			'posts'                => $deletedPosts,
			'attachments'          => $deletedAttachments,
			'retained_attachments' => $retainedAttachments,
		);
	}

	private function upsertIndex( string $mediaId, int $postId, string $parentId, int $attachmentId, int $videoAttachmentId, int $position, string $type, string $hash, string $status, string $error ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'vemoro_instagram_media';
		$wpdb->replace(
			$table,
			array(
				'media_id'            => $mediaId,
				'post_id'             => $postId,
				'parent_media_id'     => $parentId,
				'attachment_id'       => $attachmentId,
				'video_attachment_id' => $videoAttachmentId,
				'position'            => $position,
				'media_type'          => $type,
				'data_hash'           => $hash,
				'file_status'         => $status,
				'last_error'          => $error,
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/** @return array{attachments:int,retained_attachments:int} */
	private function deleteOwnedAttachments( int $postId ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'vemoro_instagram_media';
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT attachment_id, video_attachment_id FROM %i WHERE post_id = %d', $table, $postId ), ARRAY_A );
		$ids   = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row['attachment_id'];
			$ids[] = (int) $row['video_attachment_id'];}
		$deleted  = 0;
		$retained = 0;
		foreach ( array_filter( array_unique( $ids ) ) as $id ) {
			if ( '1' !== get_post_meta( $id, '_vemoro_owned', true ) ) {
				continue; }
			if ( $this->attachmentReferencedOutside( $id, array( $postId ) ) ) {
				delete_post_meta( $id, '_vemoro_owned' );
				delete_post_meta( $id, '_vemoro_media_id' );
				delete_post_meta( $id, '_vemoro_source_host' );
				++$retained;
				continue;}
			if ( wp_delete_attachment( $id, true ) ) {
				++$deleted;}
		}
		$wpdb->delete( $table, array( 'post_id' => $postId ), array( '%d' ) );
		return array(
			'attachments'          => $deleted,
			'retained_attachments' => $retained,
		);
	}

	/** @param array<int,int> $pluginPostIds */
	private function attachmentReferencedOutside( int $attachmentId, array $pluginPostIds ): bool {
		$parentId = (int) wp_get_post_parent_id( $attachmentId );
		if ( $parentId > 0 && ! in_array( $parentId, $pluginPostIds, true ) ) {
			return true; }
		$references = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post__not_in'   => $pluginPostIds,
				'meta_key'       => '_thumbnail_id',
				'meta_value'     => (string) $attachmentId,
			)
		);
		if ( $references ) {
			return true; }
		$url = wp_get_attachment_url( $attachmentId );
		global $wpdb;
		$idMarker        = '%wp-image-' . $attachmentId . '%';
		$serializedId    = '%i:' . $attachmentId . ';%';
		$jsonId          = '%"attachment_id":' . $attachmentId . '%';
		$mediaKeyPattern = '(attachment|image|media|gallery|logo|icon|background|header|thumbnail)';
		$urlPattern      = is_string( $url ) && '' !== $url ? '%' . $wpdb->esc_like( $url ) . '%' : '__vemoro_no_url__';
		if ( is_string( $url ) && '' !== $url ) {
			$content_sql  = 'SELECT ID FROM %i WHERE (post_content LIKE %s OR post_content LIKE %s)';
			$content_args = array( $wpdb->posts, '%' . $wpdb->esc_like( $url ) . '%', $idMarker );
			if ( $pluginPostIds ) {
				$content_sql .= ' AND ID NOT IN (' . implode( ',', array_fill( 0, count( $pluginPostIds ), '%d' ) ) . ')';
				$content_args = array_merge( $content_args, array_map( 'intval', $pluginPostIds ) );
			}
			$content_sql .= ' LIMIT 1';
			if ( $wpdb->get_var( $wpdb->prepare( $content_sql, $content_args ) ) ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Query consists only of fixed SQL and generated placeholders; values are prepared.
				return true;
			}
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key NOT IN ('_wp_attachment_metadata','_wp_attachment_backup_sizes','_wp_attached_file') AND (meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s OR (meta_key REGEXP %s AND meta_value LIKE %s)) LIMIT 1", $attachmentId, (string) $attachmentId, $urlPattern, $jsonId, $mediaKeyPattern, $serializedId ) ) ) {
			return true; }
		if ( (int) get_option( 'site_icon' ) === $attachmentId || (int) get_theme_mod( 'custom_logo' ) === $attachmentId ) {
			return true; }
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND (option_value LIKE %s OR option_value LIKE %s OR (option_name REGEXP %s AND (option_value = %s OR option_value LIKE %s))) LIMIT 1", $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%', $urlPattern, $jsonId, $mediaKeyPattern, (string) $attachmentId, $serializedId ) ) ) {
			return true; }
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->termmeta} WHERE meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s OR (meta_key REGEXP %s AND meta_value LIKE %s) LIMIT 1", (string) $attachmentId, $urlPattern, $jsonId, $mediaKeyPattern, $serializedId ) ) ) {
			return true; }
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s OR (meta_key REGEXP %s AND meta_value LIKE %s) LIMIT 1", (string) $attachmentId, $urlPattern, $jsonId, $mediaKeyPattern, $serializedId ) );
	}
}
