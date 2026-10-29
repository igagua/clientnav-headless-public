<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * What WordPress keeps for itself.
 *
 * Some parts of a site are run by WordPress and its plugins and are not rebuilt on the front end: the shop,
 * the cart and checkout, a customer's account, signing in. They stay on WordPress, at an address of its own
 * (Settings > Headless: "Account address"), and the list of their paths is "Kept by WordPress".
 *
 *  - A link to a kept path is given to the front end as a full address on the account address.
 *  - The front end asking what lives at a kept path is told to send the visitor there.
 *  - A visitor who opens any other page on WordPress itself is sent to that page on the front end
 *    ("Send visitors to the front end"); people who are signed in are left alone, so editors and customers
 *    can still see what WordPress draws.
 */
function account_url(): string {
	return untrailingslashit( setting( 'account' ) );
}

function kept_paths(): array {
	$own = array_filter( array_map( static fn( $p ) => trim( $p, " \t/" ), preg_split( '/[\s,]+/', setting( 'keep' ) ) ) );
	$all = $own ? $own : array( 'account', 'shop', 'cart', 'checkout', 'receipt', 'review-your-order', 'signup', 'item' );
	return array_values( (array) apply_filters( 'clientnav_headless_kept_paths', $all ) );
}

/** True for a path WordPress keeps: one of the kept paths or anything under it, and WordPress's own sign-in and admin. */
function is_kept( string $path ): bool {
	$path = '/' . ltrim( (string) wp_parse_url( $path, PHP_URL_PATH ), '/' );
	if ( preg_match( '#^/(wp-login\.php|wp-admin(/|$)|wp-signup\.php|wp-activate\.php)#', $path ) ) {
		return true;
	}
	foreach ( kept_paths() as $kept ) {
		if ( $path === "/$kept" || str_starts_with( $path, "/$kept/" ) ) {
			return true;
		}
	}
	return false;
}

/** A kept path as a full address on the account address; addresses of this site inside it (redirect_to=...) follow. */
function on_account( string $path_and_query ): string {
	$new = (string) wp_parse_url( account_url(), PHP_URL_HOST );
	foreach ( own_hosts() as $host ) {
		if ( $host !== $new ) {
			$path_and_query = str_replace( array( '://' . $host . '/', rawurlencode( '://' . $host . '/' ) ), array( '://' . $new . '/', rawurlencode( '://' . $new . '/' ) ), $path_and_query );
		}
	}
	return account_url() . '/' . ltrim( $path_and_query, '/' );
}

add_action(
	'template_redirect',
	static function () {
		if ( '1' !== setting( 'send_visitors' ) || '' === frontend_url() || is_user_logged_in() || is_preview() || is_feed() || is_robots() || is_trackback() ) {
			return;
		}
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$uri    = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query  = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		// Only plain page views are sent on: anything with a query may be a plugin at work (a checkout step, a download).
		if ( 'GET' !== $method || '' !== $query || is_kept( $path ) || ! apply_filters( 'clientnav_headless_send_visitor', true, $path ) ) {
			return;
		}
		nocache_headers();
		wp_redirect( frontend_url() . $path, 302, 'Client Navigator Headless' ); // phpcs:ignore WordPress.Security.SafeRedirect -- the front end is another host by design.
		exit;
	}
);
