<?php
/**
 * Page make-ups (roadmap 3f): a news home page or a side column described as named groups of cards, answered by
 * GET /wp-json/clientnav-headless/v1/layout?name=<name>. a business news site and a large news site each wrote the same thing in
 * their add-on (newest of some sections, with a picture, summaries cut to a length, nothing shown twice, posts an
 * editor kept off the home page left out, the editors' choice by "Stick to the top"); a site now only describes it.
 *
 * A site add-on (or a must-use file) gives its make-ups through the filter clientnav_headless_layouts:
 *
 *     add_filter( 'clientnav_headless_layouts', fn( $all ) => $all + array( 'home' => array(
 *         'lead' => array( 'sections' => array( 'articles' ), 'picture' => '_site_picture', 'one' => true, 'excerpt' => 420 ),
 *         'news' => array( 'not_sections' => array( 'pop', 'stats' ), 'count' => 13, 'excerpt' => 110 ),
 *     ) ) );
 *
 * A group: sections (slugs, any of them) and not_sections (none of them) in taxonomy (default category); type
 * (default post); count (default 5, at most 50); excerpt (characters, 0: WordPress's own thirty words); picture
 * (true: a featured image; a field name: that field is set); sticky (true: the posts marked "Stick to the top",
 * newest first, else the group's sections); hide (a field name: posts with it set are left out); unique (default
 * true: nothing a group above already showed); one (true: one card or null instead of a list). Groups are answered
 * in the order given; a group may hold groups of its own (a key "groups" with groups in it), answered in place.
 *
 * @package ClientNav\Headless
 */

namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/** Every make-up the site describes, by name. */
function layouts(): array {
	$all = apply_filters( 'clientnav_headless_layouts', array() );
	return is_array( $all ) ? array_filter( $all, 'is_array' ) : array();
}

/** Term ids of some slugs in a taxonomy; slugs the site does not have are left out. */
function layout_terms( array $slugs, string $taxonomy ): array {
	$ids = array();
	foreach ( $slugs as $slug ) {
		$t = get_term_by( 'slug', (string) $slug, $taxonomy );
		if ( $t instanceof \WP_Term ) {
			$ids[] = (int) $t->term_id;
		}
	}
	return $ids;
}

/** Whether a group is a set of groups rather than a list of cards. */
function layout_is_set( array $group ): bool {
	return isset( $group['groups'] ) && is_array( $group['groups'] ) && 1 === count( $group );
}

/**
 * Ids of the newest posts of a group, a few more than it shows so that the ones left out (shown above, kept off)
 * do not shorten it. Big sections take the door's fast way (listing); a group asking for a picture field reads
 * only the posts that have it, as the add-ons did (their picture sections are small).
 */
function layout_ids( array $g, int $want ): array {
	$type = isset( $g['type'] ) ? (array) $g['type'] : array( 'post' );
	$tax  = (string) ( $g['taxonomy'] ?? 'category' );
	$args = array( 'post_type' => $type );
	if ( ! empty( $g['sections'] ) ) {
		$in = layout_terms( (array) $g['sections'], $tax );
		if ( ! $in ) {
			return array(); // asked for sections the site does not have: nothing, not everything
		}
		$args['tax_query'] = array( array( 'taxonomy' => $tax, 'terms' => $in ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( 'category' === $tax ) {
			unset( $args['tax_query'] );
			$args['category__in'] = $in;
		}
	}
	if ( ! empty( $g['not_sections'] ) ) {
		$out = layout_terms( (array) $g['not_sections'], $tax );
		if ( $out && 'category' === $tax ) {
			$args['category__not_in'] = $out;
		} elseif ( $out ) {
			$args['tax_query']   = $args['tax_query'] ?? array();
			$args['tax_query'][] = array( 'taxonomy' => $tax, 'terms' => $out, 'operator' => 'NOT IN' );
		}
	}
	if ( ! empty( $g['sticky'] ) ) {
		$sticky = array_values( array_filter( array_map( 'intval', (array) get_option( 'sticky_posts', array() ) ) ) );
		if ( $sticky ) {
			$args = array( 'post_type' => $type, 'post__in' => $sticky );
		}
	}
	$picture = $g['picture'] ?? false;
	if ( $picture || isset( $args['post__in'] ) ) {
		$q = array_merge(
			$args,
			array(
				'post_status'         => 'publish',
				'has_password'        => false,
				'orderby'             => 'date',
				'posts_per_page'      => $want,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => true,
			)
		);
		if ( $picture ) {
			$q['meta_key'] = true === $picture ? '_thumbnail_id' : (string) $picture; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return array_map( 'intval', get_posts( $q ) );
	}
	$list = listing( $args, 1, $want, 0, false, false );
	return array_map( static fn( array $c ): int => (int) $c['id'], (array) ( $list['items'] ?? array() ) );
}

/** One group's cards (or one card), leaving out what $shown already holds and adding what it shows. */
function layout_group( array $g, array &$shown ) {
	$count  = max( 1, min( 50, (int) ( $g['count'] ?? ( empty( $g['one'] ) ? 5 : 1 ) ) ) );
	$unique = ! array_key_exists( 'unique', $g ) || ! empty( $g['unique'] );
	$hide   = isset( $g['hide'] ) ? (string) $g['hide'] : '';
	$ids    = layout_ids( $g, min( 50, $count + ( $unique ? count( $shown ) : 0 ) + ( '' !== $hide ? $count : 0 ) + 2 ) );
	$keep   = array();
	foreach ( $ids as $id ) {
		if ( ( $unique && isset( $shown[ $id ] ) ) || ( '' !== $hide && get_post_meta( $id, $hide, true ) ) ) {
			continue;
		}
		$keep[] = $id;
		if ( count( $keep ) >= $count ) {
			break;
		}
	}
	$was = excerpt_chars();
	excerpt_chars( (int) ( $g['excerpt'] ?? 0 ) );
	$flag = in_list();
	in_list( true );
	try {
		$cards = array();
		foreach ( $keep as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
				$cards[]      = card( $post );
				$shown[ $id ] = true;
			}
		}
	} finally {
		excerpt_chars( $was );
		in_list( $flag );
	}
	return empty( $g['one'] ) ? $cards : ( $cards[0] ?? null );
}

/** A whole make-up: every group in the order given, sets of groups answered in place. */
function layout_answer( array $groups, array &$shown ): array {
	$out = array();
	foreach ( $groups as $key => $g ) {
		if ( ! is_array( $g ) ) {
			continue;
		}
		$out[ $key ] = layout_is_set( $g ) ? layout_answer( $g['groups'], $shown ) : layout_group( $g, $shown );
	}
	return $out;
}

function rest_layout( \WP_REST_Request $r ) {
	$name = sanitize_key( (string) $r->get_param( 'name' ) );
	$all  = layouts();
	if ( '' === $name ) {
		return array( 'layouts' => array_keys( $all ) );
	}
	if ( ! isset( $all[ $name ] ) ) {
		return new \WP_Error( 'clientnav_no_layout', 'No such make-up.', array( 'status' => 404 ) );
	}
	$shown = array();
	return array(
		'name'   => $name,
		'groups' => (object) layout_answer( $all[ $name ], $shown ),
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			REST_NS,
			'/layout',
			array(
				'methods'             => 'GET',
				'permission_callback' => __NAMESPACE__ . '\\door_allows',
				'callback'            => kept_route( 'layout', __NAMESPACE__ . '\\rest_layout' ),
			)
		);
	}
);
