<?php
/**
 * Podcasts through Client Navigator Podcast (0.1.0 and later, its class \ClientNav\Podcast\Api): the shape fixed with
 * the podcast plugin author on 2026-10-17. The plugin gives the data through its PHP API, so
 * the door needs no SQL and no knowledge of how episodes are stored.
 *
 *   GET /episodes?show=&page=&per_page=   a show and its episodes: { show, items: [card + "episode"], total, pages,
 *                                         page, more }. No plugin or no show: 200 with show null. Unknown show: 404.
 *   GET /episodes?shows=1                 { shows: [ { slug, title, feed, count } ] }
 *   GET /episodes/feed?show=              the show's RSS with the front end's addresses (guids and audio untouched)
 *
 * The plugin's own /feed/<show>/ on the CMS gets the same addresses (filter clientnav_podcast_feed_args), so podcast
 * apps reading the WordPress address see the front end's pages too.
 *
 * @package ClientNav\Headless
 */

namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

function podcast_ready(): bool {
	return class_exists( '\ClientNav\Podcast\Api' );
}

/** The front end's address, or the CMS's when there is none. */
function podcast_front(): string {
	return '' !== frontend_url() ? frontend_url() : untrailingslashit( home_url() );
}

/** A site address as a full front-end address; other addresses as they are. */
function podcast_abs( string $url ): string {
	$path = to_path( $url );
	return ( '' !== $path && '/' === $path[0] && '/' !== ( $path[1] ?? '' ) ) ? podcast_front() . $path : $path;
}

/** Show notes with the site's links on the front end, as full addresses: podcast apps have no page to resolve paths against. */
function podcast_html( string $html ): string {
	return (string) preg_replace_callback( '/\b(href|src)=(["\'])(\/(?!\/)[^"\']*)\2/i', static fn( $m ) => $m[1] . '=' . $m[2] . podcast_front() . $m[3] . $m[2], localize_html( $html ) );
}

/** What the plugin's feed needs to name the front end. */
function podcast_feed_args( ?string $feed_path ): array {
	return array(
		'link' => __NAMESPACE__ . '\\podcast_abs',
		'self' => null !== $feed_path ? podcast_front() . $feed_path : null,
		'html' => __NAMESPACE__ . '\\podcast_html',
	);
}

function rest_episodes( \WP_REST_Request $r ) {
	if ( ! podcast_ready() ) {
		return ! empty( $r['shows'] ) ? array( 'shows' => array() ) : array( 'show' => null, 'items' => array(), 'total' => 0, 'pages' => 0, 'page' => 1, 'more' => false );
	}
	if ( ! empty( $r['shows'] ) ) {
		return array( 'shows' => array_values( (array) \ClientNav\Podcast\Api::shows() ) );
	}
	$slug = isset( $r['show'] ) && '' !== (string) $r['show'] ? sanitize_title( (string) $r['show'] ) : null;
	if ( null !== $slug && ! \ClientNav\Podcast\Api::show( $slug ) ) {
		return new \WP_Error( 'clientnav_no_show', 'No such show.', array( 'status' => 404 ) );
	}
	$per  = max( 1, min( 100, (int) ( $r['per_page'] ?? 20 ) ) );
	$page = max( 1, (int) ( $r['page'] ?? 1 ) );
	$out  = (array) \ClientNav\Podcast\Api::episodes( $slug, $page, $per );
	$items = array();
	$was   = in_list();
	in_list( true );
	try {
		foreach ( (array) ( $out['items'] ?? array() ) as $it ) {
			$post = get_post( (int) $it['id'] );
			if ( $post instanceof \WP_Post && 'publish' === $post->post_status && '' === $post->post_password ) {
				$items[] = card( $post ) + array( 'episode' => $it['episode'] );
			}
		}
	} finally {
		in_list( $was );
	}
	$out['items'] = $items;
	return $out;
}

function rest_episodes_feed( \WP_REST_Request $r ) {
	if ( ! podcast_ready() ) {
		return new \WP_Error( 'clientnav_no_show', 'No podcast on this site.', array( 'status' => 404 ) );
	}
	$slug = isset( $r['show'] ) && '' !== (string) $r['show'] ? sanitize_title( (string) $r['show'] ) : null;
	$show = \ClientNav\Podcast\Api::show( $slug );
	if ( ! $show ) {
		return new \WP_Error( 'clientnav_no_show', 'No such show.', array( 'status' => 404 ) );
	}
	$xml = \ClientNav\Podcast\Api::feed( $show['slug'], podcast_feed_args( (string) $show['feed'] ) );
	return is_string( $xml ) ? array( 'xml' => $xml ) : new \WP_Error( 'clientnav_no_show', 'No such show.', array( 'status' => 404 ) );
}

add_action(
	'rest_api_init',
	static function (): void {
		$open = array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\door_allows' );
		register_rest_route( REST_NS, '/episodes', $open + array( 'callback' => kept_route( 'episodes', lang_route( 'episodes', __NAMESPACE__ . '\\rest_episodes' ) ) ) );
		register_rest_route( REST_NS, '/episodes/feed', $open + array( 'callback' => kept_route( 'episodes-feed', __NAMESPACE__ . '\\rest_episodes_feed' ) ) );
	}
);

// The feed goes out as the feed itself, not as a JSON string.
add_filter(
	'rest_pre_serve_request',
	static function ( $served, $result, $request ) {
		if ( $served || '/' . REST_NS . '/episodes/feed' !== $request->get_route() || ! $result instanceof \WP_REST_Response ) {
			return $served;
		}
		$data = $result->get_data();
		if ( ! is_array( $data ) || ! isset( $data['xml'] ) ) {
			return $served;
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/rss+xml; charset=UTF-8' );
		}
		echo $data['xml']; // phpcs:ignore WordPress.Security.EscapeOutput -- the plugin's feed, built with every value escaped
		return true;
	},
	10,
	3
);

// The plugin's own /feed/<show>/ on the CMS names the front end too, once there is one.
add_filter(
	'clientnav_podcast_feed_args',
	static function ( $args, $slug ) {
		if ( '' === frontend_url() || ! empty( $args['link'] ) ) {
			return $args;
		}
		$show = podcast_ready() ? \ClientNav\Podcast\Api::show( (string) $slug ) : null;
		return podcast_feed_args( $show ? (string) $show['feed'] : null ) + (array) $args;
	},
	10,
	2
);

// A show's settings live in its term's meta and the plugin's options: changing them changes the answers.
add_action( 'edited_cnpod_show', __NAMESPACE__ . '\\forget' );
add_action( 'delete_cnpod_show', __NAMESPACE__ . '\\forget' );
foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
	add_action(
		$hook,
		static function ( $meta_id, $object_id, $meta_key ) {
			if ( 'cnpod_show' === $meta_key ) {
				forget();
			}
		},
		10,
		3
	);
}
foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
	add_action(
		$hook,
		static function ( $option ) {
			if ( in_array( $option, array( 'cnpod_settings', 'cnpod_feeds' ), true ) ) {
				forget();
			}
		}
	);
}
