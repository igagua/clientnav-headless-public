<?php
// Runs inside the throwaway WordPress (wp eval-file): answers in a language, with a stand-in for Client Navigator
// Translate's Api that "translates" by putting [es] in front and adds /es to addresses.
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
$door = function ( string $route, array $query ) {
	$q = new WP_REST_Request( 'GET', '/clientnav-headless/v1' . $route );
	$q->set_query_params( $query );
	$r = rest_do_request( $q );
	return array( $r->get_status(), json_decode( (string) wp_json_encode( $r->get_data() ), true ) );
};
use ClientNav\Headless as H;

$is( 'without Translate, ?lang changes nothing', ( function () use ( $door ) { list( , $d ) = $door( '/posts', array( 'lang' => 'es', 'per_page' => 1 ) ); return ! isset( $d['lang'] ); } )() );

eval( <<<'PHP'
namespace ClientNav\Translate;
final class Api {
	public static $asked  = array();
	public static $manual = false;
	public static function default_language() { return 'en'; }
	public static function languages() { return array( 'es' ); }
	public static function post( $id, $lang, $record = false ) {
		$p = get_post( $id );
		return $p ? array( 'title' => '[es] ' . $p->post_title, 'excerpt' => '[es] excerpt', 'content' => '<p>[es] text with <a href="' . home_url( '/about/' ) . '">a link</a></p>', 'slug' => $p->post_name . '-es', 'manual' => self::$manual, 'missing' => 2 ) : null;
	}
	public static function versions( $id ) { $p = get_permalink( $id ); return array( 'en' => array( 'url' => $p ), 'es' => array( 'url' => self::url( $p, 'es' ) ) ); }
	public static function translate( $entries, $lang, $post_id = 0, $record = false ) {
		$items = array();
		foreach ( $entries as $k => $e ) {
			self::$asked[] = $e['as'] ?? 'html';
			// Like the real one, a "meta" piece has its own translations: none stored for a title made a heading.
			$items[ $k ] = isset( $e['html'] ) ? '[es] ' . $e['html'] : ( 'meta' === ( $e['as'] ?? '' ) ? '[es:meta] ' : '[es] ' ) . $e['text'];
		}
		return array( 'items' => $items, 'total' => count( $entries ), 'missing' => 1 );
	}
	public static function url( $url, $lang ) { return preg_replace( '#^(https?://[^/]+)#', '$1/' . $lang, $url ); }
	public static function resolve( $path ) {
		return 0 === strpos( $path, '/es/' ) ? array( 'path' => substr( $path, 3 ), 'lang' => 'es' ) : array( 'path' => $path, 'lang' => 'en' );
	}
}
PHP
);
H\forget();

list( $st, $d ) = $door( '/resolve', array( 'path' => '/es/door-test-article/' ) );
$e = $d['entry'] ?? array();
$is( 'an /es/ address: the entry, in Spanish, from Translate\'s own fields', 'entry' === ( $d['type'] ?? '' ) && '[es] Door test article' === ( $e['title'] ?? '' ) && false !== strpos( (string) ( $e['html'] ?? '' ), '[es] text' ), array( $d['type'] ?? null, $e['title'] ?? null ) );
$is( 'its own links are paths in the language, its address gets the language', false !== strpos( (string) ( $e['html'] ?? '' ), 'href="/es/about/"' ) && '/es/door-test-article/' === ( $e['path'] ?? '' ), array( $e['path'] ?? null ) );
$is( 'versions: its address in every language', '/door-test-article/' === ( $e['versions']['en'] ?? '' ) && '/es/door-test-article/' === ( $e['versions']['es'] ?? '' ), $e['versions'] ?? null );
$is( 'terms go through Translate; the SEO words the door made from title and excerpt take their translation', 0 === strpos( (string) ( $e['terms'][0]['name'] ?? '' ), '[es] ' ) && ( $e['title'] ?? 0 ) === ( $e['seo']['title'] ?? 1 ) && ( $e['excerpt'] ?? 0 ) === ( $e['seo']['description'] ?? 1 ), $e['seo'] ?? null );
$is( 'and are not asked again, so not counted twice', ! in_array( 'meta', ClientNav\Translate\Api::$asked, true ), ClientNav\Translate\Api::$asked );
// SEO words of their own (PR Register's "Title | Site") go as "meta".
$own_seo = static function ( $seo ) {
	$seo['title'] = 'Door test article | Site';
	return $seo;
};
add_filter( 'clientnav_headless_seo', $own_seo );
H\forget();
list( , $d2 ) = $door( '/resolve', array( 'path' => '/es/door-test-article/', 'cb' => 'own' ) );
$is( 'SEO words of their own: translated as "meta"', '[es:meta] Door test article | Site' === ( $d2['entry']['seo']['title'] ?? '' ), $d2['entry']['seo'] ?? null );
remove_filter( 'clientnav_headless_seo', $own_seo );
// Addresses in the SEO words: the front end's own get the language (the practice site's /es/ pages had the English canonical).
$front = H\frontend_url();
$addr_seo = static function ( $seo ) {
	$seo['canonical'] = H\frontend_url() . '/door-test-article/';
	$seo['og']        = array( 'url' => H\frontend_url() . '/door-test-article/', 'title' => 'T' );
	$seo['twitter']   = array( 'url' => 'https://elsewhere.example.org/door-test-article/' );
	return $seo;
};
add_filter( 'clientnav_headless_seo', $addr_seo );
H\forget();
list( , $d3 ) = $door( '/resolve', array( 'path' => '/es/door-test-article/', 'cb' => 'addr' ) );
$s3 = $d3['entry']['seo'] ?? array();
$is( 'canonical and og.url on the front end get the language; another site\'s address is left alone', '' !== $front && $front . '/es/door-test-article/' === ( $s3['canonical'] ?? '' ) && $front . '/es/door-test-article/' === ( $s3['og']['url'] ?? '' ) && 'https://elsewhere.example.org/door-test-article/' === ( $s3['twitter']['url'] ?? '' ), $s3 );
H\forget();
list( , $d3 ) = $door( '/resolve', array( 'path' => '/door-test-article/', 'cb' => 'addr-en' ) );
$is( 'in the site language the canonical stays as it is', $front . '/door-test-article/' === ( $d3['entry']['seo']['canonical'] ?? '' ), $d3['entry']['seo'] ?? null );
remove_filter( 'clientnav_headless_seo', $addr_seo );
// A title translated by hand: the SEO title is that same hand translation, not asked of Translate.
ClientNav\Translate\Api::$manual = array( 'title' );
ClientNav\Translate\Api::$asked  = array();
H\forget();
list( , $d2 ) = $door( '/resolve', array( 'path' => '/es/door-test-article/', 'cb' => 'hand' ) );
$is( 'a hand-translated title: the SEO title is the same', '[es] Door test article' === ( $d2['entry']['seo']['title'] ?? '' ) && ! in_array( 'meta', ClientNav\Translate\Api::$asked, true ), array( $d2['entry']['seo'] ?? null, ClientNav\Translate\Api::$asked ) );
ClientNav\Translate\Api::$manual = false;
H\forget();
$is( '"lang": the code, and how much is still missing', 'es' === ( $d['lang']['code'] ?? '' ) && ( $d['lang']['missing'] ?? 0 ) >= 2, $d['lang'] ?? null );

list( $st, $d ) = $door( '/resolve', array( 'path' => '/door-test-article/' ) );
$is( 'the same entry in the site language: as before', 'Door test article' === ( $d['entry']['title'] ?? '' ) && ! isset( $d['lang'] ), $d['entry']['title'] ?? null );

list( $st, $d ) = $door( '/posts', array( 'category' => 'list-news', 'lang' => 'es', 'per_page' => 2 ) );
$c = $d['items'][0] ?? array();
$is( 'a list in Spanish: titles as headings, short texts as text, addresses with /es', 0 === strpos( (string) ( $c['title'] ?? '' ), '[es] ' ) && 0 === strpos( (string) ( $c['excerpt'] ?? '' ), '[es] ' ) && 0 === strpos( (string) ( $c['path'] ?? '' ), '/es/' ) && 0 === strpos( (string) ( $c['section']['path'] ?? '' ), '/es/' ), $c );
list( $st, $d ) = $door( '/terms', array( 'taxonomy' => 'cn_explainer_topic', 'lang' => 'es' ) );
$is( 'terms in Spanish', 0 === strpos( (string) ( $d['items'][0]['name'] ?? '' ), '[es] ' ) && 0 === strpos( (string) ( $d['items'][0]['path'] ?? '' ), '/es/' ), $d['items'][0] ?? null );

$menu = wp_create_nav_menu( 'Lang menu ' . wp_rand() );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Contact', 'menu-item-url' => home_url( '/contact/' ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
$places                      = (array) get_nav_menu_locations();
$places['clientnav_primary'] = $menu;
set_theme_mod( 'nav_menu_locations', $places );
list( $st, $d ) = $door( '/site', array( 'lang' => 'es' ) );
$m = $d['menus']['primary'][0] ?? array();
$is( 'menus in Spanish, as "menu", with /es addresses', '[es] Contact' === ( $m['title'] ?? '' ) && '/es/contact/' === ( $m['href'] ?? '' ) && in_array( 'menu', ClientNav\Translate\Api::$asked, true ), $m );
list( $st, $d ) = $door( '/posts', array( 'lang' => 'fr', 'per_page' => 1 ) );
$is( 'a language the site does not translate into: as without ?lang', ! isset( $d['lang'] ) && 0 !== strpos( (string) ( $d['items'][0]['title'] ?? '' ), '[es]' ), $d['lang'] ?? null );

// Elementor parts in a language: each piece with the role it has on the page.
ClientNav\Translate\Api::$asked = array();
$b     = new H\Lang_Batch( 'es' );
$parts = array( array( 't' => 'box', 'kids' => array( array( 't' => 'h', 'html' => 'Our offices' ), array( 't' => 'btn', 'html' => 'Book', 'link' => array( 'href' => '/book/' ) ), array( 't' => 'p', 'html' => '<p>Words</p>' ), array( 't' => 'img', 'alt' => 'A smile' ) ) ) );
H\lang_parts( $parts, $b, 'es' );
$b->run();
$k = $parts[0]['kids'];
$is( 'Elementor parts: heading, button and paragraph as HTML (they carry their marks), picture text as an attribute; links with /es', '[es] Our offices' === $k[0]['html'] && '[es] Book' === $k[1]['html'] && '/es/book/' === $k[1]['link']['href'] && '[es] A smile' === $k[3]['alt'] && array( 'html', 'html', 'html', 'attr' ) === ClientNav\Translate\Api::$asked, array( $k, ClientNav\Translate\Api::$asked ) );
ClientNav\Translate\Api::$asked = array();
$form = array( array( 't' => 'form', 'form' => array( 'id' => 1, 'title' => 'Contact', 'button' => 'Send', 'thanks' => 'Thanks!', 'fields' => array( array( 'name' => 'n', 'type' => 'text', 'label' => 'Name', 'placeholder' => 'Your name' ), array( 'name' => 'c', 'type' => 'select', 'label' => 'Office', 'options' => array( array( 'label' => 'Downtown', 'value' => 'd' ) ) ) ) ) ) );
$fb = new ClientNav\Headless\Lang_Batch( 'es' );
ClientNav\Headless\lang_parts( $form, $fb, 'es' );
$fb->run();
$f = $form[0]['form'];
$is( 'a form on a page: labels, placeholders, choices, button and thanks in the form role', '[es] Name' === $f['fields'][0]['label'] && '[es] Your name' === $f['fields'][0]['placeholder'] && '[es] Downtown' === $f['fields'][1]['options'][0]['label'] && '[es] Send' === $f['button'] && '[es] Thanks!' === $f['thanks'] && array( 'form', 'attr' ) === array_values( array_unique( ClientNav\Translate\Api::$asked ) ), array( $f, ClientNav\Translate\Api::$asked ) );

// Phrases that stay as they are, wherever they stand.
add_filter( 'clientnav_headless_keep_words', static fn( $words ) => array_merge( $words, array( 'South Gate' ) ) );
ClientNav\Translate\Api::$asked = array();
$b     = new H\Lang_Batch( 'es' );
$parts = array( array( 't' => 'btn', 'html' => 'south gate' ), array( 't' => 'h', 'html' => 'South Gate office hours' ), array( 't' => 'p', 'html' => '<p>South Gate</p>' ) );
H\lang_parts( $parts, $b, 'es' );
$b->run();
$is( 'a kept phrase stays as it is on a button and in a paragraph (any letter case); a longer heading is translated', 'south gate' === $parts[0]['html'] && '<p>South Gate</p>' === $parts[2]['html'] && '[es] South Gate office hours' === $parts[1]['html'] && 1 === count( ClientNav\Translate\Api::$asked ), array( $parts, ClientNav\Translate\Api::$asked ) );
remove_all_filters( 'clientnav_headless_keep_words' );

// A picture's words, and the words a site add-on puts on cards (clientnav_headless_lang_fields), each in its role.
add_filter( 'clientnav_headless_lang_fields', static fn( $f ) => $f + array( 'site.position' => 'text', 'site.intro' => 'html', 'site.services[]' => 'text', 'site.nothing' => 'text', 'deep.er.gone' => 'text' ) );
add_filter( 'clientnav_headless_card', static fn( $c ) => $c + array( 'site' => array( 'position' => 'Office Manager', 'intro' => '<p>Hello</p>', 'services' => array( 'Cleanings', 'Fillings' ), 'kept' => 'Not declared' ) ) );
$with = wp_insert_post( array( 'post_title' => 'With a picture', 'post_status' => 'publish' ) );
$pic  = wp_insert_attachment( array( 'post_title' => 'pic', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), 'pic.jpg', $with );
update_post_meta( $pic, '_wp_attachment_image_alt', 'A smiling child' );
update_post_meta( $pic, '_wp_attachment_metadata', array( 'width' => 10, 'height' => 10, 'file' => 'pic.jpg', 'sizes' => array() ) );
set_post_thumbnail( $with, $pic );
ClientNav\Translate\Api::$asked = array();
list( $st, $d ) = $door( '/posts', array( 'lang' => 'es', 'search' => 'With a picture', 'per_page' => 1 ) );
$card = $d['items'][0] ?? array();
$is( 'a card: picture words translated as an attribute', '[es] A smiling child' === ( $card['image']['alt'] ?? '' ) && in_array( 'attr', ClientNav\Translate\Api::$asked, true ), array( $card['image'] ?? null, ClientNav\Translate\Api::$asked ) );
$is( 'an add-on\'s fields: text, html and each of a list, in their roles; undeclared ones and missing paths left alone', '[es] Office Manager' === ( $card['site']['position'] ?? '' ) && '[es] <p>Hello</p>' === ( $card['site']['intro'] ?? '' ) && array( '[es] Cleanings', '[es] Fillings' ) === ( $card['site']['services'] ?? null ) && 'Not declared' === ( $card['site']['kept'] ?? '' ), $card['site'] ?? null );
add_filter( 'clientnav_headless_keep_words', static fn( $w ) => array_merge( $w, array( 'Office Manager' ) ) );
list( $st, $d ) = $door( '/posts', array( 'lang' => 'es', 'search' => 'With a picture', 'per_page' => 1, 'cb' => 'kept' ) );
$is( 'a kept phrase stays as it is inside an add-on\'s field too', 'Office Manager' === ( $d['items'][0]['site']['position'] ?? '' ), $d['items'][0]['site'] ?? null );
wp_delete_attachment( $pic, true );
wp_delete_post( $with, true );
echo "$pass passed, $fail failed\n";
