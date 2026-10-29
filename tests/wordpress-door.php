<?php
// Runs inside the throwaway WordPress (wp eval-file): the closed door, WordPress's own REST API closed, and the
// pair check with the last refresh calls.
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
$ask = function ( string $route, array $headers = array(), string $method = 'GET', array $query = array() ) {
	$q = new WP_REST_Request( $method, '/clientnav-headless/v1' . $route );
	$q->set_query_params( $query );
	foreach ( $headers as $k => $v ) {
		$q->set_header( $k, $v );
	}
	$r = rest_do_request( $q );
	return array( $r->get_status(), json_decode( (string) wp_json_encode( $r->get_data() ), true ) );
};
use ClientNav\Headless as H;
$secret = str_repeat( 'k', 44 );
$saved  = get_option( 'clientnav_headless' );
wp_set_current_user( 0 );

list( $st ) = $ask( '/posts' );
$is( 'door open (the default): anyone may read', 200 === $st, $st );

update_option( 'clientnav_headless', array( 'secret' => $secret, 'door' => 'closed' ) );
list( $st, $d ) = $ask( '/posts' );
$is( 'door closed: an anonymous request is refused', 401 === $st && 'clientnav_headless_closed' === ( $d['code'] ?? '' ), array( $st, $d ) );
list( $st ) = $ask( '/posts', array( 'Authorization' => 'Bearer ' . $secret ) );
$is( 'door closed: the front end with the shared secret is answered', 200 === $st, $st );
list( $st ) = $ask( '/posts', array( 'X-ClientNav-Door-Key' => $secret ) );
$is( 'door closed: the key in its own header works too', 200 === $st, $st );
list( $st ) = $ask( '/posts', array( 'Authorization' => 'Bearer ' . str_repeat( 'x', 44 ) ) );
$is( 'door closed: a wrong key is refused', 401 === $st, $st );
$all = array( '/site' => array(), '/resolve' => array( 'path' => '/' ), '/terms' => array( 'taxonomy' => 'category' ), '/authors' => array(), '/paths' => array(), '/feed' => array(), '/months' => array(), '/month' => array( 'month' => '2025-03' ), '/day' => array( 'day' => '2025-03-10' ), '/index' => array(), '/forms/inquiry' => array(), '/part' => array( 'where' => 'footer' ) );
$open = array();
foreach ( $all as $route => $query ) {
	list( $st ) = $ask( $route, array(), 'GET', $query );
	if ( 401 !== $st ) {
		$open[ $route ] = $st;
	}
}
$is( 'door closed: every read route refuses an anonymous request', array() === $open, $open );
list( $st ) = $ask( '/pair', array(), 'POST' );
$is( 'door closed: pairing still answers (its one-time code is its own lock)', 401 !== $st, $st );
$token = H\sign_token( array( 'pid' => 999999, 'uid' => 1, 'scope' => 'preview', 'exp' => time() + 60 ) );
list( $st ) = $ask( '/preview', array(), 'GET', array( 'token' => $token ) );
$is( 'door closed: a preview with its signed token is not stopped by the door', 401 !== $st, $st );
wp_set_current_user( 1 );
list( $st ) = $ask( '/posts' );
$is( 'door closed: an editor signed in may still read', 200 === $st, $st );
wp_set_current_user( 0 );

// WordPress's own REST API.
$is( 'REST open (the default): nothing refused', null === H\rest_refusal( '/wp/v2/posts', '' ) || ! H\rest_closed() );
update_option( 'clientnav_headless', array( 'secret' => $secret, 'door' => 'closed', 'rest' => 'closed' ) );
$r = H\rest_refusal( '/wp/v2/posts', '' );
$is( 'REST closed: an anonymous request for posts is refused', is_wp_error( $r ) && 401 === ( $r->get_error_data()['status'] ?? 0 ), $r );
$is( 'REST closed: the shared secret opens it', null === H\rest_refusal( '/wp/v2/posts', 'Bearer ' . $secret ) );
$is( 'REST closed: Client Navigator, PR Register, Translate, oEmbed and the door itself stay reachable', null === H\rest_refusal( '/clientnav/v1/health', '' ) && null === H\rest_refusal( '/prregister/v1/head', '' ) && null === H\rest_refusal( '/cntr/v1/languages', '' ) && null === H\rest_refusal( '/oembed/1.0/embed', '' ) && null === H\rest_refusal( '/clientnav-headless/v1/site', '' ) );
$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/users';
$is( 'REST closed: the authentication filter refuses an anonymous visitor', is_wp_error( apply_filters( 'rest_authentication_errors', null ) ) );
wp_set_current_user( 1 );
$is( 'REST closed: a signed-in editor passes', null === apply_filters( 'rest_authentication_errors', null ) );
wp_set_current_user( 0 );
unset( $GLOBALS['wp']->query_vars['rest_route'] );

// The pair check.
$answers = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$answers ) {
		foreach ( $answers as $part => $code ) {
			if ( false !== strpos( $url, $part ) ) {
				return is_wp_error( $code ) ? $code : array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
			}
		}
		return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);
$answers = array( '/api/preview/' => 307 );
$c       = H\pair_check();
$is( 'pair check: the front end accepts the signature, so it holds the same secret', true === $c['reachable'] && 'same' === $c['secret'], $c );
$answers = array( '/api/preview/' => 401 );
$c       = H\pair_check();
$is( 'pair check: refused, so it holds another secret', 'different' === $c['secret'], $c );
$answers = array( '/api/preview/' => 404 );
$c       = H\pair_check();
$is( 'pair check: no preview address there, so it cannot tell', 'unknown' === $c['secret'], $c );
$answers = array( '/' => new WP_Error( 'down', 'Could not resolve host' ) );
$c       = H\pair_check();
$is( 'pair check: a front end that does not answer', false === $c['reachable'] && false !== strpos( $c['said'], 'Could not resolve host' ), $c );
$is( 'pair check: the last one is kept', ( get_option( 'clientnav_headless_last_check' )['said'] ?? '' ) === $c['said'] );

// The last refresh calls.
$answers = array();
delete_option( 'clientnav_headless_refreshes' );
update_option( 'clientnav_headless', array( 'secret' => $secret, 'refresh' => 'https://front.example.test' ) );
H\Refresh::path( '/one/' );
H\Refresh::send();
H\Refresh::all();
H\Refresh::send();
$calls = H\refresh_history();
$is( 'refresh calls: kept newest first', 2 === count( $calls ) && true === $calls[0]['all'] && 1 === $calls[1]['paths'] && 200 === $calls[0]['code'], $calls );
$abilities = apply_filters( 'clientnav_abilities', array() );
$status    = call_user_func( $abilities['clientnav/headless-plugin-status']['run'], array() );
$is( 'status: the check ability is there; status shows door, REST API, refresh calls and the last check', isset( $abilities['clientnav/headless-check'] ) && false === $abilities['clientnav/headless-check']['write'] && array_key_exists( 'last_check', $status ) && 2 === count( $status['refreshes'] ?? array() ) && 'open' === ( $status['door'] ?? '' ), $status );
$before = count( H\refresh_history() );
$ran    = call_user_func( $abilities['clientnav/headless-refresh']['run'], array() );
H\Refresh::send();
$last = H\refresh_history()[0] ?? array();
$is( 'the refresh ability: a write; the front end is told to refresh everything', true === $abilities['clientnav/headless-refresh']['write'] && true === ( $ran['refreshed'] ?? null ) && true === ( $last['all'] ?? null ) && count( H\refresh_history() ) === $before + 1, array( $ran, $last ) );

false === $saved ? delete_option( 'clientnav_headless' ) : update_option( 'clientnav_headless', $saved );
delete_option( 'clientnav_headless_last_check' );
delete_option( 'clientnav_headless_refreshes' );
delete_option( 'clientnav_headless_last_refresh' );
echo "$pass passed, $fail failed\n";
