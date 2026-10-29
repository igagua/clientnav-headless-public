<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * Keeping the door's answers.
 *
 * Every answer of the read-only routes is kept for ten minutes inside WordPress, under a key made of the route,
 * its question and the "changed" counter. Anything public that changes moves the counter on (refresh.php), so a
 * kept answer is never older than the content. At a cold start only one request works an answer out; the others
 * asking the same at that moment get the last answer given (kept a day). On a large news site, 250 requests running the
 * same heavy list query at once stalled the database on 2026-10-13; this is the cure that worked there.
 */
const KEPT_MINUTES = 10;

function changed(): int {
	return (int) get_option( 'clientnav_headless_changed', 0 );
}

/** Moves the counter on. Always a new value, also for two changes within a second. */
function forget(): void {
	update_option( 'clientnav_headless_changed', max( time(), 1 + changed() ), true );
	tidy();
}

/**
 * Kept answers in wp_options, at most this many (filter clientnav_headless_kept_max). Without an object cache every
 * answer is a row there, and a crawler asks for every page: a site had 241,000 rows (8.3 GB) on 2026-10-18,
 * because expired rows went only when the content changed, 5,000 at a time. With an object cache (Redis) the answers
 * live there and its own memory limit applies.
 */
const KEPT_MAX = 2000;

/** How often, in seconds, the rows are looked over: after a change, or after an answer was kept. */
const TIDY_SECONDS = 60;

function kept_max(): int {
	return max( 100, (int) apply_filters( 'clientnav_headless_kept_max', KEPT_MAX ) );
}

/**
 * Expired kept answers stay as rows in wp_options until something removes them, and WordPress does that once a day.
 * So this plugin's own expired rows go at most a minute after they expire (whenever an answer is kept or the content
 * changes), and the oldest go when there are more than kept_max(). A long backlog goes a second's work at a time
 * ($seconds; 0 = until done, as `wp clientnav-headless tidy` does once after an update on a site with a pile).
 */
function tidy( float $seconds = 1.0, bool $now = false ): array {
	$gone = array( 'expired' => 0, 'over' => 0 );
	if ( wp_using_ext_object_cache() || ( ! $now && time() - (int) get_option( 'clientnav_headless_tidied', 0 ) < TIDY_SECONDS ) ) {
		return $gone;
	}
	update_option( 'clientnav_headless_tidied', time(), false );
	global $wpdb;
	$until   = $seconds > 0 ? microtime( true ) + $seconds : INF;
	$timeout = $wpdb->esc_like( '_transient_timeout_cnh_' ) . '%';
	// Locks left by requests that died (a lock is a minute at most).
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( 'cnh_lock_' ) . '%', time() - 60 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	do {
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 1000", $timeout, time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		drop_kept( $names );
		$gone['expired'] += count( $names );
	} while ( 1000 === count( $names ) && microtime( true ) < $until );
	$over = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout ) ) - kept_max(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $over > 0 ) {
		// Down to four fifths of the most, so this does not run again at the next answer; the soonest to expire go first.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY CAST(option_value AS UNSIGNED) LIMIT %d", $timeout, $over + intdiv( kept_max(), 5 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array_chunk( $names, 1000 ) as $chunk ) {
			if ( microtime( true ) >= $until ) {
				break; // the rest at the next look, a minute on
			}
			drop_kept( $chunk );
			$gone['over'] += count( $chunk );
		}
	}
	return $gone;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	// wp clientnav-headless tidy: removes every expired kept answer and the ones over the most, at once.
	\WP_CLI::add_command(
		'clientnav-headless tidy',
		static function () {
			$gone = tidy( 0, true );
			\WP_CLI::success( sprintf( '%d expired and %d over the most removed; %s', $gone['expired'], $gone['over'], wp_using_ext_object_cache() ? 'answers are kept in the object cache here' : 'see OPTIMIZE TABLE to give the space back' ) );
		}
	);
}

/** Removes kept answers, given the names of their timeout rows. */
function drop_kept( array $timeouts ): void {
	if ( ! $timeouts ) {
		return;
	}
	global $wpdb;
	$names = array_merge( $timeouts, array_map( static fn( string $n ): string => '_transient_' . substr( $n, strlen( '_transient_timeout_' ) ), $timeouts ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (" . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')', ...$names ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $names as $name ) {
		wp_cache_delete( $name, 'options' ); // this request may have read them already
	}
}

/**
 * Whether a route's last answer is also kept for a day, for the moment right after a change when many ask the same
 * question at once. Lists are; a single entry (/resolve, /where) is cheap to work out (14 ms on a 270,000-post site)
 * and there are as many of them as the site has pages, so a day-long copy of each would only fill wp_options.
 */
function keeps_last( string $name, array $question ): bool {
	return (bool) apply_filters( 'clientnav_headless_keep_last', ! in_array( $name, array( 'resolve', 'where' ), true ), $name, $question );
}

/** The answer to a question, kept; $make works it out when there is none. Only arrays are kept. */
function kept( string $name, array $question, callable $make ) {
	if ( ! apply_filters( 'clientnav_headless_keep', true, $name, $question ) ) {
		return $make();
	}
	$id   = md5( $name . '|' . wp_json_encode( $question ) );
	$key  = 'cnh_' . md5( $id . '|' . changed() . '|' . VERSION );
	$last = 'cnh_last_' . $id;
	$data = get_transient( $key );
	if ( is_array( $data ) ) {
		return $data;
	}
	$keep_last = keeps_last( $name, $question );
	if ( ! lock( $key ) && $keep_last ) {
		$old = get_transient( $last );
		if ( is_array( $old ) ) {
			return $old;
		}
	}
	try {
		$data = $make();
	} finally {
		unlock( $key );
	}
	if ( is_array( $data ) ) {
		set_transient( $key, $data, KEPT_MINUTES * MINUTE_IN_SECONDS );
		if ( $keep_last ) {
			set_transient( $last, $data, DAY_IN_SECONDS );
		}
		tidy();
	}
	return $data;
}

/** Only one request may work out an answer at a time. A lock older than a minute was left by a request that died. */
function lock( string $key ): bool {
	if ( wp_using_ext_object_cache() ) {
		return wp_cache_add( 'lock_' . $key, 1, 'clientnav_headless', 60 );
	}
	global $wpdb;
	$name = 'cnh_lock_' . substr( $key, 4 );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d", $name, time() - 60 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (bool) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

function unlock( string $key ): void {
	if ( wp_using_ext_object_cache() ) {
		wp_cache_delete( 'lock_' . $key, 'clientnav_headless' );
		return;
	}
	global $wpdb;
	$wpdb->delete( $wpdb->options, array( 'option_name' => 'cnh_lock_' . substr( $key, 4 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * A route whose answers are kept here. Nothing on the way (Cloudflare) may keep them: right after a publish the
 * front end asks again and must get the new answer, or it would keep the old one for its own five minutes.
 */
function kept_route( string $name, callable $route ): callable {
	return static function ( \WP_REST_Request $r ) use ( $name, $route ) {
		$question = $r->get_query_params();
		ksort( $question );
		$out = kept( $name, $question, static fn() => $route( $r ) );
		if ( ! is_array( $out ) ) {
			return $out;
		}
		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'no-cache' );
		return $response;
	};
}

// What changes every answer without a post being saved: the plugin's own settings and the site's settings the door shows.
// An option saved for the first time is "added", not "updated"; one removed is "deleted": all three count.
foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
	add_action(
		$hook,
		static function ( $option ) {
			if ( in_array( $option, array( 'clientnav_headless', 'page_on_front', 'show_on_front', 'page_for_posts', 'posts_per_page', 'category_base', 'tag_base', 'home', 'siteurl' ), true ) || 0 === strpos( (string) $option, 'theme_mods_' ) ) {
				forget();
			}
		}
	);
}
// A field or picture of a public entry changed without the entry being saved. Editing locks and other bookkeeping
// that WordPress writes all the time while someone edits do not count.
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
	add_action(
		$hook,
		static function ( $meta_id, $object_id, $meta_key ) {
			$key = is_string( $meta_key ) ? $meta_key : '';
			if ( in_array( $key, array( '_edit_lock', '_edit_last', '_encloseme', '_pingme', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug' ), true ) ) {
				return;
			}
			$post = get_post( (int) $object_id );
			if ( $post && 'publish' === $post->post_status && in_array( $post->post_type, public_types(), true ) ) {
				forget();
			}
		},
		10,
		3
	);
}
// Other plugins say "these front end pages changed" through Performance's action (Translate does, after a new
// translation): the door's kept answers go too.
add_action( 'cnp_purge_front', __NAMESPACE__ . '\\forget' );
foreach ( array( 'wp_update_nav_menu_item', 'wp_add_nav_menu_item', 'created_term', 'set_object_terms', 'profile_update', 'edit_attachment' ) as $hook ) {
	add_action( $hook, __NAMESPACE__ . '\\forget' );
}
