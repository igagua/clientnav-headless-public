<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * Is the pair working? A check without side effects: the plugin signs a preview link for an entry that does not
 * exist and asks the front end's /api/preview/ for it. A front end holding the same secret accepts the signature and
 * answers with a redirect to the preview page; one with another secret refuses (401). Nothing is refreshed, nothing
 * is kept. The answer is kept as the last check, beside the last refresh calls, for the status (settings page,
 * Client Navigator's clientnav/headless-plugin-status and clientnav/headless-check, wp clientnav-headless check).
 */
function pair_check(): array {
	$front = frontend_url();
	$out   = array( 'at' => gmdate( 'c' ), 'frontend' => $front, 'reachable' => false, 'secret' => 'unknown', 'said' => '' );
	if ( '' === $front ) {
		$out['said'] = 'No front end address is set.';
		return keep_check( $out );
	}
	$home = wp_remote_get( $front . '/', array( 'timeout' => 8, 'redirection' => 2 ) );
	$out['reachable'] = ! is_wp_error( $home ) && wp_remote_retrieve_response_code( $home ) < 500;
	if ( ! $out['reachable'] ) {
		$out['said'] = 'The front end does not answer: ' . ( is_wp_error( $home ) ? $home->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $home ) );
		return keep_check( $out );
	}
	$token = sign_token( array( 'pid' => 0, 'uid' => 0, 'scope' => 'preview', 'exp' => time() + 60 ) );
	if ( '' === $token ) {
		$out['secret'] = 'none';
		$out['said']   = 'No shared secret here yet: pair the front end (Settings > Headless).';
		return keep_check( $out );
	}
	$answer = wp_remote_get( add_query_arg( 'token', rawurlencode( $token ), $front . '/api/preview/' ), array( 'timeout' => 8, 'redirection' => 0 ) );
	$code   = is_wp_error( $answer ) ? 0 : (int) wp_remote_retrieve_response_code( $answer );
	if ( $code >= 300 && $code < 400 ) {
		$out['secret'] = 'same';
		$out['said']   = 'Paired: the front end holds the same secret.';
	} elseif ( 401 === $code || 403 === $code ) {
		$out['secret'] = 'different';
		$out['said']   = 'The front end refused the signature: it holds another secret. Pair again.';
	} else {
		$out['secret'] = 'unknown';
		$out['said']   = 'The front end has no preview address the plugin can check (' . ( $code ? 'HTTP ' . $code : ( is_wp_error( $answer ) ? $answer->get_error_message() : 'no answer' ) ) . ').';
	}
	// Whether the front end draws unsaved blocks itself (/live-preview/); without it the editor's live preview
	// shows the draft after each pause instead (src/live.jsx).
	$live              = wp_remote_get( $front . '/live-preview/', array( 'timeout' => 8, 'redirection' => 0 ) );
	$out['live_page'] = ! is_wp_error( $live ) && 404 !== (int) wp_remote_retrieve_response_code( $live ) && (int) wp_remote_retrieve_response_code( $live ) < 500;
	return keep_check( $out );
}

function keep_check( array $out ): array {
	update_option( 'clientnav_headless_last_check', $out, false );
	return $out;
}

/** The last refresh calls, newest first (refresh.php adds them). */
function refresh_history(): array {
	return array_values( (array) get_option( 'clientnav_headless_refreshes', array() ) );
}

function remember_refresh( array $call ): void {
	$all = refresh_history();
	array_unshift( $all, $call );
	update_option( 'clientnav_headless_refreshes', array_slice( $all, 0, 10 ), false );
	update_option( 'clientnav_headless_last_refresh', $call, false );
}

// Settings > Headless: "Check the front end".
add_action(
	'admin_post_clientnav_headless_check',
	static function () {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'clientnav_headless_check' ) ) {
			wp_die( 'Not allowed.' );
		}
		pair_check();
		wp_safe_redirect( admin_url( 'options-general.php?page=clientnav-headless#state' ) );
		exit;
	}
);

/** The state block of the settings page. */
function state_section(): void {
	$check = (array) get_option( 'clientnav_headless_last_check', array() );
	$calls = refresh_history();
	echo '<h2 id="state">State</h2><table class="widefat striped" style="max-width:860px"><tbody>';
	$row = static fn( string $k, string $v ) => printf( '<tr><th style="width:220px">%s</th><td>%s</td></tr>', esc_html( $k ), esc_html( $v ) );
	$row( 'Front end', '' !== frontend_url() ? frontend_url() : 'none' );
	$row( 'Shared secret', strlen( setting( 'secret' ) ) >= 32 ? 'set' : 'not set' );
	$row( 'Door', door_closed() ? 'closed: answers its front end only' : 'open' );
	$row( 'WordPress REST API', rest_closed() ? 'closed to anonymous requests' : 'open' );
	$row( 'Last check', $check ? $check['at'] . ': ' . $check['said'] : 'never' );
	foreach ( array_slice( $calls, 0, 5 ) as $i => $c ) {
		$row( 0 === $i ? 'Last refresh calls' : '', $c['at'] . ': HTTP ' . $c['code'] . ( $c['all'] ? ', everything' : ', ' . $c['paths'] . ' addresses' ) . ( $c['gone'] ? ', ' . $c['gone'] . ' gone' : '' ) );
	}
	echo '</tbody></table>';
	printf( '<form method="post" action="%s" style="margin-top:8px"><input type="hidden" name="action" value="clientnav_headless_check">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'clientnav_headless_check' );
	submit_button( 'Check the front end', 'secondary', 'submit', false );
	echo '</form>';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command(
		'clientnav-headless check',
		static function () {
			$r = pair_check();
			\WP_CLI::log( (string) wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			'same' === $r['secret'] ? \WP_CLI::success( $r['said'] ) : \WP_CLI::warning( $r['said'] );
		}
	);
}
