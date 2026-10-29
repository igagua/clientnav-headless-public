<?php
// Runs inside the throwaway WordPress (wp eval-file): lists and searches across several types, ordering by a
// field a site add-on names, and the add-on's own change of the question.
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
$ask = function ( array $q ) {
	$r = new WP_REST_Request( 'GET', '/clientnav-headless/v1/posts' );
	$r->set_query_params( $q );
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( (string) wp_json_encode( $res->get_data() ), true ) );
};
$post  = wp_insert_post( array( 'post_title' => 'Zebra news', 'post_content' => 'findme in a post', 'post_status' => 'publish' ) );
$page  = wp_insert_post( array( 'post_title' => 'Apple page', 'post_type' => 'page', 'post_content' => 'findme on a page', 'post_status' => 'publish' ) );
$early = wp_insert_post( array( 'post_title' => 'Early day', 'post_content' => 'x', 'post_status' => 'publish' ) );
$late  = wp_insert_post( array( 'post_title' => 'Late day', 'post_content' => 'x', 'post_status' => 'publish' ) );
update_post_meta( $early, '_start', '2026-01-05 10:00:00' );
update_post_meta( $late, '_start', '2026-03-09 10:00:00' );

list( $st, $d ) = $ask( array( 'type' => 'post,page', 'search' => 'findme' ) );
$types = array_map( static fn( $i ) => $i['type'], $d['items'] ?? array() );
sort( $types );
$is( 'a search across two types finds both', 200 === $st && array( 'page', 'post' ) === $types, array( $st, $types ) );
list( $st ) = $ask( array( 'type' => 'post,nothing-like-this' ) );
$is( 'a type that is not public refuses the whole question', 400 === $st, $st );
list( $st, $d ) = $ask( array( 'type' => 'post', 'search' => 'findme' ) );
$is( 'one type stays one type', 1 === count( $d['items'] ?? array() ), $d );

list( , $d ) = $ask( array( 'type' => 'post,page', 'orderby' => 'title', 'per_page' => 50 ) );
$titles = array_map( static fn( $i ) => $i['title'], $d['items'] ?? array() );
$is( 'ordered by title, ascending', $titles === ( function ( $t ) { sort( $t, SORT_STRING | SORT_FLAG_CASE ); return $t; } )( $titles ), $titles );
list( , $d ) = $ask( array( 'orderby' => 'title', 'order' => 'desc', 'per_page' => 50 ) );
$desc = array_map( static fn( $i ) => $i['title'], $d['items'] ?? array() );
$is( 'and descending when asked', $desc === array_reverse( ( function ( $t ) { sort( $t, SORT_STRING | SORT_FLAG_CASE ); return $t; } )( $desc ) ), $desc );

list( , $d ) = $ask( array( 'orderby' => 'start', 'per_page' => 50 ) );
$plain = array_map( static fn( $i ) => $i['id'], $d['items'] ?? array() );
$is( 'a field nobody listed is not an order (newest first as before)', $plain === array_values( array_unique( $plain ) ) && in_array( $late, $plain, true ), $plain );
add_filter( 'clientnav_headless_orderby', static fn( $f ) => $f + array( 'start' => array( 'key' => '_start' ) ) );
list( , $d ) = $ask( array( 'orderby' => 'start', 'order' => 'asc', 'per_page' => 50 ) );
$ids = array_map( static fn( $i ) => $i['id'], $d['items'] ?? array() );
$is( 'ordered by a named field, earliest first (only entries that have it)', array( $early, $late ) === $ids, $ids );
// (A different question from the one asked before the field was listed: the door keeps answers per question.)
list( , $d ) = $ask( array( 'orderby' => 'start', 'per_page' => 49 ) );
$ids = array_map( static fn( $i ) => $i['id'], $d['items'] ?? array() );
$is( 'latest first by default', array( $late, $early ) === $ids, $ids );
list( , $d ) = $ask( array( 'orderby' => '_start', 'per_page' => 50 ) );
$is( 'the raw field name is not a way in', ! isset( $d['items'] ) || count( $d['items'] ) >= 4, $d );

add_filter( 'clientnav_headless_query', static function ( $args, $r ) { return $r['only_early'] ? $args + array( 'post__in' => array( $GLOBALS['cnh_early'] ) ) : $args; }, 10, 2 );
$GLOBALS['cnh_early'] = $early;
list( , $d ) = $ask( array( 'only_early' => '1', 'per_page' => 50 ) );
$is( 'a site add-on can change the question', array( $early ) === array_map( static fn( $i ) => $i['id'], $d['items'] ?? array() ), $d['items'] ?? null );

foreach ( array( $post, $page, $early, $late ) as $id ) {
	wp_delete_post( $id, true );
}
echo "$pass passed, $fail failed\n";
