<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * Settings. Each one can be fixed in wp-config.php, which then wins over the settings page:
 * CLIENTNAV_HEADLESS_REFRESH (address WordPress calls to refresh pages, when it differs), CLIENTNAV_HEADLESS_SECRET.
 *
 * The front end's address is not this plugin's own fact: the Client Navigator Connector (0.45.0 and later) holds
 * it for the whole site (constant CLIENTNAV_FRONT_URL or the ability clientnav/headless-set) and every plugin
 * reads it with clientnav_front_url(). On a site whose Connector is older, switched off, or names no front end,
 * the order shared by the Client Navigator plugins applies: constant CLIENTNAV_FRONT_URL, then the own value
 * (CLIENTNAV_HEADLESS_FRONTEND or the settings page), then the filter clientnav_front_url over the result.
 */
function setting( string $key ): string {
	$const = 'CLIENTNAV_HEADLESS_' . strtoupper( $key );
	if ( defined( $const ) ) {
		return (string) constant( $const );
	}
	$all = get_option( 'clientnav_headless', array() );
	return is_array( $all ) && isset( $all[ $key ] ) ? (string) $all[ $key ] : '';
}

/** The front end's address as the site gives it to every plugin (Connector, or the shared constant); '' when it gives none. */
function connector_front_url(): string {
	$shared = function_exists( 'clientnav_front_url' ) ? untrailingslashit( trim( (string) \clientnav_front_url() ) ) : '';
	if ( '' === $shared && defined( 'CLIENTNAV_FRONT_URL' ) ) {
		$shared = untrailingslashit( trim( (string) CLIENTNAV_FRONT_URL ) );
	}
	return $shared;
}

function frontend_url(): string {
	$shared = connector_front_url();
	if ( '' !== $shared ) {
		return $shared;
	}
	return untrailingslashit( trim( (string) apply_filters( 'clientnav_front_url', untrailingslashit( setting( 'frontend' ) ) ) ) );
}

function refresh_url(): string {
	$own = untrailingslashit( setting( 'refresh' ) );
	return '' !== $own ? $own : frontend_url();
}

/**
 * Whether this site's editor offers the Client Navigator page blocks. A site with a design of its own that does not
 * use them switches them off (settings page: "off"; or CLIENTNAV_HEADLESS_BLOCKS set to 'off' in wp-config.php).
 */
function blocks_on(): bool {
	return (bool) apply_filters( 'clientnav_headless_blocks', ! in_array( strtolower( trim( setting( 'blocks' ) ) ), array( 'off', '0', 'no' ), true ) );
}

/** Whether the door hands out the search index (every entry's words in one answer). Meant for small sites. */
function search_index_on(): bool {
	return (bool) apply_filters( 'clientnav_headless_search_index', ! in_array( strtolower( trim( setting( 'search_index' ) ) ), array( 'off', '0', 'no' ), true ) );
}

/**
 * Whether files in /wp-content/ are given as paths: for a front end that passes those addresses on to WordPress
 * itself, so pictures appear under its own name (a large news site, a dental practice site do). Setting "Pictures and
 * files" = "paths", or CLIENTNAV_HEADLESS_MEDIA = 'paths'.
 */
function media_paths(): bool {
	return (bool) apply_filters( 'clientnav_headless_media_paths', 'paths' === strtolower( trim( setting( 'media' ) ) ) );
}

/**
 * Editors set WordPress's Site Address to the front end's (so "View" leads to the public page); WordPress would then
 * write its REST and preview links on the front end's host, where no WordPress answers. Those links stay on the
 * WordPress Address. Changing the setting back is not a cure: on a large news site it was set again within 20 minutes.
 */
function on_wordpress( string $url ): string {
	$home  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$site  = strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) );
	$front = strtolower( (string) wp_parse_url( frontend_url(), PHP_URL_HOST ) );
	$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $home || '' === $site || $home === $site || $home !== $front || $host !== $home ) {
		return $url;
	}
	// The WordPress Address with its scheme and port, in place of the front end's.
	$origin = preg_match( '#^https?://[^/]+#i', site_url(), $m ) ? $m[0] : '';
	return '' === $origin ? $url : (string) preg_replace( '#^https?://[^/]+#i', $origin, $url );
}
add_filter( 'rest_url', __NAMESPACE__ . '\\on_wordpress' );
add_filter( 'preview_post_link', static fn( $link ) => on_wordpress( (string) $link ), 5 );

/** Hosts whose links count as this site's own and are turned into paths. */
function own_hosts(): array {
	$hosts = array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) );
	if ( '' !== frontend_url() ) {
		$hosts[] = wp_parse_url( frontend_url(), PHP_URL_HOST );
	}
	if ( '' !== untrailingslashit( setting( 'account' ) ) ) {
		$hosts[] = wp_parse_url( setting( 'account' ), PHP_URL_HOST );
	}
	foreach ( preg_split( '/[\s,]+/', setting( 'hosts' ) ) as $h ) {
		$hosts[] = strtolower( trim( $h ) );
	}
	return array_values( array_unique( array_filter( (array) apply_filters( 'clientnav_headless_own_hosts', $hosts ) ) ) );
}

/*
 * The Connector (0.45.0 and later, class ClientNav_Headless) keeps its front-end address in this same option, as
 * array( 'front_url' => … ), and its clientnav/headless-set writes that array whole. Before 0.26.1 that wiped the
 * shared secret and every other setting here (a site 2026-10-15, a site 2026-10-28: both
 * unpaired). A write holding only front_url now keeps this plugin's settings, and a save of this plugin's settings
 * keeps the Connector's front_url. Switching headless off in the Connector still deletes the option.
 */
add_filter(
	'pre_update_option_clientnav_headless',
	static function ( $value, $old ) {
		if ( ! is_array( $value ) || ! is_array( $old ) ) {
			return $value;
		}
		if ( array( 'front_url' ) === array_keys( $value ) ) {
			return $value + $old;
		}
		if ( isset( $old['front_url'] ) && ! array_key_exists( 'front_url', $value ) ) {
			$value['front_url'] = $old['front_url'];
		}
		return $value;
	},
	10,
	2
);

add_action(
	'admin_init',
	static function () {
		register_setting(
			'clientnav_headless',
			'clientnav_headless',
			array(
				'type'              => 'array',
				'sanitize_callback' => static function ( $in ) {
					$in = is_array( $in ) ? $in : array();
					if ( '' !== connector_front_url() ) {
						// The field then only shows the Connector's value; the own one stays as it was.
						$in['frontend'] = setting( 'frontend' );
					}
					return array(
						'frontend' => esc_url_raw( trim( (string) ( $in['frontend'] ?? '' ) ) ),
						'refresh'  => esc_url_raw( trim( (string) ( $in['refresh'] ?? '' ) ) ),
						'hosts'    => sanitize_text_field( (string) ( $in['hosts'] ?? '' ) ),
						'editor_css' => wp_strip_all_tags( (string) ( $in['editor_css'] ?? '' ) ),
						'media'        => 'paths' === strtolower( trim( (string) ( $in['media'] ?? '' ) ) ) ? 'paths' : '',
						'door'         => 'closed' === strtolower( trim( (string) ( $in['door'] ?? '' ) ) ) ? 'closed' : '',
						'rest'         => 'closed' === strtolower( trim( (string) ( $in['rest'] ?? '' ) ) ) ? 'closed' : '',
						'search_index' => in_array( strtolower( trim( (string) ( $in['search_index'] ?? '' ) ) ), array( 'off', '0', 'no' ), true ) ? 'off' : '',
						'blocks'     => in_array( strtolower( trim( (string) ( $in['blocks'] ?? '' ) ) ), array( 'off', '0', 'no' ), true ) ? 'off' : '',
						'account'          => esc_url_raw( trim( (string) ( $in['account'] ?? '' ) ) ),
						'keep'             => sanitize_text_field( (string) ( $in['keep'] ?? '' ) ),
						'send_visitors'    => empty( $in['send_visitors'] ) ? '' : '1',
						'inquiry_to'       => sanitize_text_field( (string) ( $in['inquiry_to'] ?? '' ) ),
						'newsletter_lists' => sanitize_text_field( (string) ( $in['newsletter_lists'] ?? '' ) ),
						'newsletter_tags'  => sanitize_text_field( (string) ( $in['newsletter_tags'] ?? '' ) ),
						'secret'   => preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $in['secret'] ?? '' ) ),
					);
				},
			)
		);
	}
);

add_action(
	'admin_menu',
	static function () {
		add_options_page( 'Client Navigator Headless', 'Headless', 'manage_options', 'clientnav-headless', __NAMESPACE__ . '\\settings_page' );
	}
);

function settings_page(): void {
	$fields = array(
		'frontend' => array( 'Front end address', 'The public address of the site visitors see, for example https://www.example.com. With Client Navigator Connector 0.45.0 or later it is set there, once for all plugins.' ),
		'refresh'  => array( 'Refresh address', 'Only when WordPress reaches the front end at another address (same machine: http://127.0.0.1:3300). Empty: the front end address.' ),
		'hosts'    => array( 'Other own hosts', 'Hosts, separated by commas, whose links inside the content also become paths on the front end (an old address of this site).' ),
		'blocks'   => array( 'Client Navigator blocks', 'Type off to keep the Client Navigator page blocks out of this site\'s editor, for a site with its own design that does not use them. Empty: the blocks are offered.' ),
		'door'     => array( 'Door', 'Type closed so the content door answers only the front end\'s server (it sends the shared secret). Empty: open, anyone may read what the site publishes.' ),
		'rest'     => array( 'WordPress REST API', 'Type closed to refuse anonymous requests to WordPress\'s own content interface once the front end reads everything through the door. Editors signed in, Client Navigator, PR Register and Translate keep working.' ),
		'media'    => array( 'Pictures and files', 'Type paths when the front end passes /wp-content/ on to WordPress itself, so pictures and files are given as paths on its own address. Empty: full WordPress addresses.' ),
		'search_index' => array( 'Search index', 'Type off when the front end does not use the door\'s search index (all entries\' words in one answer, meant for small sites). Empty: the index is handed out.' ),
		'account'          => array( 'Account address', 'Where WordPress itself answers visitors for the parts it keeps (shop, checkout, account, sign-in), for example https://account.example.com. Empty: nothing is kept apart.' ),
		'keep'             => array( 'Kept by WordPress', 'Paths, separated by commas, that WordPress runs itself. Empty: account, shop, cart, checkout, receipt, review-your-order, signup, item.' ),
		'send_visitors'    => array( 'Send visitors to the front end', 'Type 1 to send anyone who opens another page on WordPress itself to the same page on the front end. Signed-in people are left alone.' ),
		'inquiry_to'       => array( 'Inquiries go to', 'E-mail address (or several, separated by commas) that receives the inquiry form. Empty: the site administrator.' ),
		'newsletter_lists' => array( 'Newsletter: FluentCRM lists', 'List numbers, separated by commas. Used when FluentCRM is active.' ),
		'newsletter_tags'  => array( 'Newsletter: FluentCRM tags', 'Tag numbers, separated by commas.' ),
		'secret'   => array( 'Shared secret', 'The same value as REVALIDATE_SECRET on the front end. At least 16 characters.' ),
	);
	echo '<div class="wrap"><h1>Client Navigator Headless</h1><form method="post" action="options.php">';
	settings_fields( 'clientnav_headless' );
	echo '<table class="form-table" role="presentation">';
	foreach ( $fields as $key => $f ) {
		$shared = 'frontend' === $key && '' !== connector_front_url();
		$fixed  = $shared || defined( 'CLIENTNAV_HEADLESS_' . strtoupper( $key ) );
		$value  = $shared ? connector_front_url() : ( 'secret' === $key && $fixed ? '(set in wp-config.php)' : setting( $key ) );
		printf(
			'<tr><th scope="row"><label for="cnh-%1$s">%2$s</label></th><td><input class="regular-text" id="cnh-%1$s" name="clientnav_headless[%1$s]" type="%5$s" value="%3$s" %4$s autocomplete="off"><p class="description">%6$s%7$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $f[0] ),
			esc_attr( $value ),
			$fixed ? 'readonly' : '',
			'secret' === $key && ! $fixed ? 'password' : 'text',
			esc_html( $f[1] ),
			$shared ? ' <strong>Set for the whole site (Client Navigator Connector or CLIENTNAV_FRONT_URL).</strong>' : ( $fixed ? ' <strong>Fixed in wp-config.php.</strong>' : '' )
		);
	}
	echo '</table>';
	submit_button();
	printf( '</form><p>Content door: <code>%s</code></p>', esc_html( rest_url( REST_NS . '/site' ) ) );
	state_section();
	pairing_section();
	echo '</div>';
}
