<?php
// Runs inside the throwaway WordPress (wp eval-file) after the other content tests: archives, the search feed,
// local dates, the hooks for site add-ons, several-section lists, lookups, pictures as paths, the Site Address pin,
// and the plugin's Client Navigator abilities.
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
	return array( $r->get_status(), json_decode( (string) wp_json_encode( $r->get_data() ), true ), $r->get_headers() );
};
use ClientNav\Headless as H;

$sec = term_exists( 'u2-section', 'category' ) ?: wp_insert_term( 'U2 section', 'category', array( 'slug' => 'u2-section' ) );
$oth = term_exists( 'u2-other', 'category' ) ?: wp_insert_term( 'U2 other', 'category', array( 'slug' => 'u2-other' ) );
$a   = wp_insert_post( array( 'post_title' => 'U2 March one', 'post_status' => 'publish', 'post_date' => '2025-03-10 23:30:00', 'post_date_gmt' => '2025-03-10 19:30:00', 'post_category' => array( (int) $sec['term_id'] ), 'post_content' => '<p>Text of <strong>March</strong> one.</p>' ) );
$b   = wp_insert_post( array( 'post_title' => 'U2 March two', 'post_status' => 'publish', 'post_date' => '2025-03-11 08:00:00', 'post_date_gmt' => '2025-03-11 04:00:00', 'post_category' => array( (int) $oth['term_id'] ) ) );
$c   = wp_insert_post( array( 'post_title' => 'U2 hidden', 'post_status' => 'publish', 'post_date' => '2025-03-12 08:00:00', 'post_date_gmt' => '2025-03-12 04:00:00', 'post_category' => array( (int) $sec['term_id'] ) ) );
update_post_meta( $c, '_prregister_robots', array( 'noindex', 'follow' ) );

// Archives.
list( $st, $d ) = $door( '/months', array( 'type' => 'post' ) );
$march = array_values( array_filter( $d['items'] ?? array(), static fn( $m ) => '2025-03' === $m['month'] ) );
$is( 'months: March 2025 with its three entries', 200 === $st && 3 === ( $march[0]['count'] ?? 0 ), $d['items'] ?? null );
list( $st, $d ) = $door( '/month', array( 'month' => '2025-03', 'type' => 'post' ) );
$titles = array_column( $d['items'] ?? array(), 'title' );
$is( 'month: its entries newest first, the noindex one left out', 200 === $st && array( 'U2 March two', 'U2 March one' ) === $titles, $titles );
$is( 'month: local wall clock beside the GMT date', '2025-03-10T23:30:00' === ( $d['items'][1]['local'] ?? '' ) && '2025-03-10T19:30:00Z' === ( $d['items'][1]['date'] ?? '' ), $d['items'][1] ?? null );
list( $st ) = $door( '/month', array( 'month' => 'March' ) );
$is( 'month: a malformed month is refused', 400 === $st, $st );
list( $st, $d ) = $door( '/day', array( 'day' => '2025-03-10', 'type' => 'post' ) );
$is( 'day: the entries of that local day as cards', 200 === $st && array( 'U2 March one' ) === array_column( $d['items'] ?? array(), 'title' ) && '2025-03-10T23:30:00' === ( $d['items'][0]['local'] ?? '' ), $d );

// The search feed.
list( $st, $d, $h ) = $door( '/index', array( 'after' => $a - 1, 'limit' => 2, 'type' => 'post' ) );
$is( 'index: in number order, from the cursor, with plain text', 200 === $st && $a === ( $d['items'][0]['id'] ?? 0 ) && 'Text of March one.' === ( $d['items'][0]['text'] ?? '' ) && in_array( 'U2 section', $d['items'][0]['terms'] ?? array(), true ), $d['items'][0] ?? null );
$is( 'index: "next" is the cursor, and it is never kept', ( $d['items'][1]['id'] ?? -1 ) === ( $d['next'] ?? -2 ) && 'no-store' === ( $h['Cache-Control'] ?? '' ), array( $d['next'] ?? null, $h ) );
list( $st, $d ) = $door( '/index', array( 'after' => 999999999 ) );
$is( 'index: past the last one, empty and no cursor', 200 === $st && array() === $d['items'] && null === $d['next'], $d );

// Several sections, everything except.
list( $st, $d ) = $door( '/posts', array( 'category' => 'u2-section,u2-other' ) );
$is( 'several sections make one list', 200 === $st && 3 === $d['total'], $d['total'] ?? null );
list( $st, $d ) = $door( '/posts', array( 'not_category' => 'u2-section', 'per_page' => 50 ) );
$is( 'everything except a section', 200 === $st && ! array_intersect( array( $a, $c ), array_column( $d['items'], 'id' ) ) && in_array( $b, array_column( $d['items'], 'id' ), true ), array_column( $d['items'] ?? array(), 'id' ) );

// Hooks for a site add-on.
add_filter( 'clientnav_headless_card', static function ( $card, $post ) { $card['kind'] = 'long'; return $card; }, 10, 2 );
add_filter( 'clientnav_headless_image', static function ( $pic, $post, $size ) use ( $a ) { return $post->ID === $a ? array( 'src' => 'https://cms.example.test/imgs/old-picture.jpg', 'width' => 400, 'height' => 300, 'alt' => '' ) : $pic; }, 10, 3 );
add_filter( 'clientnav_headless_old_path', static fn( $to, $old ) => '/2025/pol/1741634000.php' === $old ? get_permalink( $a ) : $to, 10, 2 );
H\forget();
list( $st, $d ) = $door( '/posts', array( 'category' => 'u2-section' ) );
$one = array_values( array_filter( $d['items'] ?? array(), static fn( $i ) => $i['id'] === $a ) )[0] ?? array();
$is( 'card filter: a site add-on puts its own field on cards', 'long' === ( $one['kind'] ?? '' ), $one );
$is( 'picture filter: a list picture from a field of its own', 'https://cms.example.test/imgs/old-picture.jpg' === ( $one['image']['src'] ?? '' ), $one['image'] ?? null );
list( $st, $d ) = $door( '/resolve', array( 'old' => '/2025/pol/1741634000.php' ) );
$is( 'old address: where it lives now', 'redirect' === ( $d['type'] ?? '' ) && '/u2-march-one/' === ( $d['to'] ?? '' ), $d );
list( $st, $d ) = $door( '/resolve', array( 'path' => '/2025/pol/1741634000.php' ) );
$is( 'old address asked as a path: the same', 'redirect' === ( $d['type'] ?? '' ) && '/u2-march-one/' === ( $d['to'] ?? '' ), $d );
list( $st ) = $door( '/resolve', array( 'old' => '/nothing/like/it.php' ) );
$is( 'an old address nobody knows: nothing', 404 === $st, $st );
remove_all_filters( 'clientnav_headless_card' );
remove_all_filters( 'clientnav_headless_image' );
H\forget();

// Lookups.
list( $st, $d ) = $door( '/resolve', array( 'id' => $a ) );
$is( 'by number: the entry', 'entry' === ( $d['type'] ?? '' ) && 'U2 March one' === ( $d['entry']['title'] ?? '' ), $d['type'] ?? null );
$draft = wp_insert_post( array( 'post_title' => 'U2 draft', 'post_status' => 'draft' ) );
list( $st ) = $door( '/resolve', array( 'id' => $draft ) );
$is( 'by number, a draft: nothing', 404 === $st, $st );
global $wp_rewrite;
$explainer = get_page_by_path( 'explainer-one', OBJECT, 'cn_explainer' );
if ( $explainer ) {
	add_filter( 'cnh_test_no_rewrite', '__return_true' );
	add_filter( 'url_to_postid', static fn( $url ) => apply_filters( 'cnh_test_no_rewrite', false ) && false !== strpos( (string) $url, '/explainers/' ) ? '' : $url );
	H\forget();
	list( $st, $d ) = $door( '/resolve', array( 'path' => '/explainers/explainer-one/' ) );
	$is( 'type folder: found even when WordPress\'s own lookup misses it', 'entry' === ( $d['type'] ?? '' ) && 'Explainer one' === ( $d['entry']['title'] ?? '' ), $d['type'] ?? null );
	remove_all_filters( 'url_to_postid' );
}

// Sitemaps leave out noindex entries.
H\forget();
list( $st, $d ) = $door( '/paths', array( 'type' => 'post', 'per_page' => 500 ) );
$paths = array_column( $d['items'] ?? array(), 'path' );
$is( 'paths: the noindex entry is left out', in_array( '/u2-march-one/', $paths, true ) && ! in_array( '/u2-hidden/', $paths, true ), count( $paths ) );

// Pictures as paths.
$pic = wp_insert_attachment( array( 'post_title' => 'U2 picture', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), '2026/01/u2.jpg' );
wp_update_attachment_metadata( $pic, array( 'width' => 800, 'height' => 600, 'file' => '2026/01/u2.jpg', 'sizes' => array() ) );
set_post_thumbnail( $b, $pic );
$saved = get_option( 'clientnav_headless' );
delete_option( 'clientnav_headless' ); // settings saved for the first time below: "added", not "updated"
list( , $d ) = $door( '/posts', array( 'category' => 'u2-other' ) );
$is( 'pictures: full WordPress addresses by default', 0 === strpos( (string) ( $d['items'][0]['image']['src'] ?? '' ), 'http' ), $d['items'][0]['image']['src'] ?? null );
update_option( 'clientnav_headless', array_merge( (array) $saved, array( 'media' => 'paths' ) ) );
list( , $d ) = $door( '/posts', array( 'category' => 'u2-other' ) );
$is( 'pictures: as paths when the front end serves /wp-content/ itself', '/wp-content/uploads/2026/01/u2.jpg' === ( $d['items'][0]['image']['src'] ?? '' ), $d['items'][0]['image']['src'] ?? null );
false === $saved ? delete_option( 'clientnav_headless' ) : update_option( 'clientnav_headless', $saved );

// The Site Address pin.
$home = get_option( 'home' );
update_option( 'home', H\frontend_url() );
$rest = rest_url( 'clientnav-headless/v1/site' );
$is( 'Site Address set to the front end: REST links stay on WordPress', 0 === strpos( $rest, untrailingslashit( site_url() ) ), $rest );
update_option( 'home', $home );
$is( 'and back to normal: untouched', 0 === strpos( rest_url(), untrailingslashit( home_url() ) ) );

// Client Navigator abilities.
$abilities = apply_filters( 'clientnav_abilities', array() );
$is( 'abilities: status and pairing, named apart from the Connector\'s', isset( $abilities['clientnav/headless-plugin-status'], $abilities['clientnav/headless-pair'] ) && false === $abilities['clientnav/headless-plugin-status']['write'] && true === $abilities['clientnav/headless-pair']['write'], array_keys( $abilities ) );
$status = call_user_func( $abilities['clientnav/headless-plugin-status']['run'], array() );
$is( 'status: version, front end and where it comes from', H\VERSION === ( $status['version'] ?? '' ) && 'CLIENTNAV_FRONT_URL' === ( $status['from'] ?? '' ) && array_key_exists( 'last_refresh', $status ), $status );
$pair = call_user_func( $abilities['clientnav/headless-pair']['run'], array() );
$is( 'pairing: a code and where to send it', preg_match( '/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', (string) ( $pair['code'] ?? '' ) ) && false !== strpos( (string) ( $pair['send_to'] ?? '' ), '/clientnav-headless/v1/pair' ), $pair );
delete_option( 'clientnav_headless_pairing' );
// The pairing made a shared secret; without a front end to answer, refresh calls would only fill the log.
false === $saved ? delete_option( 'clientnav_headless' ) : update_option( 'clientnav_headless', $saved );

echo "$pass passed, $fail failed\n";
