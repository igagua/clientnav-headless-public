<?php
// Runs inside the throwaway WordPress (wp eval-file) with tests/fixtures/test-types.php as a must-use plugin:
// lists by type, tag, taxonomy and author, cards, extra fields, terms, authors, and the answers given only on request.
$pass = 0;
$fail = 0;
$is   = function ( string $name, $ok, $got = null ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		return;
	}
	++$fail;
	echo "FAIL  $name" . ( null === $got ? '' : ': ' . substr( wp_json_encode( $got ), 0, 300 ) ) . "\n";
};
$door = function ( string $route, array $query = array() ) {
	$q = new WP_REST_Request( 'GET', '/clientnav-headless/v1' . $route );
	$q->set_query_params( $query );
	$r = rest_do_request( $q );
	return array( $r->get_status(), json_decode( wp_json_encode( $r->get_data() ), true ) );
};

$tester = get_user_by( 'login', 'tester' )->ID;
$writer = username_exists( 'writer-two' ) ?: wp_insert_user( array( 'user_login' => 'writer-two', 'user_pass' => wp_generate_password(), 'display_name' => 'Writer Two', 'role' => 'author', 'description' => 'Writes the explainers.' ) );
$cat    = term_exists( 'List news', 'category' ) ?: wp_insert_term( 'List news', 'category' );
$basics = wp_insert_term( 'Basics', 'cn_explainer_topic', array( 'description' => 'Where to start.' ) );
$deep   = wp_insert_term( 'Deep', 'cn_explainer_topic', array( 'parent' => (int) $basics['term_id'] ) );
$posts  = array();
foreach ( array( 'List one' => '2026-01-01 10:00:00', 'List two' => '2026-01-02 10:00:00', 'List three' => '2026-01-03 10:00:00' ) as $title => $date ) {
	$posts[ $title ] = wp_insert_post( array( 'post_title' => $title, 'post_content' => '<p>Text of the article.</p>', 'post_status' => 'publish', 'post_date' => $date, 'post_date_gmt' => $date, 'post_author' => $tester, 'post_category' => array( (int) $cat['term_id'] ), 'tags_input' => 'List three' === $title ? array( 'alpha' ) : array() ) );
}
$e1 = wp_insert_post( array( 'post_type' => 'cn_explainer', 'post_title' => 'Explainer one', 'post_content' => '<p>Explained.</p>', 'post_status' => 'publish', 'post_date' => '2026-02-01 10:00:00', 'post_date_gmt' => '2026-02-01 10:00:00', 'post_author' => $writer ) );
$e2 = wp_insert_post( array( 'post_type' => 'cn_explainer', 'post_title' => 'Explainer two', 'post_content' => '<p>Explained again.</p>', 'post_status' => 'publish', 'post_date' => '2026-02-02 10:00:00', 'post_date_gmt' => '2026-02-02 10:00:00', 'post_author' => $writer ) );
wp_set_object_terms( $e1, array( (int) $basics['term_id'] ), 'cn_explainer_topic' );
wp_set_object_terms( $e2, array( (int) $basics['term_id'], (int) $deep['term_id'] ), 'cn_explainer_topic' );
update_post_meta( $e2, 'last_reviewed', '2026-03-01' );
update_post_meta( $e2, 'key_points', "First\nSecond" );
update_post_meta( $e2, '_internal_note', 'for staff' );
update_post_meta( $e2, 'not_shown', 'hidden' );

// Lists.
list( $st, $d ) = $door( '/posts', array( 'category' => 'list-news' ) );
$first = $d['items'][0] ?? array();
$is( 'posts by category: three, newest first', 200 === $st && 3 === $d['total'] && 'List three' === ( $first['title'] ?? '' ), array( $st, $d['total'] ?? null, $first['title'] ?? null ) );
$is( 'card: who wrote it, with the address of the author page', 'tester' === ( $first['by']['slug'] ?? '' ) && '/author/tester/' === ( $first['by']['path'] ?? '' ) && (int) $tester === ( $first['by']['id'] ?? 0 ) && is_string( $first['author'] ), $first['by'] ?? null );
$is( 'card: all terms, category and tag', array( 'category', 'post_tag' ) === array_values( array_unique( array_column( $first['terms'] ?? array(), 'taxonomy' ) ) ), $first['terms'] ?? null );
$is( 'card: the section is the category', 'list-news' === ( $first['section']['slug'] ?? '' ) && 'list-news' === ( $first['category']['slug'] ?? '' ), $first['section'] ?? null );
list( $st, $d ) = $door( '/posts' );
$is( 'posts without a type: only posts', 200 === $st && array( 'post' ) === array_values( array_unique( array_column( $d['items'], 'type' ) ) ), array_column( $d['items'] ?? array(), 'type' ) );
$all = $d['total'];
list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer' ) );
$is( 'another type: two explainers', 200 === $st && 2 === $d['total'] && 'cn_explainer' === $d['items'][0]['type'] && '/explainers/explainer-two/' === $d['items'][0]['path'], array( $st, $d['total'] ?? null, $d['items'][0]['path'] ?? null ) );
$is( 'explainer card: the section is the most specific topic', 'deep' === ( $d['items'][0]['section']['slug'] ?? '' ) && 'basics' === ( $d['items'][1]['section']['slug'] ?? '' ), array( $d['items'][0]['section'] ?? null, $d['items'][1]['section'] ?? null ) );
list( $st ) = $door( '/posts', array( 'type' => 'nope' ) );
$is( 'unknown type: refused', 400 === $st, $st );
list( $st ) = $door( '/posts', array( 'type' => 'attachment' ) );
$is( 'a type that is not shown: refused', 400 === $st, $st );
list( $st, $d ) = $door( '/posts', array( 'tag' => 'alpha' ) );
$is( 'by tag: one', 200 === $st && 1 === $d['total'] && 'List three' === $d['items'][0]['title'], array( $st, $d['total'] ?? null ) );
list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer', 'tax' => 'cn_explainer_topic', 'term' => 'basics' ) );
$is( 'by a term of another taxonomy (slug): two', 200 === $st && 2 === $d['total'], array( $st, $d['total'] ?? null ) );
list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer', 'tax' => 'cn_explainer_topic', 'term' => (string) $deep['term_id'] ) );
$is( 'by a term (number): one', 200 === $st && 1 === $d['total'] && 'Explainer two' === $d['items'][0]['title'], array( $st, $d['total'] ?? null ) );
list( $st ) = $door( '/posts', array( 'tax' => 'cn_hidden', 'term' => 'x' ) );
$is( 'a taxonomy that is not public: refused', 400 === $st, $st );
list( $st ) = $door( '/posts', array( 'tax' => 'category' ) );
$is( 'a taxonomy without a term: refused', 400 === $st, $st );
list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer', 'author' => 'writer-two' ) );
$is( 'by author (name in the address): two', 200 === $st && 2 === $d['total'], array( $st, $d['total'] ?? null ) );
list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer', 'author' => (string) $tester ) );
$is( 'by author (number) with nothing of that type: none', 200 === $st && 0 === $d['total'] && array() === $d['items'], array( $st, $d['total'] ?? null ) );
list( $st, $d ) = $door( '/posts', array( 'category' => 'list-news', 'offset' => 1, 'per_page' => 1 ) );
$is( 'offset 1: the second newest first, two left, two pages', 200 === $st && 'List two' === $d['items'][0]['title'] && 2 === $d['total'] && 2 === $d['pages'], array( $d['items'][0]['title'] ?? null, $d['total'] ?? null, $d['pages'] ?? null ) );
list( $st, $d ) = $door( '/posts', array( 'category' => 'list-news', 'offset' => 1, 'per_page' => 1, 'page' => 2 ) );
$is( 'offset 1, page 2: the third', 200 === $st && 'List one' === ( $d['items'][0]['title'] ?? '' ), $d['items'][0]['title'] ?? null );

// Whole entries and their extra fields.
list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer', 'full' => 1, 'per_page' => 50 ) );
$two = $d['items'][0] ?? array();
$is( 'full: whole entries with text', 200 === $st && false !== strpos( (string) ( $two['html'] ?? '' ), 'Explained again.' ) && isset( $two['seo'] ), array_keys( $two ) );
$is( 'fields: the ones the type shows in the REST API', '2026-03-01' === ( $two['fields']['last_reviewed'] ?? '' ) && "First\nSecond" === ( $two['fields']['key_points'] ?? '' ), $two['fields'] ?? null );
$is( 'fields: nothing starting with "_", nothing kept out of the REST API', ! isset( $two['fields']['_internal_note'] ) && ! isset( $two['fields']['not_shown'] ), $two['fields'] ?? null );
list( $st, $d ) = $door( '/resolve', array( 'path' => '/explainers/explainer-two/' ) );
$is( 'an explainer by its address: an entry with fields and terms', 'entry' === ( $d['type'] ?? '' ) && '2026-03-01' === ( $d['entry']['fields']['last_reviewed'] ?? '' ) && 2 === count( $d['entry']['terms'] ?? array() ), $d['type'] ?? null );

// Terms and authors.
list( $st, $d ) = $door( '/terms', array( 'taxonomy' => 'cn_explainer_topic' ) );
$by_slug = array_column( $d['items'] ?? array(), null, 'slug' );
$is( 'terms: both topics, with description and parent', 200 === $st && 2 === count( $by_slug ) && 'Where to start.' === $by_slug['basics']['description'] && (int) $basics['term_id'] === $by_slug['deep']['parent'] && '/explainer-topic/basics/' === $by_slug['basics']['path'], $d );
list( $st ) = $door( '/terms', array( 'taxonomy' => 'cn_hidden' ) );
$is( 'terms of a taxonomy that is not public: refused', 400 === $st, $st );
list( $st, $d ) = $door( '/authors' );
$slugs = array_column( $d['items'] ?? array(), 'path', 'slug' );
$is( 'authors: everyone with something published', 200 === $st && '/author/writer-two/' === ( $slugs['writer-two'] ?? '' ) && isset( $slugs['tester'] ), $d );

// Answers given only when asked for.
list( $st, $d ) = $door( '/resolve', array( 'path' => '/explainers/' ) );
$is( "a type's list, not asked for: nothing", 404 === $st && 'none' === ( $d['type'] ?? '' ), array( $st, $d['type'] ?? null ) );
list( $st, $d ) = $door( '/resolve', array( 'path' => '/explainers/', 'with' => 'archive,author' ) );
$is( "a type's list, asked for", 200 === $st && 'archive' === ( $d['type'] ?? '' ) && 'cn_explainer' === ( $d['of'] ?? '' ) && 2 === ( $d['total'] ?? 0 ) && 'Explainers' === ( $d['seo']['title'] ?? '' ), array( $st, $d['type'] ?? null, $d['seo'] ?? null ) );
list( $st, $d ) = $door( '/resolve', array( 'path' => '/author/writer-two/' ) );
$is( "an author's page, not asked for: nothing", 404 === $st, $st );
list( $st, $d ) = $door( '/resolve', array( 'path' => '/author/writer-two/', 'with' => 'author' ) );
$is( "an author's page, asked for", 200 === $st && 'author' === ( $d['type'] ?? '' ) && 'Writer Two' === ( $d['author']['name'] ?? '' ) && 'Writes the explainers.' === ( $d['author']['description'] ?? '' ) && 2 === ( $d['total'] ?? 0 ) && 'door' === ( $d['seo']['source'] ?? '' ), array( $st, $d['type'] ?? null, $d['total'] ?? null ) );
list( $st, $d ) = $door( '/resolve', array( 'path' => '/author/nobody-here/', 'with' => 'author' ) );
$is( 'an author who does not exist: nothing', 404 === $st, $st );

list( $st, $d ) = $door( '/paths' );
$kinds = array_values( array_unique( array_column( $d['items'] ?? array(), 'type' ) ) );
$is( 'paths as before: entries and categories only', 200 === $st && ! array_intersect( $kinds, array( 'archive', 'author', 'cn_explainer_topic', 'post_tag' ) ) && in_array( 'cn_explainer', $kinds, true ), $kinds );
list( $st, $d ) = $door( '/paths', array( 'with' => 'terms,authors,archives' ) );
$paths = array_column( $d['items'] ?? array(), 'path' );
$is( 'paths with terms, authors and lists', 200 === $st && ! array_diff( array( '/explainers/', '/author/writer-two/', '/explainer-topic/basics/', '/tag/alpha/' ), $paths ), $paths );

// Asked for on top: other plugins' fields, picture sizes; one term; one type's addresses.
list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer' ) );
$is( 'a card has no "meta" unless asked', ! array_key_exists( 'meta', $d['items'][0] ), array_keys( $d['items'][0] ) );
list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer', 'with' => 'meta' ) );
$m = $d['items'][0]['meta'] ?? null;
$is( 'with=meta: every field the REST API shows, names starting with "_" too', is_array( $m ) && 'for staff' === ( $m['_internal_note'] ?? '' ) && '2026-03-01' === ( $m['last_reviewed'] ?? '' ) && ! array_key_exists( 'not_shown', $m ), $m );
list( $st, $d ) = $door( '/resolve', array( 'path' => '/explainers/explainer-two/', 'with' => 'meta' ) );
$is( 'with=meta on an entry too; "fields" stays without the "_" names', 'for staff' === ( $d['entry']['meta']['_internal_note'] ?? '' ) && ! isset( $d['entry']['fields']['_internal_note'] ), $d['entry']['meta'] ?? null );

$pic = wp_insert_attachment( array( 'post_title' => 'Door picture', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), '2026/01/door.jpg' );
wp_update_attachment_metadata( $pic, array( 'width' => 2000, 'height' => 1000, 'file' => '2026/01/door.jpg', 'sizes' => array( 'large' => array( 'file' => 'door-1024x512.jpg', 'width' => 1024, 'height' => 512, 'mime-type' => 'image/jpeg' ), 'sp_600' => array( 'file' => 'door-600x338.jpg', 'width' => 600, 'height' => 338, 'mime-type' => 'image/jpeg' ) ) ) );
set_post_thumbnail( $posts['List three'], $pic );
list( $st, $d ) = $door( '/posts', array( 'tag' => 'alpha' ) );
$is( 'a picture has no "sizes" unless asked', isset( $d['items'][0]['image']['src'] ) && ! array_key_exists( 'sizes', $d['items'][0]['image'] ), $d['items'][0]['image'] ?? null );
list( $st, $d ) = $door( '/posts', array( 'tag' => 'alpha', 'with' => 'sizes' ) );
$sz = $d['items'][0]['image']['sizes'] ?? array();
$is( 'with=sizes: every size by its name', 600 === ( $sz['sp_600']['width'] ?? 0 ) && false !== strpos( (string) ( $sz['sp_600']['src'] ?? '' ), 'door-600x338.jpg' ) && 2000 === ( $sz['full']['width'] ?? 0 ) && 1024 === ( $sz['large']['width'] ?? 0 ), $sz );

// The measures are the file's own, also when the theme has a narrower text column (WordPress would say 800 wide).
$GLOBALS['content_width'] = 800;
list( $st, $d ) = $door( '/posts', array( 'tag' => 'alpha', 'with' => 'sizes' ) );
$im = $d['items'][0]['image'] ?? array();
$is( 'a picture keeps its real width and height under a narrow theme', 1024 === ( $im['width'] ?? 0 ) && 512 === ( $im['height'] ?? 0 ) && 1024 === ( $im['sizes']['large']['width'] ?? 0 ) && 2000 === ( $im['sizes']['full']['width'] ?? 0 ) && false !== strpos( (string) ( $im['src'] ?? '' ), 'door-1024x512.jpg' ), $im );
unset( $GLOBALS['content_width'] );

list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer', 'by' => 'writer-two' ) );
$is( 'by= is another name for author=', 200 === $st && 2 === $d['total'], array( $st, $d['total'] ?? null ) );
list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer', 'by' => (string) $tester ) );
$is( 'by= with a number', 200 === $st && 0 === $d['total'], array( $st, $d['total'] ?? null ) );

$quoted = wp_insert_post( array( 'post_title' => 'Quoted', 'post_excerpt' => "The club's best year -- so far.", 'post_content' => '<p>Text.</p>', 'post_status' => 'publish', 'post_date' => '2025-01-01 10:00:00', 'post_date_gmt' => '2025-01-01 10:00:00', 'tags_input' => array( 'quoted' ) ) );
list( $st, $d ) = $door( '/posts', array( 'tag' => 'quoted' ) );
$is( 'a short text gets the same quotes and dashes as WordPress shows', "The club\u{2019}s best year \u{2014} so far." === ( $d['items'][0]['excerpt'] ?? '' ), $d['items'][0]['excerpt'] ?? null );
wp_delete_post( $quoted, true );

list( $st, $d ) = $door( '/terms', array( 'taxonomy' => 'cn_explainer_topic', 'slug' => 'deep' ) );
$is( 'one term by its slug', 200 === $st && 1 === count( $d['items'] ) && 'deep' === $d['items'][0]['slug'], $d );
list( $st, $d ) = $door( '/terms', array( 'taxonomy' => 'cn_explainer_topic', 'slug' => 'nothing-like-it' ) );
$is( 'a slug nobody has: an empty list', 200 === $st && array() === $d['items'], $d );

list( $st, $d ) = $door( '/paths', array( 'type' => 'cn_explainer', 'per_page' => 1 ) );
$is( "one type's addresses, a page at a time, oldest number first", 200 === $st && 2 === $d['total'] && 2 === $d['pages'] && '/explainers/explainer-one/' === $d['items'][0]['path'] && 1 === count( $d['items'] ), $d );
list( $st, $d ) = $door( '/paths', array( 'type' => 'cn_explainer', 'per_page' => 1, 'page' => 2, 'with' => 'terms,authors,archives' ) );
$is( 'page 2 has the other one, and no terms or authors mixed in', 200 === $st && array( '/explainers/explainer-two/' ) === array_column( $d['items'], 'path' ), $d );
list( $st ) = $door( '/paths', array( 'type' => 'nope' ) );
$is( 'addresses of an unknown type: refused', 400 === $st, $st );
list( $st, $d ) = $door( '/posts', array( 'search' => 'List', 'orderby' => 'date' ) );
$is( 'a search, newest first when asked', 200 === $st && array( 'List three', 'List two', 'List one' ) === array_slice( array_column( $d['items'], 'title' ), 0, 3 ), array_column( $d['items'] ?? array(), 'title' ) );
list( $st, $d ) = $door( '/posts', array( 'category' => 'list-news', 'orderby' => 'title' ) );
$is( 'by title', 200 === $st && array( 'List one', 'List three', 'List two' ) === array_column( $d['items'], 'title' ), array_column( $d['items'] ?? array(), 'title' ) );
list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer', 'full' => 1, 'per_page' => 50 ) );
$is( 'whole entries: up to 50 a page', 200 === $st && 2 === count( $d['items'] ) && 1 === $d['pages'], array( $st, $d['pages'] ?? null ) );

// Related posts without GROUP BY (0.22.2): a list under one term, or a term and its children, never repeats an entry.
$sql = array();
$log = static function ( $request, $q ) use ( &$sql ) {
	if ( $q->get( 'clientnav_one_join' ) ) {
		$sql[] = $request;
	}
	return $request;
};
add_filter( 'posts_request', $log, 10, 2 );
list( $st, $d ) = $door( '/posts', array( 'category' => 'list-news', 'exclude' => (string) $posts['List three'], 'per_page' => 4, 'count' => 0 ) );
$is( 'related: the section without the entry itself, newest first', 200 === $st && array( 'List two', 'List one' ) === array_column( $d['items'], 'title' ), array_column( $d['items'] ?? array(), 'title' ) );
$is( 'related: no GROUP BY and no sort of the whole section', $sql && false === stripos( end( $sql ), 'GROUP BY' ), end( $sql ) );
$sql = array();
list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer', 'tax' => 'cn_explainer_topic', 'term' => 'basics', 'count' => 0 ) );
$is( 'a term and its children: each entry once, no GROUP BY', 200 === $st && array( 'Explainer two', 'Explainer one' ) === array_column( $d['items'], 'title' ) && false === stripos( end( $sql ), 'GROUP BY' ) && false !== stripos( end( $sql ), 'IN ( SELECT object_id' ), array( array_column( $d['items'] ?? array(), 'title' ), end( $sql ) ) );
$sql = array();
list( $st, $d ) = $door( '/posts', array( 'type' => 'cn_explainer', 'tax' => 'cn_explainer_topic', 'term' => 'basics' ) );
$is( 'a term and its children, counted: total 2', 200 === $st && 2 === $d['total'] && 2 === count( $d['items'] ) && false === stripos( end( $sql ), 'GROUP BY' ), array( $d['total'] ?? null, end( $sql ) ) );
$sql = array();
list( $st, $d ) = $door( '/posts', array( 'category' => 'list-news', 'search' => 'List' ) );
$is( 'a search keeps WordPress\'s own query', 200 === $st && 3 === $d['total'], array( $st, $d['total'] ?? null ) );
remove_filter( 'posts_request', $log, 10 );

echo "$pass passed, $fail failed\n";
