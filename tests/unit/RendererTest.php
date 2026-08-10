<?php
use Vemoro\SocialFeed\Frontend\FeedRenderer;
use Vemoro\SocialFeed\Repository\PostRepository;

final class RendererTest extends WP_UnitTestCase {
	public function test_normalizes_shortcode_attributes(): void { $renderer=new FeedRenderer(new PostRepository());$args=$renderer->normalize(array('posts'=>'999','columns'=>'0','show_caption'=>'false','order'=>'invalid','class'=>'safe-class<script>'));$this->assertSame(100,$args['posts']);$this->assertSame(1,$args['columns']);$this->assertFalse($args['show_caption']);$this->assertSame('DESC',$args['order']);$this->assertSame('safe-classscript',$args['class']); }
	public function test_empty_feed_is_escaped_local_markup(): void { $renderer=new FeedRenderer(new PostRepository());$html=$renderer->render();$this->assertStringContainsString('vemoro-feed-empty',$html);$this->assertStringNotContainsString('instagram.com/embed',$html); }
}
