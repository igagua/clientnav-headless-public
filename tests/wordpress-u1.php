<?php
// Runs inside the throwaway WordPress (wp eval-file) after wordpress-lists.php: kept answers, lists without a full
// count, the fast way through a big section, the refresh call, plain SEO words and feeds.
$prr  = '1' === getenv( 'CNH_PRR' );
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

// Kept answers.
list( $st, $d, $h ) = $door( '/posts', array( 'category' => 'list-news' ) );
$is( 'a list answer is not kept on the way (Cloudflare)', 200 === $st && 'no-cache' === ( $h['Cache-Control'] ?? '' ), $h );
$id  = md5( 'posts|' . wp_json_encode( array( 'category' => 'list-news' ) ) );
$key = 'cnh_' . md5( $id . '|' . H\changed() . '|' . H\VERSION );
$is( 'and is kept inside WordPress', is_array( get_transient( $key ) ) );
set_transient( $key, array( 'items' => array(), 'total' => 999, 'pages' => 1, 'page' => 1, 'more' => false ), 600 );
list( $st, $d ) = $door( '/posts', array( 'category' => 'list-news' ) );
$is( 'the same question again gets the kept answer', 999 === ( $d['total'] ?? 0 ), $d['total'] ?? null );
$is( 'a list keeps its last answer for a day too', is_array( get_transient( 'cnh_last_' . $id ) ) );
list( $st ) = $door( '/resolve', array( 'path' => '/' ) );
$rid = md5( 'resolve|' . wp_json_encode( array( 'path' => '/' ) ) );
$is( 'a single entry is kept ten minutes but has no day-long copy', 200 === $st && is_array( get_transient( 'cnh_' . md5( $rid . '|' . H\changed() . '|' . H\VERSION ) ) ) && false === get_transient( 'cnh_last_' . $rid ), $st );
add_option( '_transient_cnh_stale', array( 'old' => true ), '', false );
add_option( '_transient_timeout_cnh_stale', time() - 10, '', false );
update_option( 'clientnav_headless_tidied', 0, false );
H\forget();
$is( 'expired kept answers go when the content changes', false === get_option( '_transient_cnh_stale' ) && false === get_option( '_transient_timeout_cnh_stale' ) );
// The rows stay bounded without a content change (a site, 2026-10-18: 241,000 rows, 8.3 GB).
global $wpdb;
$rows = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_cnh\\_%'" );
add_option( '_transient_cnh_stale2', array( 'old' => true ), '', false );
add_option( '_transient_timeout_cnh_stale2', time() - 10, '', false );
update_option( 'clientnav_headless_tidied', time(), false );
$door( '/posts', array( 'category' => 'list-news', 'per_page' => 1, 'cb' => 'tidy1' ) );
$is( 'looked over at most once a minute', false !== get_option( '_transient_cnh_stale2' ) );
update_option( 'clientnav_headless_tidied', 0, false );
add_option( 'cnh_lock_deadbeef', (string) ( time() - 120 ), '', 'no' );
add_option( 'cnh_lock_fresh', (string) time(), '', 'no' );
$door( '/posts', array( 'category' => 'list-news', 'per_page' => 1, 'cb' => 'tidy2' ) );
$is( 'expired kept answers go when a new answer is kept, without a content change', false === get_option( '_transient_cnh_stale2' ) && false === get_option( '_transient_timeout_cnh_stale2' ) );
wp_cache_delete( 'cnh_lock_deadbeef', 'options' );
wp_cache_delete( 'cnh_lock_fresh', 'options' );
$is( 'a lock left by a request that died goes; a live one stays', false === get_option( 'cnh_lock_deadbeef' ) && false !== get_option( 'cnh_lock_fresh' ) );
delete_option( 'cnh_lock_fresh' );
$values = array();
for ( $i = 0; $i < 2500; $i++ ) {
	$values[] = $wpdb->prepare( '(%s, %s, %s), (%s, %s, %s)', "_transient_cnh_old$i", 'a:0:{}', 'no', "_transient_timeout_cnh_old$i", (string) ( time() - 100 ), 'no' );
}
$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode( ',', $values ) );
update_option( 'clientnav_headless_tidied', time(), false );
$gone = H\tidy( 0, true );
$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%cnh\\_old%'" );
$is( 'wp clientnav-headless tidy: a backlog of 2,500 expired answers goes at once (in batches of 1,000), also inside the minute', 0 === $left && $gone['expired'] >= 2500, array( $left, $gone ) );
add_option( '_transient_cnh_last_u1test', array(), '', false );
add_option( '_transient_timeout_cnh_last_u1test', time() + DAY_IN_SECONDS, '', false );
$n = $rows();
delete_option( '_transient_cnh_last_u1test' );
delete_option( '_transient_timeout_cnh_last_u1test' );
$is( 'the day-long last answers count towards the most too', $rows() === $n - 1, array( $n, $rows() ) );
add_filter( 'clientnav_headless_kept_max', static fn() => 100 );
$values = array();
for ( $i = 0; $i < 150; $i++ ) {
	$values[] = $wpdb->prepare( '(%s, %s, %s), (%s, %s, %s)', "_transient_cnh_many$i", 'a:0:{}', 'no', "_transient_timeout_cnh_many$i", (string) ( time() + 600 + $i ), 'no' );
}
$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode( ',', $values ) );
update_option( 'clientnav_headless_tidied', 0, false );
H\tidy();
$is( 'over the most: down to four fifths of it, the soonest to expire first', 80 === $rows() && false !== get_option( '_transient_cnh_many149' ) && false === get_option( '_transient_cnh_many0' ), $rows() );
remove_all_filters( 'clientnav_headless_kept_max' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%cnh\\_many%'" );
$before = H\changed();
$new    = wp_insert_post( array( 'post_title' => 'Kept answers change', 'post_status' => 'publish', 'post_category' => array( (int) get_term_by( 'slug', 'list-news', 'category' )->term_id ) ) );
$is( 'publishing moves the changed counter on', H\changed() > $before );
list( $st, $d ) = $door( '/posts', array( 'category' => 'list-news' ) );
$is( 'and the next answer is worked out afresh, with the new entry', 4 === ( $d['total'] ?? 0 ) && 'Kept answers change' === ( $d['items'][0]['title'] ?? '' ), array( $d['total'] ?? null, $d['items'][0]['title'] ?? null ) );
$c = H\changed();
wp_update_nav_menu_object( 0, array( 'menu-name' => 'U1 menu' ) );
$is( 'a menu change moves it on too', H\changed() > $c );
list( $st, $d, $h ) = $door( '/resolve', array( 'path' => '/no-such-page-u1/' ) );
$is( 'a "nothing here" answer is not kept and not marked for keeping', 404 === $st && ! isset( $h['Cache-Control'] ), $h );

// Lists without counting.
list( $st, $d ) = $door( '/posts', array( 'category' => 'list-news', 'per_page' => 2, 'count' => '0' ) );
$is( 'count=0: no total, "more" says another page follows', 200 === $st && null === $d['total'] && null === $d['pages'] && true === $d['more'] && 2 === count( $d['items'] ), $d );
list( $st, $d ) = $door( '/posts', array( 'category' => 'list-news', 'per_page' => 2, 'page' => 2, 'count' => '0' ) );
$is( 'count=0, last page: "more" is false', 200 === $st && false === $d['more'] && 2 === count( $d['items'] ), array( $d['more'] ?? null, count( $d['items'] ?? array() ) ) );
list( $st, $d ) = $door( '/posts', array( 'category' => 'list-news', 'per_page' => 2 ) );
$is( 'counted lists carry "more" too', 4 === $d['total'] && 2 === $d['pages'] && true === $d['more'], $d );
list( $st, $d ) = $door( '/posts', array( 'page' => 5001 ) );
$is( 'past page 5,000: nothing', 200 === $st && array() === $d['items'], $d );

// The fast way through a big section gives what WordPress's own query gives.
list( , $slow ) = $door( '/posts', array( 'category' => 'list-news', 'count' => '0', 'per_page' => 10 ) );
add_filter( 'clientnav_headless_big', static fn() => 1 );
H\forget();
list( , $fast ) = $door( '/posts', array( 'category' => 'list-news', 'per_page' => 10 ) );
$is( 'a "very big" type is not counted by default', null === $fast['total'], $fast['total'] );
$is( 'the fast way gives the same entries in the same order', array_column( $slow['items'], 'id' ) === array_column( $fast['items'], 'id' ), array( array_column( $slow['items'], 'id' ), array_column( $fast['items'], 'id' ) ) );
$ids = H\newest_in( array( 'post_type' => 'post', 'post_status' => 'publish', 'ignore_sticky_posts' => true, 'has_password' => false, 'tax_query' => array( array( 'taxonomy' => 'category', 'field' => 'slug', 'terms' => 'list-news' ) ) ), 2, 1 );
$is( 'and it really took the fast way (ids, with skip)', is_array( $ids ) && array_slice( array_column( $slow['items'], 'id' ), 1, 2 ) === $ids, $ids );
list( , $fast ) = $door( '/posts', array( 'category' => 'list-news', 'per_page' => 10, 'exclude' => (string) $new ) );
$is( 'leaving one out works the fast way too', ! in_array( $new, array_column( $fast['items'], 'id' ), true ) && 3 === count( $fast['items'] ), array_column( $fast['items'], 'id' ) );
remove_all_filters( 'clientnav_headless_big' );
H\forget();

// The refresh call.
$sent = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$sent ) {
		$sent[] = array( 'url' => $url, 'body' => json_decode( (string) $args['body'], true ) );
		return array( 'headers' => array(), 'body' => '{"ok":true}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);
$purged = array();
add_action( 'cnp_purge_front', static function ( $what ) use ( &$purged ) { $purged[] = $what; } );
$saved = get_option( 'clientnav_headless' );
update_option( 'clientnav_headless', array( 'secret' => str_repeat( 's', 40 ), 'refresh' => 'https://front.example.test' ) );
H\Refresh::send(); // anything queued by the steps above goes first
$sent   = array();
$purged = array();
wp_update_post( array( 'ID' => $new, 'post_title' => 'Kept answers change, again' ) );
H\Refresh::purge_edge();
H\Refresh::send();
$b = $sent[0]['body'] ?? array();
$is( 'refresh: to the front end, with "paths" and the first one as "path"', 'https://front.example.test/api/revalidate' === ( $sent[0]['url'] ?? '' ) && in_array( '/kept-answers-change/', $b['paths'] ?? array(), true ) && ( $b['paths'][0] ?? '' ) === ( $b['path'] ?? null ) && ! isset( $b['gone'] ), $sent );
$is( 'Performance gets the same paths, not the whole zone', array( $b['paths'] ?? array() ) === $purged, $purged );
$sent = array();
wp_trash_post( $new );
H\Refresh::send();
$b = $sent[0]['body'] ?? array();
$is( 'a trashed entry is named as gone', in_array( $new, $b['gone'] ?? array(), true ) && in_array( '/kept-answers-change/', $b['paths'] ?? array(), true ), $b );
$sent = array();
$sticky = wp_insert_post( array( 'post_title' => 'Sticky one', 'post_status' => 'publish' ) );
H\Refresh::send();
$sent = array();
stick_post( $sticky );
H\Refresh::send();
$is( 'a sticky entry refreshes everything', true === ( $sent[0]['body']['all'] ?? null ), $sent );
unstick_post( $sticky );
H\Refresh::send();
$sent = array();
define( 'WP_IMPORTING', true );
$c = H\changed();
wp_insert_post( array( 'post_title' => 'Imported one', 'post_status' => 'publish' ) );
H\Refresh::send();
$is( 'while importing: nothing is sent, but kept answers are dropped', array() === $sent && H\changed() > $c, $sent );
false === $saved ? delete_option( 'clientnav_headless' ) : update_option( 'clientnav_headless', $saved );

// Feeds.
ob_start();
$server = rest_get_server();
$req    = new WP_REST_Request( 'GET', '/clientnav-headless/v1/feed' );
$req->set_query_params( array( 'category' => 'list-news', 'per_page' => 3 ) );
$res    = rest_do_request( $req );
$served = apply_filters( 'rest_pre_serve_request', false, $res, $req, $server );
$xml    = ob_get_clean();
$doc    = @simplexml_load_string( $xml );
$is( 'the feed goes out as RSS itself', true === $served && $doc instanceof SimpleXMLElement && 3 === count( $doc->channel->item ), substr( $xml, 0, 200 ) );
if ( $doc instanceof SimpleXMLElement ) {
	$front = ClientNav\Headless\frontend_url();
	$item  = $doc->channel->item[0];
	$is( 'its addresses are on the front end, the section\'s own included', 0 === strpos( (string) $item->link, $front . '/' ) && $front . '/category/list-news/' === (string) $doc->channel->link && false !== strpos( (string) $doc->channel->title, 'List news' ), array( (string) $item->link, (string) $doc->channel->link ) );
	$enc = (string) $item->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded;
	$is( 'each item carries its text and date', '' !== $enc && '' !== (string) $item->pubDate, array( strlen( $enc ), (string) $item->pubDate ) );
}
list( $st ) = $door( '/feed', array( 'type' => 'nope' ) );
$is( 'a feed of an unknown type: refused', 400 === $st, $st );

$c = H\changed();
do_action( 'cnp_purge_front', array( '/some-page/' ) );
$is( 'another plugin clearing front end pages drops the kept answers too', H\changed() > $c );

echo "$pass passed, $fail failed\n";
