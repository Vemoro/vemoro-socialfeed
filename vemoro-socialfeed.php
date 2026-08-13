<?php
/**
 * Plugin Name: Vemoro SocialFeed
 * Description: Synchronizes Instagram media server-side and displays content and media in WordPress with visitor privacy in mind.
 * Version: 2.2.3
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Vemoro
 * Author URI: https://vemoro.de/
 * Plugin URI: https://vemoro.de/socialfeed/
 * License: GPL-2.0-or-later
 * Text Domain: vemoro-socialfeed
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VEMORO_VERSION', '2.2.3' );
define( 'VEMORO_PLUGIN_FILE', __FILE__ );
define( 'VEMORO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VEMORO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Vemoro\SocialFeed\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, strlen( $prefix ) ) );
		$file     = VEMORO_PLUGIN_DIR . 'includes/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( Vemoro\SocialFeed\Activation::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Vemoro\SocialFeed\Deactivation::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) || version_compare( (string) get_bloginfo( 'version' ), '6.5', '<' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Vemoro SocialFeed for WP requires PHP 8.1 and WordPress 6.5 or newer.', 'vemoro-socialfeed' ) . '</p></div>';
				}
			);
			return;
		}
		Vemoro\SocialFeed\Plugin::instance()->boot();
	}
);

if ( ! function_exists( 'vemoro_socialfeed_render' ) ) {
	/**
	 * Render a Vemoro SocialFeed for themes.
	 *
	 * @param array<string,mixed> $args Display arguments.
	 */
	function vemoro_socialfeed_render( array $args = array() ): string {
		return Vemoro\SocialFeed\Plugin::instance()->renderer()->render( $args );
	}
}
