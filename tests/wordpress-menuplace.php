<?php
// Runs inside the throwaway WordPress (wp eval-file): a menu put in another place makes the plugin tell the front end
// to refresh everything (seen live on a site 2026-10-14: the footer changed, nothing refreshed).
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
use ClientNav\Headless as H;
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
H\Refresh::send(); // anything queued before goes first
$sent   = array();
$purged = array();

$menu = wp_create_nav_menu( 'Footer columns ' . wp_rand() );
H\Refresh::send();
$sent   = array();
$purged = array();
$places = (array) get_theme_mod( 'nav_menu_locations' );
$places['clientnav_footer'] = $menu;
set_theme_mod( 'nav_menu_locations', $places );
H\Refresh::purge_edge();
H\Refresh::send();
$is( 'a menu put in a place: the front end is told to refresh everything', 1 === count( $sent ) && true === ( $sent[0]['body']['all'] ?? null ), $sent );
$is( 'and the edge copies go too', in_array( '*', $purged, true ), $purged );
$is( 'the door\'s kept answers are forgotten (changed counter moved)', H\changed() > 0 );

// The same place set again (no change): WordPress does not update, nothing is sent.
$sent = array();
set_theme_mod( 'nav_menu_locations', $places );
H\Refresh::send();
$is( 'the same place set again sends nothing', array() === $sent, $sent );

unset( $places['clientnav_footer'] );
set_theme_mod( 'nav_menu_locations', $places );
H\Refresh::send();
wp_delete_nav_menu( $menu );
H\Refresh::send();
false === $saved ? delete_option( 'clientnav_headless' ) : update_option( 'clientnav_headless', $saved );
echo "$pass passed, $fail failed\n";
