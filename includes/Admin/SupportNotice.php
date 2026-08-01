<?php
namespace LocalInstagramFeed\Admin;

use LocalInstagramFeed\Config;

final class SupportNotice {
	private const DISMISSED_META = 'lif_support_notice_dismissed';
	private const REMIND_AT_META = 'lif_support_notice_remind_at';
	private const REMINDER_DELAY = 120 * DAY_IN_SECONDS;

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_lif_support_remind_later', array( $this, 'remindLater' ) );
		add_action( 'admin_post_lif_support_dismiss', array( $this, 'dismiss' ) );
	}

	public function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ( $screen && str_contains( (string) $screen->id, 'vemoro-socialfeed' ) ) || ! current_user_can( 'read' ) || ! $this->isDue( get_current_user_id() ) ) {
			return;
		}

		echo '<div class="notice notice-info lif-support-notice">';
		echo '<p><strong>' . esc_html__( 'Help keep Vemoro SocialFeed available', 'vemoro-socialfeed' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Vemoro SocialFeed is provided free of charge and without advertising or tracking. If the plugin saves you time, you can voluntarily help cover maintenance, hosting and Meta API operating costs.', 'vemoro-socialfeed' ) . '</p>';
		echo '<p>';
		self::renderExternalLink( Config::LIBERAPAY_URL, __( 'Support via Liberapay', 'vemoro-socialfeed' ), 'button button-primary' );
		echo ' ';
		self::renderExternalLink( Config::GITHUB_SPONSORS_URL, __( 'Support via GitHub Sponsors', 'vemoro-socialfeed' ), 'button' );
		echo '</p><div class="lif-support-notice__actions">';
		$this->renderActionForm( 'lif_support_remind_later', __( 'Remind me later', 'vemoro-socialfeed' ) );
		$this->renderActionForm( 'lif_support_dismiss', __( 'Do not show again', 'vemoro-socialfeed' ) );
		echo '</div><p>' . esc_html__( 'Questions or problems?', 'vemoro-socialfeed' ) . ' ';
		self::renderSupportEmail();
		echo '</p><p class="description">' . esc_html__( 'Supporting is entirely voluntary and has no effect on the plugin features.', 'vemoro-socialfeed' ) . '</p></div>';
	}

	public static function renderSupportCard(): void {
		echo '<div class="lif-card lif-support-card"><h2>' . esc_html__( 'Support development and operation', 'vemoro-socialfeed' ) . '</h2>';
		echo '<p>' . esc_html__( 'The plugin remains free of charge. Voluntary contributions help fund maintenance, security updates and the Vemoro connection service.', 'vemoro-socialfeed' ) . '</p><p>';
		self::renderExternalLink( Config::LIBERAPAY_URL, __( 'Support via Liberapay', 'vemoro-socialfeed' ), 'button button-primary' );
		echo ' ';
		self::renderExternalLink( Config::GITHUB_SPONSORS_URL, __( 'Support via GitHub Sponsors', 'vemoro-socialfeed' ), 'button' );
		echo '</p><p><strong>' . esc_html__( 'Technical support:', 'vemoro-socialfeed' ) . '</strong> ';
		self::renderSupportEmail();
		echo '</p><p class="description">' . esc_html__( 'No connection to either service is made until you click a link.', 'vemoro-socialfeed' ) . '</p></div>';
	}

	public function remindLater(): void {
		$this->guard( 'lif_support_remind_later' );
		update_user_meta( get_current_user_id(), self::REMIND_AT_META, time() + self::REMINDER_DELAY );
		$this->redirectBack();
	}

	public function dismiss(): void {
		$this->guard( 'lif_support_dismiss' );
		update_user_meta( get_current_user_id(), self::DISMISSED_META, '1' );
		delete_user_meta( get_current_user_id(), self::REMIND_AT_META );
		$this->redirectBack();
	}

	private function isDue( int $userId ): bool {
		if ( '1' === (string) get_user_meta( $userId, self::DISMISSED_META, true ) ) {
			return false;
		}
		return time() >= (int) get_user_meta( $userId, self::REMIND_AT_META, true );
	}

	private function renderActionForm( string $action, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		echo '<button type="submit" class="button-link">' . esc_html( $label ) . '</button></form>';
	}

	private static function renderExternalLink( string $url, string $label, string $class ): void {
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer external">' . esc_html( $label ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'vemoro-socialfeed' ) . '</span></a>';
	}

	private static function renderSupportEmail(): void {
		echo '<a href="' . esc_url( 'mailto:' . Config::SUPPORT_EMAIL ) . '">' . esc_html( Config::SUPPORT_EMAIL ) . '</a>';
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'vemoro-socialfeed' ) );
		}
		check_admin_referer( $action );
	}

	private function redirectBack(): never {
		$fallback = admin_url( 'admin.php?page=vemoro-socialfeed' );
		wp_safe_redirect( wp_get_referer() ?: $fallback );
		exit;
	}
}
