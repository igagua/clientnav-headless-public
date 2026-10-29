<?php
// Runs inside the throwaway WordPress (wp eval-file) with the real Client Navigator Translate active and Spanish
// switched on (no translations stored): the door answers in Spanish without errors, addresses and versions in
// Spanish, everything still untranslated counted as missing.
$pass = 0;
$fail = 0;
$is   = function ( string $name, $ok, $got = null ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		return;
	}
	++$fail;
	echo "FAIL  $name" . ( null === $got ? '' : ': ' . substr( wp_json_encode( $got ), 0, 400 ) ) . "\n";
};
$door = function ( string $route, array $query ) {
	$q = new WP_REST_Request( 'GET', '/clientnav-headless/v1' . $route );
	$q->set_query_params( $query );
	$r = rest_do_request( $q );
	return array( $r->get_status(), json_decode( (string) wp_json_encode( $r->get_data() ), true ) );
};
ClientNav\Headless\forget();
$is( 'Translate is there and offers Spanish', class_exists( '\ClientNav\Translate\Api' ) && in_array( 'es', (array) \ClientNav\Translate\Api::languages(), true ), class_exists( '\ClientNav\Translate\Api' ) ? \ClientNav\Translate\Api::languages() : 'no Api' );
list( $st, $d ) = $door( '/resolve', array( 'path' => '/es/door-test-article/' ) );
$is( 'an /es/ address answers the entry in Spanish', 200 === $st && 'entry' === ( $d['type'] ?? '' ) && 'es' === ( $d['lang']['code'] ?? '' ), array( $st, $d['type'] ?? null, $d['lang'] ?? null ) );
$is( 'its address and versions carry the language', 0 === strpos( (string) ( $d['entry']['path'] ?? '' ), '/es/' ) && isset( $d['entry']['versions']['es'] ) && 0 === strpos( (string) $d['entry']['versions']['es'], '/es/' ), array( $d['entry']['path'] ?? null, $d['entry']['versions'] ?? null ) );
$is( 'with nothing translated yet, the missing pieces are counted and the words stay', ( $d['lang']['missing'] ?? 0 ) > 0 && 'Door test article' === ( $d['entry']['title'] ?? '' ), array( $d['lang'] ?? null, $d['entry']['title'] ?? null ) );
list( $st, $d ) = $door( '/posts', array( 'lang' => 'es', 'per_page' => 3 ) );
$is( 'a list in Spanish: addresses with /es', 200 === $st && 0 === strpos( (string) ( $d['items'][0]['path'] ?? '' ), '/es/' ) && 'es' === ( $d['lang']['code'] ?? '' ), $d['items'][0]['path'] ?? null );
list( $st, $d ) = $door( '/resolve', array( 'path' => '/door-test-article/' ) );
$is( 'the site language: as before', 200 === $st && ! isset( $d['lang'] ) && '/door-test-article/' === ( $d['entry']['path'] ?? '' ), $d['entry']['path'] ?? null );
// Where an address lives in every language: asked in the site's language, and as Spanish writes it.
list( $st, $d ) = $door( '/where', array( 'path' => '/door-test-article/' ) );
$is( '/where: the site\'s own address and both languages', 200 === $st && '/door-test-article/' === ( $d['path'] ?? '' ) && '/door-test-article/' === ( $d['alt']['en'] ?? '' ) && 0 === strpos( (string) ( $d['alt']['es'] ?? '' ), '/es/' ), $d );
list( $st, $d2 ) = $door( '/where', array( 'path' => $d['alt']['es'] ?? '/es/' ) );
$is( '/where: asked with the Spanish address, it gives the one WordPress knows', 200 === $st && '/door-test-article/' === ( $d2['path'] ?? '' ) && ( $d['alt'] ?? null ) === ( $d2['alt'] ?? 0 ), $d2 );
list( $st ) = $door( '/where', array( 'path' => '/' ) );
$is( '/where: the home page too', 200 === $st );
// with=versions: an entry in the site's own language also says where it is in the others.
list( $st, $d ) = $door( '/resolve', array( 'path' => '/door-test-article/', 'with' => 'versions' ) );
$is( 'with=versions: the entry lists every language', 200 === $st && isset( $d['entry']['versions']['en'] ) && isset( $d['entry']['versions']['es'] ) && ! isset( $d['lang'] ), $d['entry']['versions'] ?? null );
list( $st, $d ) = $door( '/resolve', array( 'path' => '/door-test-article/', 'with' => 'versions', 'cb' => '2' ) );
list( $st, $plain ) = $door( '/resolve', array( 'path' => '/door-test-article/', 'cb' => '3' ) );
$is( 'and not unless asked', ! isset( $plain['entry']['versions'] ), $plain['entry']['versions'] ?? null );
// SEO words the door made from the entry's own title take the title's translation (a regional site, 2026-10-16):
// Translate keeps a translation by role, and the title is stored as a heading, never as "meta".
\ClientNav\Translate\Api::translate( array( array( 'text' => 'Door test article', 'as' => 'heading' ) ), 'es', 0, true );
global $wpdb;
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cntr_segments WHERE source = %s", 'Door test article' ) ) as $sid ) {
	\ClientNav\Translate\Store::save( (int) $sid, 'es', 'Artículo de prueba', \ClientNav\Translate\Store::MACHINE );
}
$t = \ClientNav\Translate\Api::translate( array( array( 'text' => 'Door test article', 'as' => 'heading' ), array( 'text' => 'Door test article', 'as' => 'meta' ) ), 'es' );
$is( 'set-up: Translate knows the title as a heading, not as meta', 'Artículo de prueba' === ( $t['items'][0] ?? '' ) && 'Door test article' === ( $t['items'][1] ?? '' ), $t );
// The door's own SEO words, as on a CMS without PR Register (PR Register is active in this part of the suite).
$door_seo = static fn( $seo, $object ) => array( 'title' => get_the_title( $object ), 'description' => $seo['description'] ?? '', 'found' => true, 'source' => 'door' );
add_filter( 'clientnav_headless_seo', $door_seo, 10, 2 );
ClientNav\Headless\forget();
list( $st, $d ) = $door( '/resolve', array( 'path' => '/es/door-test-article/', 'cb' => 'seo' ) );
remove_filter( 'clientnav_headless_seo', $door_seo, 10 );
$is( 'an entry in Spanish: its SEO title is the title\'s translation', 'Artículo de prueba' === ( $d['entry']['title'] ?? '' ) && 'Artículo de prueba' === ( $d['entry']['seo']['title'] ?? '' ) && 'door' === ( $d['entry']['seo']['source'] ?? '' ), array( $d['entry']['title'] ?? null, $d['entry']['seo'] ?? null ) );
echo "$pass passed, $fail failed\n";
