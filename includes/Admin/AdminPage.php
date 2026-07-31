<?php
namespace LocalInstagramFeed\Admin;

use LocalInstagramFeed\Api\InstagramApiClient;
use LocalInstagramFeed\Api\OAuthService;
use LocalInstagramFeed\Api\TokenService;
use LocalInstagramFeed\Config;
use LocalInstagramFeed\CronManager;
use LocalInstagramFeed\Frontend\FeedRenderer;
use LocalInstagramFeed\Repository\LogRepository;
use LocalInstagramFeed\Repository\PostRepository;
use LocalInstagramFeed\Security\SecretStore;
use LocalInstagramFeed\Sync\InstagramSyncService;
use LocalInstagramFeed\Sync\SyncLock;

final class AdminPage {
	public function __construct( private readonly OAuthService $oauth, private readonly TokenService $tokens, private readonly InstagramApiClient $api, private readonly InstagramSyncService $sync, private readonly PostRepository $posts, private readonly LogRepository $logs, private readonly SecretStore $secrets ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_init', array( $this, 'callback' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_lif_connect', array( $this, 'connect' ) );
		add_action( 'admin_post_lif_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_lif_refresh_token', array( $this, 'refresh' ) );
		add_action( 'admin_post_lif_check_connection', array( $this, 'check' ) );
		add_action( 'admin_post_lif_cleanup_orphaned_media', array( $this, 'cleanupOrphanedMedia' ) );
		add_action( 'admin_post_lif_delete_all_posts', array( $this, 'deleteAllPosts' ) );
		add_action( 'wp_ajax_lif_sync', array( $this, 'ajaxSync' ) );
		add_action( 'wp_ajax_lif_sync_progress', array( $this, 'ajaxProgress' ) );
		add_action( 'wp_ajax_lif_clear_cache', array( $this, 'ajaxClearCache' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'update_option_' . Config::OPTION, array( $this, 'settingsUpdated' ), 10, 2 );
	}

	/** @param mixed $oldValue @param mixed $newValue */
	public function settingsUpdated( mixed $oldValue, mixed $newValue ): void {
		$old         = is_array( $oldValue ) ? $oldValue : array();
		$new         = is_array( $newValue ) ? $newValue : array();
		$displayKeys = array( 'post_limit', 'caption_length', 'columns', 'columns_tablet', 'columns_mobile', 'aspect_ratio', 'show_link', 'new_tab', 'show_reels', 'show_carousels', 'mirror_videos', 'video_autoplay', 'show_caption', 'show_date', 'show_username', 'show_metrics', 'local_detail' );
		foreach ( $displayKeys as $key ) {
			if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
				update_option( Config::REFRESH_GENERATION_OPTION, (int) get_option( Config::REFRESH_GENERATION_OPTION, 0 ) + 1, false );
				break; }
		}
		FeedRenderer::clearCache();
		CronManager::reschedule();
	}

	public function menu(): void {
		add_menu_page( __( 'Vemoro SocialFeed for WP', 'vemoro-socialfeed' ), __( 'Vemoro SocialFeed', 'vemoro-socialfeed' ), 'manage_options', 'vemoro-socialfeed', array( $this, 'render' ), 'dashicons-instagram', 81 );
		add_submenu_page( 'vemoro-socialfeed', __( 'Vemoro SocialFeed settings', 'vemoro-socialfeed' ), __( 'Settings', 'vemoro-socialfeed' ), 'manage_options', 'vemoro-socialfeed', array( $this, 'render' ) );
		add_submenu_page( 'vemoro-socialfeed', __( 'Synced Instagram posts', 'vemoro-socialfeed' ), __( 'Synced posts', 'vemoro-socialfeed' ), 'manage_options', 'edit.php?post_type=' . Config::POST_TYPE );
	}

	public function settings(): void {
		register_setting(
			'lif_settings_group',
			Config::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitizeSettings' ),
				'default'           => Config::defaults(),
			)
		);
	}

	/** @param mixed $input @return array<string,mixed> */
	public function sanitizeSettings( mixed $input ): array {
		$old                   = Config::settings();
		$in                    = is_array( $input ) ? $input : array();
		$out                   = Config::defaults();
		$out['oauth_provider'] = 'custom' === (string) ( $in['oauth_provider'] ?? '' ) ? 'custom' : 'vemoro';
		$out['connect_url']    = Config::DEFAULT_CONNECT_URL;
		$out['app_id']         = defined( 'LIF_INSTAGRAM_APP_ID' ) ? '' : preg_replace( '/\D+/', '', (string) ( $in['app_id'] ?? '' ) );
		if ( ! defined( 'LIF_INSTAGRAM_APP_SECRET' ) && ! empty( $in['app_secret'] ) ) {
			if ( ! $this->secrets->store( 'app_secret', (string) $in['app_secret'] ) ) {
				add_settings_error( Config::OPTION, 'secret', __( 'The App Secret could not be encrypted. Define it in wp-config.php.', 'vemoro-socialfeed' ) ); }
		}
		$out['app_secret']            = '';
		$redirect                     = (string) preg_replace( '/[?#].*$/', '', esc_url_raw( (string) ( $in['redirect_uri'] ?? '' ) ) );
		$out['redirect_uri']          = $this->validRedirect( $redirect ) ? $redirect : '';
		$out['api_version']           = preg_match( '/^v\d+\.\d+$/', (string) ( $in['api_version'] ?? '' ) ) ? (string) $in['api_version'] : Config::DEFAULT_API_VERSION;
		$out['post_limit']            = max( 1, min( 100, (int) ( $in['post_limit'] ?? 12 ) ) );
		$out['caption_length']        = max( 0, min( 5000, (int) ( $in['caption_length'] ?? 300 ) ) );
		$out['sync_interval']         = in_array( (string) ( $in['sync_interval'] ?? '' ), array( 'lif_15_minutes', 'lif_30_minutes', 'hourly', 'lif_two_hours', 'lif_six_hours', 'daily' ), true ) ? (string) $in['sync_interval'] : 'lif_two_hours';
		$out['deleted_behavior']      = in_array( (string) ( $in['deleted_behavior'] ?? '' ), array( 'inactive', 'trash', 'delete' ), true ) ? (string) $in['deleted_behavior'] : 'inactive';
		$out['missing_grace_hours']   = in_array( (int) ( $in['missing_grace_hours'] ?? 0 ), array( 0, 12, 24, 48 ), true ) ? (int) $in['missing_grace_hours'] : 0;
		$out['excess_retention_days'] = in_array( (int) ( $in['excess_retention_days'] ?? 30 ), array( -1, 0, 7, 30, 90, 180, 365 ), true ) ? (int) $in['excess_retention_days'] : 30;
		foreach ( array( 'show_link', 'new_tab', 'show_reels', 'show_carousels', 'mirror_videos', 'video_autoplay', 'show_caption', 'show_date', 'show_username', 'show_metrics', 'local_detail', 'debug', 'delete_on_uninstall' ) as $key ) {
			$out[ $key ] = ! empty( $in[ $key ] ); }
		$out['columns']        = max( 1, min( 6, (int) ( $in['columns'] ?? 3 ) ) );
		$out['columns_tablet'] = max( 1, min( 6, (int) ( $in['columns_tablet'] ?? 2 ) ) );
		$out['columns_mobile'] = max( 1, min( 4, (int) ( $in['columns_mobile'] ?? 1 ) ) );
		$out['aspect_ratio']   = in_array( (string) ( $in['aspect_ratio'] ?? '' ), array( '9/16', '1/1', '4/5', '16/9', 'auto' ), true ) ? (string) $in['aspect_ratio'] : '9/16';
		$out['image_max_mb']   = max( 1, min( 100, (int) ( $in['image_max_mb'] ?? 15 ) ) );
		$out['video_max_mb']   = max( 1, min( 1000, (int) ( $in['video_max_mb'] ?? 100 ) ) );
		$out['max_api_pages']  = max( 1, min( 50, (int) ( $in['max_api_pages'] ?? 10 ) ) );
		$out['cache_ttl']      = max( 60, min( DAY_IN_SECONDS, (int) ( $in['cache_ttl'] ?? 7200 ) ) );
		$out['log_limit']      = max( 10, min( 5000, (int) ( $in['log_limit'] ?? 500 ) ) );
		return $out;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		} $tab = sanitize_key( Request::query( 'tab' ) ?: 'connection' );
		echo '<div class="wrap lif-admin"><div class="lif-brand"><img src="' . esc_url( LIF_PLUGIN_URL . 'assets/images/vemoro-logo.svg' ) . '" alt="Vemoro"><h1>' . esc_html__( 'SocialFeed for WP', 'vemoro-socialfeed' ) . '</h1></div><nav class="nav-tab-wrapper">';
		$tabs = array(
			'connection'  => __( 'Connection', 'vemoro-socialfeed' ),
			'sync'        => __( 'Synchronization', 'vemoro-socialfeed' ),
			'display'     => __( 'Display', 'vemoro-socialfeed' ),
			'privacy'     => __( 'Privacy', 'vemoro-socialfeed' ),
			'diagnostics' => __( 'Diagnostics', 'vemoro-socialfeed' ),
			'logs'        => __( 'Logs', 'vemoro-socialfeed' ),
		);
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=vemoro-socialfeed&tab=' . $key ) ) . '">' . esc_html( $label ) . '</a>';}
		echo '</nav>';
		settings_errors( Config::OPTION );
		if ( 'connection' === $tab ) {
			$this->connection();
		} elseif ( 'sync' === $tab ) {
			$this->syncTab();
		} elseif ( 'display' === $tab ) {
			$this->settingsForm();
		} elseif ( 'privacy' === $tab ) {
			$this->privacy();
		} elseif ( 'diagnostics' === $tab ) {
			$this->diagnostics();
		} else {
			$this->logs(); }
		SupportNotice::renderSupportCard();
		echo '</div>';
	}

	private function connection(): void {
		$meta   = (array) get_option( Config::TOKEN_OPTION, array() );
		$status = (array) get_option( Config::STATUS_OPTION, array() );
		echo '<div class="lif-card"><h2>' . esc_html__( 'Connection status', 'vemoro-socialfeed' ) . '</h2><p><strong>' . ( $this->tokens->isConnected() ? esc_html__( 'Connected', 'vemoro-socialfeed' ) : esc_html__( 'Not connected', 'vemoro-socialfeed' ) ) . '</strong></p>';
		if ( $this->tokens->isConnected() ) {
			echo '<p>' . esc_html__( 'Account ID:', 'vemoro-socialfeed' ) . ' ' . esc_html( $this->mask( $this->tokens->userId() ) ) . '<br>' . esc_html__( 'Token expires:', 'vemoro-socialfeed' ) . ' ' . esc_html( wp_date( 'Y-m-d H:i', (int) $this->tokens->expiresAt() ) ) . '</p>';}
		if ( ! empty( $status['last_error'] ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Last connection or synchronization error:', 'vemoro-socialfeed' ) . '</strong> ' . esc_html( (string) $status['last_error'] ) . ' ' . esc_html__( 'The local data was retained. You can reconnect the Instagram account without deleting it.', 'vemoro-socialfeed' ) . '</p></div>';}
		echo '<p>' . esc_html__( 'Redirect URI:', 'vemoro-socialfeed' ) . ' <code>' . esc_html( Config::redirectUri() ) . '</code></p><div class="lif-actions">';
		$connect_label = $this->tokens->isConnected() ? __( 'Reconnect with Instagram', 'vemoro-socialfeed' ) : __( 'Connect with Instagram', 'vemoro-socialfeed' );
		if ( ! $this->tokens->isConnected() ) {
			$buttons = $this->oauth->isHosted() ? $this->hostedConnectForm( $connect_label ) : $this->actionButton( 'lif_connect', $connect_label, 'primary' );
		} else {
			$buttons  = $this->oauth->isHosted() ? $this->hostedConnectForm( $connect_label ) : $this->actionButton( 'lif_connect', $connect_label, 'primary' );
			$buttons .= $this->actionButton( 'lif_check_connection', __( 'Check connection', 'vemoro-socialfeed' ) );
			$buttons .= $this->actionButton( 'lif_refresh_token', __( 'Refresh token', 'vemoro-socialfeed' ) );
		}
		echo wp_kses(
			$buttons,
			array(
				'a' => array(
					'class' => true,
					'href'  => true,
					'rel'   => true,
					'target' => true,
				),
				'form' => array(
					'action' => true,
					'class'  => true,
					'method' => true,
				),
				'input' => array(
					'name'     => true,
					'required' => true,
					'type'     => true,
					'value'    => true,
				),
				'label'  => array(),
				'p'      => array( 'class' => true ),
				'button' => array(
					'class' => true,
					'type'  => true,
				),
			)
		) . '</div>';
		if ( $this->tokens->isConnected() ) {
			echo '<hr><h3>' . esc_html__( 'Permanently disconnect', 'vemoro-socialfeed' ) . '</h3><p>' . esc_html__( 'Temporary API or token errors do not delete data. Reconnect the account where possible. If access has been permanently revoked, disconnecting deletes all Instagram Platform Data stored by the plugin. Media created by the plugin are deleted only when they are not referenced elsewhere in WordPress.', 'vemoro-socialfeed' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lif_disconnect">';
			wp_nonce_field( 'lif_disconnect' );
			echo '<p><label><input type="checkbox" name="confirm_disconnect" value="1" required> ' . esc_html__( 'I understand that the Instagram connection and all synchronized Platform Data will be permanently deleted.', 'vemoro-socialfeed' ) . '</label></p><p><button type="submit" class="button lif-delete-button">' . esc_html__( 'Disconnect and delete Instagram data', 'vemoro-socialfeed' ) . '</button></p></form>';}
		echo '</div>';
		$this->credentialsForm();
	}

	private function credentialsForm(): void {
		$s = Config::settings();
		echo '<form method="post" action="options.php" class="lif-card"><h2>' . esc_html__( 'Instagram connection method', 'vemoro-socialfeed' ) . '</h2>';
		settings_fields( 'lif_settings_group' );
		echo '<p><label><input type="radio" name="' . esc_attr( Config::OPTION ) . '[oauth_provider]" value="vemoro" ' . checked( 'vemoro', $s['oauth_provider'], false ) . '> <strong>' . esc_html__( 'Vemoro Login (recommended)', 'vemoro-socialfeed' ) . '</strong></label><br><span class="description">' . esc_html__( 'No Meta App ID or App Secret is required in WordPress. The login is handled by the Vemoro connection service.', 'vemoro-socialfeed' ) . '</span></p>';
		echo '<p><label><input type="radio" name="' . esc_attr( Config::OPTION ) . '[oauth_provider]" value="custom" ' . checked( 'custom', $s['oauth_provider'], false ) . '> <strong>' . esc_html__( 'Own Meta app (expert mode)', 'vemoro-socialfeed' ) . '</strong></label><br><span class="description">' . esc_html__( 'Use your own Meta app and callback configuration.', 'vemoro-socialfeed' ) . '</span></p>';
		echo '<h3>' . esc_html__( 'Expert-mode credentials', 'vemoro-socialfeed' ) . '</h3>';
		echo '<table class="form-table"><tr><th><label for="lif-app-id">' . esc_html__( 'Meta App ID', 'vemoro-socialfeed' ) . '</label></th><td><input id="lif-app-id" name="' . esc_attr( Config::OPTION ) . '[app_id]" value="' . esc_attr( defined( 'LIF_INSTAGRAM_APP_ID' ) ? '' : $s['app_id'] ) . '" class="regular-text" ' . ( defined( 'LIF_INSTAGRAM_APP_ID' ) ? 'disabled' : '' ) . '></td></tr>';
		echo '<tr><th><label for="lif-secret">' . esc_html__( 'Meta App Secret', 'vemoro-socialfeed' ) . '</label></th><td><input id="lif-secret" type="password" autocomplete="new-password" name="' . esc_attr( Config::OPTION ) . '[app_secret]" value="" class="regular-text" ' . ( defined( 'LIF_INSTAGRAM_APP_SECRET' ) ? 'disabled' : '' ) . '><p class="description">' . esc_html__( 'Stored encrypted; leave blank to keep the current value. wp-config.php constants take precedence.', 'vemoro-socialfeed' ) . '</p></td></tr>';
		echo '<tr><th><label for="lif-redirect">' . esc_html__( 'Redirect URI', 'vemoro-socialfeed' ) . '</label></th><td><input id="lif-redirect" name="' . esc_attr( Config::OPTION ) . '[redirect_uri]" value="' . esc_attr( Config::redirectUri() ) . '" class="large-text"><p class="description">' . esc_html__( 'Enter this URI in Meta exactly as displayed. OAuth callback URIs must not contain query parameters.', 'vemoro-socialfeed' ) . '</p></td></tr><tr><th><label for="lif-version">' . esc_html__( 'API version', 'vemoro-socialfeed' ) . '</label></th><td><input id="lif-version" name="' . esc_attr( Config::OPTION ) . '[api_version]" value="' . esc_attr( $s['api_version'] ) . '"></td></tr></table>';
		foreach ( Config::settings() as $key => $value ) {
			if ( ! in_array( $key, array( 'oauth_provider', 'connect_url', 'app_id', 'app_secret', 'redirect_uri', 'api_version' ), true ) ) {
				echo '<input type="hidden" name="' . esc_attr( Config::OPTION ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( is_bool( $value ) ? ( $value ? '1' : '0' ) : $value ) . '">';}
		}
		submit_button();
		echo '</form>';
	}

	private function syncTab(): void {
		$status  = (array) get_option( Config::STATUS_OPTION, array() );
		$pending = (int) get_option( Config::REFRESH_GENERATION_OPTION, 0 ) > (int) get_option( Config::APPLIED_REFRESH_GENERATION_OPTION, 0 );
		$next    = CronManager::nextScheduled();
		echo '<div class="lif-card"><h2>' . esc_html__( 'Synchronization', 'vemoro-socialfeed' ) . '</h2><p>' . esc_html__( 'Last successful run:', 'vemoro-socialfeed' ) . ' ' . esc_html( ! empty( $status['last_success'] ) ? wp_date( 'Y-m-d H:i:s', (int) $status['last_success'] ) : __( 'Never', 'vemoro-socialfeed' ) ) . '<br>' . esc_html__( 'Next scheduled run:', 'vemoro-socialfeed' ) . ' ' . esc_html( $next ? wp_date( 'Y-m-d H:i:s', $next ) : __( 'Not scheduled', 'vemoro-socialfeed' ) ) . '<br>' . esc_html__( 'Local records:', 'vemoro-socialfeed' ) . ' ' . (int) $this->posts->count() . '<br>' . esc_html__( 'Full refresh pending:', 'vemoro-socialfeed' ) . ' ' . esc_html( $pending ? __( 'Yes', 'vemoro-socialfeed' ) : __( 'No', 'vemoro-socialfeed' ) ) . '</p><button class="button button-primary" id="lif-sync-now">' . esc_html__( 'Synchronize now', 'vemoro-socialfeed' ) . '</button><div id="lif-sync-progress" class="lif-progress" hidden><span></span></div><pre id="lif-sync-result"></pre></div>'; }

	private function settingsForm(): void {
		$s = Config::settings();
		echo '<form method="post" action="options.php" class="lif-card"><h2>' . esc_html__( 'Display and operation', 'vemoro-socialfeed' ) . '</h2>';
		settings_fields( 'lif_settings_group' );
		echo '<input type="hidden" name="' . esc_attr( Config::OPTION ) . '[oauth_provider]" value="' . esc_attr( $s['oauth_provider'] ) . '"><input type="hidden" name="' . esc_attr( Config::OPTION ) . '[connect_url]" value="' . esc_attr( $s['connect_url'] ) . '"><input type="hidden" name="' . esc_attr( Config::OPTION ) . '[app_id]" value="' . esc_attr( $s['app_id'] ) . '"><input type="hidden" name="' . esc_attr( Config::OPTION ) . '[redirect_uri]" value="' . esc_attr( $s['redirect_uri'] ) . '"><input type="hidden" name="' . esc_attr( Config::OPTION ) . '[api_version]" value="' . esc_attr( $s['api_version'] ) . '">';
		$numbers = array(
			'post_limit'     => __( 'Posts to synchronize', 'vemoro-socialfeed' ),
			'caption_length' => __( 'Maximum caption length', 'vemoro-socialfeed' ),
			'columns'        => __( 'Desktop columns', 'vemoro-socialfeed' ),
			'columns_tablet' => __( 'Tablet columns', 'vemoro-socialfeed' ),
			'columns_mobile' => __( 'Mobile columns', 'vemoro-socialfeed' ),
			'image_max_mb'   => __( 'Image limit (MB)', 'vemoro-socialfeed' ),
			'video_max_mb'   => __( 'Video limit (MB)', 'vemoro-socialfeed' ),
			'max_api_pages'  => __( 'Maximum API pages', 'vemoro-socialfeed' ),
			'log_limit'      => __( 'Maximum log entries', 'vemoro-socialfeed' ),
		);
		echo '<table class="form-table">';
		foreach ( $numbers as $key => $label ) {
			echo '<tr><th><label for="lif-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" id="lif-' . esc_attr( $key ) . '" name="' . esc_attr( Config::OPTION ) . '[' . esc_attr( $key ) . ']" value="' . (int) $s[ $key ] . '"></td></tr>';}
		$select_rows = '<tr><th>' . esc_html__( 'Synchronization interval', 'vemoro-socialfeed' ) . '</th><td>' . $this->select(
			'sync_interval',
			$s['sync_interval'],
			array(
				'lif_15_minutes' => __( '15 minutes', 'vemoro-socialfeed' ),
				'lif_30_minutes' => __( '30 minutes', 'vemoro-socialfeed' ),
				'hourly'         => __( 'Hourly', 'vemoro-socialfeed' ),
				'lif_two_hours'  => __( '2 hours', 'vemoro-socialfeed' ),
				'lif_six_hours'  => __( '6 hours', 'vemoro-socialfeed' ),
				'daily'          => __( 'Daily', 'vemoro-socialfeed' ),
			)
		) . '</td></tr><tr><th>' . esc_html__( 'Delete posts exceeding the limit', 'vemoro-socialfeed' ) . '</th><td>' . $this->select(
			'excess_retention_days',
			$s['excess_retention_days'],
			array(
				'-1'  => __( 'Keep indefinitely', 'vemoro-socialfeed' ),
				'0'   => __( 'Immediately after a successful synchronization', 'vemoro-socialfeed' ),
				'7'   => __( 'After 7 days', 'vemoro-socialfeed' ),
				'30'  => __( 'After 30 days', 'vemoro-socialfeed' ),
				'90'  => __( 'After 90 days', 'vemoro-socialfeed' ),
				'180' => __( 'After 180 days', 'vemoro-socialfeed' ),
				'365' => __( 'After 365 days', 'vemoro-socialfeed' ),
			)
		) . '<p class="description">' . esc_html__( 'The newest configured number of posts is retained. The period starts when an older post first falls outside that limit. Deletion only runs after a complete, error-free synchronization.', 'vemoro-socialfeed' ) . '</p></td></tr><tr><th>' . esc_html__( 'Grace period for missing posts', 'vemoro-socialfeed' ) . '</th><td>' . $this->select(
			'missing_grace_hours',
			$s['missing_grace_hours'],
			array(
				'0'  => __( 'No additional grace period', 'vemoro-socialfeed' ),
				'12' => __( '12 hours', 'vemoro-socialfeed' ),
				'24' => __( '24 hours', 'vemoro-socialfeed' ),
				'48' => __( '48 hours', 'vemoro-socialfeed' ),
			)
		) . '<p class="description">' . esc_html__( 'A post is removed from the public feed only after three complete authoritative synchronizations and, if selected, after this additional period has elapsed.', 'vemoro-socialfeed' ) . '</p></td></tr><tr><th>' . esc_html__( 'Removed posts', 'vemoro-socialfeed' ) . '</th><td>' . $this->select(
			'deleted_behavior',
			$s['deleted_behavior'],
			array(
				'inactive' => __( 'Retain locally, but hide from the feed', 'vemoro-socialfeed' ),
				'trash'    => __( 'Move to trash', 'vemoro-socialfeed' ),
				'delete'   => __( 'Delete permanently', 'vemoro-socialfeed' ),
			)
		) . '<p class="description">' . esc_html__( 'Confirmed removed posts never remain publicly visible.', 'vemoro-socialfeed' ) . '</p></td></tr><tr><th>' . esc_html__( 'Aspect ratio', 'vemoro-socialfeed' ) . '</th><td>' . $this->select(
			'aspect_ratio',
			$s['aspect_ratio'],
			array(
				'9/16' => '9:16 ' . __( '(Reel format)', 'vemoro-socialfeed' ),
				'1/1'  => '1:1',
				'4/5'  => '4:5',
				'16/9' => '16:9',
				'auto' => __( 'Auto', 'vemoro-socialfeed' ),
			)
		) . '</td></tr>';
		echo wp_kses( $select_rows, $this->settingsFormHtml() );
		$checks = array(
			'show_caption'        => __( 'Show captions', 'vemoro-socialfeed' ),
			'show_date'           => __( 'Show dates', 'vemoro-socialfeed' ),
			'show_username'       => __( 'Show username', 'vemoro-socialfeed' ),
			'show_metrics'        => __( 'Show likes and comment counts', 'vemoro-socialfeed' ),
			'show_link'           => __( 'Enable external Instagram links', 'vemoro-socialfeed' ),
			'new_tab'             => __( 'Open external links in a new tab', 'vemoro-socialfeed' ),
			'show_reels'          => __( 'Synchronize Reels', 'vemoro-socialfeed' ),
			'show_carousels'      => __( 'Synchronize carousels', 'vemoro-socialfeed' ),
			'mirror_videos'       => __( 'Mirror videos locally', 'vemoro-socialfeed' ),
			'video_autoplay'      => __( 'Play local videos on hover', 'vemoro-socialfeed' ),
			'local_detail'        => __( 'Enable local detail pages', 'vemoro-socialfeed' ),
			'debug'               => __( 'Enable debug logs', 'vemoro-socialfeed' ),
			'delete_on_uninstall' => __( 'Delete all plugin data on uninstall', 'vemoro-socialfeed' ),
		);
		foreach ( $checks as $key => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( Config::OPTION ) . '[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $s[ $key ] ), true, false ) . '> ' . esc_html__( 'Enabled', 'vemoro-socialfeed' ) . '</label></td></tr>';}
		echo '</table>';
		submit_button();
		echo '</form>'; }

	private function privacy(): void {
		echo '<div class="lif-card"><h2>' . esc_html__( 'Privacy by design', 'vemoro-socialfeed' ) . '</h2><ul><li>✓ ' . esc_html__( 'External Meta scripts: no', 'vemoro-socialfeed' ) . '</li><li>✓ ' . esc_html__( 'Instagram iframes: no', 'vemoro-socialfeed' ) . '</li><li>✓ ' . esc_html__( 'Meta CDN images in the frontend: no', 'vemoro-socialfeed' ) . '</li><li>✓ ' . esc_html__( 'Browser API calls: no', 'vemoro-socialfeed' ) . '</li><li>✓ ' . esc_html__( 'Local media: yes', 'vemoro-socialfeed' ) . '</li><li>' . esc_html__( 'External Instagram links:', 'vemoro-socialfeed' ) . ' ' . ( ! empty( Config::settings()['show_link'] ) ? esc_html__( 'enabled', 'vemoro-socialfeed' ) : esc_html__( 'disabled', 'vemoro-socialfeed' ) ) . '</li></ul><p>' . esc_html__( 'Visitors do not connect to Meta when a page is loaded. The website operator’s server communicates with the Instagram API only during OAuth, synchronization and token maintenance.', 'vemoro-socialfeed' ) . '</p><p>' . esc_html__( 'Temporary API or token errors do not trigger deletion. Administrators can reconnect the account. If access is permanently revoked, use “Disconnect and delete Instagram data” to remove all API-derived Platform Data; plugin-owned media referenced elsewhere in WordPress are retained.', 'vemoro-socialfeed' ) . '</p><p>' . esc_html__( 'Add an appropriate description to your privacy policy. This technical information is not legal advice.', 'vemoro-socialfeed' ) . '</p></div>';
		$orphans = $this->posts->orphanedOwnedMediaSummary();
		/* translators: 1: number of media files, 2: maximum total file size. */
		echo '<div class="lif-card"><h2>' . esc_html__( 'Clean up orphaned media', 'vemoro-socialfeed' ) . '</h2><p>' . esc_html__( 'Finds media created by this plugin that are no longer assigned to the current Instagram data. Files used elsewhere in WordPress are retained and released from plugin ownership.', 'vemoro-socialfeed' ) . '</p><p><strong>' . esc_html( sprintf( __( 'Unassigned plugin media: %1$d (up to %2$s)', 'vemoro-socialfeed' ), $orphans['candidates'], size_format( $orphans['bytes'], 2 ) ) ) . '</strong></p>';
		if ( $orphans['candidates'] > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lif_cleanup_orphaned_media">';
			wp_nonce_field( 'lif_cleanup_orphaned_media' );
			echo '<p><label><input type="checkbox" name="confirm_cleanup" value="1" required> ' . esc_html__( 'I understand that safely identified orphaned media files will be permanently deleted.', 'vemoro-socialfeed' ) . '</label></p><p><button type="submit" class="button button-secondary">' . esc_html__( 'Clean up orphaned media', 'vemoro-socialfeed' ) . '</button></p></form>'; } else {
			echo '<p>' . esc_html__( 'No orphaned plugin media were found.', 'vemoro-socialfeed' ) . '</p>'; }
			echo '</div>';
			/* translators: %d is the number of currently synchronized local records. */
			echo '<div class="lif-card lif-danger"><h2>' . esc_html__( 'Delete all synchronized posts', 'vemoro-socialfeed' ) . '</h2><p>' . esc_html__( 'Permanently deletes all locally synchronized Instagram posts, plugin mappings and unreferenced media created by this plugin. The Instagram connection and display settings are retained, so a new synchronization can start immediately.', 'vemoro-socialfeed' ) . '</p><p><strong>' . esc_html( sprintf( __( 'Current local records: %d', 'vemoro-socialfeed' ), $this->posts->count() ) ) . '</strong></p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lif_delete_all_posts">';
			wp_nonce_field( 'lif_delete_all_posts' );
			echo '<p><label><input type="checkbox" name="confirm_delete" value="1" required> ' . esc_html__( 'I understand that the synchronized data will be permanently deleted.', 'vemoro-socialfeed' ) . '</label></p><p><button type="submit" class="button lif-delete-button">' . esc_html__( 'Delete all synchronized posts', 'vemoro-socialfeed' ) . '</button></p></form></div>';
	}

	private function diagnostics(): void {
		$upload  = wp_upload_dir();
		$next    = CronManager::nextScheduled();
		$orphans = $this->posts->orphanedOwnedMediaSummary();
		$report  = array(
			'WordPress'               => get_bloginfo( 'version' ),
			'PHP'                     => PHP_VERSION,
			'cURL'                    => extension_loaded( 'curl' ) ? 'yes' : 'no',
			'Sodium'                  => extension_loaded( 'sodium' ) ? 'yes' : 'no',
			'OpenSSL'                 => extension_loaded( 'openssl' ) ? 'yes' : 'no',
			'Uploads writable'        => wp_is_writable( $upload['basedir'] ) ? 'yes' : 'no',
			'DISABLE_WP_CRON'         => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'yes' : 'no',
			'Next cron'               => $next ? gmdate( DATE_ATOM, $next ) : 'none',
			'Connected'               => $this->tokens->isConnected() ? 'yes' : 'no',
			'Local records'           => (string) $this->posts->count(),
			'Unassigned plugin media' => (string) $orphans['candidates'],
			'Potential cleanup size'  => size_format( $orphans['bytes'], 2 ),
		);
		echo '<div class="lif-card"><h2>' . esc_html__( 'Diagnostics', 'vemoro-socialfeed' ) . '</h2><textarea class="large-text code" rows="14" readonly>' . esc_textarea( implode( "\n", array_map( static fn( $k, $v )=>$k . ': ' . $v, array_keys( $report ), $report ) ) ) . '</textarea><p><button id="lif-clear-cache" class="button">' . esc_html__( 'Clear cache', 'vemoro-socialfeed' ) . '</button></p></div>'; }

	private function logs(): void {
		echo '<div class="lif-card"><h2>' . esc_html__( 'Recent logs', 'vemoro-socialfeed' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'vemoro-socialfeed' ) . '</th><th>' . esc_html__( 'Level', 'vemoro-socialfeed' ) . '</th><th>' . esc_html__( 'Message', 'vemoro-socialfeed' ) . '</th></tr></thead><tbody>';
		foreach ( $this->logs->latest( 100 ) as $row ) {
			echo '<tr><td>' . esc_html( $row->created_at ) . '</td><td>' . esc_html( $row->level ) . '</td><td>' . esc_html( $row->message ) . '</td></tr>';
		} echo '</tbody></table></div>'; }

	public function connect(): void {
		$this->guard( 'lif_connect' );
		if ( $this->oauth->isHosted() && '1' !== Request::post( 'confirm_terms' ) ) {
			$this->redirectNotice( __( 'The Terms of Use must be accepted before starting the Vemoro Login.', 'vemoro-socialfeed' ), 'error' );
		}
		try {
			$url     = $this->oauth->authorizationUrl( get_current_user_id() );
			$allowed = $this->oauth->isHosted() ? strtolower( (string) wp_parse_url( Config::connectUrl(), PHP_URL_HOST ) ) : 'www.instagram.com';
			if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $allowed ) {
				throw new \RuntimeException( esc_html__( 'Invalid authorization host.', 'vemoro-socialfeed' ) );
			}
			$allow_host = static function ( array $hosts ) use ( $allowed ): array {
				$hosts[] = $allowed;
				return array_values( array_unique( $hosts ) );
			};
			add_filter( 'allowed_redirect_hosts', $allow_host );
			wp_safe_redirect( $url, 302, 'Vemoro SocialFeed' );
			remove_filter( 'allowed_redirect_hosts', $allow_host );
			exit;
		} catch ( \Throwable $e ) {
			$this->redirectNotice( $e->getMessage(), 'error' );
		}
	}
	public function disconnect(): void {
		$this->guard( 'lif_disconnect' );
		if ( '1' !== Request::post( 'confirm_disconnect' ) ) {
			$this->redirectNotice( __( 'Disconnection and deletion were not confirmed.', 'vemoro-socialfeed' ), 'error' ); }
		$lock = new SyncLock();
		if ( ! $lock->acquire() ) {
			$this->redirectNotice( __( 'The connection cannot be removed while a synchronization is running.', 'vemoro-socialfeed' ), 'error' ); }
		$type    = 'success';
		$message = '';
		try {
			$result = $this->posts->deleteAll();
			$this->tokens->disconnect();
			delete_option( Config::STATUS_OPTION );
			delete_transient( 'lif_sync_progress' );
			FeedRenderer::clearCache();
			$this->logs->add( 'info', 'Instagram connection and Platform Data deleted.', $result );
			/* translators: 1: deleted posts, 2: deleted plugin-owned media, 3: retained referenced media. */
			$message = sprintf( __( 'Instagram disconnected. Deleted %1$d posts and %2$d plugin-owned media files; %3$d referenced media files were retained.', 'vemoro-socialfeed' ), $result['posts'], $result['attachments'], $result['retained_attachments'] );
		} catch ( \Throwable $e ) {
			$message = $e->getMessage();
			$type    = 'error'; } finally {
			$lock->release(); }
			$this->redirectNotice( $message, $type );
	}
	public function refresh(): void {
		$this->guard( 'lif_refresh_token' );
		$ok = $this->tokens->refresh( true );
		$this->redirectNotice( $ok ? __( 'Token refreshed.', 'vemoro-socialfeed' ) : __( 'Token refresh failed.', 'vemoro-socialfeed' ), $ok ? 'success' : 'error' ); }
	public function check(): void {
		$this->guard( 'lif_check_connection' );
		try {
			$profile          = $this->api->profile();
			$meta             = (array) get_option( Config::TOKEN_OPTION, array() );
			$meta['username'] = sanitize_text_field( (string) ( $profile['username'] ?? '' ) );
			update_option( Config::TOKEN_OPTION, $meta, false );
			$this->redirectNotice( __( 'Connection is working.', 'vemoro-socialfeed' ) );
		} catch ( \Throwable $e ) {
			$this->redirectNotice( $e->getMessage(), 'error' );} }
	public function cleanupOrphanedMedia(): void {
		$this->guard( 'lif_cleanup_orphaned_media' );
		if ( '1' !== Request::post( 'confirm_cleanup' ) ) {
			$this->redirectNotice( __( 'Cleanup was not confirmed.', 'vemoro-socialfeed' ), 'error', 'privacy' ); }
		$lock = new SyncLock();
		if ( ! $lock->acquire() ) {
			$this->redirectNotice( __( 'Media cannot be cleaned up while a synchronization is running.', 'vemoro-socialfeed' ), 'error', 'privacy' ); }
		$type    = 'success';
		$message = '';
		try {
			$result = $this->posts->cleanupOrphanedOwnedMedia();
			$this->logs->add( 'info', 'Orphaned Instagram media cleanup completed.', $result );
			/* translators: 1: deleted media, 2: freed size, 3: retained referenced media, 4: failed deletions. */
			$message = sprintf( __( 'Deleted %1$d orphaned media files and freed %2$s. %3$d externally referenced files were retained; %4$d files could not be deleted.', 'vemoro-socialfeed' ), $result['deleted'], size_format( $result['bytes'], 2 ), $result['retained'], $result['failed'] );
			if ( $result['failed'] > 0 ) {
				$type = 'error'; }
		} catch ( \Throwable $e ) {
			$message = $e->getMessage();
			$type    = 'error'; } finally {
			$lock->release(); }
			$this->redirectNotice( $message, $type, 'privacy' );
	}
	public function deleteAllPosts(): void {
		$this->guard( 'lif_delete_all_posts' );
		if ( '1' !== Request::post( 'confirm_delete' ) ) {
			$this->redirectNotice( __( 'Deletion was not confirmed.', 'vemoro-socialfeed' ), 'error', 'privacy' ); }
		$lock = new SyncLock();
		if ( ! $lock->acquire() ) {
			$this->redirectNotice( __( 'The data cannot be deleted while a synchronization is running.', 'vemoro-socialfeed' ), 'error', 'privacy' ); }
		$type    = 'success';
		$message = '';
		try {
			$result = $this->posts->deleteAll();
			delete_option( Config::STATUS_OPTION );
			delete_transient( 'lif_sync_progress' );
			update_option( Config::APPLIED_REFRESH_GENERATION_OPTION, (int) get_option( Config::REFRESH_GENERATION_OPTION, 0 ), false );
			FeedRenderer::clearCache();
			$this->logs->add( 'info', 'All synchronized Instagram data deleted.', $result );
			/* translators: 1: deleted posts, 2: deleted plugin-owned media, 3: retained referenced media. */
			$message = sprintf( __( 'Deleted %1$d posts and %2$d plugin-owned media files. %3$d referenced media files were retained.', 'vemoro-socialfeed' ), $result['posts'], $result['attachments'], $result['retained_attachments'] );
		} catch ( \Throwable $e ) {
			$message = $e->getMessage();
			$type    = 'error'; } finally {
			$lock->release(); }
			$this->redirectNotice( $message, $type, 'privacy' );
	}
	public function callback(): void {
		$userId            = get_current_user_id();
		$explicitRoute     = 'oauth_callback' === sanitize_key( Request::query( 'lif_action' ) ) && 'vemoro-socialfeed' === sanitize_key( Request::query( 'page' ) );
		$instagramFallback = isset( $GLOBALS['pagenow'] ) && 'admin.php' === $GLOBALS['pagenow'] && '' !== Request::query( 'state' ) && ( '' !== Request::query( 'code' ) || '' !== Request::query( 'error' ) ) && $userId > 0 && $this->oauth->hasPendingState( $userId );
		if ( ! $explicitRoute && ! $instagramFallback ) {
			return; }
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'vemoro-socialfeed' ) ); }
		$state = sanitize_text_field( Request::query( 'state' ) );
		$code  = sanitize_text_field( Request::query( 'code' ) );
		try {
			if ( ! $this->oauth->validateState( $userId, $state ) ) {
				throw new \RuntimeException( __( 'OAuth state validation failed.', 'vemoro-socialfeed' ) ); }
			if ( '' !== Request::query( 'error' ) ) {
				$description = sanitize_text_field( Request::query( 'error_description' ) );
				throw new \RuntimeException( $description ?: __( 'Instagram authorization was cancelled or rejected.', 'vemoro-socialfeed' ) );
			}
			if ( '' === $code ) {
				throw new \RuntimeException( __( 'Instagram did not return an authorization code.', 'vemoro-socialfeed' ) ); }
			$token = $this->oauth->exchangeCode( $code );
			$this->oauth->isHosted() ? $this->tokens->acceptLongLived( $token ) : $this->tokens->acceptShortLived( $token );
			$this->redirectNotice( __( 'Instagram account connected.', 'vemoro-socialfeed' ) );
		} catch ( \Throwable $e ) {
			$this->redirectNotice( $e->getMessage(), 'error' ); }
	}
	public function ajaxSync(): void {
		$this->ajaxGuard();
		wp_send_json_success( $this->sync->sync()->toArray() ); }
	public function ajaxProgress(): void {
		$this->ajaxGuard();
		wp_send_json_success(
			get_transient( 'lif_sync_progress' ) ?: array(
				'phase'   => 'idle',
				'percent' => 0,
			)
		); }
	public function ajaxClearCache(): void {
		$this->ajaxGuard();
		FeedRenderer::clearCache();
		wp_send_json_success( array( 'message' => __( 'Cache cleared.', 'vemoro-socialfeed' ) ) ); }
	public function assets( string $hook ): void {
		if ( false === strpos( $hook, 'vemoro-socialfeed' ) ) {
			return;
		} wp_enqueue_style( 'lif-admin', LIF_PLUGIN_URL . 'assets/css/admin.css', array(), LIF_VERSION );
		wp_enqueue_script( 'lif-admin', LIF_PLUGIN_URL . 'assets/js/admin.js', array(), LIF_VERSION, true );
		wp_localize_script(
			'lif-admin',
			'lifAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'lif_admin_ajax' ),
				'syncError' => __( 'Synchronization failed.', 'vemoro-socialfeed' ),
			)
		); }
	public function notice(): void {
		$notice = get_transient( 'lif_admin_notice_' . get_current_user_id() );
		if ( ! is_array( $notice ) ) {
			return;
		}delete_transient( 'lif_admin_notice_' . get_current_user_id() );
		echo '<div class="notice notice-' . esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>'; }
	private function actionButton( string $action, string $label, string $class = 'secondary' ): string {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
		return '<a class="button button-' . $class . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> '; }
	private function hostedConnectForm( string $label ): string {
		$terms  = 'https://vemoro.de/nutzungsbedingungen/';
		$privacy = 'https://vemoro.de/datenschutz/';
		return '<form class="lif-hosted-connect" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="lif_connect">'
			. wp_nonce_field( 'lif_connect', '_wpnonce', true, false )
			. '<p><label><input type="checkbox" name="confirm_terms" value="1" required> '
			. esc_html__( 'I accept the', 'vemoro-socialfeed' ) . ' '
			. '<a href="' . esc_url( $terms ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of Use', 'vemoro-socialfeed' ) . '</a> '
			. esc_html__( 'and acknowledge the', 'vemoro-socialfeed' ) . ' '
			. '<a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Notice', 'vemoro-socialfeed' ) . '</a>.'
			. '</label></p><p class="submit"><button class="button button-primary" type="submit">' . esc_html( $label ) . '</button></p></form> '; }
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'vemoro-socialfeed' ) );
		}check_admin_referer( $action ); }
	private function ajaxGuard(): void {
		check_ajax_referer( 'lif_admin_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'vemoro-socialfeed' ) ), 403 );} }
	private function redirectNotice( string $message, string $type = 'success', string $tab = '' ): never {
		set_transient(
			'lif_admin_notice_' . get_current_user_id(),
			array(
				'message' => sanitize_text_field( $message ),
				'type'    => $type,
			),
			60
		);
		$url = admin_url( 'admin.php?page=vemoro-socialfeed' );
		if ( $tab ) {
			$url = add_query_arg( 'tab', sanitize_key( $tab ), $url );
		}wp_safe_redirect( $url );
		exit; }
	private function mask( string $id ): string {
		return strlen( $id ) > 6 ? substr( $id, 0, 3 ) . str_repeat( '•', max( 3, strlen( $id ) - 6 ) ) . substr( $id, -3 ) : str_repeat( '•', strlen( $id ) ); }
	/** @return array<string,array<string,bool>> */
	private function settingsFormHtml(): array {
		return array(
			'tr'     => array(),
			'th'     => array(),
			'td'     => array(),
			'p'      => array( 'class' => true ),
			'select' => array( 'name' => true ),
			'option' => array(
				'value'    => true,
				'selected' => true,
			),
		);
	}
	private function validRedirect( string $url ): bool {
		if ( ! $url ) {
			return true;
		}$parts = wp_parse_url( $url );
		$home   = wp_parse_url( home_url( '/' ) );
		$local  = wp_get_environment_type() === 'local' || in_array( (string) ( $parts['host'] ?? '' ), array( 'localhost', '127.0.0.1', '::1' ), true );
		return ! empty( $parts['host'] ) && strtolower( (string) $parts['host'] ) === strtolower( (string) ( $home['host'] ?? '' ) ) && ( 'https' === ( $parts['scheme'] ?? '' ) || $local ); }
	/** @param array<string,string> $choices */ private function select( string $key, mixed $current, array $choices ): string {
		$html = '<select name="' . esc_attr( Config::OPTION ) . '[' . esc_attr( $key ) . ']">';
		foreach ( $choices as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}return $html . '</select>'; }
}
