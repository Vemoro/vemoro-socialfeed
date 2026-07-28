<?php
namespace LocalInstagramFeed;

use LocalInstagramFeed\Admin\AdminPage;
use LocalInstagramFeed\Admin\SupportNotice;
use LocalInstagramFeed\Api\InstagramApiClient;
use LocalInstagramFeed\Api\OAuthService;
use LocalInstagramFeed\Api\TokenService;
use LocalInstagramFeed\Cli\Commands;
use LocalInstagramFeed\Frontend\FeedRenderer;
use LocalInstagramFeed\Frontend\Integrations;
use LocalInstagramFeed\Repository\LogRepository;
use LocalInstagramFeed\Repository\PostRepository;
use LocalInstagramFeed\Security\SecretStore;
use LocalInstagramFeed\Sync\InstagramSyncService;
use LocalInstagramFeed\Sync\MediaDownloadService;
use LocalInstagramFeed\Sync\SyncLock;

final class Plugin {
	private static ?self $instance = null;
	private ?FeedRenderer $renderer = null;
	private ?InstagramSyncService $sync = null;
	private ?TokenService $tokens = null;
	public static function instance(): self { return self::$instance ??= new self(); }
	private function __construct() {}

	public function boot(): void {
		if (Config::DB_VERSION !== (string) get_option(Config::DB_VERSION_OPTION, '')) { Activation::activate(); }
		load_plugin_textdomain('local-instagram-feed', false, dirname(plugin_basename(LIF_PLUGIN_FILE)) . '/languages');
		add_action('init', array(self::class, 'registerPostType'));
		add_action('after_setup_theme', static function(): void { add_image_size('lif-feed', 1080, 1080, false); });
		CronManager::register(); (new Integrations($this->renderer()))->register();
		$services = $this->services();
		if (is_admin()) {
			(new AdminPage(new OAuthService(), $services['tokens'], $services['api'], $services['sync'], $services['posts'], $services['logs'], $services['secrets']))->register();
			(new SupportNotice())->register();
		}
		add_action('lif_refresh_token_retry', fn(): bool => $services['tokens']->refresh(true));
		add_action('updated_post_meta', array($this, 'attachmentMetaChanged'), 10, 4); add_action('added_post_meta', array($this, 'attachmentMetaChanged'), 10, 4);
		add_action('save_post_' . Config::POST_TYPE, static function(): void { FeedRenderer::clearCache(); });
		if (defined('WP_CLI') && WP_CLI) {
			$commands = new Commands($services['sync'], $services['tokens']);
			\WP_CLI::add_command('vemoro-socialfeed', $commands);
			\WP_CLI::add_command('local-instagram-feed', $commands);
		}
	}

	public static function registerPostType(): void {
		register_post_type(
			Config::POST_TYPE,
			array(
				'labels' => array(
					'name'          => __('Synced Instagram posts', 'local-instagram-feed'),
					'singular_name' => __('Synced Instagram post', 'local-instagram-feed'),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'supports'        => array('title', 'editor', 'thumbnail'),
				'capability_type' => 'post',
				'capabilities'    => array('create_posts' => 'do_not_allow'),
				'map_meta_cap'    => true,
			)
		);
	}

	public function renderer(): FeedRenderer { return $this->renderer ??= new FeedRenderer(new PostRepository()); }
	public function runCron(): void { $this->sync()->sync(); }
	public function attachmentMetaChanged(int $metaId,int $objectId,string $metaKey,mixed $value): void { if('_wp_attachment_image_alt'===$metaKey && '1'===get_post_meta($objectId,'_lif_owned',true)){FeedRenderer::clearCache();} }
	private function sync(): InstagramSyncService { if(!$this->sync){$this->services();}return $this->sync; }
	/** @return array<string,mixed> */
	private function services(): array {
		$secrets=new SecretStore();$logs=new LogRepository();$posts=new PostRepository();$tokens=$this->tokens??=new TokenService($secrets,$logs);$api=new InstagramApiClient($tokens);$downloads=new MediaDownloadService($posts);$sync=$this->sync??=new InstagramSyncService($api,$tokens,$posts,$downloads,new SyncLock(),$logs);
		return array('secrets'=>$secrets,'logs'=>$logs,'posts'=>$posts,'tokens'=>$tokens,'api'=>$api,'sync'=>$sync);
	}
}
