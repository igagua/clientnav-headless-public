<?php
// Runs inside the throwaway WordPress (wp eval-file): the Client Navigator block set, offered or switched off.
// CNH_BLOCKS=on: nothing set; CNH_BLOCKS=off: the setting says off (set before this process started).
$on   = 'off' !== getenv( 'CNH_BLOCKS' );
$pass = 0;
$fail = 0;
$is   = function ( string $name, $ok, $got = null ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		return;
	}
	++$fail;
	echo "FAIL  $name" . ( null === $got ? '' : ': ' . substr( wp_json_encode( $got ), 0, 300 ) ) . "\n";
};
$dir   = WP_PLUGIN_DIR . '/clientnav-headless/blocks';
$all   = count( glob( $dir . '/*/block.json' ) );
$known = array_values( array_filter( array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ), static fn( $n ) => 0 === strpos( $n, 'clientnav/' ) ) );
$cats  = array_column( (array) apply_filters( 'block_categories_all', array(), null ), 'slug' );

$is( 'the plugin carries its block definitions', $all > 30, $all );
$is( 'blocks_on() says ' . ( $on ? 'on' : 'off' ), $on === ClientNav\Headless\blocks_on() );
$is( 'the editor script is registered either way (live preview)', wp_script_is( 'clientnav-headless-editor', 'registered' ) );
// Until 0.7.0 the address named includes/build/, a folder that does not exist: the script never reached a real editor.
$src = (string) wp_scripts()->registered['clientnav-headless-editor']->src;
$is( 'the editor script has the address of the file that exists', plugins_url( 'clientnav-headless/build/editor.js' ) === $src && is_readable( WP_PLUGIN_DIR . '/clientnav-headless/build/editor.js' ), $src );
if ( $on ) {
	$css = (string) wp_styles()->registered['clientnav-headless-ui']->src;
	$is( 'the blocks stylesheet has the address of the file that exists', plugins_url( 'clientnav-headless/build/ui.css' ) === $css && is_readable( WP_PLUGIN_DIR . '/clientnav-headless/build/ui.css' ), $css );
}
if ( $on ) {
	$is( 'every block is registered', count( $known ) === $all, count( $known ) );
	$is( 'the Client Navigator group is in the block list', in_array( 'clientnav', $cats, true ), $cats );
	$is( 'the blocks stylesheet is registered', wp_style_is( 'clientnav-headless-ui', 'registered' ) );
} else {
	$is( 'no Client Navigator block is registered', array() === $known, $known );
	$is( 'no Client Navigator group in the block list', ! in_array( 'clientnav', $cats, true ), $cats );
	$is( 'the blocks stylesheet is not registered', ! wp_style_is( 'clientnav-headless-ui', 'registered' ) );
}

// What the editor is told when a page is opened (a front end is named in this WordPress).
$id = wp_insert_post( array( 'post_title' => 'Blocks switch page', 'post_type' => 'page', 'post_status' => 'draft' ) );
$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
do_action( 'enqueue_block_editor_assets' );
$told = implode( "\n", (array) wp_scripts()->get_data( 'clientnav-headless-editor', 'before' ) );
$is( 'the editor script is loaded for the page', wp_script_is( 'clientnav-headless-editor', 'enqueued' ) );
$is( 'the editor is told the blocks are ' . ( $on ? 'on' : 'off' ), false !== strpos( $told, '"blocks":' . ( $on ? 'true' : 'false' ) ), $told );
wp_delete_post( $id, true );

// The door does not depend on the switch: a page still answers with its blocks parsed.
$page = wp_insert_post( array( 'post_title' => 'Blocks switch door', 'post_name' => 'blocks-switch-door', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:paragraph --><p>Plain text.</p><!-- /wp:paragraph -->' ) );
$q    = new WP_REST_Request( 'GET', '/clientnav-headless/v1/resolve' );
$q->set_query_params( array( 'path' => '/blocks-switch-door/' ) );
$d = json_decode( wp_json_encode( rest_do_request( $q )->get_data() ), true );
$is( 'the door still answers the page with its blocks', 'entry' === ( $d['type'] ?? '' ) && 'core/paragraph' === ( $d['entry']['blocks'][0]['name'] ?? '' ), $d['type'] ?? null );
wp_delete_post( $page, true );

echo "$pass passed, $fail failed\n";
