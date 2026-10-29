<?php
// Runs inside the throwaway WordPress (wp eval-file): page make-ups (roadmap 3f) as a business news site and a large news site describe
// their home pages, GET /clientnav-headless/v1/layout.
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
	$q = new WP_REST_Request( 'GET', '/clientnav-headless/v1/layout' );
	$q->set_query_params( $query );
	$r = rest_do_request( $q );
	return array( $r->get_status(), json_decode( (string) wp_json_encode( $r->get_data() ), true ) );
};
use ClientNav\Headless as H;

$cat  = static fn( string $slug ) => (int) wp_insert_term( ucfirst( $slug ), 'category', array( 'slug' => $slug ) )['term_id'];
$news = $cat( 'lt-news' );
$arts = $cat( 'lt-articles' );
$pop  = $cat( 'lt-pop' );
$made = array();
$post = static function ( string $title, $term, int $minutes_ago, array $meta = array() ) use ( &$made ) {
	$id = wp_insert_post(
		array(
			'post_title'    => $title,
			'post_content'  => str_repeat( 'Слово за словом идёт текст статьи. ', 30 ),
			'post_status'   => 'publish',
			'post_category' => (array) $term,
			'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 60 * $minutes_ago ),
			'meta_input'    => $meta,
		)
	);
	$made[] = $id;
	return $id;
};
$a1 = $post( 'LT article with picture', $arts, 1, array( '_lt_picture' => 'x.jpg' ) );
$a2 = $post( 'LT article plain', $arts, 2 );
$a3 = $post( 'LT article kept off', $arts, 3, array( '_lt_picture' => 'y.jpg', '_lt_no_home' => '1' ) );
$n  = array();
for ( $i = 1; $i <= 6; $i++ ) {
	$n[] = $post( "LT news $i", $news, 10 + $i );
}
$p1 = $post( 'LT pop', array( $pop, $news ), 5 ); // also in news: not_sections must still leave it out
stick_post( $n[4] );

add_filter(
	'clientnav_headless_layouts',
	static fn( $all ) => $all + array(
		'home'  => array(
			'lead'   => array( 'sections' => array( 'lt-articles' ), 'picture' => '_lt_picture', 'hide' => '_lt_no_home', 'one' => true, 'excerpt' => 60 ),
			'news'   => array( 'sections' => array( 'lt-news', 'lt-articles' ), 'not_sections' => array( 'lt-pop' ), 'count' => 4, 'excerpt' => 40 ),
			'chosen' => array( 'sticky' => true, 'sections' => array( 'lt-news' ), 'count' => 2, 'unique' => false ),
			'groups' => array(
				'groups' => array(
					'articles' => array( 'sections' => array( 'lt-articles' ), 'count' => 3, 'hide' => '_lt_no_home' ),
					'pop'      => array( 'sections' => array( 'lt-pop' ), 'count' => 3 ),
					'none'     => array( 'sections' => array( 'no-such-section' ) ),
				),
			),
		),
		'side'  => array( 'chosen' => array( 'sticky' => true, 'sections' => array( 'lt-news' ), 'count' => 1, 'one' => true ) ),
	)
);
H\forget();

list( $st, $d ) = $door( array() );
$is( 'without a name: the names of the make-ups', 200 === $st && array( 'home', 'side' ) === ( $d['layouts'] ?? null ), $d );
list( $st ) = $door( array( 'name' => 'nope' ) );
$is( 'an unknown make-up: 404', 404 === $st, $st );

list( $st, $d ) = $door( array( 'name' => 'home' ) );
$g = $d['groups'] ?? array();
$is( 'home answers 200 with its groups in order', 200 === $st && array( 'lead', 'news', 'chosen', 'groups' ) === array_keys( $g ), array_keys( $g ) );
$is( 'lead: one card, the newest with the picture field, cut to 60 characters', $a1 === ( $g['lead']['id'] ?? 0 ) && mb_strlen( (string) $g['lead']['excerpt'] ) <= 61, $g['lead'] ?? null );
$ids = array_column( (array) ( $g['news'] ?? array() ), 'id' );
$is( 'news: 4 newest outside lt-pop, without the lead already shown', array( $a2, $a3, $n[0], $n[1] ) === $ids, $ids );
$is( 'news: summaries cut to 40 characters', mb_strlen( (string) ( $g['news'][1]['excerpt'] ?? '' ) ) <= 41, $g['news'][1]['excerpt'] ?? null );
$chosen = array_column( (array) ( $g['chosen'] ?? array() ), 'id' );
$is( 'chosen: the post marked "Stick to the top", even if shown above (unique off)', array( $n[4] ) === $chosen, $chosen );
$arts_ids = array_column( (array) ( $g['groups']['articles'] ?? array() ), 'id' );
$is( 'a set of groups answered in place; kept-off posts left out, shown ones not repeated', array() === array_intersect( array( $a1, $a2, $a3 ), $arts_ids ), $arts_ids );
$is( 'pop group has its post', array( $p1 ) === array_column( (array) ( $g['groups']['pop'] ?? array() ), 'id' ), $g['groups']['pop'] ?? null );
$is( 'sections the site does not have: an empty list, not everything', array() === ( $g['groups']['none'] ?? null ), $g['groups']['none'] ?? null );

unstick_post( $n[4] );
H\forget();
list( , $d ) = $door( array( 'name' => 'side' ) );
$is( 'nothing marked: the sticky group falls back to its sections\' newest', $p1 === ( $d['groups']['chosen']['id'] ?? 0 ), $d['groups']['chosen'] ?? null );

update_post_meta( $a1, '_lt_no_home', '1' );
list( , $d ) = $door( array( 'name' => 'home' ) );
$is( 'an editor keeping the lead off the home page is seen on the next ask (kept answers forgotten)', array_key_exists( 'lead', (array) ( $d['groups'] ?? array() ) ) && null === $d['groups']['lead'], $d['groups'] ?? null );

foreach ( $made as $id ) {
	wp_delete_post( $id, true );
}
foreach ( array( $news, $arts, $pop ) as $t ) {
	wp_delete_term( $t, 'category' );
}
echo "$pass passed, $fail failed\n";
