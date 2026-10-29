<?php
// Runs inside the throwaway WordPress (wp eval-file) with Client Navigator Podcast active: GET /episodes and
// /episodes/feed as fixed with the podcast plugin author.
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
	return array( $r->get_status(), json_decode( (string) wp_json_encode( $r->get_data() ), true ), $r );
};
use ClientNav\Headless as H;
use ClientNav\Podcast\Episodes;
use ClientNav\Podcast\Shows;

$is( 'the podcast plugin is there', class_exists( '\ClientNav\Podcast\Api' ) );
list( $st, $d ) = $door( '/episodes', array() );
$is( 'no show yet: 200 with show null', 200 === $st && null === $d['show'] && array() === $d['items'], $d );

$saved = get_option( 'clientnav_headless' );
update_option( 'clientnav_headless', array( 'frontend' => 'https://front.example.test' ) );
$front = H\frontend_url(); // a fixture or the Connector may name another front end first
$is( 'there is a front end to name', '' !== $front && $front !== untrailingslashit( home_url() ), $front );
$show = Shows::set( 'podcast', array( 'title' => 'Test Podcast', 'description' => '<p>About <b>it</b></p>', 'author' => 'Tester', 'categories' => array( array( 'News', 'Politics' ) ), 'owner_email' => 'owner@example.test', 'home' => '/podcast-home/' ) );
$is( 'a show is made', ! is_wp_error( $show ), $show );
$made = array();
foreach ( array( 1, 2, 3 ) as $n ) {
	$id     = wp_insert_post( array( 'post_title' => "Episode $n", 'post_status' => 'publish', 'post_content' => '<p>Notes with <a href="' . home_url( '/about/' ) . '">a link</a>.</p>', 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 3600 * ( 10 - $n ) ) ) );
	$made[] = $id;
	$e      = Episodes::set( $id, array( 'show' => 'podcast', 'audio_url' => "https://media.example.test/podcast/e$n.mp3", 'audio_type' => 'audio/mpeg', 'audio_length' => 1000 * $n, 'seconds' => 60 * $n + 5, 'number' => $n, 'season' => 2026 ) );
	$is( "episode $n is made", ! is_wp_error( $e ), $e );
}
H\forget();

list( $st, $d ) = $door( '/episodes', array( 'shows' => 1 ) );
$is( '?shows=1: the shows with their feed path and count', 200 === $st && 'podcast' === ( $d['shows'][0]['slug'] ?? '' ) && 0 === strpos( (string) ( $d['shows'][0]['feed'] ?? '' ), '/' ) && 3 === (int) ( $d['shows'][0]['count'] ?? 0 ), $d );

list( $st, $d ) = $door( '/episodes', array( 'per_page' => 2 ) );
$e0 = $d['items'][0] ?? array();
$is( 'the show: title, plain description, feed as a path, no owner e-mail', 200 === $st && 'Test Podcast' === ( $d['show']['title'] ?? '' ) && 'About it' === ( $d['show']['description'] ?? '' ) && 0 === strpos( (string) ( $d['show']['feed'] ?? '' ), '/' ) && false === strpos( wp_json_encode( $d ), 'owner@example.test' ), $d['show'] ?? null );
$is( 'items are the door\'s cards plus "episode", newest first', 2 === count( $d['items'] ) && 'Episode 3' === ( $e0['title'] ?? '' ) && isset( $e0['path'], $e0['excerpt'], $e0['date'] ) && 'https://media.example.test/podcast/e3.mp3' === ( $e0['episode']['audio']['src'] ?? '' ) && '3:05' === ( $e0['episode']['audio']['duration'] ?? '' ), $e0 );
$is( 'paging: total 3, pages 2, more', 3 === $d['total'] && 2 === $d['pages'] && true === $d['more'] && 1 === $d['page'], array( $d['total'], $d['pages'], $d['more'] ) );
list( , $d2 ) = $door( '/episodes', array( 'per_page' => 2, 'page' => 2 ) );
$is( 'page 2: the oldest episode, no more', 'Episode 1' === ( $d2['items'][0]['title'] ?? '' ) && false === $d2['more'], $d2['items'] ?? null );
list( $st ) = $door( '/episodes', array( 'show' => 'no-such-show' ) );
$is( 'an unknown show: 404', 404 === $st, $st );
list( , $d3 ) = $door( '/episodes', array( 'per_page' => 500 ) );
$is( 'per_page is held at 100 (all three come)', 3 === count( $d3['items'] ), count( $d3['items'] ) );

list( $st, $d, $r ) = $door( '/episodes/feed', array( 'show' => 'podcast' ) );
$xml = (string) ( $d['xml'] ?? '' );
$is( '/episodes/feed: an RSS document', 200 === $st && false !== strpos( $xml, '<rss' ) && false !== strpos( $xml, '<enclosure' ), substr( $xml, 0, 200 ) );
$is( 'its self link is the front end\'s feed address', false !== strpos( $xml, 'href="' . $front . '/feed/podcast/"' ), substr( $xml, 0, 1200 ) );
$is( 'episode links and show notes name the front end, not the CMS', false !== strpos( $xml, $front . '/' ) && false === strpos( $xml, home_url( '/about/' ) ), null );
$is( 'audio addresses are left as they are', false !== strpos( $xml, 'https://media.example.test/podcast/e2.mp3' ) );
$guid = (string) ( $e0['episode']['guid'] ?? 'x' );
$is( 'guids are the plugin\'s own, untouched', '' !== $guid && false !== strpos( $xml, esc_xml( $guid ) ), $guid );
$is( 'the owner e-mail is in the RSS, where directories need it', false !== strpos( $xml, 'owner@example.test' ) );
list( $st ) = $door( '/episodes/feed', array( 'show' => 'no-such-show' ) );
$is( 'the feed of an unknown show: 404', 404 === $st, $st );

// A changed show is seen on the next ask: its settings live in term meta.
list( , $before ) = $door( '/episodes', array() );
Shows::set( 'podcast', array( 'title' => 'Renamed Podcast' ) );
list( , $after ) = $door( '/episodes', array() );
$is( 'a renamed show is seen at once (kept answers forgotten)', 'Renamed Podcast' === ( $after['show']['title'] ?? '' ), array( $before['show']['title'] ?? null, $after['show']['title'] ?? null ) );

// The plugin's own /feed/podcast/ on the CMS names the front end too.
$own = \ClientNav\Podcast\Api::feed( 'podcast', array() );
$is( 'the CMS\'s own feed names the front end (filter)', false !== strpos( (string) $own, $front . '/feed/podcast/' ), substr( (string) $own, 0, 600 ) );

foreach ( $made as $id ) {
	wp_delete_post( $id, true );
}
false === $saved ? delete_option( 'clientnav_headless' ) : update_option( 'clientnav_headless', $saved );
echo "$pass passed, $fail failed\n";
