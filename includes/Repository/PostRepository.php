<?php
namespace LocalInstagramFeed\Repository;

use LocalInstagramFeed\Config;
use LocalInstagramFeed\Domain\Media;

final class PostRepository {
	/** @return array<string,mixed>|null */
	public function index(string $mediaId): ?array {
		global $wpdb; $table = $wpdb->prefix . 'lif_instagram_media';
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE media_id = %s", $mediaId), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	public function findPostId(string $mediaId): int {
		global $wpdb; $table = $wpdb->prefix . 'lif_instagram_media';
		return (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$table} WHERE media_id = %s AND parent_media_id = ''", $mediaId));
	}

	public function save(Media $media, int $attachmentId, int $videoAttachmentId = 0): array {
		$postId = $this->findPostId($media->id);
		$data = array(
			'post_type' => Config::POST_TYPE, 'post_status' => 'publish',
			'post_title' => wp_trim_words($media->caption ?: sprintf(__('Instagram post %s', 'local-instagram-feed'), $media->id), 12, '…'),
			'post_content' => $media->caption,
			'post_date_gmt' => $media->timestamp ? gmdate('Y-m-d H:i:s', strtotime($media->timestamp)) : current_time('mysql', true),
		);
		if ($postId) { $data['ID'] = $postId; $result = wp_update_post(wp_slash($data), true); }
		else { $result = wp_insert_post(wp_slash($data), true); }
		if (is_wp_error($result)) { throw new \RuntimeException($result->get_error_message()); }
		$postId = (int) $result;
		$hash = $media->semanticHash();
		$meta = array(
			'_lif_media_id' => $media->id, '_lif_media_type' => $media->mediaType, '_lif_product_type' => $media->productType,
			'_lif_username' => $media->username, '_lif_caption' => $media->caption, '_lif_timestamp' => $media->timestamp,
			'_lif_permalink' => $media->permalink, '_lif_synced_at' => current_time('mysql', true), '_lif_last_success' => current_time('mysql', true),
			'_lif_hash' => $hash, '_lif_status' => 'active', '_lif_exists' => '1', '_lif_missing_count' => 0, '_lif_removed_handled' => '0',
			'_lif_attachment_id' => $attachmentId, '_lif_video_attachment_id' => $videoAttachmentId, '_lif_display_enabled' => '1',
			'_lif_like_count' => $media->likeCount, '_lif_comments_count' => $media->commentsCount,
		);
		foreach ($meta as $key => $value) { update_post_meta($postId, $key, $value); }
		if ($attachmentId) { set_post_thumbnail($postId, $attachmentId); }
		$this->upsertIndex($media->id, $postId, '', $attachmentId, $videoAttachmentId, 0, $media->mediaType, $hash, 'ok', '');
		return array('post_id' => $postId, 'created' => ! isset($data['ID']));
	}

	public function touch(int $postId): void {
		update_post_meta($postId, '_lif_last_success', current_time('mysql', true));
		update_post_meta($postId, '_lif_missing_count', 0);
		update_post_meta($postId, '_lif_exists', '1');
		update_post_meta($postId, '_lif_display_enabled', '1');
	}

	public function setDisplayEnabled(string $mediaId, bool $enabled): void {
		$postId = $this->findPostId($mediaId);
		if ($postId > 0) { update_post_meta($postId, '_lif_display_enabled', $enabled ? '1' : '0'); }
	}

	public function saveChild(int $postId, string $parentId, Media $media, int $attachmentId, int $position, string $error = ''): void {
		$this->upsertIndex($media->id, $postId, $parentId, $attachmentId, 0, $position, $media->mediaType, $media->semanticHash(), $error ? 'error' : 'ok', $error);
	}

	/** @return array<int,object> */
	public function children(string $parentId): array {
		global $wpdb; $table = $wpdb->prefix . 'lif_instagram_media';
		return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE parent_media_id = %s ORDER BY position ASC", $parentId));
	}

	/** @param array<int,string> $seen */
	public function markMissing(array $seen, string $oldestTimestamp, string $behavior): int {
		if (! $seen || ! $oldestTimestamp) { return 0; }
		$query = new \WP_Query(array('post_type' => Config::POST_TYPE, 'post_status' => array('publish', 'draft', 'trash'), 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array(array('key' => '_lif_timestamp', 'value' => $oldestTimestamp, 'compare' => '>='))));
		$removed = 0;
		foreach ($query->posts as $postId) {
			$id = (string) get_post_meta($postId, '_lif_media_id', true);
			if (in_array($id, $seen, true) || '1' === get_post_meta($postId, '_lif_removed_handled', true)) { continue; }
			$count = (int) get_post_meta($postId, '_lif_missing_count', true) + 1;
			update_post_meta($postId, '_lif_missing_count', $count);
			if ($count < 3) { continue; }
			update_post_meta($postId, '_lif_exists', '0'); update_post_meta($postId, '_lif_removed_handled', '1');
			if ('keep' === $behavior) { update_post_meta($postId, '_lif_status', 'active'); }
			else { update_post_meta($postId, '_lif_status', 'removed'); }
			if ('trash' === $behavior) { wp_trash_post((int) $postId); }
			elseif ('delete' === $behavior) { $this->deleteOwnedAttachments((int) $postId); wp_delete_post((int) $postId, true); }
			elseif ('inactive' === $behavior) { wp_update_post(array('ID' => (int) $postId, 'post_status' => 'draft')); }
			++$removed;
		}
		return $removed;
	}

	public function count(): int {
		$counts = wp_count_posts(Config::POST_TYPE); return (int) ($counts->publish ?? 0) + (int) ($counts->draft ?? 0);
	}

	/** @return array{posts:int,attachments:int,retained_attachments:int} */
	public function pruneExcess(int $limit, int $retentionDays): array {
		$allPostIds=get_posts(array('post_type'=>Config::POST_TYPE,'post_status'=>array('publish','draft','trash'),'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'date','order'=>'DESC','suppress_filters'=>true));
		$limit=max(1,$limit);$keptPostIds=get_posts(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish','posts_per_page'=>$limit,'fields'=>'ids','orderby'=>'date','order'=>'DESC','suppress_filters'=>true,'meta_query'=>array('relation'=>'AND',array('key'=>'_lif_status','value'=>'active'),array('relation'=>'OR',array('key'=>'_lif_display_enabled','compare'=>'NOT EXISTS'),array('key'=>'_lif_display_enabled','value'=>'1')))));
		$allPostIds=array_values(array_map('intval',$allPostIds));$keptPostIds=array_values(array_map('intval',$keptPostIds));
		foreach($keptPostIds as $postId){delete_post_meta($postId,'_lif_excess_since');}
		$candidates=array_values(array_diff($allPostIds,$keptPostIds));$result=array('posts'=>0,'attachments'=>0,'retained_attachments'=>0);
		if($retentionDays<0){foreach($candidates as $postId){delete_post_meta($postId,'_lif_excess_since');}return $result;}
		$now=time();$cutoff=$now-($retentionDays*DAY_IN_SECONDS);
		foreach($candidates as $postId){
			$since=(int)get_post_meta($postId,'_lif_excess_since',true);
			if($since<=0){$since=$now;update_post_meta($postId,'_lif_excess_since',$since);if($retentionDays>0){continue;}}
			if($retentionDays>0&&$since>$cutoff){continue;}
			$attachments=$this->deleteOwnedAttachments($postId);
			if(wp_delete_post($postId,true)){++$result['posts'];$result['attachments']+=$attachments['attachments'];$result['retained_attachments']+=$attachments['retained_attachments'];}
		}
		return $result;
	}

	/** @return array{posts:int,attachments:int,retained_attachments:int} */
	public function deleteAll(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'lif_instagram_media';
		$postIds = get_posts(array('post_type'=>Config::POST_TYPE,'post_status'=>array('publish','draft','trash'),'posts_per_page'=>-1,'fields'=>'ids'));
		$postIds = array_values(array_unique(array_map('intval', $postIds)));
		$attachmentIds = get_posts(array('post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'_lif_owned','meta_value'=>'1'));
		$attachmentIds = array_values(array_unique(array_map('intval', $attachmentIds)));
		$deletedAttachments = 0; $retainedAttachments = 0;
		foreach ($attachmentIds as $attachmentId) {
			if ($this->attachmentReferencedOutside($attachmentId, $postIds)) {
				delete_post_meta($attachmentId, '_lif_owned'); delete_post_meta($attachmentId, '_lif_media_id'); delete_post_meta($attachmentId, '_lif_source_host');
				++$retainedAttachments; continue;
			}
			if (wp_delete_attachment($attachmentId, true)) { ++$deletedAttachments; }
		}
		$deletedPosts = 0;
		foreach ($postIds as $postId) { if (wp_delete_post($postId, true)) { ++$deletedPosts; } }
		$wpdb->query("DELETE FROM {$table}");
		return array('posts'=>$deletedPosts,'attachments'=>$deletedAttachments,'retained_attachments'=>$retainedAttachments);
	}

	private function upsertIndex(string $mediaId, int $postId, string $parentId, int $attachmentId, int $videoAttachmentId, int $position, string $type, string $hash, string $status, string $error): void {
		global $wpdb; $table = $wpdb->prefix . 'lif_instagram_media';
		$wpdb->replace($table, array('media_id' => $mediaId, 'post_id' => $postId, 'parent_media_id' => $parentId, 'attachment_id' => $attachmentId, 'video_attachment_id' => $videoAttachmentId, 'position' => $position, 'media_type' => $type, 'data_hash' => $hash, 'file_status' => $status, 'last_error' => $error, 'updated_at' => current_time('mysql', true)), array('%s','%d','%s','%d','%d','%d','%s','%s','%s','%s','%s'));
	}

	/** @return array{attachments:int,retained_attachments:int} */
	private function deleteOwnedAttachments(int $postId): array {
		global $wpdb; $table = $wpdb->prefix . 'lif_instagram_media';
		$rows=$wpdb->get_results($wpdb->prepare("SELECT attachment_id,video_attachment_id FROM {$table} WHERE post_id = %d",$postId),ARRAY_A);$ids=array();
		foreach($rows as $row){$ids[]=(int)$row['attachment_id'];$ids[]=(int)$row['video_attachment_id'];}
		$deleted=0;$retained=0;
		foreach (array_filter(array_unique($ids)) as $id) {
			if ('1' !== get_post_meta($id, '_lif_owned', true)) { continue; }
			if($this->attachmentReferencedOutside($id,array($postId))){delete_post_meta($id,'_lif_owned');delete_post_meta($id,'_lif_media_id');delete_post_meta($id,'_lif_source_host');++$retained;continue;}
			if(wp_delete_attachment($id,true)){++$deleted;}
		}
		$wpdb->delete($table, array('post_id' => $postId), array('%d'));
		return array('attachments'=>$deleted,'retained_attachments'=>$retained);
	}

	/** @param array<int,int> $pluginPostIds */
	private function attachmentReferencedOutside(int $attachmentId, array $pluginPostIds): bool {
		$parentId = (int) wp_get_post_parent_id($attachmentId);
		if ($parentId > 0 && ! in_array($parentId, $pluginPostIds, true)) { return true; }
		$references = get_posts(array('post_type'=>'any','post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','post__not_in'=>$pluginPostIds,'meta_key'=>'_thumbnail_id','meta_value'=>(string)$attachmentId));
		if ($references) { return true; }
		$url = wp_get_attachment_url($attachmentId);
		if (! is_string($url) || '' === $url) { return false; }
		global $wpdb;
		$where = $pluginPostIds ? ' AND ID NOT IN (' . implode(',', array_map('intval', $pluginPostIds)) . ')' : '';
		return (bool) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s{$where} LIMIT 1", '%' . $wpdb->esc_like($url) . '%'));
	}
}
