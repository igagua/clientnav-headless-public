<?php
// Runs inside the throwaway WordPress (wp eval-file) with PR Register active: the SEO words come as plain text.
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
$door = function ( string $route, array $query = array() ) {
	$q = new WP_REST_Request( 'GET', '/clientnav-headless/v1' . $route );
	$q->set_query_params( $query );
	$r = rest_do_request( $q );
	return array( $r->get_status(), json_decode( (string) wp_json_encode( $r->get_data() ), true ) );
};
// SEO words are plain text.
$amp = term_exists( 'cloud-collaboration', 'category' ) ?: wp_insert_term( 'Cloud & collaboration', 'category' );
wp_insert_post( array( 'post_title' => 'In the ampersand section', 'post_status' => 'publish', 'post_category' => array( (int) $amp['term_id'] ) ) );
list( $st, $d ) = $door( '/resolve', array( 'path' => '/category/cloud-collaboration/' ) );
$is( 'a section title with "&" comes as plain text, from PR Register', 'term' === ( $d['type'] ?? '' ) && 'pr-register' === ( $d['seo']['source'] ?? '' ) && false === strpos( wp_json_encode( array( $d['seo']['title'] ?? '', $d['seo']['og']['title'] ?? '' ) ), '&amp;' ) && false !== strpos( (string) ( $d['seo']['title'] ?? '' ), 'Cloud & collaboration' ), $d['seo'] ?? null );

$asked = 0;
add_filter( 'clientnav_headless_seo', static function ( $seo ) use ( &$asked ) { if ( 'pr-register' === ( $seo['source'] ?? '' ) ) { ++$asked; } return $seo; } );
list( $st, $d ) = $door( '/posts', array( 'full' => 1, 'per_page' => 5, 'count' => '0' ) );
$is( 'whole entries in a list do not ask PR Register one by one', 200 === $st && count( $d['items'] ) > 1 && 0 === $asked && 'door' === ( $d['items'][0]['seo']['source'] ?? '' ), array( $asked, $d['items'][0]['seo']['source'] ?? null ) );
list( $st, $d ) = $door( '/resolve', array( 'path' => '/door-test-article/' ) );
$is( 'one entry on its own still does', 'pr-register' === ( $d['entry']['seo']['source'] ?? '' ), $d['entry']['seo']['source'] ?? null );

echo "$pass passed, $fail failed\n";
