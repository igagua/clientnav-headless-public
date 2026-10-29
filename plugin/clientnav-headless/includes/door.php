<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * Who may knock: the closed door, and WordPress's own content API closed with it.
 *
 * Door (setting "Door" = closed, or CLIENTNAV_HEADLESS_DOOR 'closed'): every read route of /clientnav-headless/v1
 * answers only a request carrying the shared secret (Authorization: Bearer <secret>, or X-ClientNav-Door-Key), as the
 * front end's server does. Previews keep their own signed token and /pair its one-time code. With the door open
 * (the default) anyone can read what the site publishes anyway.
 *
 * WordPress's own content API (setting "WordPress REST API" = closed, or CLIENTNAV_HEADLESS_REST 'closed'): anonymous
 * requests to it are refused, except the routes other Client Navigator plugins hand to the front end or the platform
 * (clientnav/v1, prregister/v1, cntr/v1, oEmbed; filter clientnav_headless_open_rest). Signed-in users (the editors,
 * the block editor, Elementor) and requests with the shared secret pass. Switch it on once the front end reads
 * everything through the door: with both closed, the back office shows almost nothing to the outside.
 */
function door_closed(): bool {
	return (bool) apply_filters( 'clientnav_headless_door_closed', 'closed' === strtolower( trim( setting( 'door' ) ) ) );
}

function rest_closed(): bool {
	return (bool) apply_filters( 'clientnav_headless_rest_closed', 'closed' === strtolower( trim( setting( 'rest' ) ) ) );
}

/** Whether a request carries the shared secret (32 characters or more). */
function has_key( \WP_REST_Request $r ): bool {
	$secret = setting( 'secret' );
	$given  = (string) $r->get_header( 'x_clientnav_door_key' );
	if ( '' === $given ) {
		$given = (string) preg_replace( '/^Bearer\s+/i', '', (string) $r->get_header( 'authorization' ) );
	}
	return strlen( $secret ) >= 32 && '' !== $given && hash_equals( $secret, $given );
}

/** The permission of every read route of the door. */
function door_allows( \WP_REST_Request $r ) {
	if ( ! door_closed() || has_key( $r ) || current_user_can( 'edit_posts' ) ) {
		return true;
	}
	return new \WP_Error( 'clientnav_headless_closed', 'This door answers its front end only.', array( 'status' => 401 ) );
}

add_filter(
	'rest_authentication_errors',
	static function ( $result ) {
		if ( null !== $result || ! rest_closed() || is_user_logged_in() ) {
			return $result;
		}
		$route = (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' );
		if ( '' === $route && isset( $_SERVER['REQUEST_URI'] ) ) {
			$path  = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
			$base  = (string) wp_parse_url( rest_url(), PHP_URL_PATH );
			$route = 0 === strpos( $path, $base ) ? '/' . ltrim( substr( $path, strlen( $base ) ), '/' ) : '';
		}
		return rest_refusal( $route, (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_CLIENTNAV_DOOR_KEY'] ?? '' ) );
	}
);

/** null when an anonymous request to this route may go on, otherwise the refusal. Kept apart so it can be tested. */
function rest_refusal( string $route, string $given ) {
	$open = (array) apply_filters( 'clientnav_headless_open_rest', array( '/' . REST_NS, '/clientnav/v1', '/prregister/v1', '/cntr/v1', '/oembed/1.0' ) );
	foreach ( $open as $prefix ) {
		if ( '' !== $prefix && 0 === strpos( $route, (string) $prefix ) ) {
			return null; // the door itself has its own rule (door_allows)
		}
	}
	$secret = setting( 'secret' );
	$given  = (string) preg_replace( '/^Bearer\s+/i', '', $given );
	if ( strlen( $secret ) >= 32 && '' !== $given && hash_equals( $secret, $given ) ) {
		return null;
	}
	return new \WP_Error( 'clientnav_headless_rest_closed', 'This content interface is closed; the site is read through its front end.', array( 'status' => 401 ) );
}
