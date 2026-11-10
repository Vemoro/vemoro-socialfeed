<?php
use Vemoro\SocialFeed\Api\OAuthService;
use Vemoro\SocialFeed\Admin\SupportNotice;
use Vemoro\SocialFeed\Config;
use Vemoro\SocialFeed\Frontend\FeedRenderer;
use Vemoro\SocialFeed\Repository\PostRepository;

final class SecurityAndDeletionTest extends WP_UnitTestCase {
	public function test_delete_all_does_not_mistake_its_own_mapping_for_an_external_reference(): void {
		$attachment = wp_insert_attachment( array( 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $attachment, '_vemoro_owned', '1' );
		$media = Vemoro\SocialFeed\Domain\Media::fromArray( array( 'id' => 'self-reference-test', 'media_type' => 'IMAGE' ) );
		$posts = new PostRepository();
		$saved = $posts->save( $media, $attachment );
		wp_update_post( array( 'ID' => $attachment, 'post_parent' => $saved['post_id'] ) );
		$result = $posts->deleteAll();
		$this->assertSame( 1, $result['attachments'] );
		$this->assertSame( 0, $result['retained_attachments'] );
		$this->assertNull( get_post( $attachment ) );
	}
	public function test_user_level_is_not_mistaken_for_a_media_reference(): void {
		global $wpdb;
		$attachment = wp_insert_attachment( array( 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $attachment, '_vemoro_owned', '1' );
		$user = self::factory()->user->create();
		update_user_meta( $user, $wpdb->prefix . 'user_level', $attachment );
		$result = ( new PostRepository() )->cleanupOrphanedOwnedMedia();
		$this->assertSame( 1, $result['deleted'] );
		$this->assertNull( get_post( $attachment ) );
	}
	public function test_oauth_state_is_one_time_and_user_bound(): void {
		$state='0123456789abcdef0123456789abcdef';set_transient('vemoro_oauth_state_1',hash('sha256',$state),600);$oauth=new OAuthService();
		$this->assertTrue($oauth->hasPendingState(1));$this->assertFalse($oauth->hasPendingState(2));
		$this->assertFalse($oauth->validateState(2,$state));$this->assertTrue($oauth->validateState(1,$state));$this->assertFalse($oauth->hasPendingState(1));$this->assertFalse($oauth->validateState(1,$state));
	}
	public function test_oauth_redirect_uri_has_no_query_or_fragment(): void {
		$old=get_option(Config::OPTION,array());$settings=Config::defaults();$settings['redirect_uri']=admin_url('admin.php?page=vemoro-socialfeed&vemoro_action=oauth_callback#fragment');update_option(Config::OPTION,$settings,false);
		$this->assertSame(admin_url('admin.php'),Config::redirectUri());
		update_option(Config::OPTION,$old,false);
	}
	public function test_hosted_oauth_ignores_a_legacy_configured_admin_page(): void {
		$old=get_option(Config::OPTION,array());$settings=Config::defaults();$settings['oauth_provider']='vemoro';$settings['redirect_uri']=admin_url('admin.php?page=vemoro-socialfeed');update_option(Config::OPTION,$settings,false);
		$this->assertSame(admin_url('admin.php'),Config::redirectUri());
		update_option(Config::OPTION,$old,false);
	}
	public function test_support_notice_is_limited_to_plugin_admin_screens_and_dismissible(): void {
		$subscriber=self::factory()->user->create(array('role'=>'subscriber'));wp_set_current_user($subscriber);set_current_screen('toplevel_page_vemoro-socialfeed');$notice=new SupportNotice();
		ob_start();$notice->render();$subscriberHtml=(string)ob_get_clean();$this->assertSame('',$subscriberHtml);
		$administrator=self::factory()->user->create(array('role'=>'administrator'));wp_set_current_user($administrator);
		ob_start();$notice->render();$html=(string)ob_get_clean();
		$this->assertStringContainsString(Config::LIBERAPAY_URL,$html);$this->assertStringContainsString(Config::GITHUB_SPONSORS_URL,$html);$this->assertStringContainsString('mailto:'.Config::SUPPORT_EMAIL,$html);$this->assertStringNotContainsString('<script',$html);$this->assertStringNotContainsString('<img',$html);
		set_current_screen('dashboard');ob_start();$notice->render();$dashboardHtml=(string)ob_get_clean();$this->assertSame('',$dashboardHtml);
		set_current_screen('toplevel_page_vemoro-socialfeed');update_user_meta($administrator,'vemoro_support_notice_dismissed','1');ob_start();$notice->render();$dismissed=(string)ob_get_clean();$this->assertSame('',$dismissed);
		delete_user_meta($administrator,'vemoro_support_notice_dismissed');
	}
	public function test_removed_post_is_only_deactivated_after_three_complete_observations(): void {
		$post=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish'));update_post_meta($post,'_vemoro_media_id','missing');update_post_meta($post,'_vemoro_timestamp','2026-01-01T00:00:00+0000');update_post_meta($post,'_vemoro_status','active');$repo=new PostRepository();
		$this->assertSame(0,$repo->markMissing(array('different'),'1970-01-01T00:00:00+0000','inactive'));$this->assertSame('publish',get_post_status($post));
		$this->assertSame(0,$repo->markMissing(array('different'),'1970-01-01T00:00:00+0000','inactive'));$this->assertSame(1,$repo->markMissing(array('different'),'1970-01-01T00:00:00+0000','inactive'));
		$this->assertSame('draft',get_post_status($post));$this->assertSame('removed',get_post_meta($post,'_vemoro_status',true));
	}
	public function test_missing_post_can_have_an_additional_grace_period(): void {
		$post=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish'));update_post_meta($post,'_vemoro_media_id','grace');update_post_meta($post,'_vemoro_timestamp','2026-01-01T00:00:00+0000');update_post_meta($post,'_vemoro_status','active');$repo=new PostRepository();
		$repo->markMissing(array('different'),'1970-01-01T00:00:00+0000','inactive',48);$repo->markMissing(array('different'),'1970-01-01T00:00:00+0000','inactive',48);
		$this->assertSame(0,$repo->markMissing(array('different'),'1970-01-01T00:00:00+0000','inactive',48));$this->assertSame('publish',get_post_status($post));
		update_post_meta($post,'_vemoro_missing_since',time()-(49*HOUR_IN_SECONDS));$this->assertSame(1,$repo->markMissing(array('different'),'1970-01-01T00:00:00+0000','inactive',48));$this->assertSame('draft',get_post_status($post));
	}
	public function test_delete_all_removes_plugin_data_but_retains_media_referenced_elsewhere(): void {
		$pluginPost=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish'));
		$deletable=wp_insert_attachment(array('post_title'=>'Plugin media','post_status'=>'inherit','post_mime_type'=>'image/jpeg','post_parent'=>$pluginPost));update_post_meta($deletable,'_vemoro_owned','1');
		$retained=wp_insert_attachment(array('post_title'=>'Shared media','post_status'=>'inherit','post_mime_type'=>'image/jpeg','post_parent'=>$pluginPost));update_post_meta($retained,'_vemoro_owned','1');
		$normalPost=self::factory()->post->create(array('post_status'=>'publish'));update_post_meta($normalPost,'_thumbnail_id',$retained);
		$result=(new PostRepository())->deleteAll();
		$this->assertSame(1,$result['posts']);$this->assertSame(1,$result['attachments']);$this->assertSame(1,$result['retained_attachments']);
		$this->assertNull(get_post($pluginPost));$this->assertNull(get_post($deletable));$this->assertNotNull(get_post($retained));$this->assertSame('',get_post_meta($retained,'_vemoro_owned',true));
		wp_delete_post($normalPost,true);wp_delete_attachment($retained,true);
	}
	public function test_orphan_cleanup_excludes_mapped_media_and_retains_external_references(): void {
		global $wpdb;
		$pluginPost=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish'));
		$mapped=wp_insert_attachment(array('post_title'=>'Mapped media','post_status'=>'inherit','post_mime_type'=>'image/jpeg','post_parent'=>$pluginPost));update_post_meta($mapped,'_vemoro_owned','1');
		$orphan=wp_insert_attachment(array('post_title'=>'Orphan media','post_status'=>'inherit','post_mime_type'=>'image/jpeg','post_parent'=>$pluginPost));update_post_meta($orphan,'_vemoro_owned','1');
		$dimensionCollision=wp_insert_attachment(array('post_title'=>'Dimension collision','post_status'=>'inherit','post_mime_type'=>'image/jpeg','post_parent'=>$pluginPost));update_post_meta($dimensionCollision,'_vemoro_owned','1');
		$shared=wp_insert_attachment(array('post_title'=>'Shared orphan media','post_status'=>'inherit','post_mime_type'=>'image/jpeg','post_parent'=>$pluginPost));update_post_meta($shared,'_vemoro_owned','1');
		$unrelatedAttachment=wp_insert_attachment(array('post_title'=>'Unrelated media','post_status'=>'inherit','post_mime_type'=>'image/jpeg'));update_post_meta($unrelatedAttachment,'_wp_attachment_metadata',array('width'=>$dimensionCollision,'height'=>1024));
		$wpdb->insert($wpdb->prefix.'vemoro_instagram_media',array('media_id'=>'mapped-test','post_id'=>$pluginPost,'attachment_id'=>$mapped,'updated_at'=>current_time('mysql',true)),array('%s','%d','%d','%s'));
		$normalPost=self::factory()->post->create(array('post_status'=>'publish'));update_post_meta($normalPost,'_thumbnail_id',$shared);
		$repo=new PostRepository();$summary=$repo->orphanedOwnedMediaSummary();$this->assertSame(3,$summary['candidates']);
		$result=$repo->cleanupOrphanedOwnedMedia();
		$this->assertSame(3,$result['candidates']);$this->assertSame(2,$result['deleted']);$this->assertSame(1,$result['retained']);$this->assertSame(0,$result['failed']);
		$this->assertNotNull(get_post($mapped));$this->assertNull(get_post($orphan));$this->assertNull(get_post($dimensionCollision));$this->assertNotNull(get_post($shared));$this->assertSame('',get_post_meta($shared,'_vemoro_owned',true));
		$wpdb->delete($wpdb->prefix.'vemoro_instagram_media',array('media_id'=>'mapped-test'),array('%s'));wp_delete_post($normalPost,true);wp_delete_attachment($unrelatedAttachment,true);wp_delete_attachment($shared,true);wp_delete_attachment($mapped,true);wp_delete_post($pluginPost,true);
	}
	public function test_excess_post_is_only_pruned_after_retention_period(): void {
		$new=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish','post_date'=>'2026-02-01 12:00:00'));$old=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish','post_date'=>'2026-01-01 12:00:00'));
		foreach(array($new,$old) as $postId){update_post_meta($postId,'_vemoro_status','active');update_post_meta($postId,'_vemoro_display_enabled','1');}
		$repo=new PostRepository();$first=$repo->pruneExcess(1,7);$this->assertSame(0,$first['posts']);$this->assertGreaterThan(0,(int)get_post_meta($old,'_vemoro_excess_since',true));
		update_post_meta($old,'_vemoro_excess_since',time()-(8*DAY_IN_SECONDS));$second=$repo->pruneExcess(1,7);$this->assertSame(1,$second['posts']);$this->assertNull(get_post($old));$this->assertNotNull(get_post($new));
	}
	public function test_cleanup_recovers_unmarked_imports_only_for_an_indexed_plugin_parent(): void {
		$repo = new PostRepository();
		$media = Vemoro\SocialFeed\Domain\Media::fromArray( array( 'id' => '18425766025154764', 'media_type' => 'IMAGE' ) );
		$mapped = wp_insert_attachment( array( 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $mapped, '_wp_attached_file', '2026/11/instagram-17955753504272253-31.jpg' );
		$saved = $repo->save( $media, $mapped );
		$parent = $saved['post_id'];
		wp_update_post( array( 'ID' => $mapped, 'post_parent' => $parent ) );
		$create = static function ( string $filename, int $parentId ) : int {
			$id = wp_insert_attachment( array( 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg', 'post_parent' => $parentId ) );
			update_post_meta( $id, '_wp_attached_file', '2026/11/' . $filename );
			return $id;
		};
		$interrupted = $create( 'instagram-17955753504272253-30.jpg', $parent );
		$scaled = $create( 'instagram-17955753504272253-29-scaled.jpg', $parent );
		$shared = $create( 'instagram-17955753504272253-28-scaled.jpg', $parent );
		$outside = self::factory()->post->create( array( 'post_content' => '<img class="wp-image-' . $shared . '">' ) );
		$ordinary = $create( 'ordinary-photo.jpg', $parent );
		$unattached = $create( 'instagram-17955753504272253-27.jpg', 0 );
		$otherParent = $create( 'instagram-17955753504272253-26.jpg', $outside );
		$unindexedParent = self::factory()->post->create( array( 'post_type' => Config::POST_TYPE ) );
		update_post_meta( $unindexedParent, '_vemoro_media_id', '12345' );
		$unindexed = $create( 'instagram-17955753504272253-25.jpg', $unindexedParent );
		$released = $create( 'instagram-17955753504272253-24.jpg', $parent );
		update_post_meta( $released, '_vemoro_owned', '0' );
		$this->assertSame( 3, $repo->orphanedOwnedMediaSummary()['candidates'] );
		$this->assertSame( '', get_post_meta( $interrupted, '_vemoro_owned', true ) );
		$result = $repo->cleanupOrphanedOwnedMedia();
		$this->assertSame( 2, $result['deleted'] );
		$this->assertSame( 1, $result['retained'] );
		$this->assertNull( get_post( $interrupted ) );
		$this->assertNull( get_post( $scaled ) );
		foreach ( array( $mapped, $shared, $ordinary, $unattached, $otherParent, $unindexed, $released ) as $id ) {
			$this->assertNotNull( get_post( $id ) );
		}
		$this->assertSame( '1', get_post_meta( $shared, '_vemoro_retained', true ) );
		$this->assertSame( 0, $repo->orphanedOwnedMediaSummary()['candidates'] );
	}

	public function test_cleanup_retains_media_used_through_original_or_resized_urls(): void {
		$repo = new PostRepository();
		$attachments = array();
		$posts = array();
		foreach ( array( 'original_image', 'sizes', 'backup' ) as $variant ) {
			$id = wp_insert_attachment( array( 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' ) );
			$attachments[] = $id;
			update_post_meta( $id, '_vemoro_owned', '1' );
			update_post_meta( $id, '_wp_attached_file', '2026/11/instagram-' . $id . '-scaled.jpg' );
			$filename = 'instagram-' . $id . '-400x534.jpg';
			if ( 'original_image' === $variant ) {
				wp_update_attachment_metadata( $id, array( 'original_image' => $filename ) );
			} elseif ( 'sizes' === $variant ) {
				wp_update_attachment_metadata( $id, array( 'sizes' => array( 'medium' => array( 'file' => $filename ) ) ) );
			} else {
				update_post_meta( $id, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => $filename ) ) );
			}
			$url = str_replace( wp_basename( wp_get_attachment_url( $id ) ), $filename, wp_get_attachment_url( $id ) );
			$posts[] = self::factory()->post->create( array( 'post_content' => '<img src="' . esc_url( $url ) . '">' ) );
		}
		$result = $repo->cleanupOrphanedOwnedMedia();
		$this->assertSame( 0, $result['deleted'] );
		$this->assertSame( 3, $result['retained'] );
		foreach ( $attachments as $id ) {
			$this->assertNotNull( get_post( $id ) );
			$this->assertSame( '1', get_post_meta( $id, '_vemoro_retained', true ) );
		}
	}

	public function test_display_disabled_post_is_not_rendered(): void {
		$post=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish'));update_post_meta($post,'_vemoro_status','active');update_post_meta($post,'_vemoro_display_enabled','0');
		$html=(new FeedRenderer(new PostRepository()))->render(array('post_id'=>$post));
		$this->assertStringContainsString('vemoro-feed-empty',$html);
	}
	public function test_confirmed_missing_legacy_post_is_not_rendered(): void {
		$post=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish'));update_post_meta($post,'_vemoro_status','active');update_post_meta($post,'_vemoro_exists','0');
		$html=(new FeedRenderer(new PostRepository()))->render(array('post_id'=>$post));
		$this->assertStringContainsString('vemoro-feed-empty',$html);
	}
	public function test_caption_is_fully_available_for_expansion_and_escaped(): void {
		$post=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish'));foreach(array('_vemoro_media_id'=>'escape','_vemoro_media_type'=>'IMAGE','_vemoro_status'=>'active','_vemoro_caption'=>'<script>alert(1)</script> plus text','_vemoro_timestamp'=>'2026-01-01T00:00:00+0000') as $key=>$value){update_post_meta($post,$key,$value);}
		$html=(new FeedRenderer(new PostRepository()))->render(array('post_id'=>$post,'caption_length'=>18,'show_caption'=>true));$this->assertStringNotContainsString('<script>',$html);$this->assertStringContainsString('&lt;script&gt;', $html);$this->assertStringContainsString('plus text',$html);$this->assertStringContainsString('data-vemoro-caption-toggle',$html);
	}
	public function test_cache_version_changes_on_invalidation(): void { $before=(int)get_option('vemoro_cache_version',1);FeedRenderer::clearCache();$this->assertSame($before+1,(int)get_option('vemoro_cache_version')); }
}
