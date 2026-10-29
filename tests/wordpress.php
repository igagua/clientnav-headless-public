<?php
// Runs inside a throwaway WordPress (wp eval-file) with this plugin active and CLIENTNAV_FRONT_URL set.
// CNH_PRR=1: PR Register is active too; CNH_PRR=0: it is not.
$prr   = '1' === getenv( 'CNH_PRR' );
$front = 'https://www.example.test';
$pass  = 0;
$fail  = 0;
$is    = function ( string $name, $ok, $got = null ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		return;
	}
	++$fail;
	echo "FAIL  $name" . ( null === $got ? '' : ': ' . wp_json_encode( $got ) ) . "\n";
};
$resolve = function ( string $path ) {
	$q = new WP_REST_Request( 'GET', '/clientnav-headless/v1/resolve' );
	$q->set_query_params( array( 'path' => $path ) );
	return json_decode( wp_json_encode( rest_do_request( $q )->get_data() ), true );
};

$existing = get_page_by_path( 'door-test-article', OBJECT, 'post' );
$cat      = term_exists( 'Door news', 'category' ) ?: wp_insert_term( 'Door news', 'category' );
$id       = $existing ? $existing->ID : wp_insert_post( array( 'post_title' => 'Door test article', 'post_name' => 'door-test-article', 'post_content' => '<p>Some text for the article, long enough to make a description out of it when nothing else is given.</p>', 'post_status' => 'publish', 'post_type' => 'post', 'post_category' => array( (int) $cat['term_id'] ) ) );
$draft    = wp_insert_post( array( 'post_title' => 'Door test draft', 'post_content' => '<p>Not out yet.</p>', 'post_status' => 'draft', 'post_type' => 'post' ) );

$is( 'front end address comes from CLIENTNAV_FRONT_URL', $front === ClientNav\Headless\frontend_url(), ClientNav\Headless\frontend_url() );
$is( 'PR Register is ' . ( $prr ? 'there' : 'absent' ), $prr === is_callable( array( '\PRRegister\Headless', 'head' ) ) );
$want = $prr ? 'pr-register' : 'door';

$r   = $resolve( '/door-test-article/' );
$seo = $r['entry']['seo'] ?? array();
$is( 'article: an entry with seo', 'entry' === ( $r['type'] ?? '' ) && $seo, $r['type'] ?? null );
$is( "article: source $want", $want === ( $seo['source'] ?? '' ), $seo['source'] ?? null );
$is( 'article: found and a title', true === ( $seo['found'] ?? null ) && '' !== (string) ( $seo['title'] ?? '' ), $seo );
$is( 'article: a description', '' !== (string) ( $seo['description'] ?? '' ), $seo['description'] ?? null );
$is( 'article: no ready-made html', ! isset( $seo['html'] ) );
if ( $prr ) {
	$is( 'article: canonical on the front end', "$front/door-test-article/" === ( $seo['canonical'] ?? '' ), $seo['canonical'] ?? null );
	$is( 'article: robots', is_string( $seo['robots'] ?? null ) && '' !== $seo['robots'], $seo['robots'] ?? null );
	$is( 'article: og with title and front end address', '' !== (string) ( $seo['og']['title'] ?? '' ) && 0 === strpos( (string) ( $seo['og']['url'] ?? '' ), $front ), $seo['og'] ?? null );
	$is( 'article: kind and id', 'post' === ( $seo['kind'] ?? '' ) && (int) $id === (int) ( $seo['id'] ?? 0 ), array( $seo['kind'] ?? null, $seo['id'] ?? null ) );
	$is( 'article: structured data names the front end, never this WordPress', isset( $seo['schema'] ) && false !== strpos( wp_json_encode( $seo['schema'] ), 'www.example.test' ) && false === strpos( wp_json_encode( $seo['schema'] ), '127.0.0.1' ), isset( $seo['schema'] ) ? 'present' : 'missing' );
} else {
	$is( 'article: own title, no canonical', 'Door test article' === ( $seo['title'] ?? '' ) && ! isset( $seo['canonical'] ), $seo );
}

$r   = $resolve( '/category/door-news/' );
$seo = $r['seo'] ?? array();
$is( "category: a term with seo, source $want", 'term' === ( $r['type'] ?? '' ) && $want === ( $seo['source'] ?? '' ), array( $r['type'] ?? null, $seo['source'] ?? null ) );
$is( 'category: a title', '' !== (string) ( $seo['title'] ?? '' ), $seo );
if ( $prr ) {
	$is( 'category: canonical on the front end', 0 === strpos( (string) ( $seo['canonical'] ?? '' ), "$front/" ), $seo['canonical'] ?? null );
}

$r   = $resolve( '/' );
$seo = $r['seo'] ?? array();
$is( "home: the list of posts with seo, source $want", 'posts' === ( $r['type'] ?? '' ) && $want === ( $seo['source'] ?? '' ), array( $r['type'] ?? null, $seo['source'] ?? null ) );
$is( 'home: a title', '' !== (string) ( $seo['title'] ?? '' ), $seo );
if ( $prr ) {
	$is( 'home: canonical is the front end', "$front/" === ( $seo['canonical'] ?? '' ), $seo['canonical'] ?? null );
}

$seo = (array) ClientNav\Headless\entry( get_post( $draft ) )['seo'];
$is( 'draft (as a preview reads it): own values only', 'door' === $seo['source'] && 'Door test draft' === $seo['title'] && ! isset( $seo['canonical'] ), $seo );
wp_delete_post( $draft, true );

$r = $resolve( '/no-such-page/' );
$is( 'unknown address: nothing, and no seo', 'none' === ( $r['type'] ?? '' ) && ! isset( $r['seo'] ), $r );

echo "$pass passed, $fail failed\n";
