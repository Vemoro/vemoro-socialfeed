<?php
use LocalInstagramFeed\Domain\Media;

final class MediaTest extends WP_UnitTestCase {
	public function test_maps_image_and_missing_optional_fields(): void { $media=Media::fromArray(array('id'=>'1','media_type'=>'IMAGE'));$this->assertSame('IMAGE',$media->mediaType);$this->assertSame('',$media->caption); }
	public function test_maps_carousel_children_and_hash_ignores_temporary_urls(): void { $a=Media::fromArray(array('id'=>'1','media_type'=>'CAROUSEL_ALBUM','children'=>array('data'=>array(array('id'=>'2','media_type'=>'IMAGE','media_url'=>'https://x.example/a')))));$b=Media::fromArray(array('id'=>'1','media_type'=>'CAROUSEL_ALBUM','children'=>array('data'=>array(array('id'=>'2','media_type'=>'IMAGE','media_url'=>'https://x.example/b')))));$this->assertCount(1,$a->children);$this->assertSame($a->semanticHash(),$b->semanticHash()); }
	public function test_maps_public_counts_and_includes_them_in_hash(): void { $a=Media::fromArray(array('id'=>'1','media_type'=>'IMAGE','like_count'=>12,'comments_count'=>3));$b=Media::fromArray(array('id'=>'1','media_type'=>'IMAGE','like_count'=>13,'comments_count'=>3));$this->assertSame(12,$a->likeCount);$this->assertSame(3,$a->commentsCount);$this->assertNotSame($a->semanticHash(),$b->semanticHash()); }
	public function test_detects_foreign_account_and_known_repost_product_types(): void { $own=Media::fromArray(array('id'=>'1','media_type'=>'IMAGE','username'=>'own_account'));$foreign=Media::fromArray(array('id'=>'2','media_type'=>'IMAGE','username'=>'another_account'));$repost=Media::fromArray(array('id'=>'3','media_type'=>'IMAGE','username'=>'own_account','media_product_type'=>'REPOST'));$this->assertFalse($own->isRepost('own_account'));$this->assertTrue($foreign->isRepost('own_account'));$this->assertTrue($repost->isRepost('own_account')); }
	public function test_requires_id_and_media_type(): void { $this->expectException(InvalidArgumentException::class);Media::fromArray(array('id'=>'1')); }
}
