<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * The content door. Read only, published content only, no sign-in:
 *
 *   GET /site                     name, language, menus, categories
 *   GET /resolve?path=/a/b/       what lives at an address: an entry, a term with its entries, the list of posts, or nothing;
 *                                 each with "seo" for the head of the page (from PR Register when it is there, see seo.php)
 *                                 with=author,archive also answers an author's page and a type's list (off unless asked for,
 *                                 so a front end is never handed a kind of answer it does not know)
 *   GET /posts?page=&per_page=&category=&search=&exclude=
 *              &type=             another public type than posts; several, separated by commas, make one list (a search across kinds)
 *              &tag=  &tax=&term= by tag, or by a term (slug or number) of any public taxonomy, sub-terms included
 *              &by=               by author (number or the name in the author's address); author= means the same,
 *                                 but security plugins refuse requests that carry it
 *              &offset=           leave out that many of the newest
 *              &orderby=          date, modified or title (a search is otherwise in order of best match), or a field name a site add-on
 *                                 lists in the filter clientnav_headless_orderby;  &order=asc|desc
 *              &excerpt=150       summaries cut at that many characters (at a word, with …) instead of thirty words
 *              &full=1            whole entries with their text instead of cards (feeds)
 *              &count=0           no total and no page count, only "more" (the default on a site with a very big type)
 *              &category=a,b      several sections as one list; &not_category=x,y everything except those
 *   ?excerpt=300 on /resolve      the entry's and its list's summaries cut at that many characters
 *   GET /resolve?id=              one entry by its number;  ?old=<address before WordPress> where it lives now
 *   GET /months, /month, /day, /index    archives and the search feed (archives.php)
 *   ?lang=es on /site, /resolve, /posts, /terms (and /es/... paths on /resolve): the answer in that language (lang.php)
 *   GET /feed?...                 the same question as /posts, as RSS with front end addresses (feed.php)
 *   GET /where?path=              the address in every language (with Translate): { path, alt }
 *   with=versions on /resolve     an entry also says where it lives in every language, in its own language too
 *   GET /terms?taxonomy=          the terms of a public taxonomy, with descriptions; &slug= gives that one term
 *   GET /authors                  everyone with something published
 *   GET /paths?page=              every public address with its last change (for the sitemap);
 *                                 with=terms,authors,archives adds those addresses on page 1;
 *                                 &type=&per_page= gives one type's addresses only, in a fixed order
 *
 * On /resolve and /posts, with=meta adds "meta" to every entry and card (all the fields its type shows in
 * WordPress's own REST API, other plugins' included) and with=sizes adds every size of a picture by name.
 */
add_action(
	'rest_api_init',
	static function () {
		$open = array( 'permission_callback' => __NAMESPACE__ . '\\door_allows', 'methods' => 'GET' );
		register_rest_route( REST_NS, '/site', $open + array( 'callback' => kept_route( 'site', lang_route( 'site', __NAMESPACE__ . '\\rest_site' ) ) ) );
		register_rest_route( REST_NS, '/resolve', $open + array( 'callback' => kept_route( 'resolve', lang_route( 'resolve', __NAMESPACE__ . '\\rest_resolve' ) ) ) );
		register_rest_route( REST_NS, '/posts', $open + array( 'callback' => kept_route( 'posts', lang_route( 'posts', __NAMESPACE__ . '\\rest_posts' ) ) ) );
		register_rest_route( REST_NS, '/paths', $open + array( 'callback' => kept_route( 'paths', __NAMESPACE__ . '\\rest_paths' ) ) );
		// Where an address lives in every language (any address, also a list or a section's).
		register_rest_route( REST_NS, '/where', $open + array( 'callback' => kept_route( 'where', __NAMESPACE__ . '\\rest_where' ) ) );
		register_rest_route( REST_NS, '/terms', $open + array( 'callback' => kept_route( 'terms', lang_route( 'terms', __NAMESPACE__ . '\\rest_terms' ) ) ) );
		register_rest_route( REST_NS, '/authors', $open + array( 'callback' => kept_route( 'authors', __NAMESPACE__ . '\\rest_authors' ) ) );
	}
);

function rest_site(): array {
	$menus = array();
	foreach ( array_keys( get_registered_nav_menus() ) as $place ) {
		if ( str_starts_with( $place, 'clientnav_' ) ) {
			$menus[ substr( $place, 10 ) ] = menu( $place );
		}
	}
	$cats = get_categories( array( 'hide_empty' => true ) );
	return array(
		'name'        => plain( get_bloginfo( 'name' ) ),
		'description' => plain( get_bloginfo( 'description' ) ),
		'language'    => get_bloginfo( 'language' ),
		'frontend'    => frontend_url(),
		'perPage'     => (int) get_option( 'posts_per_page' ),
		'menus'       => (object) $menus,
		'categories'  => array_map( __NAMESPACE__ . '\\term_shape', $cats ),
		'door'        => VERSION,
	);
}

/** What the request names after "with=", as a list. */
function asked_with( \WP_REST_Request $r ): array {
	return array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $r['with'] ?? '' ) ) ) );
}

/** A public taxonomy by name, or null. */
function public_taxonomy( string $name ): ?\WP_Taxonomy {
	$tax = get_taxonomy( sanitize_key( $name ) );
	return $tax && $tax->public && 'post_format' !== $tax->name ? $tax : null;
}

function refused( string $what ): \WP_Error {
	return new \WP_Error( 'clientnav_headless_unknown', $what, array( 'status' => 400 ) );
}

function rest_posts( \WP_REST_Request $r ) {
	door_with( asked_with( $r ) );
	excerpt_chars( (int) ( $r['excerpt'] ?? 0 ) );
	$args = posts_query( $r );
	if ( is_wp_error( $args ) ) {
		return $args;
	}
	$count = isset( $r['count'] ) ? '0' !== (string) $r['count'] : null;
	return listing( $args, (int) ( $r['page'] ?? 1 ), (int) ( $r['per_page'] ?? get_option( 'posts_per_page' ) ), (int) ( $r['offset'] ?? 0 ), ! empty( $r['full'] ), $count );
}

/** The question of a list (/posts, /feed) as WordPress query arguments, or why it is refused. */
function posts_query( \WP_REST_Request $r ) {
	$args  = array();
	// One type, or several separated by commas: a search across a site's kinds of content is one list.
	$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $r['type'] ?? 'post' ) ) ) ) ) );
	if ( ! $types || array_diff( $types, public_types() ) ) {
		return refused( 'No such type.' );
	}
	$args['post_type'] = 1 === count( $types ) ? $types[0] : $types;
	if ( $r['tag'] ) {
		$args['tag'] = sanitize_title( (string) $r['tag'] );
	}
	if ( $r['tax'] || $r['term'] ) {
		$tax = public_taxonomy( (string) $r['tax'] );
		if ( ! $tax || '' === (string) $r['term'] ) {
			return refused( 'No such taxonomy, or no term named.' );
		}
		$term              = (string) $r['term'];
		$by_number         = (bool) preg_match( '/^\d+$/', $term );
		$args['tax_query'] = array( array( 'taxonomy' => $tax->name, 'field' => $by_number ? 'term_id' : 'slug', 'terms' => $by_number ? (int) $term : sanitize_title( $term ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	// "by" is the same as "author": security plugins refuse any request that carries author=, as a guard against user-name scans.
	if ( $r['by'] || $r['author'] ) {
		$author = (string) ( $r['by'] ?: $r['author'] );
		if ( preg_match( '/^\d+$/', $author ) ) {
			$args['author'] = (int) $author;
		} else {
			$args['author_name'] = sanitize_title( $author );
		}
	}
	// Newest first unless asked otherwise. A search without this is in WordPress's own order of best match.
	$orderby = sanitize_key( (string) ( $r['orderby'] ?? '' ) );
	$order   = strtoupper( (string) ( $r['order'] ?? '' ) );
	$fields  = (array) apply_filters( 'clientnav_headless_orderby', array() );
	if ( in_array( $orderby, array( 'date', 'modified', 'title' ), true ) ) {
		$args['orderby'] = $orderby;
		$args['order']   = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : ( 'title' === $orderby ? 'ASC' : 'DESC' );
	} elseif ( isset( $fields[ $orderby ] ) ) {
		// By a field a site add-on names (events by their start): name => array( 'key' => meta key, 'numeric' => bool ).
		// Only names the add-on lists work; no request can order by (and so probe) any other field.
		$field                = (array) $fields[ $orderby ];
		$args['meta_key']     = (string) ( $field['key'] ?? '' ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['orderby']      = ! empty( $field['numeric'] ) ? 'meta_value_num' : 'meta_value';
		$args['order']        = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';
	}
	if ( $r['category'] && empty( $args['tax_query'] ) ) {
		// As terms of the taxonomy, so a big section can take the fast way (content.php, newest_in()).
		// Several, separated by commas, make one list (a large news site's "news" is six sections).
		$args['tax_query'] = array( array( 'taxonomy' => 'category', 'field' => 'slug', 'terms' => array_values( array_filter( array_map( 'sanitize_title', explode( ',', (string) $r['category'] ) ) ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	if ( $r['not_category'] ) {
		// Everything except these sections (a business news site's "main" leaves the long reads out).
		$args['tax_query']   = (array) ( $args['tax_query'] ?? array() );
		$args['tax_query'][] = array( 'taxonomy' => 'category', 'field' => 'slug', 'terms' => array_values( array_filter( array_map( 'sanitize_title', explode( ',', (string) $r['not_category'] ) ) ) ), 'operator' => 'NOT IN' ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	if ( $r['search'] ) {
		$args['s'] = sanitize_text_field( (string) $r['search'] );
	}
	if ( $r['exclude'] ) {
		$args['post__not_in'] = wp_parse_id_list( (string) $r['exclude'] );
	}
	// A site add-on may adjust the question (a calendar plugin that hides past events, say).
	return (array) apply_filters( 'clientnav_headless_query', $args, $r );
}

function rest_terms( \WP_REST_Request $r ) {
	$tax = public_taxonomy( (string) $r['taxonomy'] );
	if ( ! $tax ) {
		return refused( 'No such taxonomy.' );
	}
	$slug  = sanitize_title( (string) ( $r['slug'] ?? '' ) );
	$terms = get_terms(
		array(
			'taxonomy'   => $tax->name,
			'slug'       => '' !== $slug ? $slug : array(),
			'hide_empty' => '' === $slug && '0' !== (string) ( $r['hide_empty'] ?? '1' ),
			'number'     => min( 500, max( 1, (int) ( $r['per_page'] ?? 100 ) ) ),
			'offset'     => max( 0, (int) ( $r['offset'] ?? 0 ) ),
			'orderby'    => 'name',
		)
	);
	return array(
		'taxonomy' => $tax->name,
		'items'    => array_map( static fn( $t ) => term_shape( $t ) + array( 'description' => plain( (string) $t->description ) ), is_array( $terms ) ? array_values( $terms ) : array() ),
	);
}

/**
 * GET /where?path=/es/preguntas-frecuentes/  The address WordPress knows ("path", as the site's own language writes it:
 * Translate's prefix and translated slugs taken off) and the same address in every language ("alt"). Without Translate,
 * or with no language besides the site's own, there is nothing to say: 404.
 */
function rest_where( \WP_REST_Request $r ) {
	$path = '/' . trim( (string) wp_parse_url( (string) $r['path'], PHP_URL_PATH ), '/' ) . '/';
	$path = '//' === $path ? '/' : $path;
	if ( strlen( $path ) > 600 || ! translate_ready() || ! \ClientNav\Translate\Api::languages() ) {
		return new \WP_REST_Response( array( 'type' => 'none' ), 404 );
	}
	$known = (array) \ClientNav\Translate\Api::resolve( $path );
	$own   = (string) ( $known['path'] ?? $path );
	$codes = array_merge( array( (string) \ClientNav\Translate\Api::default_language() ), (array) \ClientNav\Translate\Api::languages() );
	$alt   = array();
	foreach ( $codes as $code ) {
		$alt[ $code ] = lang_path( $own, (string) $code );
	}
	return array( 'path' => $own, 'alt' => (object) $alt );
}

/** Everyone with something published in a public type. */
function published_authors(): array {
	$users = get_users( array( 'has_published_posts' => array_values( array_diff( public_types(), array( 'page' ) ) ), 'number' => 200, 'orderby' => 'display_name' ) );
	return array_values( array_filter( array_map( static fn( $u ) => author_shape( (int) $u->ID ), $users ) ) );
}

function rest_authors(): array {
	return array( 'items' => published_authors() );
}

function rest_resolve( \WP_REST_Request $r ) {
	door_with( asked_with( $r ) );
	excerpt_chars( (int) ( $r['excerpt'] ?? 0 ) );
	// By number: the entry itself (a search engine or a refresh that knows only the id).
	if ( $r['id'] ) {
		$post = get_post( (int) $r['id'] );
		if ( $post && 'publish' === $post->post_status && '' === $post->post_password && in_array( $post->post_type, public_types(), true ) ) {
			return array( 'type' => 'entry', 'entry' => entry( $post ) );
		}
		return new \WP_REST_Response( array( 'type' => 'none' ), 404 );
	}
	// An address of the site before it moved to WordPress: a site add-on knows its table (importers keep one).
	if ( $r['old'] ) {
		$to = old_path( (string) $r['old'] );
		return '' !== $to ? array( 'type' => 'redirect', 'to' => $to ) : new \WP_REST_Response( array( 'type' => 'none' ), 404 );
	}
	$path = '/' . trim( (string) wp_parse_url( (string) $r['path'], PHP_URL_PATH ), '/' );
	$path = '/' === $path ? '/' : $path . '/';
	$page = 1;
	if ( preg_match( '#^(.*/)page/(\d+)/$#', $path, $m ) ) {
		$path = $m[1];
		$page = (int) $m[2];
	}
	$per_page = (int) ( $r['per_page'] ?? get_option( 'posts_per_page' ) );

	// A part WordPress keeps for itself: the visitor is sent to WordPress's own address for it.
	if ( '' !== account_url() && is_kept( $path ) ) {
		return array( 'type' => 'redirect', 'to' => on_account( $path ), 'outside' => true );
	}

	// The front page, or the page that lists the posts.
	$front = (int) get_option( 'page_on_front' );
	$blog  = (int) get_option( 'page_for_posts' );
	if ( '/' === $path ) {
		if ( 'page' === get_option( 'show_on_front' ) && $front && 'publish' === get_post_status( $front ) ) {
			return array( 'type' => 'entry', 'front' => true, 'entry' => entry( get_post( $front ) ) );
		}
		$own = array( 'title' => plain( get_bloginfo( 'name' ) ), 'description' => plain( get_bloginfo( 'description' ) ) );
		return array( 'type' => 'posts', 'front' => true, 'title' => $own['title'], 'path' => '/', 'seo' => seo( 'home', null, $own ) ) + listing( array(), $page, $per_page );
	}

	// The page that lists the posts: WordPress's own lookup (url_to_postid) never names it, it is no single page there.
	if ( $blog && 'publish' === get_post_status( $blog ) && to_path( (string) get_permalink( $blog ) ) === $path ) {
		$post = get_post( $blog );
		$own  = array( 'title' => plain( get_the_title( $post ) ), 'description' => plain( get_the_excerpt( $post ) ) );
		return array( 'type' => 'posts', 'title' => $own['title'], 'path' => $path, 'seo' => seo( 'post', $post, $own ) ) + listing( array(), $page, $per_page );
	}

	// An entry.
	$id   = url_to_postid( home_url( $path ) );
	$post = $id ? get_post( $id ) : null;
	if ( $post && 'publish' === $post->post_status && '' === $post->post_password && in_array( $post->post_type, public_types(), true ) ) {
		$real = to_path( get_permalink( $post ) );
		if ( $real !== $path ) {
			return array( 'type' => 'redirect', 'to' => $real );
		}
		if ( $post->ID === $blog ) {
			$own = array( 'title' => plain( get_the_title( $post ) ), 'description' => plain( get_the_excerpt( $post ) ) );
			return array( 'type' => 'posts', 'title' => $own['title'], 'path' => $path, 'seo' => seo( 'post', $post, $own ) ) + listing( array(), $page, $per_page );
		}
		return array( 'type' => 'entry', 'entry' => entry( $post ) );
	}

	// A term of a public taxonomy.
	$slug = basename( untrailingslashit( $path ) );
	foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
		$term = get_term_by( 'slug', $slug, $tax );
		if ( $term instanceof \WP_Term ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) && to_path( $link ) === $path ) {
				$args = array( 'tax_query' => array( array( 'taxonomy' => $tax, 'terms' => $term->term_id ) ), 'post_type' => 'any' ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$own = array( 'title' => plain( $term->name ), 'description' => plain( $term->description ) );
				return array( 'type' => 'term', 'term' => term_shape( $term ) + array( 'description' => $own['description'] ), 'seo' => seo( 'term', $term, $own ) ) + listing( $args, $page, $per_page );
			}
		}
	}

	$with = asked_with( $r );

	// The list of a type that has one (/explainers/). A page at the same address was answered above.
	if ( in_array( 'archive', $with, true ) ) {
		foreach ( public_types() as $name ) {
			$type = get_post_type_object( $name );
			$link = $type && $type->has_archive ? get_post_type_archive_link( $name ) : false;
			if ( $link && to_path( $link ) === $path ) {
				$own = array( 'title' => plain( (string) $type->labels->name ), 'description' => plain( (string) $type->description ) );
				return array( 'type' => 'archive', 'of' => $name, 'title' => $own['title'], 'path' => $path, 'seo' => seo( 'type', $type, $own ) ) + listing( array( 'post_type' => $name ), $page, $per_page );
			}
		}
	}

	// An author's page.
	if ( in_array( 'author', $with, true ) ) {
		global $wp_rewrite;
		$base = trim( (string) $wp_rewrite->author_base, '/' );
		if ( '' !== $base && preg_match( '#^/' . preg_quote( $base, '#' ) . '/([^/]+)/$#', $path, $m ) ) {
			$user  = get_user_by( 'slug', sanitize_title( $m[1] ) );
			$by    = $user ? author_shape( (int) $user->ID ) : null;
			$types = array_values( array_diff( public_types(), array( 'page' ) ) );
			if ( $by && $types && count_user_posts( $user->ID, $types, true ) > 0 ) {
				$own = array( 'title' => $by['name'], 'description' => plain( (string) get_user_meta( $user->ID, 'description', true ) ) );
				return array( 'type' => 'author', 'author' => $by + array( 'description' => $own['description'] ), 'seo' => seo( 'author', $user, $own ) ) + listing( array( 'author' => (int) $user->ID, 'post_type' => $types ), $page, $per_page );
			}
		}
	}

	// An entry under its type's folder that WordPress's own lookup missed (/explainers/<slug>/).
	$parts = array_values( array_filter( explode( '/', $path ) ) );
	if ( 2 === count( $parts ) ) {
		foreach ( public_types() as $name ) {
			$type = get_post_type_object( $name );
			$base = $type && is_array( $type->rewrite ) ? trim( (string) ( $type->rewrite['slug'] ?? '' ), '/' ) : '';
			if ( '' !== $base && $base === $parts[0] ) {
				$found = get_page_by_path( $parts[1], OBJECT, $name );
				if ( $found instanceof \WP_Post && 'publish' === $found->post_status && '' === $found->post_password ) {
					$real = to_path( (string) get_permalink( $found ) );
					return $real === $path ? array( 'type' => 'entry', 'entry' => entry( $found ) ) : array( 'type' => 'redirect', 'to' => $real );
				}
			}
		}
	}

	// An old address WordPress still knows, or one the site add-on knows.
	$old = wp_old_slug_redirect_url( $slug );
	if ( $old ) {
		return array( 'type' => 'redirect', 'to' => to_path( $old ) );
	}
	// The address exactly as asked (an old one may end in .php, without the closing slash added above).
	$asked = '/' . ltrim( (string) wp_parse_url( (string) $r['path'], PHP_URL_PATH ), '/' );
	$to    = old_path( $asked );
	$to    = '' !== $to ? $to : old_path( $path );
	if ( '' !== $to ) {
		return array( 'type' => 'redirect', 'to' => $to );
	}
	return new \WP_REST_Response( array( 'type' => 'none' ), 404 );
}

/** Where an old address of the site lives now, or ''. Filled by a site add-on (clientnav_headless_old_path). */
function old_path( string $old ): string {
	$to = (string) apply_filters( 'clientnav_headless_old_path', '', $old );
	return '' !== $to ? to_path( $to ) : '';
}

/** The new address of an entry whose slug was changed, or an empty string. */
function wp_old_slug_redirect_url( string $slug ): string {
	global $wpdb;
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_old_slug' AND meta_value = %s LIMIT 1", $slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $id && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
}

function rest_paths( \WP_REST_Request $r ) {
	$page  = max( 1, (int) ( $r['page'] ?? 1 ) );
	$types = public_types();
	$one   = sanitize_key( (string) ( $r['type'] ?? '' ) );
	if ( '' !== $one && ! in_array( $one, $types, true ) ) {
		return refused( 'No such type.' );
	}
	$q = new \WP_Query(
		array(
			'post_type'      => '' !== $one ? $one : $types,
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => min( 500, max( 1, (int) ( $r['per_page'] ?? 500 ) ) ),
			'paged'          => $page,
			// One type's addresses keep their place from call to call, so a sitemap split into files stays put.
			'orderby'        => '' !== $one ? 'ID' : 'modified',
			'order'          => '' !== $one ? 'ASC' : 'DESC',
			'no_found_rows'  => false,
		)
	);
	$items = array();
	foreach ( $q->posts as $post ) {
		// Kept by WordPress itself, or kept out of search engines: not in the sitemap.
		if ( is_kept( (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ) ) || noindexed( $post ) ) {
			continue;
		}
		$items[] = array( 'path' => to_path( get_permalink( $post ) ), 'modified' => mysql_to_rfc3339( $post->post_modified_gmt ) . 'Z', 'type' => $post->post_type );
	}
	if ( 1 === $page && '' === $one ) {
		$with = asked_with( $r );
		foreach ( get_categories( array( 'hide_empty' => true ) ) as $cat ) {
			$items[] = array( 'path' => to_path( get_category_link( $cat ) ), 'modified' => null, 'type' => 'category' );
		}
		if ( in_array( 'terms', $with, true ) ) {
			foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
				if ( 'category' === $tax || 'post_format' === $tax ) {
					continue;
				}
				$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true, 'number' => 5000 ) );
				foreach ( is_array( $terms ) ? $terms : array() as $t ) {
					$link = get_term_link( $t );
					if ( ! is_wp_error( $link ) ) {
						$items[] = array( 'path' => to_path( $link ), 'modified' => null, 'type' => $tax );
					}
				}
			}
		}
		if ( in_array( 'archives', $with, true ) ) {
			foreach ( $types as $name ) {
				$link = get_post_type_object( $name )->has_archive ? get_post_type_archive_link( $name ) : false;
				if ( $link ) {
					$items[] = array( 'path' => to_path( $link ), 'modified' => null, 'type' => 'archive' );
				}
			}
		}
		if ( in_array( 'authors', $with, true ) ) {
			foreach ( published_authors() as $by ) {
				$items[] = array( 'path' => $by['path'], 'modified' => null, 'type' => 'author' );
			}
		}
	}
	return array( 'items' => $items, 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages, 'page' => $page );
}
