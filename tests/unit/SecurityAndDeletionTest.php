<?php
use LocalInstagramFeed\Api\OAuthService;
use LocalInstagramFeed\Config;
use LocalInstagramFeed\Frontend\FeedRenderer;
use LocalInstagramFeed\Repository\PostRepository;

final class SecurityAndDeletionTest extends WP_UnitTestCase {
	public function test_oauth_state_is_one_time_and_user_bound(): void {
		$state='0123456789abcdef0123456789abcdef';set_transient('lif_oauth_state_1',hash('sha256',$state),600);$oauth=new OAuthService();
		$this->assertTrue($oauth->hasPendingState(1));$this->assertFalse($oauth->hasPendingState(2));
		$this->assertFalse($oauth->validateState(2,$state));$this->assertTrue($oauth->validateState(1,$state));$this->assertFalse($oauth->hasPendingState(1));$this->assertFalse($oauth->validateState(1,$state));
	}
	public function test_oauth_redirect_uri_has_no_query_or_fragment(): void {
		$old=get_option(Config::OPTION,array());$settings=Config::defaults();$settings['redirect_uri']=admin_url('admin.php?page=local-instagram-feed&lif_action=oauth_callback#fragment');update_option(Config::OPTION,$settings,false);
		$this->assertSame(admin_url('admin.php'),Config::redirectUri());
		update_option(Config::OPTION,$old,false);
	}
	public function test_removed_post_is_only_deactivated_after_three_complete_observations(): void {
		$post=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish'));update_post_meta($post,'_lif_media_id','missing');update_post_meta($post,'_lif_timestamp','2026-01-01T00:00:00+0000');update_post_meta($post,'_lif_status','active');$repo=new PostRepository();
		$this->assertSame(0,$repo->markMissing(array('different'),'1970-01-01T00:00:00+0000','inactive'));$this->assertSame('publish',get_post_status($post));
		$this->assertSame(0,$repo->markMissing(array('different'),'1970-01-01T00:00:00+0000','inactive'));$this->assertSame(1,$repo->markMissing(array('different'),'1970-01-01T00:00:00+0000','inactive'));
		$this->assertSame('draft',get_post_status($post));$this->assertSame('removed',get_post_meta($post,'_lif_status',true));
	}
	public function test_delete_all_removes_plugin_data_but_retains_media_referenced_elsewhere(): void {
		$pluginPost=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish'));
		$deletable=wp_insert_attachment(array('post_title'=>'Plugin media','post_status'=>'inherit','post_mime_type'=>'image/jpeg','post_parent'=>$pluginPost));update_post_meta($deletable,'_lif_owned','1');
		$retained=wp_insert_attachment(array('post_title'=>'Shared media','post_status'=>'inherit','post_mime_type'=>'image/jpeg','post_parent'=>$pluginPost));update_post_meta($retained,'_lif_owned','1');
		$normalPost=self::factory()->post->create(array('post_status'=>'publish'));update_post_meta($normalPost,'_thumbnail_id',$retained);
		$result=(new PostRepository())->deleteAll();
		$this->assertSame(1,$result['posts']);$this->assertSame(1,$result['attachments']);$this->assertSame(1,$result['retained_attachments']);
		$this->assertNull(get_post($pluginPost));$this->assertNull(get_post($deletable));$this->assertNotNull(get_post($retained));$this->assertSame('',get_post_meta($retained,'_lif_owned',true));
		wp_delete_post($normalPost,true);wp_delete_attachment($retained,true);
	}
	public function test_excess_post_is_only_pruned_after_retention_period(): void {
		$new=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish','post_date'=>'2026-02-01 12:00:00'));$old=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish','post_date'=>'2026-01-01 12:00:00'));
		foreach(array($new,$old) as $postId){update_post_meta($postId,'_lif_status','active');update_post_meta($postId,'_lif_display_enabled','1');}
		$repo=new PostRepository();$first=$repo->pruneExcess(1,7);$this->assertSame(0,$first['posts']);$this->assertGreaterThan(0,(int)get_post_meta($old,'_lif_excess_since',true));
		update_post_meta($old,'_lif_excess_since',time()-(8*DAY_IN_SECONDS));$second=$repo->pruneExcess(1,7);$this->assertSame(1,$second['posts']);$this->assertNull(get_post($old));$this->assertNotNull(get_post($new));
	}
	public function test_display_disabled_post_is_not_rendered(): void {
		$post=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish'));update_post_meta($post,'_lif_status','active');update_post_meta($post,'_lif_display_enabled','0');
		$html=(new FeedRenderer(new PostRepository()))->render(array('post_id'=>$post));
		$this->assertStringContainsString('lif-feed-empty',$html);
	}
	public function test_caption_is_truncated_and_escaped(): void {
		$post=self::factory()->post->create(array('post_type'=>Config::POST_TYPE,'post_status'=>'publish'));foreach(array('_lif_media_id'=>'escape','_lif_media_type'=>'IMAGE','_lif_status'=>'active','_lif_caption'=>'<script>alert(1)</script> plus text','_lif_timestamp'=>'2026-01-01T00:00:00+0000') as $key=>$value){update_post_meta($post,$key,$value);}
		$html=(new FeedRenderer(new PostRepository()))->render(array('post_id'=>$post,'caption_length'=>18,'show_caption'=>true));$this->assertStringNotContainsString('<script>',$html);$this->assertStringContainsString('&lt;script&gt;', $html);$this->assertStringContainsString('…',$html);
	}
	public function test_cache_version_changes_on_invalidation(): void { $before=(int)get_option('lif_cache_version',1);FeedRenderer::clearCache();$this->assertSame($before+1,(int)get_option('lif_cache_version')); }
}
