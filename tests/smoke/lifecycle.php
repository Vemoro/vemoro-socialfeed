<?php
// Run only in a disposable WordPress installation: wp eval-file tests/smoke/lifecycle.php prepare|verify.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$check = static function ( bool $passed, string $message ): void {
	if ( ! $passed ) {
		WP_CLI::error( $message );
	}
};

if ( 'prepare' === ( $args[0] ?? '' ) ) {
	$attachment = static function ( string $name, int $parent = 0 ): int {
		$upload = wp_upload_bits( $name . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1cAAAAASUVORK5CYII=' ) );
		$id = wp_insert_attachment( array( 'post_status' => 'inherit', 'post_mime_type' => 'image/png', 'post_title' => $name ), $upload['file'], $parent );
		update_attached_file( $id, $upload['file'] );
		wp_update_attachment_metadata( $id, array( 'width' => 1, 'height' => 1, 'file' => _wp_relative_upload_path( $upload['file'] ) ) );
		update_post_meta( $id, '_vemoro_owned', '1' );
		return $id;
	};
	$mapped = $attachment( 'socialfeed-mapped' );
	$media = Vemoro\SocialFeed\Domain\Media::fromArray( array( 'id' => 'lifecycle-test', 'media_type' => 'IMAGE', 'caption' => 'Local lifecycle smoke test', 'username' => 'test_account' ) );
	$saved = ( new Vemoro\SocialFeed\Repository\PostRepository() )->save( $media, $mapped );
	$pluginPost = $saved['post_id'];
	wp_update_post( array( 'ID' => $mapped, 'post_parent' => $pluginPost ) );
	$shared = $attachment( 'socialfeed-shared', $pluginPost );
	$orphan = $attachment( 'socialfeed-orphan' );
	$normalPost = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Shared picture' ) );
	update_post_meta( $normalPost, '_thumbnail_id', $shared );
	$trashed = wp_insert_post( array( 'post_type' => Vemoro\SocialFeed\Config::POST_TYPE, 'post_status' => 'trash', 'post_title' => 'Trashed feed post' ) );
	$trashedMedia = $attachment( 'socialfeed-trashed', $trashed );
	$fixture = array( 'mapped' => $mapped, 'shared' => $shared, 'orphan' => $orphan, 'normal' => $normalPost, 'plugin' => $pluginPost, 'trashed' => $trashed, 'trashed_media' => $trashedMedia );
	foreach ( array( 'mapped', 'shared', 'orphan', 'trashed_media' ) as $key ) {
		$fixture[ $key . '_file' ] = get_attached_file( $fixture[ $key ] );
	}
	update_option( 'socialfeed_smoke_fixture', $fixture, false );
	$settings = Vemoro\SocialFeed\Config::settings();
	$settings['delete_on_uninstall'] = true;
	update_option( Vemoro\SocialFeed\Config::OPTION, $settings, false );
	wp_set_current_user( 1 );
	$shortcode = do_shortcode( '[vemoro_socialfeed]' );
	$block = do_blocks( '<!-- wp:vemoro-socialfeed/feed /-->' );
	$check( str_contains( $shortcode, '<img' ) && str_contains( $shortcode, 'Local lifecycle smoke test' ), 'Shortcode did not render local media.' );
	$check( str_contains( $block, '<img' ) && str_contains( $block, 'vemoro-feed-block' ), 'Gutenberg block did not render.' );
	$check( ! preg_match( '/(?:src|srcset)="[^\"]*(?:instagram\.com|fbcdn\.net|cdninstagram\.com)/', $shortcode . $block ), 'Feed emitted remote Meta media.' );
	$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/vemoro-socialfeed/feed' );
	$request->set_param( 'context', 'edit' );
	$response = rest_do_request( $request );
	$check( 200 === $response->get_status(), 'Gutenberg REST preview returned an error.' );
	$check( str_contains( $response->get_data()['rendered'], 'Local lifecycle smoke test' ), 'Gutenberg REST preview missed the post.' );
	wp_schedule_single_event( time() + 900, 'vemoro_refresh_token_retry' );
	WP_CLI::success( 'Local images, shortcode, Gutenberg block and authenticated REST preview passed.' );
} elseif ( 'verify' === ( $args[0] ?? '' ) ) {
	$fixture = get_option( 'socialfeed_smoke_fixture' );
	$check( is_array( $fixture ), 'Lifecycle fixture is missing.' );
	foreach ( array( 'mapped', 'orphan', 'trashed_media', 'plugin', 'trashed' ) as $key ) {
		$check( null === get_post( $fixture[ $key ] ), 'Uninstall retained plugin data: ' . $key );
		if ( isset( $fixture[ $key . '_file' ] ) ) {
			$check( ! file_exists( $fixture[ $key . '_file' ] ), 'Uninstall retained a plugin media file: ' . $key );
		}
	}
	$check( null !== get_post( $fixture['shared'] ) && file_exists( $fixture['shared_file'] ), 'Uninstall deleted an externally used image.' );
	$check( '' === get_post_meta( $fixture['shared'], '_vemoro_owned', true ), 'Retained media still has plugin ownership.' );
	$check( null !== get_post( $fixture['normal'] ), 'Uninstall deleted an ordinary post.' );
	$check( ! wp_next_scheduled( 'vemoro_sync_instagram_feed' ) && ! wp_next_scheduled( 'vemoro_refresh_token_retry' ), 'Uninstall retained scheduled jobs.' );
	$check( false === get_option( 'vemoro_settings' ) && false === get_option( 'vemoro_sync_lock' ), 'Uninstall retained plugin options.' );
	global $wpdb;
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'vemoro_instagram_media' ) ) ), 'Uninstall retained the media index.' );
	WP_CLI::success( 'Uninstall removed owned and orphaned media, retained shared media, and cleared plugin data and jobs.' );
} else {
	WP_CLI::error( 'Choose prepare or verify.' );
}
