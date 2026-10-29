<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * The page blocks. Their definitions (blocks/<name>/block.json), the editor script and the stylesheet are built
 * from the @clientnav/ui library, so the editor draws a block with the same code as the front end.
 * The blocks save their values only; the front end draws them. WordPress itself shows nothing for them.
 * A site can switch the whole set off (blocks_on() in settings.php); the editor script is told so too.
 */
add_action(
	'init',
	static function () {
		$dir = plugin_dir_path( __DIR__ );
		if ( ! is_readable( $dir . 'build/editor.js' ) ) {
			return;
		}
		// The plugin's own folder, not this includes/ folder: build/ sits beside it.
		$url = plugin_dir_url( dirname( __DIR__ ) . '/clientnav-headless.php' );
		wp_register_script( 'clientnav-headless-editor', $url . 'build/editor.js', array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-api-fetch', 'wp-data', 'wp-editor', 'wp-plugins', 'react', 'react-dom', 'react-jsx-runtime' ), (string) filemtime( $dir . 'build/editor.js' ), true );
		// The script is registered either way: it also holds the live preview panel. Without the blocks it registers none.
		if ( ! blocks_on() ) {
			return;
		}
		wp_register_style( 'clientnav-headless-ui', $url . 'build/ui.css', array(), (string) filemtime( $dir . 'build/ui.css' ) );
		// A site's own look inside the editor: its variables and fonts, as CSS.
		$own = (string) apply_filters( 'clientnav_headless_editor_css', setting( 'editor_css' ) );
		if ( '' !== $own ) {
			wp_add_inline_style( 'clientnav-headless-ui', wp_strip_all_tags( $own ) );
		}
		foreach ( glob( $dir . 'blocks/*/block.json' ) as $file ) {
			register_block_type( dirname( $file ) );
		}
	}
);

add_filter(
	'block_categories_all',
	static fn( array $cats ) => blocks_on() ? array_merge( array( array( 'slug' => 'clientnav', 'title' => 'Client Navigator' ) ), $cats ) : $cats
);
