<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/** A file's address: a path when the front end serves /wp-content/ itself (media_paths()), otherwise as WordPress gives it. */
function media_url( string $url ): string {
	return media_paths() ? to_path( $url ) : $url;
}

/** A link to one of this site's own hosts becomes a path; anything else is returned unchanged. */
function to_path( string $url ): string {
	if ( '' === $url || '#' === $url[0] ) {
		return $url;
	}
	if ( '/' === $url[0] ) {
		return '/' !== ( $url[1] ?? '' ) && '' !== account_url() && is_kept( $url ) ? on_account( $url ) : $url;
	}
	$p = wp_parse_url( $url );
	if ( empty( $p['host'] ) || ! in_array( strtolower( $p['host'] ), own_hosts(), true ) ) {
		return $url;
	}
	// Files stay where they are: the front end does not hold the media library. A front end that passes
	// /wp-content/ on to WordPress itself asks for them as paths (settings.php, media_paths()).
	$path = $p['path'] ?? '/';
	$rest = $path . ( isset( $p['query'] ) ? '?' . $p['query'] : '' ) . ( isset( $p['fragment'] ) ? '#' . $p['fragment'] : '' );
	if ( preg_match( '#^/(wp-content|wp-includes|wp-json)/#', $path ) ) {
		return media_paths() && 0 === strpos( $path, '/wp-content/' ) ? $rest : $url;
	}
	// What WordPress keeps for itself (shop, account, sign-in) is linked at its own address.
	if ( is_kept( $path ) ) {
		return '' !== account_url() ? on_account( $rest ) : $url;
	}
	return $rest;
}

/** Inside a piece of HTML: links to own hosts become paths, and files on an old own host are read from this WordPress. */
function localize_html( string $html ): string {
	$html = preg_replace_callback(
		'/(<a\b[^>]*?\bhref=)(["\'])(.*?)\2/i',
		static fn( $m ) => $m[1] . $m[2] . esc_url( to_path( html_entity_decode( $m[3] ) ) ) . $m[2],
		$html
	);
	$here = wp_parse_url( content_url(), PHP_URL_HOST );
	// A front end that passes /wp-content/ on to WordPress itself (setting "Pictures and files") gets pictures and files
	// in the text as paths too; otherwise they are named on this WordPress, whichever of the site's names they carried.
	$paths = media_paths();
	foreach ( array_unique( array_merge( array( $here ), own_hosts() ) ) as $host ) {
		if ( $paths ) {
			$html = preg_replace( '#https?://' . preg_quote( (string) $host, '#' ) . '(?::\d+)?/wp-content/#i', '/wp-content/', $html );
		} elseif ( $host !== $here ) {
			$html = preg_replace( '#https?://' . preg_quote( (string) $host, '#' ) . '(?::\d+)?/wp-content/#i', content_url( '/' ), $html );
		}
	}
	return $html;
}

function plain( string $html ): string {
	return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}

/**
 * What the request asked for on top of the usual answer (with=meta,sizes,...). Set once by the route,
 * read where the answer is put together.
 */
function door_with( ?array $set = null ): array {
	static $with = array();
	if ( null !== $set ) {
		$with = $set;
	}
	return $with;
}

/**
 * One size of a picture: its address, and the width and height of the file itself. WordPress's own function
 * answers with a width cut down to the theme's text column for the larger sizes; a front end needs the real one.
 */
function image_size( int $attachment_id, string $size ) {
	$src = wp_get_attachment_image_src( $attachment_id, $size );
	if ( ! $src ) {
		return false;
	}
	$meta = wp_get_attachment_metadata( $attachment_id );
	$real = is_array( $meta ) ? ( 'full' === $size || empty( $meta['sizes'][ $size ] ) ? $meta : $meta['sizes'][ $size ] ) : array();
	// A size that was never made is answered with the full picture, and so are its measures.
	if ( ! empty( $real['width'] ) && ! empty( $real['height'] ) && ( 'full' === $size || ! empty( $meta['sizes'][ $size ] ) || ! $src[3] ) ) {
		$src[1] = (int) $real['width'];
		$src[2] = (int) $real['height'];
	}
	return $src;
}

function image( int $attachment_id, string $size = 'full' ): ?array {
	$src = $attachment_id ? image_size( $attachment_id, $size ) : false;
	if ( ! $src ) {
		return null;
	}
	$out = array(
		'src'    => media_url( $src[0] ),
		'width'  => (int) $src[1],
		'height' => (int) $src[2],
		'alt'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		'srcset' => media_paths() ? (string) preg_replace_callback( '#https?://[^\s,]+#', static fn( $m ) => media_url( $m[0] ), (string) wp_get_attachment_image_srcset( $attachment_id, $size ) ) : (string) wp_get_attachment_image_srcset( $attachment_id, $size ),
	);
	// with=sizes: every size WordPress made of the picture, by its name, for a front end that picks sizes itself.
	if ( in_array( 'sizes', door_with(), true ) ) {
		$meta  = wp_get_attachment_metadata( $attachment_id );
		$sizes = array();
		foreach ( array_merge( array_keys( is_array( $meta ) ? (array) ( $meta['sizes'] ?? array() ) : array() ), array( 'full' ) ) as $name ) {
			$one = image_size( $attachment_id, $name );
			if ( $one ) {
				$sizes[ $name ] = array( 'src' => media_url( $one[0] ), 'width' => (int) $one[1], 'height' => (int) $one[2] );
			}
		}
		$out['sizes'] = (object) $sizes;
	}
	return $out;
}

function term_shape( \WP_Term $t ): array {
	$link = get_term_link( $t );
	return array(
		'id'       => $t->term_id,
		'taxonomy' => $t->taxonomy,
		'name'     => plain( $t->name ),
		'slug'     => $t->slug,
		'path'     => is_wp_error( $link ) ? '' : to_path( $link ),
		'count'    => (int) $t->count,
		'parent'   => (int) $t->parent,
	);
}

/** Post types the front end shows. Builder libraries are public in WordPress but are not pages of the site. */
function public_types(): array {
	$types = array();
	foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
		if ( 'attachment' !== $type && ! preg_match( '/^(elementor|e-)/', $type ) && is_post_type_viewable( $type ) ) {
			$types[] = $type;
		}
	}
	return array_values( (array) apply_filters( 'clientnav_headless_post_types', $types ) );
}

/** The terms of an entry in every public taxonomy of its type. */
function entry_terms( \WP_Post $post ): array {
	$out = array();
	foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
		if ( ! $tax->public || 'post_format' === $tax->name ) {
			continue;
		}
		$terms = get_the_terms( $post, $tax->name );
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[] = term_shape( $t );
		}
	}
	return $out;
}

/**
 * The section an entry is filed under, shown above its headline: of its type's first taxonomy with levels
 * (categories for posts), the term the editor marked as main (PR Register, Rank Math), else the most specific one.
 */
function section_term( \WP_Post $post, array $terms ): ?array {
	$tax = '';
	foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $t ) {
		if ( $t->public && 'post_format' !== $t->name && ( $t->hierarchical || '' === $tax ) && 'post_tag' !== $t->name ) {
			$tax = $t->name;
			if ( $t->hierarchical ) {
				break;
			}
		}
	}
	$own  = array_values( array_filter( $terms, static fn( $t ) => $t['taxonomy'] === $tax ) );
	$main = (int) get_post_meta( $post->ID, '_prregister_primary_category', true );
	$main = $main ? $main : (int) get_post_meta( $post->ID, 'rank_math_primary_' . $tax, true );
	$pick = null;
	foreach ( $own as $t ) {
		if ( $t['id'] === $main ) {
			$pick = $t;
			break;
		}
		if ( null === $pick || ( 0 === $pick['parent'] && 0 !== $t['parent'] ) ) {
			$pick = $t;
		}
	}
	$pick = apply_filters( 'clientnav_headless_section', $pick, $post, $own );
	return is_array( $pick ) ? $pick : null;
}

/** Who wrote an entry, as the site shows authors in public: name and the address of the author's page. */
function author_shape( int $user_id ): ?array {
	$user = $user_id ? get_userdata( $user_id ) : false;
	if ( ! $user ) {
		return null;
	}
	$out = apply_filters(
		'clientnav_headless_author',
		array(
			'id'   => (int) $user->ID,
			'name' => plain( (string) $user->display_name ),
			'slug' => (string) $user->user_nicename,
			'path' => to_path( (string) get_author_posts_url( $user->ID, $user->user_nicename ) ),
		),
		$user
	);
	return is_array( $out ) && $out ? $out : null;
}

/**
 * The extra fields of an entry: the ones its type registers for WordPress's own REST API (show_in_rest),
 * which is where anyone can read them already. Fields whose name starts with "_" are left out.
 */
function fields( \WP_Post $post ): array {
	return (array) apply_filters( 'clientnav_headless_fields', rest_meta( $post, false ), $post );
}

/**
 * The meta of an entry that its type shows in WordPress's own REST API. $all includes the names starting
 * with "_" (other plugins' fields, such as an SEO plugin's); a front end asks for those with with=meta.
 */
function rest_meta( \WP_Post $post, bool $all ): array {
	$out = array();
	foreach ( array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $post->post_type ) ) as $key => $def ) {
		if ( empty( $def['show_in_rest'] ) || '' === (string) $key || ( ! $all && '_' === ( (string) $key )[0] ) ) {
			continue;
		}
		$out[ $key ] = get_post_meta( $post->ID, $key, ! empty( $def['single'] ) );
	}
	return $out;
}

/**
 * How long a card's excerpt is when the question says so (?excerpt=<characters>): cut at a word, "…" added.
 * 0 is WordPress's own rule (thirty words). Set by the route for the request, like door_with().
 */
function excerpt_chars( ?int $set = null ): int {
	static $chars = 0;
	if ( null !== $set ) {
		$chars = max( 0, min( 2000, $set ) );
	}
	return $chars;
}

/** A text cut to at most $chars characters at a word, with "…" when something was cut. Lists in Russian need a measure in characters, not words. */
function cut_chars( string $text, int $chars ): string {
	$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	if ( mb_strlen( $text ) <= $chars ) {
		return $text;
	}
	$cut = mb_substr( $text, 0, $chars );
	$at  = mb_strrpos( $cut, ' ' );
	return rtrim( $at ? mb_substr( $cut, 0, $at ) : $cut ) . '…';
}

/** The short form of an entry, as lists and cards need it. */
function card( \WP_Post $post ): array {
	$cats    = 'post' === $post->post_type ? get_the_category( $post->ID ) : array();
	$terms   = entry_terms( $post );
	$words   = str_word_count( wp_strip_all_tags( $post->post_content ) );
	$author  = get_userdata( (int) $post->post_author );
	// As WordPress does: the editor's own excerpt, else the text before the "more" mark, else the text, cut short.
	$source  = preg_split( '/<!--more(.*?)?-->/', $post->post_content, 2 )[0];
	// Bare addresses go: WordPress turns them into embedded players (YouTube and the like), which leave no words.
	$bare    = (string) preg_replace( '#https?://\S+#', ' ', strip_shortcodes( wp_strip_all_tags( (string) $source ) ) );
	$chars   = excerpt_chars();
	// An excerpt the editor wrote is given as written, whatever length was asked: they chose those words.
	$excerpt = has_excerpt( $post ) ? $post->post_excerpt : ( $chars > 0 ? cut_chars( html_entity_decode( $bare, ENT_QUOTES, 'UTF-8' ), $chars ) : wp_trim_words( $bare, 30 ) );
	$out     = array(
		'id'       => $post->ID,
		'local'    => str_replace( ' ', 'T', $post->post_date ), // the site's own wall clock, no zone (a large news site, a business news site)
		'type'     => $post->post_type,
		'slug'     => $post->post_name,
		'path'     => to_path( get_permalink( $post ) ),
		'title'    => plain( get_the_title( $post ) ),
		'excerpt'  => plain( wptexturize( $excerpt ) ), // the same quotes and dashes as the title gets
		'date'     => mysql_to_rfc3339( $post->post_date_gmt ) . 'Z',
		'modified' => mysql_to_rfc3339( $post->post_modified_gmt ) . 'Z',
		'author'   => $author ? $author->display_name : '',
		'by'       => author_shape( (int) $post->post_author ),
		'category' => $cats ? term_shape( $cats[0] ) : null,
		'section'  => section_term( $post, $terms ),
		'terms'    => $terms,
		'image'    => picture_of( $post, 'large' ),
		'minutes'  => max( 1, (int) round( $words / 220 ) ),
	);
	if ( in_array( 'meta', door_with(), true ) ) {
		$out['meta'] = (object) rest_meta( $post, true );
	}
	// A site add-on puts its own fields on cards here (a byline, a kind, a video, an office's address).
	return (array) apply_filters( 'clientnav_headless_card', $out, $post );
}

/**
 * The picture of an entry: its featured image, unless a site add-on knows better (a list picture kept in a
 * field of its own, as imported archives have). $size: 'large' for cards, 'full' for the entry itself.
 */
function picture_of( \WP_Post $post, string $size ): ?array {
	$pic = apply_filters( 'clientnav_headless_image', image( (int) get_post_thumbnail_id( $post ), $size ), $post, $size );
	return is_array( $pic ) && ! empty( $pic['src'] ) ? $pic : null;
}

/** Whether search engines are told to leave an entry out (PR Register, Rank Math); such entries stay out of sitemaps. */
function noindexed( \WP_Post $post ): bool {
	$says = false;
	foreach ( array( '_prregister_robots', 'rank_math_robots' ) as $key ) {
		$robots = get_post_meta( $post->ID, $key, true );
		if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
			$says = true;
		}
	}
	return (bool) apply_filters( 'clientnav_headless_noindex', $says, $post );
}

/**
 * The block tree of an entry. Every block carries its rendered HTML, so a front end that does not
 * know a block can still show it; blocks of other namespaces also carry their attributes and children.
 */
function blocks( string $content ): array {
	$out = array();
	foreach ( parse_blocks( $content ) as $block ) {
		$shaped = block_shape( $block );
		if ( $shaped ) {
			$out[] = $shaped;
		}
	}
	return $out;
}

function block_shape( array $block ): ?array {
	if ( empty( $block['blockName'] ) ) {
		// Text outside any block (classic content).
		$html = trim( $block['innerHTML'] ?? '' );
		return '' === $html ? null : array( 'name' => 'core/freeform', 'attrs' => (object) array(), 'html' => localize_html( wpautop( $html ) ), 'innerBlocks' => array() );
	}
	$core  = str_starts_with( $block['blockName'], 'core/' );
	$inner = array();
	if ( ! $core ) {
		foreach ( $block['innerBlocks'] ?? array() as $child ) {
			$shaped = block_shape( $child );
			if ( $shaped ) {
				$inner[] = $shaped;
			}
		}
	}
	$attrs = $block['attrs'] ?? array();
	// In a preview: where the block sits in the editor (mark_paths in preview.php).
	$path = $attrs['cnhPath'] ?? null;
	unset( $attrs['cnhPath'] );
	// A picture chosen in the editor is stored as its id and address; the front end gets it with its sizes.
	foreach ( $attrs as $key => $value ) {
		if ( is_array( $value ) && isset( $value['id'], $value['url'] ) ) {
			$attrs[ $key ] = image( (int) $value['id'] ) ?? array( 'src' => (string) $value['url'], 'width' => (int) ( $value['width'] ?? 0 ), 'height' => (int) ( $value['height'] ?? 0 ), 'alt' => (string) ( $value['alt'] ?? '' ), 'srcset' => '' );
		}
	}
	return array(
		'name'        => $block['blockName'],
		'attrs'       => (object) $attrs,
		// Shortcodes run on the whole text in WordPress, so a block's own HTML still carries them as written.
		'html'        => localize_html( trim( do_shortcode( render_block( $block ) ) ) ),
		'innerBlocks' => $inner,
	) + ( is_string( $path ) ? array( 'path' => $path ) : array() );
}

/** An entry in full. */
function entry( \WP_Post $post ): array {
	$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- blocks and shortcodes read it.
	setup_postdata( $post );
	$out = card( $post );

	$out['image']  = picture_of( $post, 'full' );
	$out['fields'] = (object) fields( $post );
	// with=elementor: a page designed in Elementor, as parts and CSS (elementor.php). Never for items of a list.
	if ( in_array( 'elementor', door_with(), true ) && ! in_list() && built_with_elementor( (int) $post->ID ) ) {
		$out['elementor'] = elementor_page( $post );
	}
	$out['blocks']   = blocks( $post->post_content );
	$out['html']     = localize_html( (string) apply_filters( 'the_content', $post->post_content ) );
	// The way back to the home page, for pages that sit under other pages.
	$out['crumbs'] = array( array( 'title' => 'Home', 'href' => '/' ) );
	foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
		// A page can carry a shorter name for this line (meta _clientnav_crumb).
		$short           = (string) get_post_meta( $ancestor, '_clientnav_crumb', true );
		$out['crumbs'][] = array( 'title' => '' !== $short ? $short : plain( get_the_title( $ancestor ) ), 'href' => to_path( (string) get_permalink( $ancestor ) ) );
	}
	$out['crumbs'][] = array( 'title' => $out['title'] );
	// with=versions: where the entry lives in every language (language switch, hreflang), also in the site's own language.
	if ( in_array( 'versions', door_with(), true ) && ! in_list() && translate_ready() ) {
		$versions = array();
		foreach ( (array) \ClientNav\Translate\Api::versions( (int) $post->ID ) as $code => $v ) {
			$versions[ $code ] = to_path( (string) ( $v['url'] ?? $v['path'] ?? '' ) );
		}
		$out['versions'] = (object) $versions;
	}
	$out['template'] = (string) get_page_template_slug( $post );
	$out['parent']   = (int) $post->post_parent;
	$out['seo']      = seo( 'post', $post, array( 'title' => $out['title'], 'description' => $out['excerpt'] ) );
	wp_reset_postdata();
	return (array) apply_filters( 'clientnav_headless_entry', $out, $post );
}

/**
 * A page of published entries as cards, newest first, at most 50 a page; $full gives whole entries (for feeds).
 * $offset leaves out that many of the newest, on every page.
 *
 * $count: whether to count every matching entry for "total" and "pages". Counting means going through the whole
 * list; on a very big type (more than big_size() published entries) it is left out unless asked, and "more" says
 * whether another page follows. Pages past 5,000 are not served: archives by month are the way that deep.
 */
function listing( array $args, int $page, int $per_page, int $offset = 0, bool $full = false, ?bool $count = null ): array {
	$page   = max( 1, $page );
	$per    = min( 50, max( 1, $per_page ) );
	$offset = max( 0, $offset );
	$args   = array_merge(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'has_password'        => false,
		),
		$args
	);
	$count  = $count ?? ! is_big( (array) $args['post_type'] );
	$one    = __NAMESPACE__ . ( $full ? '\\entry' : '\\card' );
	// Whole entries in a list do not each ask PR Register for their head (seo.php, in_list()).
	$make   = static function ( $post ) use ( $one ) {
		$was = in_list();
		in_list( true );
		try {
			return $one( $post );
		} finally {
			in_list( $was );
		}
	};
	if ( $page > 5000 ) {
		return array( 'items' => array(), 'total' => $count ? 0 : null, 'pages' => $count ? 0 : null, 'page' => $page, 'more' => false );
	}
	$skip = $offset + ( $page - 1 ) * $per;
	if ( ! $count ) {
		// One more than shown says whether another page follows, without counting the rest.
		$ids = newest_in( $args, $per + 1, $skip );
		if ( null === $ids ) {
			$q   = new \WP_Query( array_merge( $args, array( 'fields' => 'ids', 'no_found_rows' => true, 'posts_per_page' => $per + 1, 'offset' => $skip, 'clientnav_one_join' => true ) ) );
			$ids = array_map( 'intval', $q->posts );
		}
		$more  = count( $ids ) > $per;
		$posts = $ids ? get_posts( array( 'post__in' => array_slice( $ids, 0, $per ), 'orderby' => 'post__in', 'post_type' => $args['post_type'], 'post_status' => 'publish', 'numberposts' => $per, 'ignore_sticky_posts' => true, 'suppress_filters' => true ) ) : array();
		return array( 'items' => array_map( $make, $posts ), 'total' => null, 'pages' => null, 'page' => $page, 'more' => $more );
	}
	if ( $offset ) {
		$args['offset'] = $skip;
	}
	$q     = new \WP_Query( array_merge( $args, array( 'paged' => $page, 'posts_per_page' => $per, 'clientnav_one_join' => true ) ) );
	$total = max( 0, (int) $q->found_posts - $offset );
	$pages = (int) ceil( $total / $per );
	return array(
		'items' => array_map( $make, $q->posts ),
		'total' => $total,
		'pages' => $pages,
		'page'  => $page,
		'more'  => $page < $pages,
	);
}

/** Above this many published entries a type counts as very big: lists are not counted and big sections take the fast way. */
function big_size(): int {
	return (int) apply_filters( 'clientnav_headless_big', 20000 );
}

function is_big( array $types ): bool {
	$all = 0;
	foreach ( $types as $type ) {
		$all += (int) ( wp_count_posts( (string) $type )->publish ?? 0 );
	}
	return $all > big_size();
}

/**
 * The newest entries filed under some terms, as ids, or null when WordPress's own query should be used.
 * For a big section WordPress joins and sorts every entry of the section: about a second each time on a large news site's
 * 270,000 articles, and 250 of those at once stalled its database on 2026-10-13. Asking for the entries "whose id is
 * among the section's" leaves the database free to walk by date (a semi-join, no GROUP BY); measured 2026-10-16 it is
 * never slower than EXISTS and on MySQL 8.4, production's, 2-4 times faster on sections of 30,000-40,000 (tests/groupby/).
 * Small sections keep WordPress's query (without its GROUP BY: one_join()). Only the plain case takes this way: one
 * taxonomy, newest first, no search or author.
 */
function newest_in( array $args, int $count, int $skip ): ?array {
	global $wpdb;
	$tax = $args['tax_query'] ?? null;
	if ( ! is_array( $tax ) || 1 !== count( $tax ) || ! is_array( $tax[0] ?? null ) || array_diff( array_keys( $args ), array( 'post_type', 'post_status', 'ignore_sticky_posts', 'has_password', 'tax_query', 'post__not_in' ) ) ) {
		return null;
	}
	$c     = $tax[0];
	$terms = array();
	foreach ( (array) $c['terms'] as $t ) {
		$term = get_term_by( ( $c['field'] ?? 'term_id' ) === 'slug' ? 'slug' : 'id', $t, (string) $c['taxonomy'] );
		if ( $term instanceof \WP_Term ) {
			$terms[] = $term;
			if ( is_taxonomy_hierarchical( $term->taxonomy ) && ( $c['include_children'] ?? true ) ) {
				foreach ( (array) get_term_children( $term->term_id, $term->taxonomy ) as $child ) {
					$kid = get_term( (int) $child, $term->taxonomy );
					if ( $kid instanceof \WP_Term ) {
						$terms[] = $kid;
					}
				}
			}
		}
	}
	if ( ! $terms ) {
		return array();
	}
	if ( array_sum( wp_list_pluck( $terms, 'count' ) ) <= big_size() ) {
		return null;
	}
	$in    = implode( ',', array_unique( array_map( 'intval', wp_list_pluck( $terms, 'term_taxonomy_id' ) ) ) );
	$types = implode( ',', array_map( static fn( $t ) => "'" . esc_sql( (string) $t ) . "'", (array) $args['post_type'] ) );
	$not   = ! empty( $args['post__not_in'] ) ? ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', (array) $args['post__not_in'] ) ) . ')' : '';
	return array_map(
		'intval',
		$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type IN ($types) AND p.post_status = 'publish' AND p.post_password = ''$not AND p.ID IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($in) ) ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
				max( 1, $count ),
				max( 0, $skip )
			)
		)
	);
}

/**
 * A door list filed under the terms of one taxonomy, without WordPress's GROUP BY. WordPress joins the term
 * relationships and then groups by post, which makes the database sort every entry of the section before taking
 * the first few: 40-420 ms per related-posts list on the production server (three news CMSes), about 5 ms without
 * (2026-10-16). With one term the join cannot repeat a post, so the GROUP BY goes; with several (a section and its
 * children) the join becomes "ID IN (entries of these terms)", which cannot repeat one either and leaves the
 * database free to walk by date. Anything else (two taxonomies, meta, search's own joins) is left as WordPress made it.
 */
function one_join( array $clauses, \WP_Query $q ): array {
	global $wpdb;
	if ( ! $q->get( 'clientnav_one_join' ) || "{$wpdb->posts}.ID" !== trim( (string) $clauses['groupby'] ) ) {
		return $clauses;
	}
	$tr = $wpdb->term_relationships;
	if ( trim( (string) $clauses['join'] ) !== "LEFT JOIN {$tr} ON ({$wpdb->posts}.ID = {$tr}.object_id)" ) {
		return $clauses;
	}
	$in = '/' . preg_quote( $tr, '/' ) . '\.term_taxonomy_id IN \(([\d,]+)\)/';
	if ( 1 !== preg_match_all( $in, (string) $clauses['where'], $m ) || substr_count( (string) $clauses['where'], "{$tr}." ) !== 1 ) {
		return $clauses;
	}
	$ids = array_unique( array_map( 'intval', explode( ',', $m[1][0] ) ) );
	if ( count( $ids ) > 1 ) {
		$clauses['join']  = '';
		$clauses['where'] = preg_replace( $in, "{$wpdb->posts}.ID IN ( SELECT object_id FROM {$tr} WHERE term_taxonomy_id IN (" . implode( ',', $ids ) . ') )', (string) $clauses['where'] );
	}
	$clauses['groupby'] = '';
	return $clauses;
}
add_filter( 'posts_clauses', __NAMESPACE__ . '\one_join', 10, 2 );

/** A menu as a tree, by its place (theme location). */
function menu( string $location ): array {
	$places = get_nav_menu_locations();
	$items  = empty( $places[ $location ] ) ? false : wp_get_nav_menu_items( $places[ $location ] );
	if ( ! $items ) {
		return array();
	}
	$by_parent = array();
	foreach ( $items as $item ) {
		$by_parent[ (int) $item->menu_item_parent ][] = $item;
	}
	$build = static function ( int $parent ) use ( &$build, $by_parent ): array {
		$out = array();
		foreach ( $by_parent[ $parent ] ?? array() as $item ) {
			$out[] = array(
				'id'          => (int) $item->ID,
				'title'       => plain( $item->title ),
				'href'        => to_path( (string) $item->url ),
				'description' => plain( (string) $item->description ),
				'note'        => (string) $item->attr_title,
				'classes'     => array_values( array_filter( (array) $item->classes ) ),
				'newTab'      => '_blank' === $item->target,
				'children'    => $build( (int) $item->ID ),
			);
		}
		return $out;
	};
	return $build( 0 );
}
