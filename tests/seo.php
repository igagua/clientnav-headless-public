<?php
// The "seo" part of the door: PR Register's fields when it is there, the door's own otherwise.
// Modes: (none) PR Register absent; full; cms (PR Register's headless mode off: its addresses name the back office);
//        other-front (canonical on another address than this front end); empty (empty fields); throws; notfound.
define( 'ABSPATH', __DIR__ );
define( 'CLIENTNAV_FRONT_URL', 'https://www.example.com' );
function add_action() {}
function add_filter() {}
function get_option( $k, $d = array() ) { return array(); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function apply_filters( $tag, $value ) { return $value; }
$mode = $argv[1] ?? '';
$head = array(
	'found' => true, 'status' => 200, 'kind' => 'post', 'id' => 7, 'title' => 'SEO title', 'description' => 'SEO description',
	'canonical' => 'https://www.example.com/a/', 'robots' => 'index, follow',
	'og' => array( 'title' => 'OG', 'url' => 'https://www.example.com/a/', 'image' => array( 'url' => 'https://cms.example.com/wp-content/uploads/a.jpg' ) ),
	'twitter' => array( 'card' => 'summary' ), 'schema' => array( array( '@type' => 'Article' ) ), 'breadcrumbs' => array( array( 'name' => 'Home' ) ),
	'summary' => '', 'faq' => array(), 'modified' => '2026-10-13T00:00:00+00:00', 'html' => '<title>SEO title</title>',
);
$on = true;
if ( 'cms' === $mode ) {
	$on = false;
	$head['canonical'] = $head['og']['url'] = 'https://cms.example.com/a/';
} elseif ( 'other-front' === $mode ) {
	$head['canonical'] = $head['og']['url'] = 'https://other.example.com/a/';
} elseif ( 'empty' === $mode ) {
	$head['description'] = '';
	$head['title']       = '';
} elseif ( 'notfound' === $mode ) {
	$head = array( 'found' => false, 'status' => 404, 'title' => 'Not found', 'robots' => 'noindex, follow' );
}
$GLOBALS['t'] = array( 'head' => $head, 'on' => $on, 'throws' => 'throws' === $mode );
if ( '' !== $mode ) {
	eval( 'namespace PRRegister; class Headless { static function head( $k, $o, $f = true ) { if ( $GLOBALS["t"]["throws"] ) { throw new \Exception( "broken" ); } return $GLOBALS["t"]["head"]; } static function on() { return $GLOBALS["t"]["on"]; } }' );
}
// plain() lives in content.php, which needs a whole WordPress; the same thing here.
eval( 'namespace ClientNav\\Headless; function plain( $s ) { return trim( preg_replace( "/\\s+/", " ", html_entity_decode( strip_tags( (string) $s ), ENT_QUOTES | ENT_HTML5, "UTF-8" ) ) ); }' );
require dirname( __DIR__ ) . '/plugin/clientnav-headless/includes/settings.php';
require dirname( __DIR__ ) . '/plugin/clientnav-headless/includes/seo.php';
$got  = (array) ClientNav\Headless\seo( 'post', null, array( 'title' => 'Own title', 'description' => 'Own description' ) );
$fail = array();
$is   = function ( $name, $ok ) use ( &$fail ) { if ( ! $ok ) { $fail[] = $name; } };
$own  = in_array( $mode, array( '', 'throws', 'notfound' ), true );
$is( 'found is always true', true === $got['found'] );
$is( 'never passes html or status on', ! isset( $got['html'] ) && ! isset( $got['status'] ) );
if ( $own ) {
	$is( 'source door', 'door' === $got['source'] );
	$is( 'own title and description', 'Own title' === $got['title'] && 'Own description' === $got['description'] );
	$is( 'nothing else', array( 'description', 'found', 'source', 'title' ) === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $got ) ) );
} else {
	$is( 'source pr-register', 'pr-register' === $got['source'] );
	$is( 'robots passed on', 'index, follow' === $got['robots'] );
	$is( 'sharing picture kept', 'https://cms.example.com/wp-content/uploads/a.jpg' === $got['og']['image']['url'] );
	$is( 'empty summary and faq left out', ! isset( $got['summary'] ) && ! isset( $got['faq'] ) );
	if ( 'empty' === $mode ) {
		$is( 'empty fields keep the own values', 'Own title' === $got['title'] && 'Own description' === $got['description'] );
	} else {
		$is( 'title and description from PR Register', 'SEO title' === $got['title'] && 'SEO description' === $got['description'] );
	}
	if ( in_array( $mode, array( 'cms', 'other-front' ), true ) ) {
		$is( 'addresses of another host are not passed on', ! isset( $got['canonical'] ) && ! isset( $got['schema'] ) && ! isset( $got['breadcrumbs'] ) && ! isset( $got['og']['url'] ) );
	} else {
		$is( 'canonical, og.url, schema and breadcrumbs passed on', 'https://www.example.com/a/' === $got['canonical'] && 'https://www.example.com/a/' === $got['og']['url'] && isset( $got['schema'][0] ) && isset( $got['breadcrumbs'][0] ) );
	}
}
if ( $fail ) {
	fwrite( STDERR, 'FAIL (' . ( $mode ?: 'absent' ) . '): ' . implode( '; ', $fail ) . "\n" );
	exit( 1 );
}
echo 'ok seo ' . ( $mode ?: 'absent' ) . "\n";
