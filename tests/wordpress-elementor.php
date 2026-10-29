<?php
// Runs inside the throwaway WordPress (wp eval-file) with Elementor (free, from wordpress.org) active: a page designed
// in Elementor described as parts, with V4 (atomic) elements and their CSS when this Elementor has them.
$pass = 0;
$fail = 0;
$is   = function ( string $name, $ok, $got = null ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		return;
	}
	++$fail;
	echo "FAIL  $name" . ( null === $got ? '' : ': ' . substr( wp_json_encode( $got ), 0, 400 ) ) . "\n";
};
$door = function ( array $query ) {
	$q = new WP_REST_Request( 'GET', '/clientnav-headless/v1/resolve' );
	$q->set_query_params( $query );
	$r = rest_do_request( $q );
	return array( $r->get_status(), json_decode( (string) wp_json_encode( $r->get_data() ), true ) );
};
use ClientNav\Headless as H;

// The site's own front-end hooks queue two Elementor stylesheets from uploads: one Elementor wrote, one it never did.
$upl = wp_upload_dir();
wp_mkdir_p( $upl['basedir'] . '/elementor/css' );
file_put_contents( $upl['basedir'] . '/elementor/css/local-1-frontend-desktop.css', '/* written */' );
add_action( 'wp_enqueue_scripts', static function () use ( $upl ) {
	wp_enqueue_style( 'site-local-1', $upl['baseurl'] . '/elementor/css/local-1-frontend-desktop.css', array(), 'a' );
	wp_enqueue_style( 'site-local-739', $upl['baseurl'] . '/elementor/css/local-739-frontend-desktop.css', array(), 'b' );
} );

$is( 'Elementor is there', H\elementor_ready(), defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'none' );
$atomic = class_exists( '\Elementor\Modules\AtomicWidgets\Elements\Atomic_Heading\Atomic_Heading' ) || class_exists( '\Elementor\Modules\AtomicWidgets\Elements\Atomic_Widget_Base' );
echo 'Elementor ' . ( defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '?' ) . ', V4 elements: ' . ( $atomic ? 'yes' : 'no' ) . "\n";

$home  = untrailingslashit( home_url() );
$data  = array(
	array(
		'id' => 'c1', 'elType' => 'container', 'settings' => array(), 'elements' => array(
			array( 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Classic & title', 'header_size' => 'h3', 'link' => array( 'url' => $home . '/contact/' ) ), 'elements' => array() ),
			array( 'id' => 'w2', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p>Read <a href="' . $home . '/about/">about us</a>.</p>' ), 'elements' => array() ),
			array( 'id' => 'w3', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Call us', 'link' => array( 'url' => 'tel:+15551234', 'is_external' => '' ), 'hide_desktop' => 'hidden-desktop' ), 'elements' => array() ),
			array( 'id' => 'w4', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => '<p>Hand <script>alert(1)</script>written</p>' ), 'elements' => array() ),
			array( 'id' => 'w5', 'elType' => 'widget', 'widgetType' => 'shortcode', 'settings' => array( 'shortcode' => '[unknown_thing a=1]' ), 'elements' => array() ),
			array( 'id' => 'w6', 'elType' => 'widget', 'widgetType' => 'icon-list', 'settings' => array( 'icon_list' => array( array( 'text' => 'First point', 'link' => array( 'url' => '' ) ), array( 'text' => '' ) ) ), 'elements' => array() ),
			array( 'id' => 'w7', 'elType' => 'widget', 'widgetType' => 'spacer', 'settings' => array(), 'elements' => array() ),
			array( 'id' => 'w8', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Hidden everywhere', 'hide_desktop' => 'hidden-desktop', 'hide_tablet' => 'hidden-tablet', 'hide_mobile' => 'hidden-mobile' ), 'elements' => array() ),
			array( 'id' => 'w9', 'elType' => 'widget', 'widgetType' => 'alert', 'settings' => array( 'alert_title' => 'Site-made <script>alert(2)</script>words', 'alert_description' => 'Drawn as the CMS would' ), 'elements' => array() ),
		),
	),
);
if ( $atomic ) {
	$data[] = array(
		'id' => 'a1', 'elType' => 'e-flexbox', 'isInner' => false,
		'settings' => array( 'classes' => array( '$$type' => 'classes', 'value' => array( 'e-a1-k1' ) ) ),
		'styles' => array( 'e-a1-k1' => array( 'id' => 'e-a1-k1', 'label' => 'local', 'type' => 'class', 'variants' => array( array( 'meta' => array( 'breakpoint' => 'desktop', 'state' => null ), 'props' => array( 'color' => array( '$$type' => 'color', 'value' => '#123456' ) ) ) ) ) ),
		'elements' => array(
			array( 'id' => 'a2', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => array( 'title' => array( '$$type' => 'string', 'value' => 'Atomic heading' ), 'tag' => array( '$$type' => 'string', 'value' => 'h1' ) ), 'elements' => array() ),
			array( 'id' => 'a3', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => array( 'paragraph' => array( '$$type' => 'string', 'value' => 'Atomic words' ) ), 'elements' => array() ),
		),
	);
}
$page = wp_insert_post( array( 'post_title' => 'Designed page', 'post_name' => 'designed-page', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<p>What WordPress would show.</p>' ) );
update_post_meta( $page, '_elementor_edit_mode', 'builder' );
update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
H\forget();

list( $st, $d ) = $door( array( 'path' => '/designed-page/' ) );
$is( 'without being asked, no layout (answers stay light)', 'entry' === ( $d['type'] ?? '' ) && ! isset( $d['entry']['elementor'] ), array_keys( $d['entry'] ?? array() ) );
list( $st, $d ) = $door( array( 'path' => '/designed-page/', 'with' => 'elementor' ) );
$el    = $d['entry']['elementor'] ?? array();
$box   = $el['parts'][0] ?? array();
$kids  = $box['kids'] ?? array();
$is( 'with=elementor: the layout as parts, under its scope', 'box' === ( $box['t'] ?? '' ) && '.cnh-el' === ( $el['scope'] ?? '' ), array_keys( $el ) );
$is( 'classic heading: tag, words, link as a path', 'h' === ( $kids[0]['t'] ?? '' ) && 'h3' === ( $kids[0]['el'] ?? '' ) && 'Classic &amp; title' === ( $kids[0]['html'] ?? '' ) && '/contact/' === ( $kids[0]['link']['href'] ?? '' ), $kids[0] ?? null );
$is( 'classic text: rich, own link as a path', 'rich' === ( $kids[1]['t'] ?? '' ) && false !== strpos( (string) ( $kids[1]['html'] ?? '' ), 'href="/about/"' ), $kids[1] ?? null );
$is( 'classic button: kept with "hide on desktop"', 'btn' === ( $kids[2]['t'] ?? '' ) && array( 'desktop' ) === ( $kids[2]['hide'] ?? null ) && 'tel:+15551234' === ( $kids[2]['link']['href'] ?? '' ), $kids[2] ?? null );
$is( 'hand-written HTML: words kept, script gone', 'rich' === ( $kids[3]['t'] ?? '' ) && false === stripos( (string) ( $kids[3]['html'] ?? '' ), 'script' ) && false !== strpos( (string) ( $kids[3]['html'] ?? '' ), 'written' ), $kids[3] ?? null );
$is( 'an unknown shortcode: marked, with its name', 'x' === ( $kids[4]['t'] ?? '' ) && 'unknown_thing' === ( $kids[4]['note'] ?? '' ), $kids[4] ?? null );
$is( 'the answer names the kit\'s body class when Elementor has a kit', array_key_exists( 'kit', $el ) && ( '' === $el['kit'] || str_starts_with( (string) $el['kit'], 'elementor-kit-' ) ), $el['kit'] ?? null );
$is( 'the answer names the stylesheets unknown widgets register, as a list', isset( $el['assets'] ) && is_array( $el['assets'] ), $el['assets'] ?? null );
$css = implode( ' ', (array) ( $el['assets'] ?? array() ) );
$is( 'a stylesheet in uploads is named only when the file is there', str_contains( $css, 'local-1-frontend-desktop.css' ) && ! str_contains( $css, 'local-739-' ), $el['assets'] ?? null );
$is( 'and the scripts the site queues for its front end, as a list without WordPress\'s own', isset( $el['scripts'] ) && is_array( $el['scripts'] ) && ! array_filter( $el['scripts'], static fn( $s ) => str_contains( (string) $s, '/wp-includes/' ) ), $el['scripts'] ?? null );
if ( post_type_exists( 'elementor_component' ) ) {
	$comp = wp_insert_post( array( 'post_type' => 'elementor_component', 'post_status' => 'publish', 'post_title' => 'A component' ) );
	update_post_meta( $comp, '_elementor_edit_mode', 'builder' );
	update_post_meta( $comp, '_elementor_data', wp_slash( wp_json_encode( array( array( 'id' => 'c1', 'elType' => 'e-div-block', 'settings' => array(), 'elements' => array( array( 'id' => 'c2', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => array( 'title' => array( '$$type' => 'string', 'value' => 'From the component' ) ), 'elements' => array() ) ) ) ) ) ) );
	$use = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Uses a component', 'post_name' => 'uses-a-component' ) );
	update_post_meta( $use, '_elementor_edit_mode', 'builder' );
	update_post_meta( $use, '_elementor_data', wp_slash( wp_json_encode( array( array( 'id' => 'u1', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => array( array( 'id' => 'u2', 'elType' => 'widget', 'widgetType' => 'e-component', 'settings' => array( 'component_instance' => array( '$$type' => 'component-instance', 'value' => array( 'component_id' => array( '$$type' => 'number', 'value' => $comp ) ) ) ), 'elements' => array() ) ) ) ) ) ) );
	H\forget();
	list( $st, $d ) = $door( array( 'path' => '/uses-a-component/', 'with' => 'elementor' ) );
	$inner = $d['entry']['elementor']['parts'][0]['kids'][0] ?? array();
	// The words of an atomic heading inside a component come empty in this throwaway site (they come on a real site, a site 2026-10-15); the structure is what is checked.
	$is( 'a reusable component is drawn as its own parts in place', 'box' === ( $inner['t'] ?? '' ) && 'e-component' === ( $inner['base'] ?? '' ) && 'h' === ( $inner['kids'][0]['kids'][0]['t'] ?? '' ), $inner );
}
$last = end( $kids );
$is( 'a widget the plugin does not know carries its own markup, without scripts', 'x' === ( $last['t'] ?? '' ) && 'alert' === ( $last['widget'] ?? '' ) && str_contains( (string) ( $last['html'] ?? '' ), 'Site-made' ) && str_contains( (string) ( $last['html'] ?? '' ), 'Drawn as the CMS would' ) && ! str_contains( (string) ( $last['html'] ?? '' ), '<script' ), $last );
$is( 'icon list without empty rows; spacer and an element hidden everywhere left out', 'list' === ( $kids[5]['t'] ?? '' ) && 1 === count( $kids[5]['items'] ?? array() ) && 7 === count( $kids ), array( count( $kids ), $kids[5] ?? null ) );

// A site add-on resolves its own widget.
add_filter( 'clientnav_headless_elementor_part', static fn( $made, $type ) => 'shortcode' === $type ? array( 't' => 'reviews', 'stars' => 5 ) : $made, 10, 2 );
H\forget();
list( $st, $d ) = $door( array( 'path' => '/designed-page/', 'with' => 'elementor' ) );
$is( 'a site add-on resolves its own widgets', 'reviews' === ( $d['entry']['elementor']['parts'][0]['kids'][4]['t'] ?? '' ), $d['entry']['elementor']['parts'][0]['kids'][4] ?? null );
remove_all_filters( 'clientnav_headless_elementor_part' );

if ( $atomic ) {
	H\forget();
	list( $st, $d ) = $door( array( 'path' => '/designed-page/', 'with' => 'elementor' ) );
	$el   = $d['entry']['elementor'] ?? array();
	$flex = $el['parts'][1] ?? array();
	$is( 'V4 flexbox with its class', 'box' === ( $flex['t'] ?? '' ) && 'e-flexbox' === ( $flex['base'] ?? '' ) && in_array( 'e-a1-k1', $flex['cls'] ?? array(), true ), $flex );
	$is( 'V4 heading and paragraph', 'h1' === ( $flex['kids'][0]['el'] ?? '' ) && false !== strpos( (string) ( $flex['kids'][0]['html'] ?? '' ), 'Atomic heading' ) && 'p' === ( $flex['kids'][1]['t'] ?? '' ), $flex['kids'] ?? null );
	$is( 'V4 styles rendered as CSS under the scope', false !== strpos( (string) ( $el['css'] ?? '' ), '.cnh-el' ) && false !== stripos( (string) ( $el['css'] ?? '' ), '#123456' ), $el['css'] ?? null );
}

// A V4 loop (e-collection-loop) without the element registered (no Elementor Pro here; on a site, Pro
// without a licence that has the loop feature, 2026-10-17): the door runs the query from the saved settings and fills
// the post tags for each entry itself.
if ( $atomic ) {
	$s    = static fn( string $v ): array => array( '$$type' => 'string', 'value' => $v );
	$n    = static fn( int $v ): array => array( '$$type' => 'number', 'value' => $v );
	$cls  = static fn( string $c ): array => array( '$$type' => 'classes', 'value' => array( $c ) );
	$dyn  = static fn( string $name, array $set = array() ): array => array( '$$type' => 'dynamic', 'value' => array( 'name' => $name, 'group' => 'post', 'settings' => $set ) );
	$ids  = static fn( string $key, array $list ): array => array( '$$type' => 'query-filter', 'value' => array( 'key' => $s( $key ), 'values' => array( '$$type' => 'query-array', 'value' => array_map( static fn( int $id ): array => array( '$$type' => 'query', 'value' => array( 'id' => $n( $id ) ) ), $list ) ) ) );
	$item = array(
		array( 'id' => 'li1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => array( 'tag' => $s( 'h3' ), 'title' => $dyn( 'post-title', array( 'before' => $s( '' ), 'after' => $s( '' ) ) ), 'link' => array( '$$type' => 'link', 'value' => array( 'destination' => $dyn( 'post-url' ) ) ) ), 'elements' => array() ),
		array( 'id' => 'li2', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => array( 'paragraph' => $dyn( 'post-date', array( 'type' => $s( 'published' ), 'format' => $s( 'custom' ), 'custom_format' => $s( 'Y-m-d' ) ) ) ), 'elements' => array() ),
		array( 'id' => 'li3', 'elType' => 'widget', 'widgetType' => 'e-image', 'settings' => array( 'image' => array( '$$type' => 'image', 'value' => array( 'src' => $dyn( 'post-featured-image' ), 'size' => $s( 'full' ) ) ) ), 'elements' => array() ),
		array( 'id' => 'li4', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => array( 'paragraph' => $dyn( 'post-excerpt', array( 'max_length' => $n( 3 ), 'apply_to_post_content' => array( '$$type' => 'boolean', 'value' => true ) ) ) ), 'elements' => array() ),
	);
	$loop = static fn( string $id, array $query ): array => array(
		'id' => $id, 'elType' => 'e-collection-loop', 'settings' => array( 'classes' => $cls( "e-$id-k" ), 'query' => array( '$$type' => 'loop-query', 'value' => $query ) ),
		'styles' => array( "e-$id-k" => array( 'id' => "e-$id-k", 'label' => 'local', 'type' => 'class', 'variants' => array( array( 'meta' => array( 'breakpoint' => 'desktop', 'state' => null ), 'props' => array( 'color' => array( '$$type' => 'color', 'value' => '#654321' ) ) ) ) ) ),
		'elements' => array( array( 'id' => "{$id}l", 'elType' => 'e-collection-loop-layout', 'settings' => array( 'classes' => $cls( 'e-lay' ) ), 'elements' => array(
			array( 'id' => "{$id}i", 'elType' => 'e-collection-loop-item', 'settings' => array( 'classes' => $cls( 'e-itm' ) ), 'elements' => $item ),
		) ) ),
	);
	$news  = wp_insert_term( 'Loop news', 'category' )['term_id'];
	$photo = wp_insert_attachment( array( 'post_title' => 'Loop picture', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), '2026/01/loop.jpg' );
	wp_update_attachment_metadata( $photo, array( 'width' => 800, 'height' => 450, 'file' => '2026/01/loop.jpg', 'sizes' => array() ) );
	$lp = array();
	foreach ( array( 1, 2, 3 ) as $day ) {
		$lp[ $day ] = wp_insert_post( array( 'post_title' => "Loop story $day", 'post_status' => 'publish', 'post_date' => "2026-01-0$day 10:00:00", 'post_category' => array( $news ), 'post_content' => 'one two three four five' ) );
	}
	set_post_thumbnail( $lp[3], $photo );
	$other  = wp_insert_post( array( 'post_title' => 'Aside story', 'post_status' => 'publish', 'post_date' => '2026-01-05 10:00:00', 'post_content' => 'elsewhere' ) );
	$looped = wp_insert_post( array( 'post_title' => 'Looped page', 'post_name' => 'looped-page', 'post_type' => 'page', 'post_status' => 'publish' ) );
	update_post_meta( $looped, '_elementor_edit_mode', 'builder' );
	$terms = static fn( string $which ): array => array( $which => array( '$$type' => 'query-filter-array', 'value' => array( $ids( 'terms', array( $news ) ) ) ) );
	$data  = array(
		$loop( 'lo1', array( 'posts_per_page' => $n( 2 ), 'source' => $s( 'post' ), 'orderby' => $s( 'post_date' ), 'order' => $s( 'desc' ) ) + $terms( 'include_filters' ) ),
		$loop( 'lo2', array( 'posts_per_page' => $n( 10 ), 'source' => $s( 'post' ), 'orderby' => $s( 'post_title' ), 'order' => $s( 'asc' ) ) + $terms( 'exclude_filters' ) ),
		$loop( 'lo3', array( 'posts_per_page' => $n( 5 ), 'source' => $s( 'manual' ), 'selection' => array( '$$type' => 'query-array', 'value' => array( array( '$$type' => 'query', 'value' => array( 'id' => $n( $lp[1] ) ) ) ) ) ) ),
		$loop( 'lo4', array( 'posts_per_page' => $n( 5 ), 'source' => $s( 'post' ), 'query_id' => $s( 'only_one' ) ) ),
	);
	update_post_meta( $looped, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	add_action( 'elementor/query/only_one', static fn( $q ) => $q->set( 'posts_per_page', 1 ) );
	H\forget();
	$is( 'loop: no Elementor Pro here to run the query', ! class_exists( '\ElementorPro\Modules\CollectionLoop\Query\Loop_Query_Args_Builder' ) );
	// Elementor 4.3.5 (free) has a loop element of its own; on a site (Pro without the loop licence) there is
	// none. Both ways are tried: as it comes, then with the element taken away.
	$manager = \Elementor\Plugin::$instance->elements_manager;
	$kept    = $manager->get_element_types( 'e-collection-loop' );
	echo 'Elementor\'s own loop element here: ' . ( $kept ? get_class( $kept ) : 'none' ) . "\n";
	list( $st, $d ) = $door( array( 'path' => '/looped-page/', 'with' => 'elementor' ) );
	$known = $d['entry']['elementor'] ?? array();
	if ( $kept && method_exists( $manager, 'unregister_element_type' ) ) {
		$manager->unregister_element_type( 'e-collection-loop' );
		H\forget();
		list( $st, $d ) = $door( array( 'path' => '/looped-page/', 'with' => 'elementor' ) );
		$manager->register_element_type( $kept );
	}
	$el    = $d['entry']['elementor'] ?? array();
	$rows  = static fn( int $i ): array => (array) ( $el['parts'][ $i ]['kids'][0]['kids'] ?? array() );
	$title = static fn( array $row ): string => wp_strip_all_tags( (string) ( $row['kids'][0]['html'] ?? '' ) );
	$is( 'loop: without the element, the same parts as with it', ! $manager->get_element_types( 'e-collection-loop' ) || $known['parts'] === $el['parts'], array( $known['parts'][0]['cls'] ?? null, $el['parts'][0]['cls'] ?? null ) );
	$is( 'loop: the loop keeps its own class', in_array( 'e-lo1-k', $el['parts'][0]['cls'] ?? array(), true ), array_diff_key( $el['parts'][0] ?? array(), array( 'kids' => 1 ) ) );
	$is( 'loop: drawn as loop, layout and items with their classes', 'e-collection-loop' === ( $el['parts'][0]['base'] ?? '' ) && 'e-collection-loop-layout' === ( $el['parts'][0]['kids'][0]['base'] ?? '' ) && array( 'e-lay' ) === ( $el['parts'][0]['kids'][0]['cls'] ?? null ) && 'e-collection-loop-item' === ( $rows( 0 )[0]['base'] ?? '' ) && array( 'e-itm' ) === ( $rows( 0 )[0]['cls'] ?? null ), $el['parts'][0] ?? null );
	$is( 'loop: the terms chosen, newest first, as many as asked', array( 'Loop story 3', 'Loop story 2' ) === array_map( $title, $rows( 0 ) ), array_map( $title, $rows( 0 ) ) );
	$first = $rows( 0 )[0]['kids'] ?? array();
	$is( 'loop item: the title links to its entry', 'h3' === ( $first[0]['el'] ?? '' ) && H\to_path( (string) get_permalink( $lp[3] ) ) === ( $first[0]['link']['href'] ?? '' ), $first[0] ?? null );
	$is( 'loop item: the date in the chosen format', '2026-01-03' === trim( wp_strip_all_tags( (string) ( $first[1]['html'] ?? '' ) ) ), $first[1] ?? null );
	$is( 'loop item: the featured picture', 'img' === ( $first[2]['t'] ?? '' ) && str_contains( (string) ( $first[2]['src'] ?? '' ), 'loop.jpg' ), $first[2] ?? null );
	$is( 'loop item: the excerpt cut to its words', 'one two three...' === trim( html_entity_decode( wp_strip_all_tags( (string) ( $first[3]['html'] ?? '' ) ) ) ), $first[3] ?? null );
	$second = $rows( 0 )[1]['kids'] ?? array();
	$is( 'loop item: an entry without a picture has none', array( 'h', 'p', 'p' ) === array_column( $second, 't' ), array_column( $second, 't' ) );
	$aside = array_map( $title, $rows( 1 ) );
	$sorted = $aside;
	sort( $sorted, SORT_STRING | SORT_FLAG_CASE );
	$is( 'loop: terms left out, ordered by title', in_array( 'Aside story', $aside, true ) && ! array_intersect( array( 'Loop story 1', 'Loop story 2', 'Loop story 3' ), $aside ) && $sorted === $aside, $aside );
	$is( 'loop: a manual selection', array( 'Loop story 1' ) === array_map( $title, $rows( 2 ) ), array_map( $title, $rows( 2 ) ) );
	$is( 'loop: site code changes a loop by its query id', 1 === count( $rows( 3 ) ), count( $rows( 3 ) ) );
	$is( 'loop: its style rules come as CSS', str_contains( (string) ( $el['css'] ?? '' ), '#654321' ), $el['css'] ?? null );
	$GLOBALS['post'] = get_post( $looped ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	H\elementor_page( get_post( $looped ) );
	$is( 'loop: the page\'s own entry is the current post again afterwards', $looped === (int) ( $GLOBALS['post']->ID ?? 0 ), $GLOBALS['post']->ID ?? null );
	$args = H\el_loop_args( array( 'source' => 'post', 'posts_per_page' => 500, 'exclude_filters' => array( array( 'key' => 'current_post' ), array( 'key' => 'authors', 'values' => array( 7 ) ) ), 'select_date' => 'week', 'ignore_sticky_posts' => false ) );
	$is( 'loop query: count capped, current entry and authors left out, date window, sticky posts kept', 60 === $args['posts_per_page'] && in_array( $looped, $args['post__not_in'] ?? array(), true ) && array( 7 ) === ( $args['author__not_in'] ?? null ) && array( 'after' => '-1 week' ) === ( $args['date_query'] ?? null ) && 0 === $args['ignore_sticky_posts'], $args );
	$is( 'loop query: a type the door does not show, or a list of terms, gives nothing', null === H\el_loop_args( array( 'source' => 'elementor_library' ) ) && null === H\el_loop_args( array( 'template_type' => 'post-taxonomy' ) ) );
	remove_all_actions( 'elementor/query/only_one' );
	foreach ( array_merge( array_values( $lp ), array( $other, $looped, $photo ) ) as $gone ) {
		wp_delete_post( $gone, true );
	}
	wp_delete_term( $news, 'category' );
	H\forget();
}

// Standard widgets met on a live Elementor Pro site (a site): counter, gallery, site logo, loop carousel.
$styles = array();
$pic    = wp_insert_attachment( array( 'post_title' => 'Gallery picture', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), '2026/01/g.jpg' );
wp_update_attachment_metadata( $pic, array( 'width' => 900, 'height' => 600, 'file' => '2026/01/g.jpg', 'sizes' => array() ) );
$counter = H\el_classic( 'counter', array( 'starting_number' => 0, 'ending_number' => 250, 'suffix' => '+', 'title' => 'Articles' ), array(), $styles );
$is( 'counter: number, suffix, title', 'counter' === ( $counter['t'] ?? '' ) && 250.0 === ( $counter['value'] ?? 0 ) && '+' === ( $counter['suffix'] ?? '' ) && 'Articles' === ( $counter['title'] ?? '' ), $counter );
$free = H\el_classic( 'image-gallery', array( 'wp_gallery' => array( array( 'id' => $pic ), array( 'id' => 0 ) ), 'gallery_columns' => 4 ), array(), $styles );
$pro  = H\el_classic( 'gallery', array( 'galleries' => array( array( 'multiple_gallery' => array( array( 'id' => $pic ) ) ) ) ), array(), $styles );
$is( 'gallery (free and Pro): the pictures, columns', 'gallery' === ( $free['t'] ?? '' ) && 1 === count( $free['items'] ?? array() ) && 4 === ( $free['cols'] ?? 0 ) && 1 === count( $pro['items'] ?? array() ), array( $free, $pro ) );
set_theme_mod( 'custom_logo', $pic );
$logo = H\el_classic( 'theme-site-logo', array(), array(), $styles );
$is( 'site logo: the site\'s logo, leading home', 'img' === ( $logo['t'] ?? '' ) && '/' === ( $logo['link']['href'] ?? '' ) && 900 === ( $logo['width'] ?? 0 ), $logo );
remove_theme_mod( 'custom_logo' );
$carousel = H\el_classic( 'loop-carousel', array( 'post_query_post_type' => 'post', 'posts_per_page' => 3 ), array(), $styles );
$is( 'loop carousel: cards in one row', 'cards' === ( $carousel['t'] ?? '' ) && true === ( $carousel['row'] ?? false ) && count( $carousel['items'] ?? array() ) > 0, $carousel['t'] ?? null );

// Saving an Elementor template refreshes everything.
$c = H\changed();
wp_insert_post( array( 'post_type' => 'elementor_library', 'post_title' => 'Footer', 'post_status' => 'publish' ) );
$is( 'a saved Elementor template drops the kept answers', H\changed() > $c );
$c = H\changed();
\Elementor\Plugin::$instance->files_manager->clear_cache();
$is( 'Elementor emptying its CSS folder drops the kept answers too', H\changed() > $c );
list( $st ) = ( function () { $r = rest_do_request( new WP_REST_Request( 'GET', '/clientnav-headless/v1/part' ) ); return array( $r->get_status() ); } )();
$is( 'no theme builder here: /part answers nothing', 404 === $st, $st );
$part = static function ( array $q ): array {
	$req = new WP_REST_Request( 'GET', '/clientnav-headless/v1/part' );
	foreach ( $q as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$r = rest_do_request( $req );
	return array( $r->get_status(), $r->get_data() );
};
list( $st ) = $part( array( 'where' => 'single' ) );
$is( 'a single template needs the address it is for', 400 === $st, $st );
list( $st ) = $part( array( 'where' => 'single', 'path' => '/no-such-entry-here/' ) );
$is( 'a single template for an address where nothing lives: nothing', 404 === $st, $st );
list( $st ) = $part( array( 'where' => 'single', 'path' => '/designed-page/' ) );
$is( 'a single template without the theme builder: nothing, and the page is left as it was', 404 === $st && 'designed-page' === get_post( (int) get_page_by_path( 'designed-page' )->ID )->post_name, $st );
list( $st ) = $part( array( 'where' => '404' ) );
$is( 'a not-found template without the theme builder: nothing', 404 === $st, $st );

// A template's design written without a save (Client Navigator's Elementor abilities): everything is refreshed.
$sent = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$sent ) {
		if ( false === strpos( (string) $url, '/api/revalidate' ) ) {
			return $pre;
		}
		$sent[] = json_decode( (string) $args['body'], true );
		return array( 'headers' => array(), 'body' => '{"ok":true}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);
$was_set = get_option( 'clientnav_headless' );
update_option( 'clientnav_headless', array( 'secret' => str_repeat( 's', 40 ), 'refresh' => 'https://front.example.test' ) );
$tpl   = wp_insert_post( array( 'post_type' => 'elementor_library', 'post_title' => 'Menu part', 'post_status' => 'publish' ) );
$draft = wp_insert_post( array( 'post_type' => 'elementor_library', 'post_title' => 'Draft part', 'post_status' => 'draft' ) );
H\Refresh::send();
$sent   = array();
$before = H\changed();
update_post_meta( $draft, '_elementor_data', wp_slash( '[]' ) );
H\Refresh::send();
$is( 'a draft template\'s design: nothing is refreshed', array() === $sent, $sent );
update_post_meta( $tpl, '_elementor_data', wp_slash( '[{"id":"a1","elType":"widget","widgetType":"e-paragraph","settings":{"paragraph":"Documentation"}}]' ) );
H\Refresh::send();
$is( 'a published template\'s design written alone: the front end is told to refresh everything, kept answers go', 1 === count( $sent ) && true === ( $sent[0]['all'] ?? null ) && H\changed() > $before, $sent );
wp_delete_post( $tpl, true );
wp_delete_post( $draft, true );
H\Refresh::send();
false === $was_set ? delete_option( 'clientnav_headless' ) : update_option( 'clientnav_headless', $was_set );

echo "$pass passed, $fail failed\n";
