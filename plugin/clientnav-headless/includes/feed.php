<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * Feeds for the front end:  GET /feed?type=&category=&tag=&tax=&term=&per_page=
 * An RSS 2.0 document whose every address is on the front end, so a front end can answer /feed/ and
 * /category/<name>/feed/ by passing it on. Kept like the other answers.
 */
add_action(
	'rest_api_init',
	static function () {
		register_rest_route( REST_NS, '/feed', array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\door_allows', 'callback' => kept_route( 'feed', __NAMESPACE__ . '\\rest_feed' ) ) );
	}
);

function rest_feed( \WP_REST_Request $r ) {
	door_with( array( 'sizes' ) );
	$args = posts_query( $r );
	if ( is_wp_error( $args ) ) {
		return $args;
	}
	$list  = listing( $args, 1, (int) ( $r['per_page'] ?? 20 ), 0, true, false );
	$front = '' !== frontend_url() ? frontend_url() : untrailingslashit( home_url() );
	$abs   = static fn( string $p ): string => ( '' !== $p && '/' === $p[0] && '/' !== ( $p[1] ?? '' ) ) ? $front . $p : $p;
	// The page the feed belongs to: a section's, or the home page's.
	$term = null;
	if ( ! empty( $args['tax_query'][0] ) ) {
		$c    = $args['tax_query'][0];
		$term = get_term_by( $c['field'], is_array( $c['terms'] ) ? reset( $c['terms'] ) : $c['terms'], $c['taxonomy'] );
	}
	$home  = $term instanceof \WP_Term ? to_path( (string) get_term_link( $term ) ) : '/';
	$title = plain( get_bloginfo( 'name' ) ) . ( $term instanceof \WP_Term ? ' – ' . plain( $term->name ) : '' );
	$x     = static fn( string $s ): string => esc_xml( $s );
	$cdata = static fn( string $s ): string => '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $s ) . ']]>';

	$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$out .= '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/"><channel>';
	$out .= '<title>' . $x( $title ) . '</title><link>' . $x( $abs( $home ) ) . '</link><description>' . $x( plain( get_bloginfo( 'description' ) ) ) . '</description>';
	$out .= '<language>' . $x( get_bloginfo( 'language' ) ) . '</language>';
	$out .= '<atom:link href="' . $x( $abs( $home ) . 'feed/' ) . '" rel="self" type="application/rss+xml"/>';
	if ( ! empty( $list['items'][0]['date'] ) ) {
		$out .= '<lastBuildDate>' . $x( gmdate( DATE_RSS, strtotime( $list['items'][0]['modified'] ) ) ) . '</lastBuildDate>';
	}
	foreach ( $list['items'] as $e ) {
		$link = $abs( (string) $e['path'] );
		// Inside the text, the site's own links and pictures become full addresses: feed readers have no page to resolve them against.
		$html = preg_replace_callback( '/\b(href|src)=(["\'])(\/(?!\/)[^"\']*)\2/i', static fn( $m ) => $m[1] . '=' . $m[2] . $abs( $m[3] ) . $m[2], (string) ( $e['html'] ?? '' ) );
		$out .= '<item><title>' . $x( (string) $e['title'] ) . '</title><link>' . $x( $link ) . '</link><guid isPermaLink="true">' . $x( $link ) . '</guid>';
		$out .= '<pubDate>' . $x( gmdate( DATE_RSS, strtotime( (string) $e['date'] ) ) ) . '</pubDate>';
		if ( ! empty( $e['by']['name'] ) ) {
			$out .= '<dc:creator>' . $cdata( (string) $e['by']['name'] ) . '</dc:creator>';
		}
		foreach ( (array) ( $e['terms'] ?? array() ) as $t ) {
			$out .= '<category>' . $cdata( (string) $t['name'] ) . '</category>';
		}
		$out .= '<description>' . $cdata( (string) $e['excerpt'] ) . '</description><content:encoded>' . $cdata( (string) $html ) . '</content:encoded>';
		if ( ! empty( $e['image']['src'] ) ) {
			$out .= '<media:content url="' . $x( $abs( (string) $e['image']['src'] ) ) . '" medium="image" width="' . (int) $e['image']['width'] . '" height="' . (int) $e['image']['height'] . '"/>';
		}
		$out .= '</item>';
	}
	$out .= '</channel></rss>';
	return array( 'xml' => $out );
}

// The REST server would send the document as a JSON string; it goes out as the feed itself.
add_filter(
	'rest_pre_serve_request',
	static function ( $served, $result, $request ) {
		if ( $served || '/' . REST_NS . '/feed' !== $request->get_route() || ! $result instanceof \WP_REST_Response ) {
			return $served;
		}
		$data = $result->get_data();
		if ( ! is_array( $data ) || ! isset( $data['xml'] ) ) {
			return $served;
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/rss+xml; charset=UTF-8' );
		}
		echo $data['xml']; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with every value escaped
		return true;
	},
	10,
	3
);
