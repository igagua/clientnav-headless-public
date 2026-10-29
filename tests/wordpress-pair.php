<?php
// Runs inside the throwaway WordPress (wp eval-file): pairing a front end with a one-time code.
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
$pair = function ( string $code ) {
	$q = new WP_REST_Request( 'POST', '/clientnav-headless/v1/pair' );
	$q->set_body_params( array( 'code' => $code ) );
	$r = rest_do_request( $q );
	return array( $r->get_status(), $r->get_data(), $r->get_headers() );
};
$before = get_option( 'clientnav_headless' );

list( $st ) = $pair( 'NOPE-NOPE-NOPE' );
$is( 'no code open: nothing to pair with', 404 === $st, $st );

update_option( 'clientnav_headless', array( 'blocks' => 'off' ) );
$code = ClientNav\Headless\pairing_code();
$made = ClientNav\Headless\setting( 'secret' );
$is( 'asking for a code makes a long secret when there is none', strlen( $made ) >= 32 && 'off' === get_option( 'clientnav_headless' )['blocks'], strlen( $made ) );
$is( 'the code is kept only as a hash', false === strpos( wp_json_encode( get_option( 'clientnav_headless_pairing' ) ), $code ) && preg_match( '/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code ), $code );

list( $st, $d ) = $pair( 'WRON-GCOD-E000' );
$is( 'a wrong code is refused and tells nothing', 403 === $st && ! isset( $d['secret'] ), $st );
list( $st, $d, $h ) = $pair( strtolower( $code ) );
$is( 'the right code (any letter case) gets the secret once, not kept anywhere on the way', 200 === $st && $made === ( $d['secret'] ?? '' ) && 'no-store' === ( $h['Cache-Control'] ?? '' ), array( $st, $h ) );
list( $st ) = $pair( $code );
$is( 'the same code a second time: closed', 404 === $st, $st );

$second = ClientNav\Headless\pairing_code();
$is( 'a second code keeps the secret that exists', $made === ClientNav\Headless\setting( 'secret' ) );
for ( $i = 0; $i < 5; $i++ ) {
	$pair( 'WRON-GCOD-E00' . $i );
}
list( $st ) = $pair( $second );
$is( 'five wrong guesses close the code', 404 === $st, $st );

$third = ClientNav\Headless\pairing_code();
$kept  = get_option( 'clientnav_headless_pairing' );
$kept['until'] = time() - 1;
update_option( 'clientnav_headless_pairing', $kept, false );
list( $st ) = $pair( $third );
$is( 'a code after its fifteen minutes: closed', 404 === $st, $st );

$token = ClientNav\Headless\sign_token( array( 'pid' => 1, 'uid' => 1, 'scope' => 'preview', 'exp' => time() + 60 ) );
$is( 'with the secret made, previews are signed', '' !== $token && null !== ClientNav\Headless\read_token( $token, 'preview' ) );

// The Connector's clientnav/headless-set writes array( 'front_url' => … ) into this same option (unpaired two sites before 0.26.1).
update_option( 'clientnav_headless', array( 'front_url' => 'https://front.example.com' ), true );
$all = get_option( 'clientnav_headless' );
$is( 'the Connector writing its front_url keeps the secret and the other settings', $made === ClientNav\Headless\setting( 'secret' ) && 'off' === ( $all['blocks'] ?? '' ) && 'https://front.example.com' === ( $all['front_url'] ?? '' ), array_keys( (array) $all ) );
update_option( 'clientnav_headless', array( 'blocks' => '', 'secret' => $made ) );
$all = get_option( 'clientnav_headless' );
$is( 'saving this plugin\'s settings keeps the Connector\'s front_url', 'https://front.example.com' === ( $all['front_url'] ?? '' ) && '' === $all['blocks'], array_keys( (array) $all ) );

delete_option( 'clientnav_headless' );
false === $before || add_option( 'clientnav_headless', $before );
echo "$pass passed, $fail failed\n";
