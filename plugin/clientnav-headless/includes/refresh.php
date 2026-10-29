<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the front end what changed, so it rebuilds those pages:
 * POST <refresh address>/api/revalidate   Authorization: Bearer <secret>
 *   {"paths": ["/a/", "/b/"], "path": "/a/", "gone": [12]}   or   {"all": true, "gone": [12]}
 * "path" is the first of "paths", for front ends that read only one; "gone" lists entries that left the public
 * site (trashed, deleted, unpublished), so a search engine kept by the front end drops them.
 *
 * Calls are collected during the request and sent once at its very end, after the answer has gone back to
 * the editor's browser, so saving never waits for the front end. The call waits for its answer, so it also
 * follows the redirect a front end sends when it wants the address with a closing slash. Nothing is sent
 * while WordPress imports (WP_IMPORTING); the door's kept answers are dropped in every case (kept.php).
 * Client Navigator Performance gets the same paths for its copies at the edge (cnp_purge_front).
 */
final class Refresh {
	private static array $paths = array();
	private static bool $all    = false;
	private static array $before = array();
	private static array $gone   = array();

	private static function importing(): bool {
		return defined( 'WP_IMPORTING' ) && WP_IMPORTING;
	}

	public static function path( string $path ): void {
		forget();
		delete_transient( SEARCH_INDEX_KEPT ); // something public changed: the kept search index is out of date
		if ( '' !== $path && '/' === $path[0] && ! self::importing() ) {
			self::$paths[ $path ] = true;
		}
	}

	public static function all(): void {
		forget();
		delete_transient( SEARCH_INDEX_KEPT );
		if ( self::importing() ) {
			return;
		}
		if ( ! self::$all ) {
			// Not a post being published, so Client Navigator Performance would not notice by itself:
			// its copies of the front end at the edge go too ('*' empties the whole Cloudflare zone, so only here).
			do_action( 'cnp_purge_front', '*' );
		}
		self::$all = true;
	}

	/** An entry that left the public site. */
	public static function gone( int $post_id ): void {
		if ( ! self::importing() ) {
			self::$gone[ $post_id ] = true;
		}
	}

	/** Hands Performance the paths before it sends its own purge (shutdown, priority 5). */
	public static function purge_edge(): void {
		if ( self::$paths && ! self::$all ) {
			do_action( 'cnp_purge_front', array_keys( self::$paths ) );
		}
	}

	/** What will be sent, for tests and for the Client Navigator ability that shows the plugin's state. */
	public static function pending(): array {
		return array( 'all' => self::$all, 'paths' => array_keys( self::$paths ), 'gone' => array_keys( self::$gone ) );
	}

	/** Remembers where an entry lived before it is changed, so its old address is refreshed too. */
	public static function remember( int $post_id ): void {
		$post = get_post( $post_id );
		// The first look counts: when WordPress moves an entry to the bin it renames it ("…__trashed") before the
		// second look, and the old address is the one to refresh.
		if ( $post && 'publish' === $post->post_status && ! isset( self::$before[ $post_id ] ) ) {
			self::$before[ $post_id ] = self::addresses( $post );
		}
	}

	private static function addresses( \WP_Post $post ): array {
		$out = array( to_path( (string) get_permalink( $post ) ) );
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( ! $tax->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax->name );
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				$link = get_term_link( $t );
				if ( ! is_wp_error( $link ) ) {
					$out[] = to_path( $link );
				}
			}
		}
		return $out;
	}

	public static function saved( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ! in_array( $post->post_type, public_types(), true ) ) {
			return;
		}
		$was = self::$before[ $post_id ] ?? array();
		if ( 'publish' !== $post->post_status && ! $was ) {
			return; // A draft nobody could see: nothing to refresh.
		}
		if ( 'publish' !== $post->post_status || ! get_post( $post_id ) ) {
			self::gone( $post_id );
		}
		foreach ( array_merge( $was, 'publish' === $post->post_status ? self::addresses( $post ) : array() ) as $p ) {
			self::path( $p );
		}
		self::path( '/' );
		$blog = (int) get_option( 'page_for_posts' );
		if ( $blog ) {
			self::path( to_path( (string) get_permalink( $blog ) ) );
		}
	}

	public static function send(): void {
		$url    = refresh_url();
		$secret = setting( 'secret' );
		if ( ( ! self::$all && ! self::$paths ) || '' === $url || strlen( $secret ) < 16 ) {
			return;
		}
		$paths = array_keys( self::$paths );
		$body  = self::$all ? array( 'all' => true ) : array( 'paths' => $paths, 'path' => $paths[0] );
		if ( self::$gone ) {
			$body['gone'] = array_keys( self::$gone );
		}
		// A request sent "without waiting" is often cut off before it leaves; finishing the page first and then waiting is reliable.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		$answer = wp_remote_post(
			$url . '/api/revalidate',
			array(
				'timeout' => 4,
				'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $secret ),
				'body'    => wp_json_encode( $body ),
			)
		);
		// The last call, for Client Navigator's status of this plugin (abilities.php).
		remember_refresh( array( 'at' => gmdate( 'c' ), 'code' => is_wp_error( $answer ) ? 0 : (int) wp_remote_retrieve_response_code( $answer ), 'all' => self::$all, 'paths' => count( self::$paths ), 'gone' => count( self::$gone ), 'error' => is_wp_error( $answer ) ? $answer->get_error_message() : '' ) );
		if ( is_wp_error( $answer ) || 200 !== wp_remote_retrieve_response_code( $answer ) ) {
			error_log( 'clientnav-headless: the front end did not take the refresh: ' . ( is_wp_error( $answer ) ? $answer->get_error_message() : wp_remote_retrieve_response_code( $answer ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		self::$paths = array();
		self::$all   = false;
		self::$gone  = array();
	}
}

add_action( 'pre_post_update', array( Refresh::class, 'remember' ) );
add_action( 'wp_trash_post', array( Refresh::class, 'remember' ) );
add_action( 'before_delete_post', array( Refresh::class, 'remember' ) );
add_action( 'wp_after_insert_post', array( Refresh::class, 'saved' ), 10, 2 );
add_action(
	'deleted_post',
	static function ( $post_id, $post ) {
		Refresh::saved( (int) $post_id, $post );
	},
	10,
	2
);
// Menus, terms and the site's name appear on every page.
// Sticky entries can stand on every list page.
foreach ( array( 'wp_update_nav_menu', 'wp_delete_nav_menu', 'edited_term', 'delete_term', 'update_option_blogname', 'update_option_blogdescription', 'update_option_permalink_structure', 'update_option_sticky_posts' ) as $hook ) {
	add_action( $hook, array( Refresh::class, 'all' ) );
}
// A menu put in another place (Appearance > Menus > Manage Locations, or wp menu location assign) changes every page too:
// WordPress keeps the places in the theme's mods, not in a menu, so none of the menu hooks above fire.
foreach ( array( 'added_option', 'updated_option' ) as $hook ) {
	add_action(
		$hook,
		static function ( $option ) {
			if ( 0 === strpos( (string) $option, 'theme_mods_' ) ) {
				Refresh::all();
			}
		}
	);
}
// Elementor emptied its CSS folder (Regenerate CSS, its own update, the kit saved): it writes the files again only when
// a page is drawn, so the front end must ask again or it keeps naming files that are gone (a site, 2026-10-16).
add_action( 'elementor/core/files/clear_cache', array( Refresh::class, 'all' ) );
add_action( 'shutdown', array( Refresh::class, 'purge_edge' ), 1 );
add_action( 'shutdown', array( Refresh::class, 'send' ) );
