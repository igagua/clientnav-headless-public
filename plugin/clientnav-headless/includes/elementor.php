<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * Pages designed in Elementor, for a front end that draws them itself.
 *
 * Staff keep editing pages in Elementor. Nothing of Elementor's own markup, styles or scripts goes to the public
 * site: each element is resolved here (its text, picture, link, with dynamic values filled in) and handed over as
 * a tree of plain parts; the front end draws them with its own markup. The style rules of V4 (atomic) elements are
 * rendered to CSS by Elementor's own renderer, scoped under the front end's wrapper class (elementor_scope()).
 * Ported from a dental practice site's door, where it carries 26 designed pages live.
 *
 *   /resolve?path=...&with=elementor   an entry built in Elementor gets "elementor": { parts, css, faq }
 *   /part?where=header|footer          the theme builder's header or footer, as parts and CSS (header: also "nav",
 *                                      the mega menu's items with their panels)
 *
 * Parts: { t: box|h|p|btn|img|svg|yt|hr|rich|list|ibox|quote|stars|social|map|search|form|cards|counter|gallery|x, ... }
 * with cls (classes), id, link {href, blank}, hide [desktop|tablet|mobile] where they apply. "x" is an element
 * this plugin does not know; a site add-on resolves its own widgets with the filter clientnav_headless_elementor_part.
 */

/** The wrapper class the front end puts around a page's parts; the CSS is scoped under it. */
function elementor_scope(): string {
	return (string) apply_filters( 'clientnav_headless_elementor_scope', '.cnh-el' );
}

function elementor_ready(): bool {
	return class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->elements_manager );
}

/** Whether an entry is built in Elementor. */
function built_with_elementor( int $post_id ): bool {
	return elementor_ready() && 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
}

/** An entry's Elementor layout: parts, CSS, and the questions of accordions marked for search engines. */
function elementor_page( \WP_Post $post ): ?array {
	$raw = built_with_elementor( $post->ID ) ? json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true ) : null;
	if ( ! is_array( $raw ) || ! $raw ) {
		return null;
	}
	// Dynamic values (and loops inside the page) are resolved with this entry as the current post.
	$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $post );
	$styles = array();
	$parts  = el_parts( $raw, $styles );
	wp_reset_postdata();
	$faq = array();
	el_faq( $parts, $faq );
	return array( 'parts' => $parts, 'css' => el_css( $styles ), 'faq' => $faq, 'scope' => elementor_scope(), 'assets' => el_assets_take(), 'scripts' => el_scripts_site(), 'kit' => el_kit_class() );
}

// --- small pieces -------------------------------------------------------------------------------

/** HTML for the front end: own links as paths, files as the site gives them (media_paths()). */
function el_html( string $html ): string {
	$html = localize_html( $html );
	return media_paths() ? (string) preg_replace_callback( '/\b(src|srcset)=(["\'])(.*?)\2/i', static fn( $m ) => $m[1] . '=' . $m[2] . preg_replace_callback( '#https?://[^\s,"\']+#', static fn( $u ) => media_url( $u[0] ), $m[3] ) . $m[2], $html ) : $html;
}

/** A link as the front end takes it: path or outside address, and whether it opens a new tab. Null when there is none. */
function el_link( $link ): ?array {
	$href = is_array( $link ) ? (string) ( $link['href'] ?? $link['url'] ?? '' ) : '';
	if ( is_array( $link['destination'] ?? null ) ) {
		$href = (string) ( $link['destination']['url'] ?? $link['destination']['value'] ?? $href );
	}
	if ( '' === $href || '#' === $href ) {
		return null;
	}
	return array( 'href' => to_path( $href ), 'blank' => '_blank' === ( $link['target'] ?? '' ) || ! empty( $link['is_external'] ) || ! empty( $link['isTargetBlank'] ) );
}

/** Inline text of a heading, paragraph or button: only the tags that mark up words. */
/**
 * The attributes the page builder lets an editor put on an element, as far as they name or describe it: aria-*,
 * role, title. Anything that could run or load something (on*, href, src, style) is left out.
 */
function el_attrs( $attributes ): array {
	$out = array();
	// Resolved, the page builder hands them over as a ready attribute string: key="value" key="value".
	if ( is_string( $attributes ) ) {
		preg_match_all( '/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*"([^"]*)"/', $attributes, $m, PREG_SET_ORDER );
		$attributes = array();
		foreach ( $m as $pair ) {
			$attributes[ $pair[1] ] = html_entity_decode( $pair[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
	}
	foreach ( (array) $attributes as $k => $row ) {
		$key   = is_array( $row ) ? (string) ( $row['key'] ?? ( $row['value']['key']['value'] ?? '' ) ) : (string) $k;
		$value = is_array( $row ) ? ( $row['value'] ?? '' ) : $row;
		if ( is_array( $value ) ) {
			$value = $value['value']['value'] ?? ( $value['value'] ?? '' );
		}
		$key = strtolower( trim( $key ) );
		if ( '' !== $key && is_scalar( $value ) && preg_match( '/^(aria-[a-z-]+|role|title)$/', $key ) ) {
			$out[ $key ] = mb_substr( wp_strip_all_tags( (string) $value ), 0, 200 );
		}
	}
	return $out;
}

/** An SVG file from this site's own uploads, drawn inline (shapes only, nothing that runs), or empty. */
function el_svg_file( string $url ): string {
	$up   = wp_upload_dir();
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$base = (string) wp_parse_url( (string) ( $up['baseurl'] ?? '' ), PHP_URL_PATH );
	if ( '' === $base || 0 !== strpos( $path, $base . '/' ) || ! str_ends_with( strtolower( $path ), '.svg' ) || str_contains( $path, '..' ) ) {
		return '';
	}
	$file = (string) ( $up['basedir'] ?? '' ) . substr( $path, strlen( $base ) );
	if ( ! is_readable( $file ) || filesize( $file ) > 200000 ) {
		return '';
	}
	$svg = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$svg = (string) preg_replace( '/<\?xml.*?\?>|<!DOCTYPE.*?>|<!--.*?-->/is', '', $svg );
	return trim( wp_kses( $svg, el_allowed_html() ) );
}

/** A picture's other sizes, each as the front end's address when pictures are paths (as its src). */
function el_srcset( string $srcset ): string {
	return media_paths() ? (string) preg_replace_callback( '#https?://[^\s,]+#', static fn( $m ) => media_url( $m[0] ), $srcset ) : $srcset;
}

function el_inline( $html ): string {
	return el_html( wp_kses( (string) $html, array( 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'u' => array(), 's' => array(), 'br' => array(), 'sup' => array(), 'sub' => array(), 'span' => array(), 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ) );
}

function el_yt( string $url ): string {
	return preg_match( '~(?:v=|youtu\.be/|embed/|shorts/)([A-Za-z0-9_-]{6,20})~', $url, $m ) ? $m[1] : '';
}

/** An attachment as a picture part. */
function el_attachment( int $id, string $size = 'full' ): ?array {
	$pic = $id ? image( $id, $size ) : null;
	return $pic ? array( 'src' => $pic['src'], 'width' => $pic['width'], 'height' => $pic['height'], 'alt' => $pic['alt'] ) : null;
}

/** An icon chosen in a classic widget, as inline SVG; empty when it cannot be drawn that way. */
function el_icon( $icon ): string {
	if ( ! is_array( $icon ) || empty( $icon['value'] ) || ! class_exists( '\Elementor\Icons_Manager' ) ) {
		return '';
	}
	$html = (string) \Elementor\Icons_Manager::try_get_icon_html( $icon, array( 'aria-hidden' => 'true' ) );
	return str_starts_with( ltrim( $html ), '<svg' ) ? $html : '';
}

/** A colour setting of a classic widget, when it is a plain colour value. */
function el_colour( $value ): string {
	return is_string( $value ) && preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([0-9., %]+\))$/i', trim( $value ) ) ? trim( $value ) : '';
}

// --- elements ----------------------------------------------------------------------------------

function el_parts( array $elements, array &$styles ): array {
	$out = array();
	foreach ( $elements as $element ) {
		$part = is_array( $element ) ? el_part( $element, $styles ) : null;
		if ( $part ) {
			$out[] = $part;
		}
	}
	return $out;
}

/** One Elementor element as a plain part; null when it shows nothing. */
function el_part( array $element, array &$styles ): ?array {
	$type = 'widget' === ( $element['elType'] ?? '' ) ? (string) ( $element['widgetType'] ?? '' ) : (string) ( $element['elType'] ?? '' );
	$kids = (array) ( $element['elements'] ?? array() );
	// A site add-on resolves its own widgets first (null: not mine).
	$own = apply_filters( 'clientnav_headless_elementor_part', null, $type, $element, $kids );
	if ( is_array( $own ) ) {
		return 'none' === ( $own['t'] ?? '' ) ? null : $own;
	}
	try {
		$inst = \Elementor\Plugin::$instance->elements_manager->create_element_instance( $element );
	} catch ( \Throwable $e ) {
		$inst = null;
	}
	if ( ! $inst ) {
		// Elementor Pro registers its loop only under a licence with the loop feature, which a CMS copy on a new
		// address does not get (a site, 2026-10-17): the door then runs the loop from its saved settings.
		return 'e-collection-loop' === $type ? el_loop_bare( $element, $styles ) : null;
	}
	if ( ! method_exists( $inst, 'get_atomic_settings' ) ) {
		// Classic widgets: their settings with dynamic values (an address, a phone number) filled in.
		try {
			$resolved = (array) $inst->get_settings_for_display();
		} catch ( \Throwable $e ) {
			$resolved = (array) ( $element['settings'] ?? array() );
		}
		$made = el_classic( $type, $resolved, $kids, $styles );
		if ( $made && 'x' === ( $made['t'] ?? '' ) && empty( $made['note'] ) ) {
			$made = el_wrapped_form( $inst ) ?? $made + array( 'html' => el_rendered( $inst ) );
		}
		// A part shown only on some screens (a second button for phones) keeps that.
		$hide = array_values( array_filter( array( 'desktop', 'tablet', 'mobile' ), static fn( string $on ): bool => ! empty( $resolved[ 'hide_' . $on ] ) ) );
		if ( 3 === count( $hide ) ) {
			return null;
		}
		return $made && $hide ? $made + array( 'hide' => $hide ) : $made;
	}
	foreach ( (array) ( $element['styles'] ?? array() ) as $id => $style ) {
		$styles[ $id ] = $style;
	}
	try {
		$set = (array) json_decode( (string) wp_json_encode( $inst->get_atomic_settings() ), true );
	} catch ( \Throwable $e ) {
		return null;
	}
	$base = array( 'cls' => array_values( array_filter( array_map( 'strval', (array) ( $set['classes'] ?? array() ) ) ) ) );
	if ( ! empty( $set['_cssid'] ) ) {
		$base['id'] = sanitize_html_class( (string) $set['_cssid'] );
	}
	$to = el_link( $set['link'] ?? null );
	if ( $to ) {
		$base['link'] = $to;
	}
	$attrs = el_attrs( $set['attributes'] ?? null );
	if ( $attrs ) {
		$base['attrs'] = $attrs;
	}
	$tag = static fn( array $allowed, string $default ) => in_array( $set['tag'] ?? '', $allowed, true ) ? $set['tag'] : $default;
	switch ( $type ) {
		case 'e-flexbox':
		case 'e-grid':
		case 'e-div-block':
			return array( 't' => 'box', 'base' => $type, 'el' => $tag( array( 'div', 'section', 'header', 'footer', 'article', 'aside', 'nav', 'main' ), 'div' ), 'kids' => el_parts( $kids, $styles ) ) + $base;
		case 'e-heading':
			return array( 't' => 'h', 'el' => $tag( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div' ), 'h2' ), 'html' => el_inline( $set['title'] ?? '' ) ) + $base;
		case 'e-paragraph':
			return array( 't' => 'p', 'el' => $tag( array( 'p', 'span', 'div' ), 'p' ), 'html' => el_inline( $set['paragraph'] ?? '' ) ) + $base;
		case 'e-button':
			return array( 't' => 'btn', 'html' => el_inline( $set['text'] ?? '' ) ) + $base;
		case 'e-image':
			$image = (array) ( $set['image'] ?? array() );
			if ( empty( $image['src'] ) ) {
				return null;
			}
			return array( 't' => 'img', 'src' => media_url( (string) $image['src'] ), 'srcset' => el_srcset( (string) ( $image['srcset'] ?? '' ) ), 'width' => (int) ( $image['width'] ?? 0 ), 'height' => (int) ( $image['height'] ?? 0 ), 'alt' => (string) ( $image['alt'] ?? '' ) ) + $base;
		case 'e-svg':
			$svg  = (array) ( $set['svg'] ?? array() );
			$html = (string) ( $svg['html'] ?? '' );
			// Elementor draws an icon file inline, so it takes the colour of its button: the same here.
			if ( '' === $html && ! empty( $svg['url'] ) ) {
				$html = el_svg_file( (string) $svg['url'] );
			}
			return array( 't' => 'svg', 'html' => $html, 'src' => media_url( (string) ( $svg['url'] ?? '' ) ) ) + $base;
		case 'e-youtube':
			return array( 't' => 'yt', 'video' => el_yt( (string) ( $set['source'] ?? '' ) ) ) + $base;
		case 'e-divider':
			return array( 't' => 'hr' ) + $base;
		case 'e-accordion':
			$items = el_parts( $kids, $styles );
			$state = (string) ( $set['default_state'] ?? 'first_expanded' );
			foreach ( $items as $i => $item ) {
				if ( 'details' === ( $item['el'] ?? '' ) ) {
					$items[ $i ]['open'] = 'all_expanded' === $state || ( 'first_expanded' === $state && 0 === $i );
				}
			}
			return array( 't' => 'box', 'base' => $type, 'el' => 'div', 'kids' => $items, 'one' => 'multiple' !== ( $set['max_expanded'] ?? 'one' ), 'faq' => ! empty( $set['faq_schema'] ) ) + $base;
		case 'e-accordion-item':
			// An answer can be linked to: without a name of its own the item is named after its question.
			$item = array( 't' => 'box', 'base' => $type, 'el' => 'details', 'kids' => el_parts( $kids, $styles ) ) + $base;
			$name = empty( $item['id'] ) ? sanitize_title( el_words( array_slice( $item['kids'], 0, 1 ) ) ) : '';
			return $item + ( '' !== $name ? array( 'id' => $name ) : array() );
		case 'e-accordion-item-header':
			return array( 't' => 'box', 'base' => $type, 'el' => 'summary', 'kids' => el_parts( $kids, $styles ) ) + $base;
		case 'e-accordion-item-title':
		case 'e-accordion-item-icon':
		case 'e-accordion-item-content':
			return array( 't' => 'box', 'base' => $type, 'el' => 'e-accordion-item-content' === $type ? 'div' : 'span', 'kids' => el_parts( $kids, $styles ) ) + $base;
		case 'e-collection-loop':
			return el_loop( $inst, $set, $element, $styles ) + $base;
		case 'e-component':
			// A reusable component (Elementor 4): its own elements, as parts, in place of the instance.
			$id    = (int) ( $set['component_instance']['component_id'] ?? ( $element['settings']['component_instance']['value']['component_id']['value'] ?? 0 ) );
			$inner = el_component( $id, $styles );
			return null === $inner ? array( 't' => 'x', 'widget' => $type, 'html' => el_rendered( $inst ) ) + $base : array( 't' => 'box', 'base' => $type, 'el' => 'div', 'kids' => $inner ) + $base;
	}
	// A site-made widget that stands for a Fluent Forms form (its script would draw it): the form itself.
	$form_id = (int) ( $set['form_id'] ?? 0 );
	$form    = $form_id > 0 && function_exists( __NAMESPACE__ . '\\fluent_form' ) ? fluent_form( $form_id ) : null;
	if ( $form ) {
		return array( 't' => 'form', 'form' => $form ) + $base;
	}
	return ( el_wrapped_form( $inst ) ?? array( 't' => 'x', 'widget' => $type, 'html' => el_rendered( $inst ) ) ) + $base;
}

/** The parts of a published Elementor component (post type elementor_component); null when there is none. A component inside itself stops. */
function el_component( int $id, array &$styles ): ?array {
	static $open = array();
	$post = $id > 0 ? get_post( $id ) : null;
	if ( ! $post || 'elementor_component' !== $post->post_type || 'publish' !== $post->post_status || isset( $open[ $id ] ) ) {
		return null;
	}
	$data = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
	if ( ! is_array( $data ) ) {
		return null;
	}
	$open[ $id ] = true;
	try {
		return el_parts( $data, $styles );
	} finally {
		unset( $open[ $id ] );
	}
}

/**
 * The stylesheets an element this plugin does not know registers for itself (a site-made widget's own look), noted
 * while a page's parts are made and handed over with them as "assets", so a front end can load them. Addresses on
 * this site's own hosts come as the front end's addresses (paths when pictures do). Scripts are left out: a widget's
 * behaviour is the front end's own work.
 */
function el_assets_note( $inst ): void {
	static $seen = array();
	if ( ! method_exists( $inst, 'get_style_depends' ) ) {
		return;
	}
	try {
		$handles = (array) $inst->get_style_depends();
	} catch ( \Throwable $e ) {
		return;
	}
	$styles = wp_styles();
	foreach ( $handles as $handle ) {
		$src = isset( $styles->registered[ $handle ] ) ? (string) $styles->registered[ $handle ]->src : '';
		if ( '' === $src || isset( $seen[ $src ] ) ) {
			continue;
		}
		$seen[ $src ] = true;
		if ( '/' === $src[0] && '/' !== ( $src[1] ?? '' ) ) {
			$src = site_url( $src );
		}
		$ver = isset( $styles->registered[ $handle ]->ver ) && $styles->registered[ $handle ]->ver ? (string) $styles->registered[ $handle ]->ver : '';
		$GLOBALS['cnh_el_assets'][] = media_url( add_query_arg( 'ver', $ver ?: null, $src ) );
	}
}

/**
 * The stylesheets the site itself queues for its front end: site-made widgets (must-use loaders) and plugins register
 * and queue theirs on the front-end hooks, which a door request never fires, so they are fired here once and what
 * they queued is taken, less WordPress's, the theme's and Elementor's own (the front end has its own look for those
 * and the page builder's rules come as "css"). The filter clientnav_headless_assets lets a site add-on trim or add.
 */
/** The active Elementor kit's id (its site-wide colours, type and custom CSS), 0 when there is none. */
function el_kit_id(): int {
	try {
		return class_exists( '\\Elementor\\Plugin' ) ? (int) \Elementor\Plugin::$instance->kits_manager->get_active_id() : 0;
	} catch ( \Throwable $e ) {
		return 0;
	}
}

/** The class the CMS puts on <body> for the kit ("elementor-kit-576"): the kit's own rules are written for it. */
function el_kit_class(): string {
	$id = el_kit_id();
	return $id ? 'elementor-kit-' . $id : '';
}

/** The kit's own stylesheet as Elementor wrote it (custom CSS included), when it is a file. */
function el_kit_sheet(): string {
	$id   = el_kit_id();
	$up   = wp_upload_dir();
	$file = $id ? ( (string) ( $up['basedir'] ?? '' ) ) . "/elementor/css/post-$id.css" : '';
	return '' !== $file && is_readable( $file ) ? media_url( add_query_arg( 'ver', (string) filemtime( $file ), ( (string) ( $up['baseurl'] ?? '' ) ) . "/elementor/css/post-$id.css" ) ) : '';
}

function el_assets_site(): array {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out  = array();
	$kit  = el_kit_sheet();
	if ( '' !== $kit ) {
		$out[] = $kit;
	}
	try {
		if ( ! did_action( 'wp_enqueue_scripts' ) ) {
			do_action( 'wp_enqueue_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}
		do_action( 'elementor/frontend/after_enqueue_styles' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	} catch ( \Throwable $e ) {
		return $out;
	}
	$styles = wp_styles();
	$styles->all_deps( $styles->queue );
	$theme = wp_parse_url( get_stylesheet_directory_uri(), PHP_URL_PATH ) ?: '/wp-content/themes/';
	$up    = wp_upload_dir();
	$upath = untrailingslashit( (string) wp_parse_url( (string) ( $up['baseurl'] ?? '' ), PHP_URL_PATH ) ) . '/';
	foreach ( (array) $styles->to_do as $handle ) {
		$reg = $styles->registered[ $handle ] ?? null;
		$src = $reg ? (string) $reg->src : '';
		if ( '' === $src || preg_match( '/^(elementor|e-|wp-|admin|dashicons|classic|global|clientnav|font-awesome|eicons|swiper)/', (string) $handle ) ) {
			continue;
		}
		if ( '/' === $src[0] && '/' !== ( $src[1] ?? '' ) ) {
			$src = site_url( $src );
		}
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		if ( str_contains( $path, '/wp-includes/' ) || str_contains( $path, '/plugins/elementor' ) || str_starts_with( $path, $theme ) ) {
			continue;
		}
		// A file in the uploads folder is kept only when it is there: a site's hooks may name Elementor CSS for a
		// template Elementor has not written on this CMS (a site, local-739-*.css), and the front end
		// would get an HTML 404 for a stylesheet.
		if ( '/' !== $upath && str_starts_with( $path, $upath ) && ! is_readable( (string) ( $up['basedir'] ?? '' ) . '/' . rawurldecode( substr( $path, strlen( $upath ) ) ) ) ) {
			continue;
		}
		$ver   = $reg->ver ? (string) $reg->ver : '';
		$out[] = media_url( '' !== $ver ? add_query_arg( 'ver', $ver, $src ) : $src );
	}
	$out = array_values( array_unique( (array) apply_filters( 'clientnav_headless_assets', $out ) ) );
	return $out;
}

/**
 * The scripts the site itself queues for its front end, the same way: a site-made widget's behaviour (a menu's
 * toggle, a carousel, a counter) lives in them. WordPress's, jQuery, Elementor's and the theme's are left out; a front
 * end loads them or not as its own decision. The filter clientnav_headless_scripts lets a site add-on trim or add.
 */
function el_scripts_site(): array {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	el_assets_site(); // fires the front-end hooks once
	try {
		do_action( 'elementor/frontend/after_enqueue_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	} catch ( \Throwable $e ) {
		return array();
	}
	$out     = array();
	$scripts = wp_scripts();
	$scripts->all_deps( $scripts->queue );
	$theme = wp_parse_url( get_stylesheet_directory_uri(), PHP_URL_PATH ) ?: '/wp-content/themes/';
	foreach ( (array) $scripts->to_do as $handle ) {
		$reg = $scripts->registered[ $handle ] ?? null;
		$src = $reg ? (string) $reg->src : '';
		if ( '' === $src || preg_match( '/^(elementor|e-|wp-|jquery|admin|clientnav|swiper|imagesloaded|underscore|backbone|react|lodash|moment|regenerator|hoverintent)/', (string) $handle ) ) {
			continue;
		}
		if ( '/' === $src[0] && '/' !== ( $src[1] ?? '' ) ) {
			$src = site_url( $src );
		}
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		if ( str_contains( $path, '/wp-includes/' ) || str_contains( $path, '/plugins/elementor' ) || str_starts_with( $path, $theme ) ) {
			continue;
		}
		$ver = $reg->ver ? (string) $reg->ver : '';
		$url = media_url( '' !== $ver ? add_query_arg( 'ver', $ver, $src ) : $src );
		// A script WordPress prints as a module (a plugin sets type="module" on its tag) must load as one: "#module".
		$tag = (string) apply_filters( 'script_loader_tag', "<script src='" . esc_url( $src ) . "' id='" . esc_attr( $handle ) . "-js'></script>\n", $handle, $src ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		if ( 'module' === ( $reg->extra['type'] ?? '' ) || preg_match( '/\btype\s*=\s*["\']?module\b/i', $tag ) ) {
			$url .= '#module';
		}
		$out[] = $url;
	}
	$out = array_values( array_unique( (array) apply_filters( 'clientnav_headless_scripts', $out ) ) );
	return $out;
}

/** The stylesheets of this answer: the site's own front-end ones and those noted for unknown widgets, each once. */
function el_assets_take(): array {
	$out = array_values( array_unique( array_merge( el_assets_site(), (array) ( $GLOBALS['cnh_el_assets'] ?? array() ) ) ) );
	$GLOBALS['cnh_el_assets'] = array();
	return $out;
}

/**
 * What an element this plugin does not know (a site-made or third-party widget) draws by itself, as the CMS would
 * show it: its own markup with pictures as the front end's addresses, scripts and event handlers removed. A front
 * end can show that as it is until a site add-on answers the widget as a part of its own.
 */
function el_rendered( $inst ): string {
	$html = (string) preg_replace( '~<(script|style|noscript)\b[^>]*>.*?</\1>~is', '', el_raw( $inst ) );
	return trim( localize_html( wp_kses( $html, el_allowed_html() ) ) );
}

/** What a widget's markup may keep: a post's tags, plus the icons drawn as SVG and the attributes a button's script reads. */
function el_allowed_html(): array {
	static $allowed = null;
	if ( null === $allowed ) {
		$allowed = wp_kses_allowed_html( 'post' );
		$shape   = array_fill_keys( array( 'd', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'fill-rule', 'clip-rule', 'opacity', 'transform', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'width', 'height', 'points', 'viewbox', 'xmlns', 'class', 'aria-hidden', 'focusable', 'role' ), true );
		foreach ( array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'defs', 'use', 'title' ) as $tag ) {
			$allowed[ $tag ] = $shape;
		}
		foreach ( array( 'button', 'div', 'span', 'a', 'nav', 'ul', 'li', 'section', 'input', 'label', 'select', 'option', 'textarea', 'form' ) as $tag ) {
			$allowed[ $tag ] = ( $allowed[ $tag ] ?? array() ) + array_fill_keys( array( 'class', 'id', 'type', 'name', 'value', 'placeholder', 'aria-label', 'aria-expanded', 'aria-controls', 'aria-hidden', 'aria-haspopup', 'aria-current', 'role', 'tabindex', 'hidden', 'disabled', 'data-*' ), true );
		}
		$allowed['a']    += array( 'href' => true, 'target' => true, 'rel' => true, 'title' => true );
		$allowed['form'] += array( 'action' => true, 'method' => true );
	}
	return $allowed;
}

/** The markup an element prints, as is; empty when it cannot be drawn here. */
function el_raw( $inst ): string {
	// Kept per instance, not per object number: a loop makes and drops an instance per row, and PHP gives a dropped
	// object's number to the next one, which would get the earlier row's markup.
	static $done = null;
	$done ??= new \WeakMap();
	if ( isset( $done[ $inst ] ) ) {
		return $done[ $inst ];
	}
	if ( ! method_exists( $inst, 'render_content' ) ) {
		return $done[ $inst ] = '';
	}
	el_assets_note( $inst );
	try {
		ob_start();
		$inst->render_content();
		return $done[ $inst ] = (string) ob_get_clean();
	} catch ( \Throwable $e ) {
		ob_end_clean();
		return $done[ $inst ] = '';
	}
}

/**
 * An unknown widget that wraps a Fluent Forms form (a site-made "connected form", a shortcode in a text widget):
 * the form it holds, as a form part the front end draws and sends by itself; null when it holds none.
 */
function el_wrapped_form( $inst ): ?array {
	$raw = el_raw( $inst );
	if ( '' === $raw || ! preg_match( '/<form\b[^>]*\bdata-form_id=["\']?(\d+)/i', $raw, $m ) ) {
		return null;
	}
	$made = function_exists( __NAMESPACE__ . '\\fluent_form' ) ? fluent_form( (int) $m[1] ) : null;
	return $made ? array( 't' => 'form', 'form' => $made ) : null;
}

/**
 * A loop (Elementor V4): its query is run and the item template resolved once for every entry found, with that entry
 * as the current post. Elementor Pro runs the query when it has the loop element; without it (no licence with the
 * loop feature, or no Pro at all) the door builds the same query from the loop's saved settings (el_loop_args()).
 */
function el_loop( $inst, array $set, array $element, array &$styles ): array {
	$layout = null;
	foreach ( (array) ( $element['elements'] ?? array() ) as $kid ) {
		if ( 'e-collection-loop-layout' === ( $kid['elType'] ?? '' ) ) {
			$layout = $kid;
		}
	}
	$rows = array();
	$lcls = array();
	if ( $layout ) {
		foreach ( (array) ( $layout['styles'] ?? array() ) as $id => $style ) {
			$styles[ $id ] = $style;
		}
		$lcls     = array_values( (array) ( $layout['settings']['classes']['value'] ?? array() ) );
		$template = null;
		foreach ( (array) ( $layout['elements'] ?? array() ) as $item ) {
			if ( 'e-collection-loop-item' === ( $item['elType'] ?? '' ) ) {
				$template = $item;
				break;
			}
		}
		if ( $template ) {
			foreach ( (array) ( $template['styles'] ?? array() ) as $id => $style ) {
				$styles[ $id ] = $style;
			}
			$icls = array_values( (array) ( $template['settings']['classes']['value'] ?? array() ) );
			$row  = static function () use ( &$rows, $template, $icls, &$styles ): bool {
				$rows[] = array( 't' => 'box', 'base' => 'e-collection-loop-item', 'el' => 'div', 'cls' => $icls, 'kids' => el_parts( el_loop_fill( (array) ( $template['elements'] ?? array() ) ), $styles ) );
				return count( $rows ) < 60;
			};
			$builder = '\ElementorPro\Modules\CollectionLoop\Query\Loop_Query_Args_Builder';
			if ( $inst && class_exists( $builder ) ) {
				$builder::item_provider_from_resolved( (array) ( $set['query'] ?? array() ), $inst )->iterate( $row );
			} else {
				el_loop_run( (array) el_plain( $element['settings']['query'] ?? array() ), $row );
			}
		}
	}
	return array( 't' => 'box', 'base' => 'e-collection-loop', 'el' => 'div', 'kids' => array( array( 't' => 'box', 'base' => 'e-collection-loop-layout', 'el' => 'div', 'cls' => $lcls, 'kids' => $rows ) ) );
}

/** A loop Elementor has no element for, from its saved data: its own classes, name and style rules, then the loop. */
function el_loop_bare( array $element, array &$styles ): array {
	foreach ( (array) ( $element['styles'] ?? array() ) as $id => $style ) {
		$styles[ $id ] = $style;
	}
	$set  = (array) el_plain( $element['settings'] ?? array() );
	$base = array( 'cls' => array_values( array_filter( array_map( 'strval', (array) ( $set['classes'] ?? array() ) ) ) ) );
	if ( ! empty( $set['_cssid'] ) && is_string( $set['_cssid'] ) ) {
		$base['id'] = sanitize_html_class( $set['_cssid'] );
	}
	return el_loop( null, array(), $element, $styles ) + $base;
}

/** A saved V4 value without its type marks ({"$$type":…,"value":…}); a chosen entry or term ("query") as its number. */
function el_plain( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( array_key_exists( '$$type', $value ) ) {
		return 'query' === $value['$$type'] && is_array( $value['value'] ?? null ) ? el_plain( $value['value']['id'] ?? null ) : el_plain( $value['value'] ?? null );
	}
	return array_map( __NAMESPACE__ . '\\el_plain', $value );
}

/**
 * The WP_Query arguments of a loop's saved settings, as Elementor Pro 4.3 builds them (Post_Template_Type,
 * Query_Filters): source (a post type, "manual" or "current_query"), terms and authors to include or leave out,
 * chosen entries and the current entry to leave out, date window, order, count, sticky posts. Only the types the
 * door shows are listed. Null when the loop lists something else (terms).
 */
function el_loop_args( array $q ): ?array {
	if ( ! in_array( (string) ( $q['template_type'] ?? 'post' ), array( '', 'post' ), true ) ) {
		return null;
	}
	$known  = public_types();
	$source = (string) ( $q['source'] ?? 'post' );
	$per    = min( 60, max( 1, (int) ( $q['posts_per_page'] ?? 3 ) ) );
	if ( 'current_query' === $source ) {
		global $wp_query;
		$args = $wp_query instanceof \WP_Query ? (array) $wp_query->query_vars : array();
		if ( isset( $q['posts_per_page'] ) ) {
			$args['posts_per_page'] = $per;
		}
		return (array) apply_filters( 'elementor/query/get_query_args/current_query', $args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	}
	if ( 'manual' !== $source && ! in_array( $source, $known, true ) ) {
		return null;
	}
	$orderby = in_array( $q['orderby'] ?? '', array( 'post_date', 'post_title', 'menu_order', 'modified', 'comment_count', 'rand' ), true ) ? $q['orderby'] : 'post_date';
	$args    = array( 'post_type' => 'manual' === $source ? $known : $source, 'posts_per_page' => $per, 'post_status' => 'publish', 'orderby' => $orderby, 'order' => 'asc' === ( $q['order'] ?? '' ) ? 'ASC' : 'DESC', 'no_found_rows' => true );
	$ids     = static fn( $list ): array => array_values( array_filter( array_map( static fn( $v ) => is_numeric( $v ) ? (int) $v : 0, is_array( $list ) ? $list : array() ) ) );
	if ( 'manual' === $source ) {
		return $args + array( 'post__in' => array_slice( $ids( $q['selection'] ?? array() ), 0, $per ) ?: array( 0 ), 'ignore_sticky_posts' => 1 );
	}
	$when = (string) ( $q['select_date'] ?? 'anytime' );
	$back = array( 'today' => '-1 day', 'week' => '-1 week', 'month' => '-1 month', 'quarter' => '-3 month', 'year' => '-1 year' );
	if ( isset( $back[ $when ] ) ) {
		$args['date_query'] = array( 'after' => $back[ $when ] );
	} elseif ( 'exact' === $when ) {
		$span = array_filter( array( 'after' => $q['date_after'] ?? null, 'before' => $q['date_before'] ?? null ), static fn( $d ) => is_string( $d ) && '' !== $d );
		if ( $span ) {
			$args['date_query'] = $span + array( 'inclusive' => true );
		}
	}
	$args['ignore_sticky_posts'] = 'post' !== $source || ( $q['ignore_sticky_posts'] ?? true ) ? 1 : 0;
	foreach ( array( 'include' => $q['include_filters'] ?? array(), 'exclude' => $q['exclude_filters'] ?? array() ) as $mode => $filters ) {
		foreach ( is_array( $filters ) ? $filters : array() as $filter ) {
			$filter = is_array( $filter ) ? $filter : array();
			$list   = $ids( $filter['values'] ?? array() );
			switch ( (string) ( $filter['key'] ?? '' ) ) {
				case 'terms':
					$terms = $list ? get_terms( array( 'include' => $list, 'hide_empty' => false ) ) : array();
					$tax   = array();
					foreach ( is_array( $terms ) ? $terms : array() as $term ) {
						$tax[ $term->taxonomy ][] = (int) $term->term_id;
					}
					foreach ( $tax as $name => $in ) {
						$args['tax_query'][] = array( 'taxonomy' => $name, 'field' => 'term_id', 'terms' => $in ) + ( 'exclude' === $mode ? array( 'operator' => 'NOT IN' ) : array() ); // phpcs:ignore WordPress.DB.SlowDBQuery
					}
					break;
				case 'authors':
					if ( 'include' === $mode ) {
						$args['author__in'] = array_values( array_unique( array_merge( $args['author__in'] ?? array(), $list ) ) );
					} elseif ( $list && empty( $args['author__in'] ) ) {
						$args['author__not_in'] = array_values( array_unique( array_merge( $args['author__not_in'] ?? array(), $list ) ) );
					}
					break;
				case 'manual_selection':
					if ( 'exclude' === $mode && $list ) {
						$args['post__not_in'] = array_values( array_unique( array_merge( $args['post__not_in'] ?? array(), $list ) ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams
					}
					break;
				case 'current_post':
					// The entry the page is drawn for: the main query's on a page load, else the one elementor_page() set.
					$now = is_singular() ? get_queried_object_id() : ( ! is_archive() && ! is_search() && ( $GLOBALS['post'] ?? null ) instanceof \WP_Post ? (int) $GLOBALS['post']->ID : 0 );
					if ( 'exclude' === $mode && $now ) {
						$args['post__not_in'] = array_values( array_unique( array_merge( $args['post__not_in'] ?? array(), array( $now ) ) ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams
					}
					break;
			}
		}
	}
	if ( count( $args['tax_query'] ?? array() ) > 1 ) {
		$args['tax_query']['relation'] = 'AND';
	}
	return $args;
}

/** Runs a loop's query and calls $row once per entry with it as the current post (false stops); the post before is put back. */
function el_loop_run( array $q, callable $row ): void {
	$args = el_loop_args( $q );
	if ( null === $args ) {
		return;
	}
	// A loop with a query id lets site code change its query, as Elementor Pro does (elementor/query/{id}).
	$id     = is_string( $q['query_id'] ?? null ) ? sanitize_key( $q['query_id'] ) : '';
	$listen = static function ( \WP_Query $query ) use ( $id ) {
		do_action( "elementor/query/{$id}", $query, null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	};
	if ( '' !== $id ) {
		add_action( 'pre_get_posts', $listen );
	}
	$query = new \WP_Query( $args );
	if ( '' !== $id ) {
		remove_action( 'pre_get_posts', $listen );
	}
	$was = $GLOBALS['post'] ?? null;
	try {
		while ( $query->have_posts() ) {
			$query->the_post();
			if ( false === $row() ) {
				break;
			}
		}
	} finally {
		wp_reset_postdata();
		if ( $was instanceof \WP_Post ) {
			$GLOBALS['post'] = $was; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $was );
		}
	}
}

/**
 * A loop item's elements with the dynamic values this Elementor cannot fill (no tag registered under that name, as
 * without Elementor Pro) filled for the current post: title, address, date, excerpt, featured picture, a custom
 * field, author. Each is written in the value type its element takes there, so Elementor resolves it as typed in.
 */
function el_loop_fill( array $elements ): array {
	foreach ( $elements as $i => $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}
		$schema = el_schema( $element );
		foreach ( (array) ( $element['settings'] ?? array() ) as $key => $value ) {
			$elements[ $i ]['settings'][ $key ] = el_fill( $value, $schema[ $key ] ?? null );
		}
		if ( ! empty( $element['elements'] ) ) {
			$elements[ $i ]['elements'] = el_loop_fill( (array) $element['elements'] );
		}
	}
	return $elements;
}

/** The settings an atomic element takes (its props schema), by element type; empty when Elementor does not know it. */
function el_schema( array $element ): array {
	static $known = array();
	$widget = 'widget' === ( $element['elType'] ?? '' );
	$type   = (string) ( $widget ? ( $element['widgetType'] ?? '' ) : ( $element['elType'] ?? '' ) );
	if ( ! array_key_exists( $type, $known ) ) {
		try {
			$kind           = $widget ? \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $type ) : \Elementor\Plugin::$instance->elements_manager->get_element_types( $type );
			$known[ $type ] = $kind && method_exists( $kind, 'get_props_schema' ) ? (array) $kind::get_props_schema() : array();
		} catch ( \Throwable $e ) {
			$known[ $type ] = array();
		}
	}
	return $known[ $type ];
}

function el_fill( $value, $type ) {
	if ( ! is_array( $value ) || ! isset( $value['$$type'] ) ) {
		return $value;
	}
	if ( 'dynamic' === $value['$$type'] ) {
		return el_dynamic_static( (array) ( $value['value'] ?? array() ), $type ) ?? $value;
	}
	$own = is_a( $type, '\Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type' ) ? $type->get_prop_type( $value['$$type'] ) : $type;
	if ( is_a( $own, '\Elementor\Modules\AtomicWidgets\PropTypes\Base\Object_Prop_Type' ) && is_array( $value['value'] ?? null ) ) {
		foreach ( $value['value'] as $k => $v ) {
			$value['value'][ $k ] = el_fill( $v, $own->get_shape_field( $k ) );
		}
	}
	return $value;
}

/** The value type names a setting takes ("escaped-html", "url", "image-src"), or none when that is not known. */
function el_type_keys( $type ): array {
	try {
		if ( is_a( $type, '\Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type' ) ) {
			return array_values( array_map( static fn( $one ) => $one::get_key(), $type->get_prop_types() ) );
		}
		return is_object( $type ) && method_exists( $type, 'get_key' ) ? array( $type::get_key() ) : array();
	} catch ( \Throwable $e ) {
		return array();
	}
}

/** A dynamic value (Elementor's post tags) as a plain value for the current post; null when Elementor fills it itself or the tag is not one of these. */
function el_dynamic_static( array $dynamic, $type ): ?array {
	$name = (string) ( $dynamic['name'] ?? '' );
	$tags = \Elementor\Plugin::$instance->dynamic_tags ?? null;
	$post = get_post();
	if ( '' === $name || ! $post || ( $tags && $tags->get_tag_info( $name ) ) ) {
		return null;
	}
	$s    = el_plain( $dynamic['settings'] ?? array() );
	$s    = is_array( $s ) ? $s : array();
	$take = el_type_keys( $type );
	$as   = static fn( array $want ): string => $take ? ( array_values( array_intersect( $want, $take ) )[0] ?? $want[0] ) : $want[0];
	if ( 'post-featured-image' === $name ) {
		$pic = (int) get_post_thumbnail_id( $post );
		$to  = $as( array( 'image-src', 'image-attachment-id', 'url' ) );
		if ( ! $pic ) {
			return array( '$$type' => $to, 'value' => null );
		}
		$value = array(
			'image-src'           => array( 'id' => array( '$$type' => 'image-attachment-id', 'value' => $pic ), 'url' => null ),
			'image-attachment-id' => $pic,
			'url'                 => (string) wp_get_attachment_url( $pic ),
		);
		return array( '$$type' => $to, 'value' => $value[ $to ] );
	}
	switch ( $name ) {
		case 'post-url':
			return array( '$$type' => $as( array( 'url', 'string', 'escaped-html', 'html' ) ), 'value' => (string) get_permalink( $post ) );
		case 'post-title':
			$text = get_the_title( $post );
			break;
		case 'post-date':
		case 'post-time':
			$changed = 'modified' === ( $s['type'] ?? '' );
			$format  = (string) ( $s['format'] ?? 'default' );
			if ( 'human' === $format ) {
				/* translators: %s: how long ago, e.g. "3 days" */
				$text = sprintf( __( '%s ago', 'clientnav-headless' ), human_time_diff( (int) get_post_timestamp( $post, $changed ? 'modified' : 'date' ) ) );
				break;
			}
			$format = 'custom' === $format ? (string) ( $s['custom_format'] ?? '' ) : ( 'default' === $format ? '' : $format );
			if ( 'post-time' === $name ) {
				$text = $changed ? (string) get_the_modified_time( $format, $post ) : (string) get_the_time( $format, $post );
			} else {
				$text = $changed ? (string) get_the_modified_date( $format, $post ) : (string) get_the_date( $format, $post );
			}
			break;
		case 'post-excerpt':
			$text = has_excerpt( $post ) ? $post->post_excerpt : ( ! empty( $s['apply_to_post_content'] ) ? $post->post_content : '' );
			$text = trim( wp_strip_all_tags( strip_shortcodes( $text ) ) );
			$max  = (int) ( $s['max_length'] ?? 0 );
			$text = $max > 0 ? wp_trim_words( $text, $max, '...' ) : $text;
			break;
		case 'post-custom-field':
			$key  = (string) ( $s['custom_key'] ?? '' ) ?: (string) ( $s['key'] ?? '' );
			$meta = '' !== $key && ! is_protected_meta( $key, 'post' ) ? get_post_meta( $post->ID, $key, true ) : '';
			$text = is_scalar( $meta ) ? (string) $meta : '';
			break;
		case 'post-id':
			$text = (string) $post->ID;
			break;
		case 'author-name':
			$text = (string) get_the_author_meta( 'display_name', (int) $post->post_author );
			break;
		default:
			return null;
	}
	if ( '' !== $text ) {
		$text = (string) ( $s['before'] ?? '' ) . $text . (string) ( $s['after'] ?? '' );
	}
	return array( '$$type' => $as( array( 'string', 'escaped-html', 'html' ) ), 'value' => $text );
}

/** A classic (not atomic) widget or container. Their fine styling is not carried over; the front end gives each a plain look. */
function el_classic( string $type, array $s, array $kids, array &$styles ): ?array {
	$text = static fn( string $key ): string => is_string( $s[ $key ] ?? null ) ? $s[ $key ] : '';
	$link = static fn( $l ): array => el_link( $l ) ? array( 'link' => el_link( $l ) ) : array();
	switch ( $type ) {
		case 'container':
		case 'section':
		case 'column':
			return array( 't' => 'box', 'base' => 'classic-' . $type, 'el' => 'div', 'cls' => array(), 'kids' => el_parts( $kids, $styles ) );
		case 'heading':
			return array( 't' => 'h', 'el' => in_array( $s['header_size'] ?? '', array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $s['header_size'] : 'h2', 'html' => el_inline( $text( 'title' ) ), 'cls' => array( 'classic-heading' ), 'align' => $text( 'align' ) ) + $link( $s['link'] ?? null );
		case 'text-editor':
			return array( 't' => 'rich', 'html' => el_html( wp_kses_post( wpautop( $text( 'editor' ) ) ) ) );
		case 'button':
			return array( 't' => 'btn', 'html' => el_inline( $text( 'text' ) ), 'cls' => array( 'classic-button' ), 'align' => $text( 'align' ), 'icon' => el_icon( $s['selected_icon'] ?? null ), 'bg' => el_colour( $s['background_color'] ?? '' ), 'ink' => el_colour( $s['button_text_color'] ?? '' ) ) + $link( $s['link'] ?? null );
		case 'image':
			$picture = el_attachment( (int) ( $s['image']['id'] ?? 0 ), 'custom' === ( $s['image_size'] ?? '' ) ? 'full' : (string) ( $s['image_size'] ?? 'large' ) );
			return $picture ? array( 't' => 'img', 'cls' => array( 'classic-image' ) ) + $picture + $link( $s['link'] ?? null ) : null;
		case 'icon-list':
			$items = array();
			foreach ( (array) ( $s['icon_list'] ?? array() ) as $row ) {
				if ( '' !== trim( wp_strip_all_tags( (string) ( $row['text'] ?? '' ) ) ) ) {
					$items[] = array( 'html' => el_inline( (string) ( $row['text'] ?? '' ) ), 'icon' => el_icon( $row['selected_icon'] ?? null ) ) + $link( $row['link'] ?? null );
				}
			}
			return $items ? array( 't' => 'list', 'items' => $items, 'inline' => 'inline' === ( $s['view'] ?? '' ), 'ink' => el_colour( $s['text_color'] ?? '' ), 'tint' => el_colour( $s['icon_color'] ?? '' ), 'align' => $text( 'icon_align' ) ) : null;
		case 'icon-box':
		case 'image-box':
			return array( 't' => 'ibox', 'title' => el_inline( $text( 'title_text' ) ), 'html' => el_html( wp_kses_post( $text( 'description_text' ) ) ), 'icon' => el_icon( $s['selected_icon'] ?? null ), 'picture' => el_attachment( (int) ( $s['image']['id'] ?? 0 ), 'medium_large' ) ) + $link( $s['link'] ?? null );
		case 'testimonial':
			return array( 't' => 'quote', 'html' => el_html( wp_kses_post( $text( 'testimonial_content' ) ) ), 'name' => plain( $text( 'testimonial_name' ) ), 'role' => plain( $text( 'testimonial_job' ) ), 'picture' => el_attachment( (int) ( $s['testimonial_image']['id'] ?? 0 ), 'thumbnail' ) );
		case 'star-rating':
		case 'rating':
			return array( 't' => 'stars', 'value' => (float) ( $s['rating'] ?? $s['rating_value']['size'] ?? 5 ), 'of' => (int) ( is_array( $s['rating_scale'] ?? null ) ? ( $s['rating_scale']['size'] ?? 5 ) : ( $s['rating_scale'] ?? 5 ) ) ?: 5 );
		case 'social-icons':
			$items = array();
			foreach ( (array) ( $s['social_icon_list'] ?? array() ) as $row ) {
				$to = el_link( $row['link'] ?? null );
				if ( $to ) {
					$items[] = array( 'name' => is_string( $row['social_icon']['value'] ?? null ) ? (string) preg_replace( '/^fa[bsr]? fa-/', '', $row['social_icon']['value'] ) : 'link', 'icon' => el_icon( $row['social_icon'] ?? null ), 'link' => $to );
				}
			}
			return array( 't' => 'social', 'items' => $items );
		case 'google_maps':
			return array( 't' => 'map', 'place' => plain( $text( 'address' ) ) );
		case 'divider':
			return array( 't' => 'hr', 'cls' => array() );
		case 'spacer':
			return null;
		case 'search':
		case 'search-form':
			return array( 't' => 'search', 'ask' => plain( $text( 'placeholder' ) ) );
		case 'counter':
			return array( 't' => 'counter', 'value' => (float) ( $s['ending_number'] ?? 0 ), 'from' => (float) ( $s['starting_number'] ?? 0 ), 'prefix' => plain( $text( 'prefix' ) ), 'suffix' => plain( $text( 'suffix' ) ), 'title' => plain( $text( 'title' ) ) );
		case 'image-gallery':
		case 'gallery':
			// The free gallery keeps its pictures in wp_gallery, the Pro one in gallery (one gallery) or galleries (several, each with its own list).
			$list = (array) ( $s['wp_gallery'] ?? $s['gallery'] ?? array() );
			foreach ( (array) ( $s['galleries'] ?? array() ) as $one ) {
				$list = array_merge( $list, (array) ( $one['multiple_gallery'] ?? array() ) );
			}
			$items = array_values( array_filter( array_map( static fn( $pic ) => el_attachment( (int) ( $pic['id'] ?? 0 ), 'large' ), $list ) ) );
			return $items ? array( 't' => 'gallery', 'items' => $items, 'cols' => max( 1, min( 8, (int) ( $s['gallery_columns'] ?? $s['columns'] ?? 3 ) ) ) ) : null;
		case 'theme-site-logo':
			// The site's logo (Appearance > Customize, or the one chosen in the widget), leading home.
			$logo = el_attachment( (int) ( $s['image']['id'] ?? 0 ) ?: (int) get_theme_mod( 'custom_logo' ), 'full' );
			return $logo ? array( 't' => 'img', 'cls' => array( 'site-logo' ), 'link' => array( 'href' => '/', 'blank' => false ) ) + $logo : null;
		case 'video':
			$id = el_yt( $text( 'youtube_url' ) );
			return '' !== $id ? array( 't' => 'yt', 'video' => $id ) : null;
		case 'nav-menu':
			$items = array();
			foreach ( (array) ( wp_get_nav_menu_items( $text( 'menu' ) ) ?: array() ) as $row ) {
				$items[] = array( 'html' => esc_html( (string) $row->title ), 'link' => array( 'href' => to_path( (string) $row->url ), 'blank' => '_blank' === $row->target ) );
			}
			return $items ? array( 't' => 'list', 'items' => $items, 'inline' => 'vertical' !== ( $s['layout'] ?? '' ), 'align' => 'center' ) : null;
		case 'html':
			// Hand-written HTML: its words and links are kept, anything that runs or loads is not.
			$html = el_html( wp_kses_post( (string) preg_replace( '#<(style|script)\b.*?</\1>#is', '', $text( 'html' ) ) ) );
			return '' === trim( wp_strip_all_tags( $html ) ) ? null : array( 't' => 'rich', 'html' => $html, 'cls' => array( 'classic-html' ) );
		case 'fluent-form-widget':
			$made = fluent_form( (int) ( $s['form_list'] ?? 0 ) );
			return $made ? array( 't' => 'form', 'form' => $made ) : null;
		case 'shortcode':
			$code = $text( 'shortcode' );
			if ( preg_match( '/\[fluentform[^\]]*\bid=["\']?(\d+)/', $code, $m ) ) {
				$made = fluent_form( (int) $m[1] );
				return $made ? array( 't' => 'form', 'form' => $made ) : null;
			}
			return array( 't' => 'x', 'widget' => 'shortcode', 'note' => preg_match( '/\[([a-z0-9_-]+)/i', $code, $m ) ? $m[1] : '' );
	}
	// Lists of entries made by classic widgets arrive as cards, drawn like the site's own lists.
	if ( in_array( $type, array( 'posts', 'loop-grid', 'loop-carousel', 'jet-listing-grid' ), true ) ) {
		$posts = el_listed( $type, $s );
		if ( $posts ) {
			in_list( true );
			$cards = array( 't' => 'cards', 'items' => array_map( __NAMESPACE__ . '\\card', $posts ) );
			in_list( false );
			if ( 'loop-grid' === $type ) {
				// A loop grid whose template lays text over the picture: tiles, some wider (the "alternate template" rows).
				$wide = array();
				foreach ( (array) ( 'yes' === ( $s['alternate_template'] ?? '' ) ? ( $s['alternate_templates'] ?? array() ) : array() ) as $row ) {
					if ( (int) ( $row['column_span'] ?? 1 ) > 1 ) {
						$wide[] = max( 1, (int) ( $row['repeat_template'] ?? 0 ) );
					}
				}
				$cards += array( 'look' => 'tiles', 'cols' => max( 1, min( 6, (int) ( $s['columns'] ?? 3 ) ) ), 'wide' => $wide );
			}
			if ( 'loop-carousel' === $type || ( 'jet-listing-grid' === $type && 'yes' === ( $s['carousel_enabled'] ?? '' ) ) ) {
				$cards['row'] = true; // a carousel: one row
			}
			return $cards;
		}
	}
	return array( 't' => 'x', 'widget' => $type );
}

/** The entries a classic list widget shows: only of the types the door shows. */
function el_listed( string $type, array $s ): array {
	$known = public_types();
	if ( 'jet-listing-grid' === $type ) {
		$id = (int) ( $s['custom_query_id'] ?? 0 );
		if ( 'yes' === ( $s['custom_query'] ?? '' ) && $id && class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) {
			$query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( $id );
			$found = $query ? (array) $query->get_items() : array();
			$found = array_values( array_filter( $found, static fn( $p ): bool => $p instanceof \WP_Post && 'publish' === $p->post_status && in_array( $p->post_type, $known, true ) ) );
			if ( $found ) {
				return array_slice( $found, 0, 60 );
			}
		}
		$listing = (int) ( $s['lisitng_id'] ?? 0 ); // sic: JetEngine's own name for the setting
		$data    = $listing ? (array) get_post_meta( $listing, '_listing_data', true ) : array();
		$of      = (string) ( $data['post_type'] ?? '' );
		$of      = ! empty( $s['custom_post_types'] ) && 'yes' === ( $s['use_custom_post_types'] ?? '' ) ? (string) reset( $s['custom_post_types'] ) : $of;
		return in_array( $of, $known, true ) ? get_posts( array( 'post_type' => $of, 'post_status' => 'publish', 'numberposts' => min( 60, max( 1, (int) ( $s['posts_num'] ?? 12 ) ) ) ) ) : array();
	}
	$pre = 'posts' === $type ? 'posts_' : 'post_query_'; // loop grid and loop carousel share their query settings
	$of  = (string) ( $s[ $pre . 'post_type' ] ?? 'post' );
	$of  = in_array( $of, array( 'current_query', 'by_id', 'related', '' ), true ) ? 'post' : $of;
	if ( ! in_array( $of, $known, true ) ) {
		return array();
	}
	$skin = (string) ( $s['_skin'] ?? 'classic' );
	$args = array( 'post_type' => $of, 'post_status' => 'publish', 'numberposts' => min( 60, max( 1, (int) ( $s[ $skin . '_posts_per_page' ] ?? $s['posts_per_page'] ?? 6 ) ) ), 'offset' => max( 0, (int) ( $s[ $pre . 'offset' ] ?? 0 ) ) );
	$ids  = array_filter( array_map( 'intval', (array) ( $s[ $pre . 'include_term_ids' ] ?? array() ) ) );
	if ( $ids && in_array( 'terms', (array) ( $s[ $pre . 'include' ] ?? array() ), true ) ) {
		$tax = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id );
			if ( $term instanceof \WP_Term ) {
				$tax[ $term->taxonomy ][] = (int) $term->term_id;
			}
		}
		foreach ( $tax as $name => $terms ) {
			$args['tax_query'][] = array( 'taxonomy' => $name, 'field' => 'term_id', 'terms' => $terms ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
	}
	return get_posts( $args );
}

/** Questions and answers of the accordions marked for search engines, as plain text. */
function el_faq( array $parts, array &$found ): void {
	foreach ( $parts as $part ) {
		if ( 'box' === ( $part['t'] ?? '' ) && 'e-accordion' === ( $part['base'] ?? '' ) && ! empty( $part['faq'] ) ) {
			foreach ( (array) $part['kids'] as $item ) {
				$question = '';
				$answer   = '';
				foreach ( (array) ( $item['kids'] ?? array() ) as $side ) {
					$words = el_words( array( $side ) );
					if ( 'summary' === ( $side['el'] ?? '' ) ) {
						$question = $words;
					} else {
						$answer .= ' ' . $words;
					}
				}
				if ( '' !== $question && '' !== trim( $answer ) ) {
					$found[] = array( 'question' => $question, 'answer' => trim( $answer ) );
				}
			}
		}
		if ( ! empty( $part['kids'] ) ) {
			el_faq( (array) $part['kids'], $found );
		}
	}
}

/** All words of some parts, as plain text. */
function el_words( array $parts ): string {
	$out = '';
	foreach ( $parts as $part ) {
		$out .= ' ' . plain( (string) ( $part['html'] ?? '' ) );
		if ( ! empty( $part['kids'] ) ) {
			$out .= ' ' . el_words( (array) $part['kids'] );
		}
	}
	return trim( (string) preg_replace( '/\s+/u', ' ', $out ) );
}

/** The CSS of the collected V4 style rules, under the front end's wrapper class. */
function el_css( array $styles ): string {
	if ( ! $styles || ! class_exists( '\Elementor\Modules\AtomicWidgets\Styles\Styles_Renderer' ) ) {
		return '';
	}
	try {
		$css = \Elementor\Modules\AtomicWidgets\Styles\Styles_Renderer::make( \Elementor\Plugin::$instance->breakpoints->get_breakpoints_config(), elementor_scope() )->render( array_values( $styles ) );
	} catch ( \Throwable $e ) {
		return '';
	}
	// It goes inside a <style> element: nothing in it may close that element.
	return str_ireplace( '</style', '', el_kit_css( (string) $css ) . el_html( (string) $css ) );
}

/**
 * What the page builder's rules rely on but do not carry: the site kit's variables (its colours and type, which the
 * rules name as var(--e-global-…)), put on :root, and the font faces Elementor hosts on this site for the
 * families the rules use, with the font files served by this WordPress. Without these a front end shows the page in
 * the browser's own typeface and without the kit's colours.
 */
function el_kit_css( string $css ): string {
	static $kit = null;
	$dir = (string) ( wp_upload_dir()['basedir'] ?? '' );
	if ( null === $kit ) {
		$kit = '';
		try {
			$id = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
		} catch ( \Throwable $e ) {
			$id = 0;
		}
		$file = $id && '' !== $dir ? "$dir/elementor/css/post-$id.css" : '';
		if ( '' !== $file && is_readable( $file ) && preg_match( '/\.elementor-kit-' . $id . '\{([^}]*)\}/', (string) file_get_contents( $file ), $m ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			// On :root, above <body>: the kit's own rules (a dark theme) redefine them on body and must win.
			$kit = ':root{' . $m[1] . '}';
		}
	}
	$out = $kit;
	preg_match_all( '/font-family:\s*["\']?([A-Za-z0-9 ]+?)["\']?\s*[;,}]/', $css . $kit, $found );
	foreach ( array_unique( array_map( 'strtolower', $found[1] ?? array() ) ) as $family ) {
		$file = '' !== $dir ? "$dir/elementor/google-fonts/css/" . sanitize_title( $family ) . '.css' : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			continue;
		}
		// The faces were written for the site's address at the time (a copy keeps the live site's): a file that lies
		// in this WordPress's own uploads comes from here now, whatever address the face names.
		$out .= preg_replace_callback(
			'~url\((["\']?)https?://([^/)"\']+)(/wp-content/uploads/[^)"\'?#]+)([^)"\']*)\1\)~i',
			static fn( array $m ): string => 'url(' . ( is_readable( $dir . substr( $m[3], strlen( '/wp-content/uploads' ) ) ) ? media_url( home_url( $m[3] ) ) : "https://{$m[2]}{$m[3]}{$m[4]}" ) . ')',
			(string) file_get_contents( $file ) // phpcs:ignore WordPress.WP.AlternativeFunctions
		);
	}
	return str_replace( '<', '', $out );
}

// --- the theme builder's header and footer ----------------------------------------------------

/** The data of the theme builder's template for a place (header, footer), or null. */
function el_location( string $where ): ?array {
	if ( ! elementor_ready() || ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
		return null;
	}
	$docs = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_documents_for_location( $where );
	$doc  = $docs ? reset( $docs ) : null;
	$raw  = $doc ? json_decode( (string) get_post_meta( $doc->get_main_id(), '_elementor_data', true ), true ) : null;
	return is_array( $raw ) ? $raw : null;
}

function el_first_widget( array $elements, string $type ): ?array {
	foreach ( $elements as $element ) {
		if ( $type === ( $element['widgetType'] ?? '' ) ) {
			return $element;
		}
		$found = el_first_widget( (array) ( $element['elements'] ?? array() ), $type );
		if ( $found ) {
			return $found;
		}
	}
	return null;
}

function el_all_widgets( array $elements, string $type, array &$out ): void {
	foreach ( $elements as $element ) {
		if ( $type === ( $element['widgetType'] ?? '' ) ) {
			$out[] = $element;
		}
		el_all_widgets( (array) ( $element['elements'] ?? array() ), $type, $out );
	}
}

function el_shown( array $element ): array {
	try {
		$inst = \Elementor\Plugin::$instance->elements_manager->create_element_instance( $element );
		return $inst ? (array) $inst->get_settings_for_display() : array();
	} catch ( \Throwable $e ) {
		return (array) ( $element['settings'] ?? array() );
	}
}

/** The mega menu of a header: each item, and for an item with a panel its links (title, text, icon) and picture. */
function el_nav( array $raw ): array {
	$menu = el_first_widget( $raw, 'mega-menu' );
	if ( ! $menu ) {
		return array();
	}
	$out = array();
	foreach ( array_values( (array) ( el_shown( $menu )['menu_items'] ?? array() ) ) as $i => $item ) {
		$entry = array( 'label' => plain( (string) ( $item['item_title'] ?? '' ) ), 'path' => to_path( (string) ( $item['item_link']['url'] ?? '' ) ) ?: '/', 'links' => array(), 'picture' => null );
		if ( 'yes' === ( $item['item_dropdown_content'] ?? '' ) ) {
			$panel = (array) ( $menu['elements'][ $i ]['elements'] ?? array() );
			$boxes = array();
			el_all_widgets( $panel, 'icon-box', $boxes );
			foreach ( $boxes as $box ) {
				$set = el_shown( $box );
				$to  = el_link( $set['link'] ?? null );
				if ( $to ) {
					$entry['links'][] = array( 'title' => plain( (string) ( $set['title_text'] ?? '' ) ), 'text' => plain( (string) ( $set['description_text'] ?? '' ) ), 'icon' => el_icon( $set['selected_icon'] ?? null ), 'link' => $to );
				}
			}
			$image = el_first_widget( $panel, 'image' );
			if ( $image ) {
				$entry['picture'] = el_attachment( (int) ( el_shown( $image )['image']['id'] ?? 0 ), 'medium_large' );
			}
		}
		if ( '' !== $entry['label'] ) {
			$out[] = $entry;
		}
	}
	return $out;
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route( REST_NS, '/part', array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\door_allows', 'callback' => kept_route( 'part', lang_route( 'part', __NAMESPACE__ . '\\rest_part' ) ) ) );
	}
);

/**
 * WordPress's main query for a public address, as a page load sets it up: the theme builder's conditions and the
 * dynamic tags (title, date, picture, content, terms, breadcrumbs) then answer for that address. "404" sets up a
 * page not found, "search" a search for $search. Returns what the address is, or null when nothing lives there.
 */
function el_context( string $where, string $path, string $search = '' ): ?string {
	global $wp, $wp_query, $wp_the_query, $post;
	$saved = array( $_SERVER['REQUEST_URI'] ?? '', $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
	try {
		if ( '404' === $where ) {
			$wp_query = new \WP_Query();
			$wp_query->set_404();
		} elseif ( 'search' === $where ) {
			$wp_query = new \WP_Query( array( 's' => $search, 'post_status' => 'publish' ) );
		} else {
			$home                   = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$_SERVER['REQUEST_URI'] = rtrim( $home, '/' ) . '/' . ltrim( $path, '/' );
			$_GET                   = array();
			$wp->query_vars         = array();
			$wp->parse_request();
			$wp_query = new \WP_Query( $wp->query_vars );
		}
	} finally {
		list( $_SERVER['REQUEST_URI'], $_GET ) = $saved;
	}
	$wp_the_query = $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	if ( 'single' === $where ) {
		if ( ! $wp_query->is_singular() || ! $wp_query->have_posts() ) {
			return null;
		}
		$wp_query->the_post();
		$post = get_queried_object(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		return 'single';
	}
	if ( 'archive' === $where ) {
		return $wp_query->is_archive() || $wp_query->is_home() ? 'archive' : null;
	}
	return $where;
}

/** The theme builder's template for a place in the current query's context: [ template id, its data ] or null. */
function el_location_doc( string $location ): ?array {
	if ( ! elementor_ready() || ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
		return null;
	}
	$docs = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_documents_for_location( $location );
	$doc  = $docs ? reset( $docs ) : null;
	$raw  = $doc ? json_decode( (string) get_post_meta( $doc->get_main_id(), '_elementor_data', true ), true ) : null;
	return is_array( $raw ) ? array( (int) $doc->get_main_id(), $raw ) : null;
}

function rest_part( \WP_REST_Request $r ) {
	$where = sanitize_key( (string) $r['where'] );
	$raw   = in_array( $where, array( 'header', 'footer' ), true ) ? el_location( $where ) : null;
	$id    = 0;
	// A popup of the page builder, by its number (links open it with "#elementor-action…popup:open").
	if ( 'popup' === $where ) {
		$pid  = absint( $r['id'] ?? 0 );
		$post = $pid ? get_post( $pid ) : null;
		if ( $post && 'elementor_library' === $post->post_type && 'publish' === $post->post_status && 'popup' === get_post_meta( $pid, '_elementor_template_type', true ) ) {
			$data = json_decode( (string) get_post_meta( $pid, '_elementor_data', true ), true );
			if ( is_array( $data ) ) {
				list( $id, $raw ) = array( $pid, $data );
			}
		}
	}
	// A template for a kind of page (an article, a list, search results, not found), in that page's own context.
	if ( in_array( $where, array( 'single', 'archive', 'search', '404' ), true ) ) {
		$path = (string) ( $r['path'] ?? '' );
		if ( in_array( $where, array( 'single', 'archive' ), true ) && ( '' === $path || '/' !== $path[0] ) ) {
			return new \WP_REST_Response( array( 'type' => 'none', 'error' => 'path' ), 400 );
		}
		if ( ! el_context( $where, $path, sanitize_text_field( (string) ( $r['search'] ?? '' ) ) ) ) {
			return new \WP_REST_Response( array( 'type' => 'none' ), 404 );
		}
		// Elementor keeps search results and not-found pages among the single and archive templates.
		$found = el_location_doc( '404' === $where ? 'single' : ( 'search' === $where ? 'archive' : $where ) );
		if ( $found ) {
			list( $id, $raw ) = $found;
		}
	}
	if ( ! $raw ) {
		return new \WP_REST_Response( array( 'type' => 'none' ), 404 );
	}
	$styles = array();
	$out    = array( 'where' => $where, 'parts' => el_parts( $raw, $styles ), 'scope' => elementor_scope() );
	$out   += array( 'css' => el_css( $styles ), 'assets' => el_assets_take(), 'scripts' => el_scripts_site(), 'kit' => el_kit_class() );
	if ( $id ) {
		$out['template'] = $id;
		wp_reset_postdata();
	}
	if ( 'header' === $where ) {
		$out['nav'] = el_nav( $raw );
	}
	return $out;
}

// A saved header, footer or other Elementor template shows on many pages: everything is refreshed.
add_action(
	'save_post_elementor_library',
	static function ( $post_id, $post ) {
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && ! wp_is_post_revision( $post ) ) {
			Refresh::all();
		}
	},
	10,
	2
);
// The same when only a template's design is written, without a save of the template itself: Client Navigator's
// Elementor abilities (elementor-element-update, -insert, -delete) and imports change _elementor_data alone, and
// nothing told the front end (a marketing site, 2026-10-16: the menu label and footer badge stayed old on beta).
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
	add_action(
		$hook,
		static function ( $meta_id, $object_id, $meta_key ) {
			if ( ! in_array( $meta_key, array( '_elementor_data', '_elementor_page_settings', '_elementor_conditions' ), true ) ) {
				return;
			}
			$post = get_post( (int) $object_id );
			if ( $post instanceof \WP_Post && 'elementor_library' === $post->post_type && 'publish' === $post->post_status ) {
				Refresh::all();
			}
		},
		10,
		3
	);
}
