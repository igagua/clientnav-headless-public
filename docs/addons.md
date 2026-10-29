# Site add-ons

Client Navigator Headless is one plugin for every headless site. What only one site has goes into a small plugin of its own beside it, a **site add-on**: the extra fields of its kinds of content, the old addresses it must keep, the widgets only it uses, its own small routes. The core plugin never learns a site's names; the add-on never copies the core's work.

This guide shows how an add-on is built, with a dental practice site's add-on (`clientnav-site-addon`, about 900 lines) as the worked example. Other add-ons in use: a large news site, a business news site, a regional site, a regional site.

## When a site needs one

Most sites need none. Without an add-on the door already serves every public type and taxonomy, pages built with Elementor (classic and V4) and the block editor, menus, forms (Fluent Forms), search, feeds, sitemaps' addresses, SEO words (PR Register's when it is there) and languages through Client Navigator Translate.

Write an add-on when the site has:

- fields of its own on its content (a team member's position, an event's start, an office's address and map);
- addresses from an older site that must still lead somewhere;
- widgets or shortcodes the core cannot draw (a block made by a theme, a site's own shortcode);
- a list or a page shape the core does not answer (a newspaper's home page make-up, side columns);
- words that must never be translated (place names, people's names);
- settings that must hold whatever is stored (blocks off, pictures as paths).

## Shape

```
clientnav-<site>-addon/
  clientnav-<site>-addon.php   header, constants, filters
  <topic>.php                   one file per topic (forms, blocks, listing, site)
  tests/wordpress*.php          checks, run with the core's suite
```

The header names the site and what the add-on replaces. Every call into the core goes through a check that the core is there, so the add-on does nothing (and breaks nothing) when the core is switched off:

```php
function door_ready(): bool {
	return defined( 'ClientNav\Headless\VERSION' );
}
```

The core's helpers are public functions in the `ClientNav\Headless` namespace and may be used: `plain()` (words without markup), `to_path()` (a link to the site as a path), `kept_route()` (an answer kept like the door's own), `door_allows` (the door's lock, for an add-on's own routes), `offered_lang()`, `door_lang()`, `lang_path()`, `fluent_form()`, `el_parts()`.

## Extra fields on cards and entries

`clientnav_headless_card` runs for every card in a list and for every entry (an entry is a card with more). Put the site's fields under one key named after the site, so they never clash with the core's:

```php
function with_extra( array $out, \WP_Post $post ): array {
	if ( isset( KINDS[ $post->post_type ] ) ) {
		$out['site'] = array( 'kind' => KINDS[ $post->post_type ] ) + extra( $post );
	}
	return $out;
}
add_filter( 'clientnav_headless_card', __NAMESPACE__ . '\\with_extra', 10, 2 );
```

`clientnav_headless_entry` does the same for whole entries only (for what is too heavy for a list). `clientnav_headless_fields` changes which meta fields an entry shows (by default those the type shows in WordPress's REST API). `clientnav_headless_image` replaces an entry's picture (a site that keeps its picture in a field of its own).

### In other languages

Words in the add-on's fields are translated with the rest of the answer once they are named, each with the role it has on the page (`heading`, `text`, `link`, `button`, `menu`, `meta`, `attr`, or `html` for a piece of HTML). The role matters: Translate keeps a translation by role and finds the one made on the WordPress page only under the same role.

```php
add_filter(
	'clientnav_headless_lang_fields',
	static fn( array $fields ): array => $fields + array(
		'site.position'   => 'text',
		'site.lead'       => 'heading',
		'site.intro'      => 'html',
		'site.services[]' => 'text', // every element of a list
	)
);
```

Phrases that must stay as they are, wherever they stand:

```php
add_filter( 'clientnav_headless_keep_words', static fn( array $words ): array => array_merge( $words, array( 'South Gate', 'Hoover' ) ) );
```

## Lists

- `clientnav_headless_orderby`: names a field a list may be ordered by (`?orderby=start`). Only names the add-on lists work, so no request can order by, and so probe, any other field.

  ```php
  add_filter( 'clientnav_headless_orderby', static fn( array $f ): array => $f + array( 'start' => array( 'key' => '_EventStartDate' ) ) );
  ```

- `clientnav_headless_query`: adjusts the question of every list (`/posts`, `/feed`) before WordPress asks the database. the practice site switches off the calendar plugin's own filtering so past events can be listed.
- `clientnav_headless_post_types`: which types the door serves. the practice site keeps only its own kinds and pages, so a media player's type does not appear as pages of the site.
- `clientnav_headless_section`: which term is an entry's "section" on cards (the most specific category by default).
- `clientnav_headless_big`: from how many entries a type counts as big (20,000): big lists are not counted and big sections take the fast way.

## Old addresses

A site moved from another system keeps its old addresses through `clientnav_headless_old_path`. `/resolve` asks it when an address is not found, and answers a redirect to the new address:

```php
add_filter(
	'clientnav_headless_old_path',
	static function ( string $to, string $old ): string {
		if ( '' !== $to ) {
			return $to;
		}
		$id = lookup_old_address( $old ); // the site's own table or meta
		return $id ? (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH ) : '';
	},
	10,
	2
);
```

a large news site keeps 270,000 old addresses this way, in a table of its own with an index (a meta query over that many posts would be slow).

## Widgets the core cannot draw

The core asks the add-on first about every Elementor element: `clientnav_headless_elementor_part` gets `null`, the element's type, the element and its children. Return a part (an array) to answer it, or what was given to leave it to the core:

```php
add_filter(
	'clientnav_headless_elementor_part',
	static function ( $own, $type, $element ) {
		if ( null !== $own || 'shortcode' !== $type ) {
			return $own;
		}
		return block( (string) ( $element['settings']['shortcode'] ?? '' ) ); // null: not this site's
	},
	10,
	3
);
```

the practice site answers its Google reviews, service cards, its programme pages and "choose your office" forms this way. Related: `clientnav_headless_elementor_scope` (the class the front end puts around designed pages; the practice site's is `.site`), `clientnav_headless_assets` and `clientnav_headless_scripts` (stylesheets and scripts the front end loads for those pages).

## Forms and search

- `clientnav_headless_form`: changes a form's description before the front end draws it (the practice site gives forms without thank-you words the site's own).
- `clientnav_headless_form_submitted` (action): after a form handled by the door is saved: the key, the values, the entry id, the request.
- `clientnav_headless_search_labels`, `clientnav_headless_search_include`, `clientnav_headless_index_item`: what the search results and the search index call each kind, what they leave out, and what each item carries.

## SEO

`clientnav_headless_seo` gets the head of every page (PR Register's when it is there, otherwise the door's own title and description) and the object it is for. the practice site replaces the CMS copy's name ("Example Practice (CMS copy)") with the site's, so the copy's name never reaches a visitor. `clientnav_headless_noindex` says whether an entry is kept out of search engines.

## Settings that must hold

Client Navigator does not write an add-on's or the core's options for a site, so what a site needs whatever is stored is set by filter:

```php
add_filter( 'clientnav_headless_blocks', '__return_false' );       // no Client Navigator blocks in the editor
add_filter( 'clientnav_headless_search_index', '__return_false' ); // the site has its own search
add_filter( 'clientnav_headless_media_paths', '__return_true' );   // the front end passes /wp-content/ on
add_filter( 'clientnav_headless_own_hosts', static fn( array $h ): array => array_merge( $h, array( 'www.example.com', 'example.com' ) ) );
```

Also by filter: `clientnav_headless_door_closed` and `clientnav_headless_rest_closed` (the door and WordPress's REST API for anonymous callers), `clientnav_headless_open_rest` (REST routes left open when the API is closed), `clientnav_headless_kept_paths` (addresses WordPress keeps for itself: shop, account), `clientnav_headless_send_visitor`, `clientnav_headless_keep` and `clientnav_headless_keep_last` (kept answers), `clientnav_headless_editor_css`.

## Routes of its own

When the site needs an answer the core does not give, register a route under the add-on's own namespace, behind the door's lock, kept like the door's answers (cleared when content changes):

```php
add_action(
	'rest_api_init',
	static function (): void {
		if ( ! door_ready() ) {
			return;
		}
		$kept = \ClientNav\Headless\kept_route( 'site-site', static fn( \WP_REST_Request $r ) => site_payload() );
		register_rest_route( 'clientnav-site/v1', '/site', array( 'methods' => 'GET', 'permission_callback' => '\ClientNav\Headless\door_allows', 'callback' => $kept ) );
	}
);
```

`door_allows` follows the door's own setting: open, or closed to all but the front end (shared secret) and signed-in editors. In a language, set `door_lang()` for the time the answer is made and put the words through the core's `Lang_Batch`, as the practice site's `site_in()` does.

## Tests

Checks go in `tests/wordpress*.php` of the add-on, written like the core's (`wp eval-file` in a throwaway WordPress, a `$is( name, ok, got )` per check, a last line `N passed, M failed`). They run with the core's suite on the test machine, beside the plugin:

```bash
ADDON=../clientnav-site-addon bin/test-remote.sh user@test-machine
```

Before a site moves onto the core, compare the old door and the new one answer by answer on the real content (the practice site: `tools/compare-doors.mjs`, 1,661 comparisons, every difference explained), and the live pages before and after the switch.

## Release

An add-on is released through the platform's plugin catalogue for its one site, like the core, and installed with `clientnav/plugin-install`. Raise its version on every change. The core is released first when the add-on needs something new in it, and the add-on's description should name the core version it needs.
