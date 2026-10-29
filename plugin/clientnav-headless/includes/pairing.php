<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * Connecting a front end without copying a secret by hand.
 *
 * An administrator asks for a pairing code (Settings > Headless, or pairing_code() through Client Navigator).
 * It is good for fifteen minutes and for one use. The front end's machine sends it once:
 *   POST /wp-json/clientnav-headless/v1/pair   {"code": "..."}
 * and gets back the shared secret, which it keeps in its own settings. Nobody needs to see the secret:
 * WordPress makes it the first time a code is asked for, if none is set.
 */
const PAIRING_OPTION = 'clientnav_headless_pairing';
const PAIRING_MINUTES = 15;
const PAIRING_TRIES   = 5;

/** A new one-time code; the shared secret is made first when there is none. The code is kept only as a hash. */
function pairing_code(): string {
	if ( strlen( setting( 'secret' ) ) < 32 && ! defined( 'CLIENTNAV_HEADLESS_SECRET' ) ) {
		$all           = get_option( 'clientnav_headless', array() );
		$all           = is_array( $all ) ? $all : array();
		$all['secret'] = wp_generate_password( 64, false, false );
		update_option( 'clientnav_headless', $all );
	}
	$code = strtoupper( implode( '-', str_split( wp_generate_password( 12, false, false ), 4 ) ) );
	update_option( PAIRING_OPTION, array( 'hash' => hash( 'sha256', $code ), 'until' => time() + PAIRING_MINUTES * MINUTE_IN_SECONDS, 'tries' => 0 ), false );
	return $code;
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route( REST_NS, '/pair', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => __NAMESPACE__ . '\\rest_pair' ) );
	}
);

function rest_pair( \WP_REST_Request $r ): \WP_REST_Response {
	$kept  = get_option( PAIRING_OPTION );
	$given = strtoupper( trim( (string) $r['code'] ) );
	$no    = static fn( string $why, int $status ) => new \WP_REST_Response( array( 'ok' => false, 'error' => $why ), $status, array( 'Cache-Control' => 'no-store' ) );
	if ( ! is_array( $kept ) || (int) ( $kept['until'] ?? 0 ) < time() ) {
		delete_option( PAIRING_OPTION );
		return $no( 'No pairing code is open. Ask for a new one under Settings > Headless.', 404 );
	}
	if ( '' === $given || ! hash_equals( (string) $kept['hash'], hash( 'sha256', $given ) ) ) {
		$kept['tries'] = (int) ( $kept['tries'] ?? 0 ) + 1;
		// A few wrong guesses close the code, so it cannot be tried out.
		$kept['tries'] >= PAIRING_TRIES ? delete_option( PAIRING_OPTION ) : update_option( PAIRING_OPTION, $kept, false );
		return $no( 'This is not the open pairing code.', 403 );
	}
	delete_option( PAIRING_OPTION );
	return new \WP_REST_Response(
		array(
			'ok'       => true,
			'secret'   => setting( 'secret' ),
			'frontend' => frontend_url(),
			'door'     => rest_url( REST_NS ),
		),
		200,
		array( 'Cache-Control' => 'no-store' )
	);
}

// Settings > Headless: a button that shows a new code once, to the administrator who asked for it.
add_action(
	'admin_post_clientnav_headless_pair',
	static function () {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'clientnav_headless_pair' ) ) {
			wp_die( 'Not allowed.' );
		}
		set_transient( 'clientnav_headless_code_' . get_current_user_id(), pairing_code(), PAIRING_MINUTES * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=clientnav-headless#pairing' ) );
		exit;
	}
);

function pairing_section(): void {
	$key  = 'clientnav_headless_code_' . get_current_user_id();
	$code = (string) get_transient( $key );
	delete_transient( $key );
	echo '<h2 id="pairing">Connect a front end</h2><p>A one-time code, good for ' . (int) PAIRING_MINUTES . ' minutes. The front end\'s machine sends it to <code>' . esc_html( rest_url( REST_NS . '/pair' ) ) . '</code> and gets the shared secret back; nobody has to copy the secret. Asking for a new code closes the old one.</p>';
	if ( '' !== $code ) {
		printf( '<p style="font-size:1.4em"><strong>%s</strong></p>', esc_html( $code ) );
	}
	printf( '<form method="post" action="%s"><input type="hidden" name="action" value="clientnav_headless_pair">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'clientnav_headless_pair' );
	submit_button( 'Make a pairing code', 'secondary', 'submit', false );
	echo '</form>';
}
