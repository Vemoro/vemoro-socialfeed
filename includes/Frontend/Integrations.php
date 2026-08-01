<?php
namespace LocalInstagramFeed\Frontend;

use LocalInstagramFeed\Config;

final class Integrations {
	public function __construct( private readonly FeedRenderer $renderer ) {}
	public function register(): void {
		$renderShortcode = fn( array $attrs = array() ): string => $this->renderer->render(
			shortcode_atts(
				array(
					'posts'          => null,
					'columns'        => null,
					'columns_tablet' => null,
					'columns_mobile' => null,
					'show_caption'   => null,
					'show_date'      => null,
					'show_username'  => null,
					'show_metrics'   => null,
					'show_link'      => null,
					'caption_length' => null,
					'aspect_ratio'   => null,
					'order'          => null,
					'class'          => null,
				),
				$attrs,
				'vemoro_socialfeed'
			)
		);
		add_shortcode( 'vemoro_socialfeed', $renderShortcode );
		add_shortcode( 'local_instagram_feed', $renderShortcode );
		add_action( 'init', array( $this, 'block' ) );
		add_filter(
			'query_vars',
			static function ( array $vars ): array {
				$vars[] = 'lif_detail';
				return $vars;
			}
		);
		add_action(
			'init',
			static function (): void {
				add_rewrite_rule( '^instagram-feed/([^/]+)/?$', 'index.php?lif_detail=$matches[1]', 'top' );
			}
		);
		add_action( 'template_redirect', array( $this, 'detail' ) );
	}
	public function block(): void {
		wp_register_style( 'lif-block-editor', LIF_PLUGIN_URL . 'assets/css/frontend.css', array(), LIF_VERSION );
		wp_register_script( 'lif-block-editor', LIF_PLUGIN_URL . 'assets/js/block.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ), LIF_VERSION, true );
		$settings = Config::settings();
		$defaults = array(
			'heading'                  => '',
			'heading_level'            => 2,
			'heading_font_family'      => '',
			'heading_font_size'        => '',
			'heading_custom_font_size' => 40,
			'heading_color'            => '',
			'heading_align'            => '',
			'heading_weight'           => '',
			'heading_style'            => '',
			'heading_spacing'          => 16,
			'posts'                    => (int) $settings['post_limit'],
			'columns'                  => (int) $settings['columns'],
			'columns_tablet'           => (int) $settings['columns_tablet'],
			'columns_mobile'           => (int) $settings['columns_mobile'],
			'show_caption'             => (bool) $settings['show_caption'],
			'show_date'                => (bool) $settings['show_date'],
			'show_username'            => (bool) $settings['show_username'],
			'show_metrics'             => (bool) $settings['show_metrics'],
			'show_link'                => (bool) $settings['show_link'],
			'show_video_indicator'     => true,
			'caption_length'           => (int) $settings['caption_length'],
			'aspect_ratio'             => (string) $settings['aspect_ratio'],
			'order'                    => 'DESC',
			'class'                    => '',
			'section_background'       => '',
			'full_viewport_background' => false,
		);
		wp_add_inline_script( 'lif-block-editor', 'window.lifBlockDefaults = ' . wp_json_encode( $defaults ) . ';', 'before' );
		wp_add_inline_script( 'lif-block-editor', 'window.lifBlockPalette = ' . wp_json_encode( $this->block_palette() ) . ';', 'before' );
		wp_add_inline_script( 'lif-block-editor', 'window.lifBlockTypography = ' . wp_json_encode( $this->block_typography() ) . ';', 'before' );
		wp_set_script_translations( 'lif-block-editor', 'vemoro-socialfeed', LIF_PLUGIN_DIR . 'languages' );
		register_block_type( LIF_PLUGIN_DIR . 'blocks/vemoro-feed', array( 'render_callback' => array( $this, 'render_block' ) ) );
		register_block_type( LIF_PLUGIN_DIR . 'blocks/feed', array( 'render_callback' => array( $this, 'render_block' ) ) );
	}
	public function render_block( array $attrs ): string {
		$background = isset( $attrs['section_background'] ) ? sanitize_hex_color( (string) $attrs['section_background'] ) : '';
		$extra      = array( 'class' => 'lif-feed-block' . ( ! empty( $attrs['full_viewport_background'] ) ? ' lif-feed-block--viewport' : '' ) );
		if ( $background ) {
			$extra['style'] = 'background-color:' . $background . ';'; }
		$heading = trim( wp_strip_all_tags( (string) ( $attrs['heading'] ?? '' ) ) );
		$level   = min( 6, max( 2, (int) ( $attrs['heading_level'] ?? 2 ) ) );
		$title   = '' !== $heading ? sprintf( '<h%1$d class="lif-feed-block__title"%3$s>%2$s</h%1$d>', $level, esc_html( $heading ), $this->heading_style( $attrs ) ) : '';
		return '<div ' . get_block_wrapper_attributes( $extra ) . '>' . $title . $this->renderer->render( $attrs ) . '</div>';
	}
	private function block_palette(): array {
		$palette  = array();
		$settings = wp_get_global_settings( array( 'color', 'palette' ) );
		foreach ( array( 'theme', 'default', 'custom' ) as $origin ) {
			foreach ( (array) ( $settings[ $origin ] ?? array() ) as $color ) {
				if ( ! empty( $color['color'] ) ) {
					$palette[] = $color; }
			}
		}
		$green_sand = '#F3FAF6';
		foreach ( $palette as $color ) {
			if ( strtolower( (string) ( $color['color'] ?? '' ) ) === strtolower( $green_sand ) ) {
				return $palette; }
		}
		$palette[] = array(
			'name'  => __( 'Green sand', 'vemoro-socialfeed' ),
			'slug'  => 'lif-green-sand',
			'color' => $green_sand,
		);
		return $palette;
	}
	private function block_typography(): array {
		$result = array(
			'fontSizes'    => array(),
			'fontFamilies' => array(),
		);
		foreach ( array( 'fontSizes', 'fontFamilies' ) as $type ) {
			$settings = wp_get_global_settings( array( 'typography', $type ) );
			foreach ( array( 'theme', 'default', 'custom' ) as $origin ) {
				foreach ( (array) ( $settings[ $origin ] ?? array() ) as $preset ) {
					if ( ! empty( $preset['slug'] ) && ! empty( $preset['name'] ) ) {
						$result[ $type ][] = array(
							'slug' => $preset['slug'],
							'name' => $preset['name'],
						); }
				}
			}
		}
		return $result;
	}
	private function heading_style( array $attrs ): string {
		$styles = array();
		$family = sanitize_html_class( (string) ( $attrs['heading_font_family'] ?? '' ) );
		$size   = sanitize_html_class( (string) ( $attrs['heading_font_size'] ?? '' ) );
		if ( '' !== $family ) {
			$styles[] = 'font-family:var(--wp--preset--font-family--' . $family . ')'; }
		if ( 'custom' === $size ) {
			$styles[] = 'font-size:' . min( 120, max( 12, (int) ( $attrs['heading_custom_font_size'] ?? 40 ) ) ) . 'px'; } elseif ( '' !== $size ) {
			$styles[] = 'font-size:var(--wp--preset--font-size--' . $size . ')'; }
			$color = sanitize_hex_color( (string) ( $attrs['heading_color'] ?? '' ) );
			if ( $color ) {
				$styles[] = 'color:' . $color; }
			$align = (string) ( $attrs['heading_align'] ?? '' );
			if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
				$styles[] = 'text-align:' . $align; }
			$weight = (string) ( $attrs['heading_weight'] ?? '' );
			if ( in_array( $weight, array( '400', '700' ), true ) ) {
				$styles[] = 'font-weight:' . $weight; }
			$font_style = (string) ( $attrs['heading_style'] ?? '' );
			if ( in_array( $font_style, array( 'normal', 'italic' ), true ) ) {
				$styles[] = 'font-style:' . $font_style; }
			$styles[] = 'margin-bottom:' . min( 120, max( 0, (int) ( $attrs['heading_spacing'] ?? 16 ) ) ) . 'px';
			return $styles ? ' style="' . esc_attr( implode( ';', $styles ) ) . '"' : '';
	}
	public function detail(): void {
		$slug = sanitize_title( (string) get_query_var( 'lif_detail' ) );
		if ( ! $slug || empty( Config::settings()['local_detail'] ) ) {
			return; }
		$post = get_page_by_path( $slug, OBJECT, Config::POST_TYPE );
		if ( ! $post || 'publish' !== $post->post_status ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return; }
		status_header( 200 );
		nocache_headers();
		$title = esc_html( get_the_title( $post ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress supplies safe language attributes and the title is escaped above.
		echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The renderer returns complete HTML whose dynamic values are escaped at construction.
		wp_head();
		echo '</head><body ';
		body_class( 'lif-detail-page' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The title is escaped above and the renderer escapes every dynamic value while returning complete HTML.
		echo '><main class="lif-detail"><h1>' . $title . '</h1>' . $this->renderer->render(
			array(
				'post_id'        => (int) $post->ID,
				'posts'          => 1,
				'show_caption'   => true,
				'show_date'      => true,
				'show_username'  => true,
				'detail_context' => true,
			)
		) . '</main>';
		wp_footer();
		echo '</body></html>';
		exit;
	}
}
