<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * The "seo" part of the door's answers: what belongs in the head of the page on the front end.
 *
 * With PR Register 0.21 or later on this WordPress the fields are the ones its headless API gives for the same
 * page (title, description, canonical, robots, og, twitter, schema, breadcrumbs, summary, faq, modified), so the
 * front end gets them in the same answer as the page and can hand them to PR Register's front-end kit as they are.
 * Without it, or when it fails, only the entry's own title and description are there. "source" says which.
 *
 *   - An empty field from PR Register never replaces the door's own value.
 *   - Fields that carry addresses (canonical, og.url, schema, breadcrumbs) are passed on only when PR Register
 *     writes them for this front end; otherwise they would name the back office.
 */
/**
 * Whether entries being made now are items of a list: then PR Register is not asked per item. Each head is a
 * Resolver run and a Schema graph; 20 to 50 of them on every uncached list request is what would hurt a crawled
 * archive (PR Register chat, measured on a large news site 2026-10-14). A list has its own head (term, posts page, home).
 */
function in_list( ?bool $set = null ): bool {
	static $on = false;
	if ( null !== $set ) {
		$on = $set;
	}
	return $on;
}

function seo( string $kind, $object, array $own ): object {
	$out  = $own + array( 'found' => true, 'source' => 'door' );
	$head = in_list() ? null : pr_register_head( $kind, $object );
	if ( null !== $head ) {
		unset( $head['html'], $head['status'] );
		$front     = frontend_url();
		$canonical = (string) ( $head['canonical'] ?? '' );
		$fronted   = '' !== $front && pr_register_fronted() && ( '' === $canonical || $canonical === $front || 0 === strpos( $canonical, $front . '/' ) );
		if ( ! $fronted ) {
			unset( $head['canonical'], $head['schema'], $head['breadcrumbs'] );
			if ( isset( $head['og'] ) && is_array( $head['og'] ) ) {
				unset( $head['og']['url'] );
			}
		}
		foreach ( $head as $key => $value ) {
			if ( null !== $value && '' !== $value && array() !== $value ) {
				$out[ $key ] = $value;
			}
		}
		$out['source'] = 'pr-register';
		// Words for a page title and a sharing card are plain text: PR Register hands some of them over with HTML entities ("Cloud &amp; collaboration").
		foreach ( array( 'title', 'description' ) as $k ) {
			if ( isset( $out[ $k ] ) && is_string( $out[ $k ] ) ) {
				$out[ $k ] = plain( $out[ $k ] );
			}
			foreach ( array( 'og', 'twitter' ) as $card ) {
				if ( isset( $out[ $card ][ $k ] ) && is_string( $out[ $card ][ $k ] ) ) {
					$out[ $card ][ $k ] = plain( $out[ $card ][ $k ] );
				}
			}
		}
	}
	return (object) apply_filters( 'clientnav_headless_seo', $out, $object, $kind );
}

/** PR Register's answer for a page that exists; null when the plugin is absent, too old, fails, or does not know the page. */
function pr_register_head( string $kind, $object ): ?array {
	if ( ! is_callable( array( '\PRRegister\Headless', 'head' ) ) ) {
		return null;
	}
	try {
		$head = \PRRegister\Headless::head( $kind, $object, true );
	} catch ( \Throwable $e ) {
		return null;
	}
	return is_array( $head ) && ! empty( $head['found'] ) ? $head : null;
}

/** Whether PR Register writes its addresses for a front end (its headless mode is on). */
function pr_register_fronted(): bool {
	try {
		return is_callable( array( '\PRRegister\Headless', 'on' ) ) && (bool) \PRRegister\Headless::on();
	} catch ( \Throwable $e ) {
		return false;
	}
}
