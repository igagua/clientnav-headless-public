<?php
// Runs inside the throwaway WordPress (wp eval-file): pictures and files in an entry's text, as addresses on this
// WordPress or, when the front end serves /wp-content/ itself, as paths.
$pass = 0;
$fail = 0;
$is   = function ( string $name, $ok, $got = null ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		return;
	}
	++$fail;
	echo "FAIL  $name" . ( null === $got ? '' : ': ' . substr( is_string( $got ) ? $got : wp_json_encode( $got ), 0, 400 ) ) . "\n";
};
use ClientNav\Headless as H;
$up   = content_url( '/uploads/2026/01/a.jpg' );
$id   = wp_insert_post( array( 'post_title' => 'With pictures', 'post_status' => 'publish', 'post_content' => '<p><img src="' . $up . '" srcset="' . $up . ' 800w, ' . content_url( '/uploads/2026/01/a-300.jpg' ) . ' 300w" alt="x"> <a href="' . content_url( '/uploads/2026/01/doc.pdf' ) . '">file</a> <img src="https://elsewhere.test/wp-content/uploads/b.jpg" alt="y"></p>' ) );
$html = fn() => H\localize_html( (string) apply_filters( 'the_content', get_post( $id )->post_content ) );
$off  = $html();
$is( 'by default pictures and files stay named on this WordPress', false !== strpos( $off, 'src="' . $up . '"' ), $off );
add_filter( 'clientnav_headless_media_paths', '__return_true' );
$on = $html();
$is( 'with "pictures as paths": src and srcset as paths', false !== strpos( $on, 'src="/wp-content/uploads/2026/01/a.jpg"' ) && false !== strpos( $on, '/wp-content/uploads/2026/01/a-300.jpg 300w' ) && false === strpos( $on, content_url( '/uploads' ) ), $on );
$is( 'a link to a file too', false !== strpos( $on, 'href="/wp-content/uploads/2026/01/doc.pdf"' ), $on );
$is( 'another site\'s pictures are left alone', false !== strpos( $on, 'https://elsewhere.test/wp-content/uploads/b.jpg' ), $on );
wp_delete_post( $id, true );
echo "$pass passed, $fail failed\n";
