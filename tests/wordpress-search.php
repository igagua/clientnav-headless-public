<?php
// Runs inside the throwaway WordPress (wp eval-file): the search index, kept between calls, or switched off.
// CNH_SEARCH=off: the setting says off (set before this process started).
$on   = 'off' !== getenv( 'CNH_SEARCH' );
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
$ask = function () {
	$r = rest_do_request( new WP_REST_Request( 'GET', '/clientnav-headless/v1/search-index' ) );
	return array( $r->get_status(), json_decode( wp_json_encode( $r->get_data() ), true ) );
};
$key = ClientNav\Headless\SEARCH_INDEX_KEPT;

$is( 'search_index_on() says ' . ( $on ? 'on' : 'off' ), $on === ClientNav\Headless\search_index_on() );
if ( ! $on ) {
	list( $st ) = $ask();
	$is( 'switched off: the route is not there', 404 === $st, $st );
	echo "$pass passed, $fail failed\n";
	return;
}

delete_transient( $key );
$first = wp_insert_post( array( 'post_title' => 'Search index first', 'post_content' => '<!-- wp:paragraph --><p>Quokka words.</p><!-- /wp:paragraph -->', 'post_status' => 'publish', 'post_type' => 'post' ) );
list( $st, $d ) = $ask();
$titles = array_column( $d['items'] ?? array(), 'title' );
$is( 'the index answers with the entries', 200 === $st && in_array( 'Search index first', $titles, true ), array( $st, count( $titles ) ) );
$is( 'and is kept', is_array( get_transient( $key ) ) );

set_transient( $key, array( 'items' => array( array( 'title' => 'the kept copy' ) ) ), 600 );
list( $st, $d ) = $ask();
$is( 'a second call reads the kept copy instead of working it out again', 'the kept copy' === ( $d['items'][0]['title'] ?? '' ), $d['items'][0] ?? null );

$second = wp_insert_post( array( 'post_title' => 'Search index second', 'post_content' => '<p>More.</p>', 'post_status' => 'publish', 'post_type' => 'post' ) );
$is( 'publishing drops the kept copy', false === get_transient( $key ) );
list( $st, $d ) = $ask();
$is( 'the next call has the new entry', in_array( 'Search index second', array_column( $d['items'] ?? array(), 'title' ), true ) );

wp_insert_post( array( 'post_title' => 'Search index draft', 'post_status' => 'draft', 'post_type' => 'post' ) );
$is( 'a draft nobody can see leaves the kept copy alone', is_array( get_transient( $key ) ) );
$menu = wp_update_nav_menu_object( 0, array( 'menu-name' => 'Search index menu' ) );
$is( 'a new menu, not shown anywhere yet, leaves it alone', is_array( get_transient( $key ) ) );
wp_update_nav_menu_object( $menu, array( 'menu-name' => 'Search index menu, renamed' ) );
$is( 'a change to a menu drops it', false === get_transient( $key ) );
wp_delete_nav_menu( $menu );

wp_delete_post( $first, true );
wp_delete_post( $second, true );
echo "$pass passed, $fail failed\n";
