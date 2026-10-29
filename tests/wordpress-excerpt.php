<?php
// Runs inside the throwaway WordPress (wp eval-file): excerpts made as WordPress makes them.
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
use ClientNav\Headless as H;
$more  = wp_insert_post( array( 'post_title' => 'With more', 'post_status' => 'publish', 'post_content' => "<p>What are the best VPNs for travelling?<!--more--><br />\nA VPN gives you privacy and much more text here.</p>" ) );
$own   = wp_insert_post( array( 'post_title' => 'Own excerpt', 'post_status' => 'publish', 'post_excerpt' => 'Written by hand.', 'post_content' => 'Body <!--more--> rest' ) );
$plain = wp_insert_post( array( 'post_title' => 'Plain', 'post_status' => 'publish', 'post_content' => str_repeat( 'word ', 50 ) ) );
$is( 'the text before the more mark', 'What are the best VPNs for travelling?' === H\card( get_post( $more ) )['excerpt'], H\card( get_post( $more ) )['excerpt'] );
$is( 'the editor\'s own excerpt first', 'Written by hand.' === H\card( get_post( $own ) )['excerpt'] );
$is( 'otherwise thirty words', 30 === str_word_count( rtrim( H\card( get_post( $plain ) )['excerpt'], '…' ) ) );
$embed = wp_insert_post( array( 'post_title' => 'Embeds', 'post_status' => 'publish', 'post_content' => "Videos\nhttps://youtu.be/zCCeT99_Na4?si=abc\nhttps://youtu.be/FCL29cyOlYc after" ) );
$is( 'bare addresses (embedded players) leave no words', 'Videos after' === H\card( get_post( $embed ) )['excerpt'], H\card( get_post( $embed ) )['excerpt'] );

// The page that lists the posts, asked by its address.
$blog = wp_insert_post( array( 'post_title' => 'Archive', 'post_name' => 'archive', 'post_type' => 'page', 'post_status' => 'publish' ) );
$was  = array( get_option( 'show_on_front' ), get_option( 'page_for_posts' ) );
update_option( 'show_on_front', 'page' );
update_option( 'page_for_posts', $blog );
$q = new WP_REST_Request( 'GET', '/clientnav-headless/v1/resolve' );
$q->set_query_params( array( 'path' => '/archive/' ) );
$r = rest_do_request( $q );
$d = $r->get_data();
$is( 'the posts page answers as the list of posts', 200 === $r->get_status() && 'posts' === ( $d['type'] ?? '' ) && 'Archive' === ( $d['title'] ?? '' ) && ! empty( $d['items'] ), array( $r->get_status(), $d['type'] ?? null ) );
$q->set_query_params( array( 'path' => '/archive/page/2/' ) );
$d = rest_do_request( $q )->get_data();
$is( 'and its second page', 'posts' === ( $d['type'] ?? '' ) && 2 === ( $d['page'] ?? 0 ), array( $d['type'] ?? null, $d['page'] ?? null ) );
update_option( 'show_on_front', $was[0] );
update_option( 'page_for_posts', $was[1] );
// Summaries by character count (?excerpt=), for lists in languages where a word count is no measure.
$ru = wp_insert_post( array( 'post_title' => 'По-русски', 'post_status' => 'publish', 'post_content' => '<p>Правительство Грузии утвердило новый порядок регистрации, который &laquo;упростит&raquo; работу малого бизнеса, сообщает агентство.</p>' ) );
$q  = new WP_REST_Request( 'GET', '/clientnav-headless/v1/posts' );
$q->set_query_params( array( 'search' => 'Правительство', 'excerpt' => 40 ) );
$d = json_decode( (string) wp_json_encode( rest_do_request( $q )->get_data() ), true );
$s = $d['items'][0]['excerpt'] ?? '';
$is( 'cut at a word within 40 characters, with …', mb_strlen( $s ) <= 41 && str_ends_with( $s, '…' ) && 0 === strpos( $s, 'Правительство Грузии утвердило' ) && false === strpos( $s, 'новый порядок' ), $s );
$q->set_query_params( array( 'search' => 'Правительство', 'excerpt' => 400 ) );
$d = json_decode( (string) wp_json_encode( rest_do_request( $q )->get_data() ), true );
$is( 'a text shorter than the cut is whole, with entities decoded and no …', false !== strpos( $d['items'][0]['excerpt'] ?? '', '«упростит»' ) && ! str_ends_with( $d['items'][0]['excerpt'] ?? '', '…' ), $d['items'][0]['excerpt'] ?? null );
$q->set_query_params( array( 'search' => 'Правительство' ) );
$d = json_decode( (string) wp_json_encode( rest_do_request( $q )->get_data() ), true );
$is( 'without the parameter, thirty words as before', false === strpos( $d['items'][0]['excerpt'] ?? '', '…' ) || mb_strlen( $d['items'][0]['excerpt'] ) > 41, $d['items'][0]['excerpt'] ?? null );
$q->set_query_params( array( 'search' => 'Own excerpt', 'excerpt' => 8 ) );
$d = json_decode( (string) wp_json_encode( rest_do_request( $q )->get_data() ), true );
$is( 'an editor\'s own excerpt is given as written, whatever length was asked', 'Written by hand.' === ( $d['items'][0]['excerpt'] ?? '' ), $d['items'][0]['excerpt'] ?? null );
$r = new WP_REST_Request( 'GET', '/clientnav-headless/v1/resolve' );
$r->set_query_params( array( 'path' => wp_make_link_relative( get_permalink( $ru ) ), 'excerpt' => 40 ) );
$d = json_decode( (string) wp_json_encode( rest_do_request( $r )->get_data() ), true );
$is( 'on /resolve too: the entry\'s summary cut at 40', mb_strlen( $d['entry']['excerpt'] ?? '' ) <= 41 && str_ends_with( $d['entry']['excerpt'] ?? '', '…' ), $d['entry']['excerpt'] ?? null );
$comma = wp_insert_post( array( 'post_title' => 'Comma', 'post_status' => 'publish', 'post_content' => 'Раз, два, три четыре пять' ) );
$r->set_query_params( array( 'path' => wp_make_link_relative( get_permalink( $comma ) ), 'excerpt' => 9 ) );
$d = json_decode( (string) wp_json_encode( rest_do_request( $r )->get_data() ), true );
$is( 'a trailing comma before the cut is kept (the site shows it so)', 'Раз,…' === ( $d['entry']['excerpt'] ?? '' ), $d['entry']['excerpt'] ?? null );
wp_delete_post( $comma, true );
wp_delete_post( $ru, true );
foreach ( array( $more, $own, $plain, $embed, $blog ) as $id ) {
	wp_delete_post( $id, true );
}
echo "$pass passed, $fail failed\n";
