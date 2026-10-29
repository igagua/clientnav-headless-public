<?php
// Where the front end's address comes from: the Connector first, the own setting only without it.
// Modes: (none) no Connector; connector: it answers; connector-empty: it answers '';
//        constant: no Connector, CLIENTNAV_FRONT_URL set; filter: no Connector, filter clientnav_front_url changes the own value.
define( 'ABSPATH', __DIR__ );
$GLOBALS['opt'] = array( 'frontend' => 'https://own.example.com/' );
function add_action() {}
function add_filter() {}
function get_option( $k, $d = array() ) { return $GLOBALS['opt']; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
$mode = $argv[1] ?? '';
function apply_filters( $tag, $value ) { return 'filter' === $GLOBALS['mode'] && 'clientnav_front_url' === $tag ? 'https://filtered.example.com/' : $value; }
if ( 'constant' === $mode ) {
	define( 'CLIENTNAV_FRONT_URL', 'https://const.example.com/' );
}
if ( 'connector' === $mode ) {
	function clientnav_front_url() { return 'https://www.example.com/'; }
} elseif ( 'connector-empty' === $mode ) {
	function clientnav_front_url() { return ''; }
}
require dirname( __DIR__ ) . '/plugin/clientnav-headless/includes/settings.php';
$want = array( 'connector' => 'https://www.example.com', 'constant' => 'https://const.example.com', 'filter' => 'https://filtered.example.com' )[ $mode ] ?? 'https://own.example.com';
$got  = ClientNav\Headless\frontend_url();
if ( $got !== $want ) {
	fwrite( STDERR, "FAIL ($mode): got $got, want $want\n" );
	exit( 1 );
}
echo 'ok ' . ( $mode ?: 'no connector' ) . ": $got\n";
