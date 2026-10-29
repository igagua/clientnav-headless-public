<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * Languages, through Client Navigator Translate (0.10.4 and later, its class \ClientNav\Translate\Api).
 *
 * A front end asks in a language with ?lang=es, or by the address itself (/es/primera-cita-dental/ on /resolve).
 * The answer is the same answer, in that language:
 *   - an entry's title, excerpt and text from Translate's own post fields (hand translations included);
 *   - titles and short texts of lists, names of terms, menus, SEO words and the words of Elementor parts, sent to
 *     Translate in one batch per answer, each with its role (heading, text, menu, meta, button, link, attr, html):
 *     without the right role Translate does not find translations made on the WordPress pages before;
 *   - every address of the site in that language (Translate's prefix and translated slugs);
 *   - "versions": an entry's address in every language, for hreflang;
 *   - "lang": { code, total, missing }: how many pieces were asked and how many have no translation yet (a front end
 *     can tell search engines to wait while most of a page is still missing).
 * Phrases that must stay as they are (office names, people's names) are given by the filter
 * clientnav_headless_keep_words (exact phrases, any letter case): such a piece is never sent to Translate.
 * Nothing new is recorded for translation from these answers: anyone can ask the door.
 * Without Translate, or for a language it does not translate into, answers are as without ?lang.
 */

/** The language an answer is being made in ('' for the site's own). Set by the routes. */
function door_lang( ?string $set = null ): string {
	static $lang = '';
	if ( null !== $set ) {
		$lang = $set;
	}
	return $lang;
}

function translate_ready(): bool {
	return class_exists( '\ClientNav\Translate\Api' );
}

/** A language code the site translates into, or ''. */
function offered_lang( string $code ): string {
	$code = strtolower( sanitize_key( $code ) );
	return '' !== $code && translate_ready() && in_array( $code, (array) \ClientNav\Translate\Api::languages(), true ) ? $code : '';
}

/** A path of this site in a language (prefix and translated slug); paths of other sites and empty ones as they are. */
function lang_path( string $path, string $lang ): string {
	if ( '' === $lang || '' === $path || '/' !== $path[0] || '/' === ( $path[1] ?? '' ) || preg_match( '#^/(wp-content|wp-includes|wp-json)/#', $path ) ) {
		return $path;
	}
	$q    = '';
	$hash = '';
	if ( false !== ( $at = strpos( $path, '#' ) ) ) {
		$hash = substr( $path, $at );
		$path = substr( $path, 0, $at );
	}
	if ( false !== ( $at = strpos( $path, '?' ) ) ) {
		$q    = substr( $path, $at );
		$path = substr( $path, 0, $at );
	}
	$url = (string) \ClientNav\Translate\Api::url( home_url( $path ), $lang );
	$out = (string) wp_parse_url( $url, PHP_URL_PATH );
	return ( '' !== $out ? $out : $path ) . $q . $hash;
}

/** Links inside a piece of HTML lead to the same language (the site's own addresses only). */
function lang_links( string $html, string $lang ): string {
	return (string) preg_replace_callback(
		'/(<a\b[^>]*\shref=")(\/[^"\/][^"]*|\/)"/i',
		static fn( array $m ): string => $m[1] . esc_attr( lang_path( html_entity_decode( $m[2], ENT_QUOTES ), $lang ) ) . '"',
		$html
	);
}

/**
 * Pieces of an answer to translate together: each added piece is written back in place when the batch runs.
 * Held by reference, so the answer itself changes.
 */
final class Lang_Batch {
	private array $entries = array();
	private array $refs    = array();
	public int $total      = 0;
	public int $missing    = 0;

	/** Exact phrases never sent to Translate (lower case): office names, people's names... */
	private array $keep;

	public function __construct( private string $lang ) {
		$this->keep = array_flip( array_filter( array_map( static fn( $w ) => mb_strtolower( trim( plain( (string) $w ) ) ), (array) apply_filters( 'clientnav_headless_keep_words', array(), $lang ) ) ) );
	}

	/** Whether a piece is one of the phrases that stay as they are, wherever they stand (a card, a button, a heading). */
	private function kept_as_is( string $piece ): bool {
		return $this->keep && isset( $this->keep[ mb_strtolower( trim( plain( $piece ) ) ) ] );
	}

	/** Words already asked in this batch: text => the piece that asked them first. */
	private array $seen = array();
	private array $same = array();

	/**
	 * The same words asked again (the SEO title the door made from the entry's own title, a term page's title
	 * from the term's name) take the translation of the first asking: Translate keeps translations by role, and the
	 * "meta" role would find none for words translated as a heading (a regional site, 2026-10-16). Nor are they
	 * counted twice as missing.
	 */
	/** Words already in the language (a hand translation put in place): not asked again. */
	public function done( string $words ): void {
		$this->seen[ $words ] = -1;
	}

	public function text( &$target, string $as ): void {
		if ( is_string( $target ) && '' !== trim( $target ) && ! $this->kept_as_is( $target ) ) {
			if ( -1 === ( $this->seen[ $target ] ?? null ) ) {
				return;
			}
			if ( isset( $this->seen[ $target ] ) ) {
				$this->same[] = array( $this->seen[ $target ], &$target );
				return;
			}
			$k                   = count( $this->entries );
			$this->entries[ $k ] = array( 'text' => $target, 'as' => $as );
			$this->refs[ $k ]      = &$target;
			$this->seen[ $target ] = $k;
		}
	}

	public function html( &$target ): void {
		if ( is_string( $target ) && '' !== trim( wp_strip_all_tags( $target ) ) && ! $this->kept_as_is( $target ) ) {
			$k                   = count( $this->entries );
			$this->entries[ $k ] = array( 'html' => $target );
			$this->refs[ $k ]    = &$target;
		}
	}

	public function run(): void {
		if ( ! $this->entries ) {
			return;
		}
		$res = (array) \ClientNav\Translate\Api::translate( $this->entries, $this->lang );
		foreach ( (array) ( $res['items'] ?? array() ) as $k => $value ) {
			if ( isset( $this->refs[ $k ] ) && is_string( $value ) ) {
				$this->refs[ $k ] = isset( $this->entries[ $k ]['html'] ) ? lang_links( $value, $this->lang ) : $value;
			}
		}
		foreach ( array_keys( $this->same ) as $i ) {
			$this->same[ $i ][1] = $this->refs[ $this->same[ $i ][0] ];
		}
		$this->total   += (int) ( $res['total'] ?? 0 );
		$this->missing += (int) ( $res['missing'] ?? 0 );
	}
}

/** One answer of the door in a language. $kind: resolve, posts, terms, site, part. */
function translate_answer( array $a, string $kind, string $lang ): array {
	$b = new Lang_Batch( $lang );
	switch ( $kind ) {
		case 'resolve':
			if ( isset( $a['entry'] ) && is_array( $a['entry'] ) ) {
				lang_entry( $a['entry'], $b, $lang );
			}
			if ( isset( $a['term'] ) && is_array( $a['term'] ) ) {
				lang_term( $a['term'], $b, $lang, 'heading' );
			}
			if ( isset( $a['author'] ) && is_array( $a['author'] ) ) {
				$a['author']['path'] = lang_path( (string) ( $a['author']['path'] ?? '' ), $lang );
				$b->text( $a['author']['description'], 'text' );
			}
			foreach ( array( 'path', 'to' ) as $key ) {
				if ( isset( $a[ $key ] ) && is_string( $a[ $key ] ) ) {
					$a[ $key ] = lang_path( $a[ $key ], $lang );
				}
			}
			if ( isset( $a['title'] ) && is_string( $a['title'] ) ) {
				$b->text( $a['title'], 'heading' );
			}
			if ( isset( $a['seo'] ) ) {
				lang_seo( $a['seo'], $b, $lang );
			}
			lang_items( $a, $b, $lang );
			break;
		case 'posts':
			lang_items( $a, $b, $lang );
			break;
		case 'terms':
			foreach ( $a['items'] as &$t ) {
				lang_term( $t, $b, $lang );
			}
			unset( $t );
			break;
		case 'site':
			$b->text( $a['description'], 'meta' );
			foreach ( (array) ( $a['menus'] ?? array() ) as $place => $items ) {
				$a['menus'][ $place ] = lang_menu( (array) $items, $b, $lang );
			}
			foreach ( (array) ( $a['categories'] ?? array() ) as $i => $t ) {
				lang_term( $a['categories'][ $i ], $b, $lang );
			}
			break;
		case 'part':
			lang_parts( $a['parts'], $b, $lang );
			break;
	}
	$b->run();
	$a['lang'] = array( 'code' => $lang, 'total' => $b->total, 'missing' => $b->missing );
	return $a;
}

function lang_items( array &$a, Lang_Batch $b, string $lang ): void {
	if ( isset( $a['items'] ) && is_array( $a['items'] ) ) {
		foreach ( $a['items'] as &$card ) {
			if ( is_array( $card ) ) {
				lang_card( $card, $b, $lang );
			}
		}
		unset( $card );
	}
}

/**
 * Pieces a site add-on puts on cards and entries, named with the filter clientnav_headless_lang_fields as
 * array( 'site.position' => 'text', 'site.intro' => 'html', 'site.services[]' => 'text' ): a dotted path into the
 * answer, "[]" for every element of a list, and the role the words have (heading, text, link, button, menu, meta,
 * attr, or html for a piece of HTML).
 */
function lang_extra( array &$a, Lang_Batch $b ): void {
	foreach ( (array) apply_filters( 'clientnav_headless_lang_fields', array() ) as $path => $role ) {
		$parts = explode( '.', (string) $path );
		$last  = array_pop( $parts );
		$at    = &$a;
		foreach ( $parts as $p ) {
			if ( ! isset( $at[ $p ] ) || ! is_array( $at[ $p ] ) ) {
				continue 2;
			}
			$at = &$at[ $p ];
		}
		$each = str_ends_with( $last, '[]' );
		$last = $each ? substr( $last, 0, -2 ) : $last;
		if ( ! isset( $at[ $last ] ) ) {
			continue;
		}
		if ( $each ) {
			if ( is_array( $at[ $last ] ) ) {
				foreach ( $at[ $last ] as &$one ) {
					'html' === $role ? $b->html( $one ) : $b->text( $one, (string) $role );
				}
				unset( $one );
			}
		} else {
			'html' === $role ? $b->html( $at[ $last ] ) : $b->text( $at[ $last ], (string) $role );
		}
		unset( $at );
	}
}

/** A picture's words. */
function lang_picture( array &$c, Lang_Batch $b ): void {
	if ( isset( $c['image'] ) && is_array( $c['image'] ) && isset( $c['image']['alt'] ) ) {
		$b->text( $c['image']['alt'], 'attr' );
	}
}

function lang_card( array &$c, Lang_Batch $b, string $lang ): void {
	$b->text( $c['title'], 'heading' );
	$b->text( $c['excerpt'], 'text' );
	lang_picture( $c, $b );
	lang_extra( $c, $b );
	if ( isset( $c['path'] ) ) {
		$c['path'] = lang_path( (string) $c['path'], $lang );
	}
	foreach ( array( 'category', 'section' ) as $key ) {
		if ( isset( $c[ $key ] ) && is_array( $c[ $key ] ) ) {
			lang_term( $c[ $key ], $b, $lang );
		}
	}
	if ( isset( $c['terms'] ) && is_array( $c['terms'] ) ) {
		foreach ( $c['terms'] as &$t ) {
			lang_term( $t, $b, $lang );
		}
		unset( $t );
	}
	if ( isset( $c['by']['path'] ) ) {
		$c['by']['path'] = lang_path( (string) $c['by']['path'], $lang );
	}
}

function lang_term( array &$t, Lang_Batch $b, string $lang, string $as = 'link' ): void {
	$b->text( $t['name'], $as );
	$b->text( $t['description'], 'text' );
	if ( isset( $t['path'] ) ) {
		$t['path'] = lang_path( (string) $t['path'], $lang );
	}
}

/**
 * A full address on the front end (or a path) in the language; any other address is left alone, and so is one that
 * already carries the language.
 */
function lang_front_url( string $url, string $lang ): string {
	$front = rtrim( frontend_url(), '/' );
	if ( '' === $lang || '' === $url ) {
		return $url;
	}
	if ( '/' === $url[0] ) {
		$base = '';
		$path = $url;
	} elseif ( '' !== $front && ( $url === $front || 0 === strpos( $url, $front . '/' ) ) ) {
		$base = $front;
		$path = '' === substr( $url, strlen( $front ) ) ? '/' : substr( $url, strlen( $front ) );
	} else {
		return $url;
	}
	if ( 0 === strpos( $path, '/' . $lang . '/' ) || '/' . $lang === $path ) {
		return $url;
	}
	return $base . lang_path( $path, $lang );
}

/** The SEO words; addresses on the front end (canonical, og.url) get the language too. */
function lang_seo( &$seo, Lang_Batch $b, string $lang = '' ): void {
	if ( ! is_array( $seo ) ) {
		return;
	}
	if ( isset( $seo['canonical'] ) && is_string( $seo['canonical'] ) ) {
		$seo['canonical'] = lang_front_url( $seo['canonical'], $lang );
	}
	if ( isset( $seo['og']['url'] ) && is_string( $seo['og']['url'] ) ) {
		$seo['og']['url'] = lang_front_url( $seo['og']['url'], $lang );
	}
	$b->text( $seo['title'], 'meta' );
	$b->text( $seo['description'], 'meta' );
	foreach ( array( 'og', 'twitter' ) as $card ) {
		if ( isset( $seo[ $card ] ) && is_array( $seo[ $card ] ) ) {
			$b->text( $seo[ $card ]['title'], 'meta' );
			$b->text( $seo[ $card ]['description'], 'meta' );
		}
	}
}

function lang_menu( array $items, Lang_Batch $b, string $lang ): array {
	foreach ( $items as $i => $item ) {
		$b->text( $items[ $i ]['title'], 'menu' );
		if ( isset( $item['href'] ) ) {
			$items[ $i ]['href'] = lang_path( (string) $item['href'], $lang );
		}
		if ( ! empty( $item['children'] ) ) {
			$items[ $i ]['children'] = lang_menu( (array) $item['children'], $b, $lang );
		}
	}
	return $items;
}

/** An entry: Translate's own fields for it (hand translations included), its addresses, its other parts. */
function lang_entry( array &$e, Lang_Batch $b, string $lang ): void {
	$id     = (int) ( $e['id'] ?? 0 );
	$fields = $id ? \ClientNav\Translate\Api::post( $id, $lang, false ) : null;
	$manual = is_array( $fields ) ? (array) ( $fields['manual'] ?? array() ) : array();
	// A title or excerpt written by hand in the language is used as written; otherwise the plain words go through
	// the batch, as every other piece does (Translate keeps a sentence by its plain form, not by its entities).
	$hand = array();
	foreach ( array( 'title' => 'heading', 'excerpt' => 'text' ) as $key => $as ) {
		if ( in_array( $key, $manual, true ) && '' !== (string) ( $fields[ $key ] ?? '' ) ) {
			if ( is_string( $e[ $key ] ?? null ) ) {
				$hand[ $e[ $key ] ] = plain( (string) $fields[ $key ] );
			}
			$e[ $key ] = plain( (string) $fields[ $key ] );
		} else {
			$b->text( $e[ $key ], $as );
		}
	}
	if ( is_array( $fields ) && isset( $fields['content'] ) && '' !== (string) $fields['content'] && isset( $e['html'] ) ) {
		// The text, hand-translated or sentence by sentence by Translate, with its links in the language.
		$e['html'] = lang_links( localize_html( (string) $fields['content'] ), $lang );
		$b->missing += (int) ( $fields['missing'] ?? 0 );
	} elseif ( isset( $e['html'] ) ) {
		$b->html( $e['html'] );
	}
	lang_picture( $e, $b );
	lang_extra( $e, $b );
	if ( isset( $e['path'] ) ) {
		$e['path'] = lang_path( (string) $e['path'], $lang );
	}
	if ( $id ) {
		$versions = array();
		foreach ( (array) \ClientNav\Translate\Api::versions( $id ) as $code => $v ) {
			$versions[ $code ] = to_path( (string) ( $v['url'] ?? $v['path'] ?? '' ) );
		}
		$e['versions'] = (object) $versions;
	}
	foreach ( array( 'category', 'section' ) as $key ) {
		if ( isset( $e[ $key ] ) && is_array( $e[ $key ] ) ) {
			lang_term( $e[ $key ], $b, $lang );
		}
	}
	if ( isset( $e['terms'] ) && is_array( $e['terms'] ) ) {
		foreach ( $e['terms'] as &$t ) {
			lang_term( $t, $b, $lang );
		}
		unset( $t );
	}
	if ( isset( $e['crumbs'] ) && is_array( $e['crumbs'] ) ) {
		foreach ( $e['crumbs'] as $i => $crumb ) {
			$b->text( $e['crumbs'][ $i ]['title'], 'link' );
			if ( isset( $crumb['href'] ) ) {
				$e['crumbs'][ $i ]['href'] = lang_path( (string) $crumb['href'], $lang );
			}
		}
	}
	if ( isset( $e['seo'] ) ) {
		$seo = (array) $e['seo'];
		// SEO words the door took from a title or excerpt translated by hand: that same translation.
		$by_hand = static function ( array &$at ) use ( $hand, $b ): void {
			foreach ( array( 'title', 'description' ) as $k ) {
				if ( is_string( $at[ $k ] ?? null ) && isset( $hand[ $at[ $k ] ] ) ) {
					$at[ $k ] = $hand[ $at[ $k ] ];
					$b->done( $at[ $k ] );
				}
			}
		};
		if ( $hand ) {
			$by_hand( $seo );
			foreach ( array( 'og', 'twitter' ) as $card ) {
				if ( isset( $seo[ $card ] ) && is_array( $seo[ $card ] ) ) {
					$by_hand( $seo[ $card ] );
				}
			}
		}
		lang_seo( $seo, $b, $lang );
		$e['seo'] = $seo;
	}
	if ( isset( $e['elementor']['parts'] ) && is_array( $e['elementor']['parts'] ) ) {
		lang_parts( $e['elementor']['parts'], $b, $lang );
	}
}

/** The words of Elementor parts, each with the role it has on the page; their links in the language. */
function lang_parts( array &$parts, Lang_Batch $b, string $lang ): void {
	foreach ( $parts as &$p ) {
		if ( ! is_array( $p ) ) {
			continue;
		}
		switch ( (string) ( $p['t'] ?? '' ) ) {
			case 'h':
			case 'btn':
			case 'p':
			case 'rich':
			case 'quote':
				// Pieces of a designed page carry their inline marks (<br>, <strong>): they go as HTML, as they stand.
				$b->html( $p['html'] );
				break;
			case 'ibox':
				$b->html( $p['title'] );
				$b->html( $p['html'] );
				break;
			case 'img':
				$b->text( $p['alt'], 'attr' );
				break;
			case 'counter':
				$b->html( $p['title'] );
				break;
			case 'list':
				foreach ( (array) ( $p['items'] ?? array() ) as $i => $item ) {
					$b->html( $p['items'][ $i ]['html'] );
					if ( isset( $item['link']['href'] ) ) {
						$p['items'][ $i ]['link']['href'] = lang_path( (string) $item['link']['href'], $lang );
					}
				}
				break;
			case 'cards':
				lang_items( $p, $b, $lang );
				break;
		}
		// A form drawn on the page (a form part, or one per office in a site's own part): its words in the form role.
		if ( isset( $p['form'] ) && is_array( $p['form'] ) ) {
			lang_form( $p['form'], $b );
		}
		foreach ( array( 'offices', 'places' ) as $many ) {
			if ( ! empty( $p[ $many ] ) && is_array( $p[ $many ] ) ) {
				foreach ( $p[ $many ] as &$one ) {
					if ( isset( $one['form'] ) && is_array( $one['form'] ) ) {
						lang_form( $one['form'], $b );
					}
				}
				unset( $one );
			}
		}
		if ( isset( $p['link']['href'] ) ) {
			$p['link']['href'] = lang_path( (string) $p['link']['href'], $lang );
		}
		if ( ! empty( $p['kids'] ) && is_array( $p['kids'] ) ) {
			lang_parts( $p['kids'], $b, $lang );
		}
	}
	unset( $p );
}

/** A form as the door describes it: labels, placeholders, choices, the button and the thank-you words. */
function lang_form( array &$f, Lang_Batch $b ): void {
	$b->text( $f['title'], 'form' );
	$b->text( $f['button'], 'form' );
	$b->text( $f['thanks'], 'form' );
	if ( isset( $f['fields'] ) && is_array( $f['fields'] ) ) {
		lang_fields( $f['fields'], $b );
	}
}

function lang_fields( array &$fields, Lang_Batch $b ): void {
	foreach ( $fields as &$x ) {
		if ( ! is_array( $x ) ) {
			continue;
		}
		$b->text( $x['label'], 'form' );
		$b->text( $x['placeholder'], 'attr' );
		if ( isset( $x['html'] ) ) {
			$b->html( $x['html'] );
		}
		if ( isset( $x['options'] ) && is_array( $x['options'] ) ) {
			foreach ( $x['options'] as &$o ) {
				if ( is_array( $o ) ) {
					$b->text( $o['label'], 'form' );
				}
			}
			unset( $o );
		}
		if ( isset( $x['fields'] ) && is_array( $x['fields'] ) ) {
			lang_fields( $x['fields'], $b );
		}
	}
	unset( $x );
}

/**
 * Wraps a read route so it answers in the asked language. For /resolve the language may also come from the path
 * (/es/...): the path WordPress knows is put in its place before the route runs.
 */
function lang_route( string $kind, callable $route ): callable {
	return static function ( \WP_REST_Request $r ) use ( $kind, $route ) {
		$lang = offered_lang( (string) ( $r['lang'] ?? '' ) );
		if ( 'resolve' === $kind && translate_ready() && is_string( $r['path'] ) && '' !== $r['path'] ) {
			$found = (array) \ClientNav\Translate\Api::resolve( (string) $r['path'] );
			$from  = offered_lang( (string) ( $found['lang'] ?? '' ) );
			if ( '' !== $from ) {
				$lang = $from;
				$r->set_param( 'path', (string) ( $found['path'] ?? $r['path'] ) );
			}
		}
		door_lang( $lang );
		try {
			$out = $route( $r );
		} finally {
			door_lang( '' );
		}
		if ( '' === $lang ) {
			return $out;
		}
		$data = $out instanceof \WP_REST_Response ? $out->get_data() : $out;
		if ( ! is_array( $data ) || ( $out instanceof \WP_REST_Response && $out->get_status() >= 400 ) ) {
			return $out;
		}
		$made = translate_answer( json_decode( (string) wp_json_encode( $data ), true ), $kind, $lang );
		if ( $out instanceof \WP_REST_Response ) {
			$out->set_data( $made );
			return $out;
		}
		return $made;
	};
}

// A new translation changes answers in that language: Translate says so through cnp_purge_front, which already
// drops the kept answers (kept.php).
