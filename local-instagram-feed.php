<?php
/**
 * Plugin Name: Vemoro SocialFeed for WP
 * Description: Synchronisiert Instagram-Medien serverseitig und gibt Inhalte und Medien datenschutzfreundlich aus WordPress aus.
 * Version: 2.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Vemoro
 * Author URI: https://vemoro.de/
 * Plugin URI: https://vemoro.de/socialfeed/
 * License: GPL-2.0-or-later
 * Text Domain: local-instagram-feed
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('LIF_VERSION', '2.0.0');
define('LIF_PLUGIN_FILE', __FILE__);
define('LIF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LIF_PLUGIN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(
	static function (string $class): void {
		$prefix = 'LocalInstagramFeed\\';
		if (0 !== strpos($class, $prefix)) {
			return;
		}
		$relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
		$file     = LIF_PLUGIN_DIR . 'includes/' . $relative . '.php';
		if (is_readable($file)) {
			require_once $file;
		}
	}
);

register_activation_hook(__FILE__, array(LocalInstagramFeed\Activation::class, 'activate'));
register_deactivation_hook(__FILE__, array(LocalInstagramFeed\Deactivation::class, 'deactivate'));

add_action(
	'plugins_loaded',
	static function (): void {
		if (version_compare(PHP_VERSION, '8.1', '<') || version_compare((string) get_bloginfo('version'), '6.5', '<')) {
			add_action('admin_notices', static function (): void {
				echo '<div class="notice notice-error"><p>' . esc_html__('Vemoro SocialFeed for WP requires PHP 8.1 and WordPress 6.5 or newer.', 'local-instagram-feed') . '</p></div>';
			});
			return;
		}
		LocalInstagramFeed\Plugin::instance()->boot();
	}
);

if (! function_exists('lif_render_feed')) {
	/**
	 * Render a local Instagram feed for themes.
	 *
	 * @param array<string,mixed> $args Display arguments.
	 */
	function lif_render_feed(array $args = array()): string {
		return LocalInstagramFeed\Plugin::instance()->renderer()->render($args);
	}
}

if (! function_exists('vemoro_socialfeed_render')) {
	/**
	 * Render a Vemoro SocialFeed for themes.
	 *
	 * @param array<string,mixed> $args Display arguments.
	 */
	function vemoro_socialfeed_render(array $args = array()): string {
		return lif_render_feed($args);
	}
}
