<?php
// Runs inside the throwaway WordPress (wp eval-file): click-to-edit marks in previews, the fresh preview link,
// and the pair check's look for an instant live page.
$pass = 0;
$fail = 0;
$is   = function ( string $name, $ok, $got = null ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		return;
	}
	++$fail;
	echo "FAIL  $name" . ( null === $got ? '' : ': ' . substr( is_string( $got ) ? $got : wp_json_encode( $got ), 0, 400 ) ) . "\n";
};
use ClientNav\Headless as H;
$saved = get_option( 'clientnav_headless' );
update_option( 'clientnav_headless', array( 'secret' => str_repeat( 's', 44 ), 'frontend' => 'https://front.example.test' ) );
wp_set_current_user( 1 );

$content = "<!-- wp:paragraph -->\n<p>First</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Inside one</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Inside two</h2>\n<!-- /wp:heading --></div>\n<!-- /wp:group -->\n\n<!-- wp:paragraph -->\n<p>Last</p>\n<!-- /wp:paragraph -->";
$id      = wp_insert_post( array( 'post_title' => 'Marked', 'post_content' => $content, 'post_status' => 'publish' ) );
$classic = wp_insert_post( array( 'post_title' => 'Classic', 'post_content' => "Para one\n\nPara two <a href=\"/x/\">link</a>", 'post_status' => 'draft' ) );

$ask = function ( int $pid ) {
	$q = new WP_REST_Request( 'GET', '/clientnav-headless/v1/preview' );
	$q->set_query_params( array( 'token' => H\sign_token( array( 'pid' => $pid, 'uid' => 1, 'scope' => 'preview', 'exp' => time() + 60 ) ) ) );
	$r = rest_do_request( $q );
	return array( $r->get_status(), json_decode( (string) wp_json_encode( $r->get_data() ), true ) );
};
list( $st, $d ) = $ask( $id );
$html           = $d['entry']['html'] ?? '';
$is( 'preview answers', 200 === $st, $st );
$is( 'every block marked with its place in the editor', 1 === preg_match( '/<p[^>]*data-cnh-block="0"[^>]*>First/', $html ) && false !== strpos( $html, 'data-cnh-block="1"' ) && 1 === preg_match( '/<p[^>]*data-cnh-block="1\\.0"[^>]*>Inside one/', $html ) && 1 === preg_match( '/<h2[^>]*data-cnh-block="1\\.1"/', $html ) && 1 === preg_match( '/<p[^>]*data-cnh-block="2"[^>]*>Last/', $html ), $html );
$is( 'the group\'s own element carries its mark', 1 === preg_match( '/<div[^>]*data-cnh-block="1"[^>]*class="wp-block-group|<div[^>]*class="wp-block-group[^"]*"[^>]*data-cnh-block="1"/', $html ), $html );
$is( 'no editor-only attribute left in the HTML', false === strpos( $html, 'cnhPath' ), $html );
$tree = $d['entry']['blocks'] ?? array();
$is( 'the block tree carries paths, not the working attribute', array( '0', '1', '2' ) === array_column( $tree, 'path' ) && ! isset( $tree[0]['attrs']['cnhPath'] ), $tree );

list( , $d ) = $ask( $classic );
$html        = $d['entry']['html'] ?? '';
$is( 'classic content: one mark around it all (the editor shows it as one Classic block)', 1 === substr_count( $html, 'data-cnh-block=' ) && false !== strpos( $html, 'data-cnh-block="0"' ) && 1 === preg_match( '#<div data-cnh-block="0" style="display:contents">\\s*<p>Para one</p>\\s*<p>Para two <a href="[^"]*">link</a></p>\\s*</div>#', $html ), $html );

// POST /live: the editor's unsaved blocks drawn as the entry, for a front end without block components (0.23.0).
$live = function ( array $body, int $uid = 1, string $scope = 'live', int $pid = 0 ) use ( $id ) {
	$pid = $pid ?: $id;
	$q = new WP_REST_Request( 'POST', '/clientnav-headless/v1/live' );
	$q->set_header( 'content-type', 'application/json' );
	$q->set_body( wp_json_encode( $body + array( 'token' => H\sign_token( array( 'pid' => $pid, 'uid' => $uid, 'scope' => $scope, 'exp' => time() + 60 ) ) ) ) );
	$r = rest_do_request( $q );
	return array( $r->get_status(), json_decode( (string) wp_json_encode( $r->get_data() ), true ) );
};
$unsaved        = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Typed now</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Not saved <script>alert(1)</script></p>\n<!-- /wp:paragraph -->";
list( $st, $d ) = $live( array( 'title' => 'New title', 'content' => $unsaved ) );
$html           = $d['entry']['html'] ?? '';
$is( 'live: the unsaved blocks drawn as the entry, with the typed title', 200 === $st && 'New title' === ( $d['entry']['title'] ?? '' ) && false !== strpos( $html, 'Typed now' ) && false === strpos( $html, 'Inside one' ), array( $st, $d['entry']['title'] ?? null, $html ) );
$is( 'live: blocks marked for click-to-edit', 1 === preg_match( '/<h2[^>]*data-cnh-block="0"/', $html ) && 1 === preg_match( '/<p[^>]*data-cnh-block="1"/', $html ), $html );
$is( 'live: nothing saved', 'Marked' === get_post( $id )->post_title && false !== strpos( get_post( $id )->post_content, 'Inside one' ) );
$author = wp_insert_user( array( 'user_login' => 'live-author-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
$own    = wp_insert_post( array( 'post_title' => 'Own', 'post_content' => '<p>x</p>', 'post_status' => 'draft', 'post_author' => $author ) );
list( $st, $d ) = $live( array( 'content' => $unsaved ), $author, 'live', $own );
$is( 'live: an author without unfiltered HTML gets what WordPress would save for them', 200 === $st && ! user_can( $author, 'unfiltered_html' ) && false === strpos( (string) ( $d['entry']['html'] ?? '' ), '<script' ) && false !== strpos( (string) ( $d['entry']['html'] ?? '' ), 'Typed now' ), array( $st, $d['entry']['html'] ?? null ) );
wp_delete_post( $own, true );
$reader = wp_insert_user( array( 'user_login' => 'live-reader-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
list( $st ) = $live( array( 'content' => $unsaved ), $reader );
$is( 'live: refused when the token\'s person may not edit the entry', in_array( $st, array( 401, 403 ), true ), $st );
list( $st ) = $live( array( 'content' => $unsaved ), 1, 'preview' );
$is( 'live: refused with a token of another scope', in_array( $st, array( 401, 403 ), true ), $st );
list( $st ) = $live( array( 'content' => str_repeat( 'x', 1048577 ) ) );
$is( 'live: refused when too big', 400 === $st, $st );
wp_delete_user( $author );
wp_delete_user( $reader );

// The public door never carries marks.
$q = new WP_REST_Request( 'GET', '/clientnav-headless/v1/resolve' );
$q->set_query_params( array( 'path' => wp_make_link_relative( get_permalink( $id ) ) ) );
$r = rest_do_request( $q );
$d = json_decode( (string) wp_json_encode( $r->get_data() ), true );
$is( 'the public door has no marks', 200 === $r->get_status() && false === strpos( wp_json_encode( $d ), 'cnh-block' ) && false === strpos( wp_json_encode( $d ), 'cnhPath' ), $d );
$is( 'the entry itself is unchanged', $content === get_post( $id )->post_content );

// A fresh Preview address for the editor.
$q = new WP_REST_Request( 'GET', '/clientnav-headless/v1/preview-link' );
$q->set_query_params( array( 'post' => $classic ) );
$r = rest_do_request( $q );
$u = $r->get_data()['url'] ?? '';
$is( 'preview link: the front end\'s preview address with a fresh token', 200 === $r->get_status() && 0 === strpos( $u, H\frontend_url() . '/api/preview/?token=' ), $r->get_data() );
parse_str( (string) wp_parse_url( $u, PHP_URL_QUERY ), $qs );
$is( 'preview link: the token names the entry', ( H\read_token( (string) ( $qs['token'] ?? '' ), 'preview' )['pid'] ?? 0 ) === $classic );
wp_set_current_user( 0 );
$r = rest_do_request( $q );
$is( 'preview link: nobody else gets one', in_array( $r->get_status(), array( 401, 403 ), true ), $r->get_status() );
wp_set_current_user( 1 );

// The pair check looks for an instant live page.
$live = 404;
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$live ) {
		$code = false !== strpos( $url, '/live-preview/' ) ? $live : ( false !== strpos( $url, '/api/preview/' ) ? 307 : 200 );
		return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);
$c = H\pair_check();
$is( 'pair check: no live page there', false === $c['live_page'] && 'same' === $c['secret'], $c );
$live = 200;
$c    = H\pair_check();
$is( 'pair check: a live page there', true === $c['live_page'], $c );

wp_delete_post( $id, true );
wp_delete_post( $classic, true );
delete_option( 'clientnav_headless_last_check' );
false === $saved ? delete_option( 'clientnav_headless' ) : update_option( 'clientnav_headless', $saved );
echo "$pass passed, $fail failed\n";
