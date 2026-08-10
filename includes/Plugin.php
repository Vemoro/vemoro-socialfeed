<?php
namespace Vemoro\SocialFeed;

use Vemoro\SocialFeed\Admin\AdminPage;
use Vemoro\SocialFeed\Admin\SupportNotice;
use Vemoro\SocialFeed\Api\InstagramApiClient;
use Vemoro\SocialFeed\Api\OAuthService;
use Vemoro\SocialFeed\Api\TokenService;
use Vemoro\SocialFeed\Cli\Commands;
use Vemoro\SocialFeed\Frontend\FeedRenderer;
use Vemoro\SocialFeed\Frontend\Integrations;
use Vemoro\SocialFeed\Repository\LogRepository;
use Vemoro\SocialFeed\Repository\PostRepository;
use Vemoro\SocialFeed\Security\SecretStore;
use Vemoro\SocialFeed\Sync\InstagramSyncService;
use Vemoro\SocialFeed\Sync\MediaDownloadService;
use Vemoro\SocialFeed\Sync\SyncLock;

final class Plugin {
	private static ?self $instance      = null;
	private ?FeedRenderer $renderer     = null;
	private ?InstagramSyncService $sync = null;
	private ?TokenService $tokens       = null;
	public static function instance(): self {
		return self::$instance ??= new self(); }
	private function __construct() {}

	public function boot(): void {
		if ( Config::DB_VERSION !== (string) get_option( Config::DB_VERSION_OPTION, '' ) ) {
			Activation::upgrade(); }
		add_action( 'admin_init', array( self::class, 'addPrivacyPolicyContent' ) );
		add_action(
			'init',
			static function (): void {
				self::registerPostType();
				Activation::registerRewriteRules();
			}
		);
		add_action(
			'after_setup_theme',
			static function (): void {
				add_image_size( 'vemoro-feed', 1080, 1080, false );
			}
		);
		CronManager::register();
		( new Integrations( $this->renderer() ) )->register();
		$services = $this->services();
		if ( is_admin() ) {
			( new AdminPage( new OAuthService(), $services['tokens'], $services['api'], $services['sync'], $services['posts'], $services['logs'], $services['secrets'] ) )->register();
			( new SupportNotice() )->register();
		}
		add_action( 'vemoro_refresh_token_retry', fn(): bool => $services['tokens']->refresh( true ) );
		add_action( 'updated_post_meta', array( $this, 'attachmentMetaChanged' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'attachmentMetaChanged' ), 10, 4 );
		add_action(
			'save_post_' . Config::POST_TYPE,
			static function (): void {
				FeedRenderer::clearCache();
			}
		);
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$commands = new Commands( $services['sync'], $services['tokens'] );
			\WP_CLI::add_command( 'vemoro-socialfeed', $commands );
		}
	}

	public static function addPrivacyPolicyContent(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<p>' . esc_html__( 'Vemoro SocialFeed synchronizes Instagram content server-side. Visitors do not connect to Meta when viewing the local feed. The website server communicates with Instagram during OAuth, synchronization and token maintenance. Posts, captions, metadata and media are stored in this WordPress installation. The Vemoro connection service briefly processes the callback address and OAuth security data; permanent Instagram tokens are stored only in WordPress. See the current service privacy information and terms before publishing this text.', 'vemoro-socialfeed' ) . '</p>';
		$content .= '<p><a href="https://vemoro.de/socialfeed/datenschutz/" rel="external noopener noreferrer">' . esc_html__( 'Vemoro SocialFeed privacy information', 'vemoro-socialfeed' ) . '</a> · <a href="https://vemoro.de/nutzungsbedingungen/" rel="external noopener noreferrer">' . esc_html__( 'Vemoro terms of service', 'vemoro-socialfeed' ) . '</a></p>';
		wp_add_privacy_policy_content( 'Vemoro SocialFeed for WP', wp_kses_post( wpautop( $content, false ) ) );
	}

	public static function registerPostType(): void {
		register_post_type(
			Config::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Synced Instagram posts', 'vemoro-socialfeed' ),
					'singular_name' => __( 'Synced Instagram post', 'vemoro-socialfeed' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}

	public function renderer(): FeedRenderer {
		return $this->renderer ??= new FeedRenderer( new PostRepository() ); }
	public function runCron(): void {
		$this->sync()->sync(); }
	public function attachmentMetaChanged( int $metaId, int $objectId, string $metaKey, mixed $value ): void {
		if ( '_wp_attachment_image_alt' === $metaKey && '1' === get_post_meta( $objectId, '_vemoro_owned', true ) ) {
			FeedRenderer::clearCache();} }
	private function sync(): InstagramSyncService {
		if ( ! $this->sync ) {
			$this->services();
		}return $this->sync; }
	/** @return array<string,mixed> */
	private function services(): array {
		$secrets   = new SecretStore();
		$logs      = new LogRepository();
		$posts     = new PostRepository();
		$tokens    = $this->tokens ??= new TokenService( $secrets, $logs );
		$api       = new InstagramApiClient( $tokens );
		$downloads = new MediaDownloadService( $posts );
		$sync      = $this->sync ??= new InstagramSyncService( $api, $tokens, $posts, $downloads, new SyncLock(), $logs );
		return array(
			'secrets' => $secrets,
			'logs'    => $logs,
			'posts'   => $posts,
			'tokens'  => $tokens,
			'api'     => $api,
			'sync'    => $sync,
		);
	}
}
