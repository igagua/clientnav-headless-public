<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * What Client Navigator can ask of this plugin, through the Connector (which logs every run): its state, and a
 * pairing code. Named so they do not clash with the Connector's own clientnav/headless-status and -set, which
 * hold the site-wide front end address (platform chat, 2026-10-14).
 */
add_filter(
	'clientnav_abilities',
	static function ( $abilities ) {
		return (array) $abilities + array(
			'clientnav/headless-plugin-status' => array(
				'label'       => 'Headless plugin status',
				'group'       => 'site',
				'write'       => false,
				'description' => 'Client Navigator Headless (the content door a separate front end reads): its version, the front end it serves and where that address comes from, whether it is paired (shared secret set) and the last pair check, whether the door and WordPress\'s own REST API are closed, whether the Client Navigator blocks and the search index are on, which types it shows and which count as very big, whether PR Register and Translate are there, and the last refresh calls to the front end.',
				'input'       => array(),
				'run'         => __NAMESPACE__ . '\\status',
			),
			'clientnav/headless-check'         => array(
				'label'       => 'Check the front end',
				'group'       => 'site',
				'write'       => false,
				'description' => 'Client Navigator Headless: checks the pair without side effects: whether the front end answers, and whether it holds the same shared secret (a signed preview link for an entry that does not exist: accepted means the same secret). Nothing is refreshed or kept on the front end.',
				'input'       => array(),
				'run'         => __NAMESPACE__ . '\\pair_check',
			),
			'clientnav/headless-refresh'       => array(
				'label'       => 'Refresh the front end',
				'group'       => 'site',
				'write'       => true,
				'description' => 'Client Navigator Headless: forgets every kept answer of the door and tells the front end to refresh every page (and Client Navigator Performance to empty the edge copies), as a saved menu or template does. For a change the door did not notice, such as code or a setting written straight to the database.',
				'input'       => array(),
				'run'         => static function (): array {
					Refresh::all();
					return array( 'refreshed' => true, 'frontend' => frontend_url(), 'refresh_url' => refresh_url() );
				},
			),
			'clientnav/headless-pair'          => array(
				'label'       => 'Pair a front end',
				'group'       => 'site',
				'write'       => true,
				'description' => 'Client Navigator Headless: makes a one-time pairing code, good for 15 minutes and one use, and makes the shared secret first if there is none. The front end\'s machine sends the code to the address given (POST, {"code": ...}) and gets the secret back; nobody copies the secret. The code is shown once; asking again closes the old one.',
				'input'       => array(),
				'run'         => static fn() => array( 'code' => pairing_code(), 'minutes' => PAIRING_MINUTES, 'send_to' => rest_url( REST_NS . '/pair' ) ),
			),
		);
	}
);

function status(): array {
	$types = public_types();
	return array(
		'version'      => VERSION,
		'frontend'     => frontend_url(),
		'from'         => '' !== connector_front_url() ? ( function_exists( 'clientnav_front_url' ) && '' !== (string) \clientnav_front_url() ? 'Client Navigator Connector' : 'CLIENTNAV_FRONT_URL' ) : ( '' !== frontend_url() ? 'this plugin\'s own setting' : 'none' ),
		'refresh_url'  => refresh_url(),
		'paired'       => strlen( setting( 'secret' ) ) >= 32,
		'blocks'       => blocks_on(),
		'search_index' => search_index_on(),
		'media_paths'  => media_paths(),
		'types'        => $types,
		'very_big'     => array_values( array_filter( $types, static fn( $t ) => is_big( array( $t ) ) ) ),
		'pr_register'  => is_callable( array( '\PRRegister\Headless', 'head' ) ),
		'translate'    => class_exists( '\ClientNav\Translate\Api' ),
		'changed'      => changed() ? gmdate( 'c', changed() ) : null,
		'door'         => door_closed() ? 'closed' : 'open',
		'rest'         => rest_closed() ? 'closed' : 'open',
		'last_refresh' => get_option( 'clientnav_headless_last_refresh', null ),
		'refreshes'    => refresh_history(),
		'last_check'   => get_option( 'clientnav_headless_last_check', null ),
	);
}
