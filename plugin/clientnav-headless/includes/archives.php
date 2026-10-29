<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * Archives and the search feed, for sites too big to page through:
 *
 *   GET /months?type=           every month with entries: month, count, last change (one GROUP BY, kept)
 *   GET /month?month=2026-10&type=   that month's entries (up to 5,000): id, path, title, dates; noindex left out (sitemaps)
 *   GET /day?day=2026-10-13&type=    that day's entries as cards (up to 400)
 *   GET /index?after=<id>&limit=&type=   entries in number order with their plain text, for a search engine kept by the
 *                                        front end (Typesense); up to 500 at a time, "next" is the cursor. Never kept.
 *
 * Months and days are the site's own wall clock (WordPress's post_date), as its archive addresses are.
 */
add_action(
	'rest_api_init',
	static function () {
		$open = array( 'permission_callback' => __NAMESPACE__ . '\\door_allows', 'methods' => 'GET' );
		register_rest_route( REST_NS, '/months', $open + array( 'callback' => kept_route( 'months', __NAMESPACE__ . '\\rest_months' ) ) );
		register_rest_route( REST_NS, '/month', $open + array( 'callback' => kept_route( 'month', __NAMESPACE__ . '\\rest_month' ) ) );
		register_rest_route( REST_NS, '/day', $open + array( 'callback' => kept_route( 'day', __NAMESPACE__ . '\\rest_day' ) ) );
		register_rest_route( REST_NS, '/index', $open + array( 'callback' => __NAMESPACE__ . '\\rest_index' ) );
	}
);

/** The types an archive question names (type=a,b), or every public type but pages; null when one is unknown. */
function archive_types( \WP_REST_Request $r ): ?array {
	$asked = array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $r['type'] ?? '' ) ) ) );
	$all   = array_values( array_diff( public_types(), array( 'page' ) ) );
	if ( ! $asked ) {
		return $all;
	}
	return array_diff( $asked, public_types() ) ? null : $asked;
}

function types_sql( array $types ): string {
	return implode( ',', array_map( static fn( $t ) => "'" . esc_sql( (string) $t ) . "'", $types ) );
}

function rest_months( \WP_REST_Request $r ) {
	global $wpdb;
	$types = archive_types( $r );
	if ( null === $types ) {
		return refused( 'No such type.' );
	}
	$in   = types_sql( $types );
	$rows = $wpdb->get_results( "SELECT SUBSTRING(post_date, 1, 7) AS m, COUNT(*) AS n, MAX(post_modified_gmt) AS changed FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status = 'publish' AND post_password = '' GROUP BY m ORDER BY m DESC" ); // phpcs:ignore WordPress.DB
	return array(
		'items' => array_map(
			static fn( $row ) => array( 'month' => (string) $row->m, 'count' => (int) $row->n, 'modified' => mysql_to_rfc3339( (string) $row->changed ) . 'Z' ),
			(array) $rows
		),
	);
}

function rest_month( \WP_REST_Request $r ) {
	$types = archive_types( $r );
	$month = (string) ( $r['month'] ?? '' );
	if ( null === $types || ! preg_match( '/^(\d{4})-(\d{2})$/', $month, $m ) ) {
		return refused( 'Give month=YYYY-MM and, if any, known types.' );
	}
	$q     = new \WP_Query(
		array(
			'post_type'              => $types,
			'post_status'            => 'publish',
			'has_password'           => false,
			'date_query'             => array( array( 'year' => (int) $m[1], 'month' => (int) $m[2] ) ),
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'posts_per_page'         => 5000,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		)
	);
	$items = array();
	foreach ( $q->posts as $post ) {
		if ( noindexed( $post ) ) {
			continue;
		}
		$items[] = array(
			'id'       => (int) $post->ID,
			'type'     => $post->post_type,
			'path'     => to_path( (string) get_permalink( $post ) ),
			'title'    => plain( get_the_title( $post ) ),
			'date'     => mysql_to_rfc3339( $post->post_date_gmt ) . 'Z',
			'local'    => str_replace( ' ', 'T', $post->post_date ),
			'modified' => mysql_to_rfc3339( $post->post_modified_gmt ) . 'Z',
		);
	}
	return array( 'month' => $month, 'items' => $items );
}

function rest_day( \WP_REST_Request $r ) {
	door_with( asked_with( $r ) );
	$types = archive_types( $r );
	$day   = (string) ( $r['day'] ?? '' );
	if ( null === $types || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m ) ) {
		return refused( 'Give day=YYYY-MM-DD and, if any, known types.' );
	}
	$q = new \WP_Query(
		array(
			'post_type'           => $types,
			'post_status'         => 'publish',
			'has_password'        => false,
			'date_query'          => array( array( 'year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3] ) ),
			'orderby'             => 'date',
			'order'               => 'DESC',
			'posts_per_page'      => 400,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		)
	);
	return array( 'day' => $day, 'items' => array_map( __NAMESPACE__ . '\\card', $q->posts ) );
}

/** Not kept: a search engine walks it once, in order, and must see what is there now. */
function rest_index( \WP_REST_Request $r ) {
	global $wpdb;
	$types = archive_types( $r );
	if ( null === $types ) {
		return refused( 'No such type.' );
	}
	$after = max( 0, (int) ( $r['after'] ?? 0 ) );
	$limit = min( 500, max( 1, (int) ( $r['limit'] ?? 100 ) ) );
	$in    = types_sql( $types );
	$ids   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status = 'publish' AND post_password = '' AND ID > %d ORDER BY ID ASC LIMIT %d", $after, $limit ) ) ); // phpcs:ignore WordPress.DB
	$posts = $ids ? get_posts( array( 'post__in' => $ids, 'orderby' => 'post__in', 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => $limit, 'suppress_filters' => true ) ) : array();
	$items = array();
	foreach ( $posts as $post ) {
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( $tax->public && 'post_format' !== $tax->name ) {
				foreach ( (array) get_the_terms( $post, $tax->name ) as $t ) {
					if ( $t instanceof \WP_Term ) {
						$terms[] = plain( $t->name );
					}
				}
			}
		}
		$by      = author_shape( (int) $post->post_author );
		$item    = array(
			'id'       => (int) $post->ID,
			'type'     => $post->post_type,
			'path'     => to_path( (string) get_permalink( $post ) ),
			'title'    => plain( get_the_title( $post ) ),
			'excerpt'  => plain( has_excerpt( $post ) ? $post->post_excerpt : '' ),
			'text'     => mb_substr( plain( strip_shortcodes( (string) $post->post_content ) ), 0, 20000 ),
			'date'     => mysql_to_rfc3339( $post->post_date_gmt ) . 'Z',
			'local'    => str_replace( ' ', 'T', $post->post_date ),
			'modified' => mysql_to_rfc3339( $post->post_modified_gmt ) . 'Z',
			'terms'    => $terms,
			'by'       => $by ? $by['name'] : '',
		);
		$items[] = (array) apply_filters( 'clientnav_headless_index_item', $item, $post );
	}
	$next = count( $ids ) === $limit ? end( $ids ) : null;
	return new \WP_REST_Response( array( 'items' => $items, 'next' => $next ), 200, array( 'Cache-Control' => 'no-store' ) );
}
