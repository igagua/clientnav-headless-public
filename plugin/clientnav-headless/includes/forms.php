<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * Forms of the front end.
 *
 * The visitor's browser talks to the front end only; the front end's server hands a submission on to
 *   POST /clientnav-headless/v1/forms/<form>      Authorization: Bearer <shared secret>
 *   { "fields": { ... }, "ip": "...", "page": "/contact/" }
 * WordPress checks the values, keeps the entry (Tools > Form entries), sends the notice e-mail and, for a form
 * with a "crm" part, puts the address into FluentCRM when that plugin is there.
 *
 * Two forms come built in: "inquiry" and "newsletter". Change or add forms with the filter clientnav_headless_forms.
 */
function forms(): array {
	$ids = static fn( string $key ) => array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', setting( $key ) ) ) ) );
	return (array) apply_filters(
		'clientnav_headless_forms',
		array(
			'inquiry'    => array(
				'label'   => 'Inquiry',
				'to'      => '' !== setting( 'inquiry_to' ) ? setting( 'inquiry_to' ) : (string) get_option( 'admin_email' ),
				'subject' => 'Website inquiry',
				'success' => 'Thanks — we’ve received your message and will get back to you within one business day.',
				'fields'  => array(
					'topic'   => array( 'label' => 'Interested in', 'max' => 120 ),
					'name'    => array( 'label' => 'Name', 'required' => true, 'max' => 120 ),
					'company' => array( 'label' => 'Company', 'required' => true, 'max' => 160 ),
					'email'   => array( 'label' => 'Work email', 'type' => 'email', 'required' => true ),
					'message' => array( 'label' => 'How can we help?', 'required' => true, 'max' => 5000 ),
				),
			),
			'newsletter' => array(
				'label'   => 'Newsletter',
				'to'      => '',
				'subject' => 'Newsletter signup',
				'success' => 'Thank you. You are on the list.',
				// Said instead when the address still has to be confirmed by e-mail.
				'confirm' => 'Please check your inbox to confirm your subscription.',
				'fields'  => array(
					'email' => array( 'label' => 'Email', 'type' => 'email', 'required' => true ),
				),
				'crm'     => array( 'lists' => $ids( 'newsletter_lists' ), 'tags' => $ids( 'newsletter_tags' ), 'double_optin' => true ),
			),
		)
	);
}

add_action(
	'init',
	static function () {
		register_post_type(
			'cn_form_entry',
			array(
				'labels'              => array( 'name' => 'Form entries', 'singular_name' => 'Form entry' ),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'tools.php',
				'exclude_from_search' => true,
				'supports'            => array( 'title', 'editor' ),
				'capability_type'     => 'page',
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
			)
		);
	}
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			REST_NS,
			'/forms/(?P<form>[a-z0-9-]+)',
			array(
				'methods'             => 'POST',
				// Only the front end's server may hand in a submission.
				'permission_callback' => static function ( \WP_REST_Request $r ) {
					$secret = setting( 'secret' );
					$given  = preg_replace( '/^Bearer\s+/i', '', (string) $r->get_header( 'authorization' ) );
					return strlen( $secret ) >= 16 && hash_equals( $secret, (string) $given );
				},
				'callback'            => __NAMESPACE__ . '\\rest_form',
			)
		);
	}
);

function rest_form( \WP_REST_Request $r ) {
	$forms = forms();
	$key   = (string) $r['form'];
	if ( ! isset( $forms[ $key ] ) ) {
		return new \WP_REST_Response( array( 'ok' => false, 'error' => 'No such form.' ), 404 );
	}
	$form = $forms[ $key ];
	$body = (array) $r->get_json_params();
	$in   = (array) ( $body['fields'] ?? array() );

	// No more than six submissions in ten minutes from one address.
	$ip = sanitize_text_field( (string) ( $body['ip'] ?? '' ) );
	if ( '' !== $ip ) {
		$slot  = 'cnh_form_' . md5( $ip );
		$count = (int) get_transient( $slot );
		if ( $count >= 6 ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'Too many messages in a short time. Please try again in a few minutes.' ), 429 );
		}
		set_transient( $slot, $count + 1, 10 * MINUTE_IN_SECONDS );
	}

	// Client Navigator Anti-Spam, when it is on for this site in headless mode. It judges the check fields the browser
	// added and Turnstile, and logs and counts by itself. A refused submission is not kept anywhere: forms can carry
	// patient data. Connector 0.46 and later: this plugin vouches for the request itself (it came with the shared
	// secret), so the front end signs nothing. Before that: Anti-Spam checks its own signature on this same request
	// (the front end's server adds X-ClientNav-Frontend-* headers over the raw body).
	$visitor = sanitize_text_field( (string) ( $body['ip'] ?? '' ) );
	$from    = sanitize_text_field( mb_substr( (string) ( $body['page'] ?? '' ), 0, 300 ) );
	$flat    = array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $in );
	if ( function_exists( 'clientnav_antispam_check' ) ) {
		$verdict = (array) \clientnav_antispam_check( $flat, array( 'ip' => $visitor, 'page' => $from ) );
		if ( empty( $verdict['ok'] ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => (string) ( $verdict['message'] ?? 'This could not be sent.' ) ), 422 );
		}
	} elseif ( class_exists( 'ClientNav_AntiSpam_Guard' ) ) {
		if ( class_exists( 'ClientNav_AntiSpam_Log' ) && property_exists( 'ClientNav_AntiSpam_Log', 'page' ) ) {
			\ClientNav_AntiSpam_Log::$page = $from;
		}
		$refusal = \ClientNav_AntiSpam_Guard::refuse( 'headless', $flat );
		if ( null !== $refusal ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => (string) $refusal ), 422 );
		}
	}

	$values = array();
	$errors = array();
	foreach ( $form['fields'] as $name => $def ) {
		$raw   = is_scalar( $in[ $name ] ?? '' ) ? (string) ( $in[ $name ] ?? '' ) : '';
		$value = 'message' === $name ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		$value = mb_substr( $value, 0, (int) ( $def['max'] ?? 254 ) );
		if ( ! empty( $def['required'] ) && '' === $value ) {
			$errors[ $name ] = $def['label'] . ' is needed.';
		} elseif ( 'email' === ( $def['type'] ?? '' ) && '' !== $value && ! is_email( $value ) ) {
			$errors[ $name ] = 'That e-mail address does not look right.';
		}
		$values[ $name ] = $value;
	}
	if ( $errors ) {
		return new \WP_REST_Response( array( 'ok' => false, 'error' => 'Please check the marked fields.', 'fields' => $errors ), 422 );
	}

	$page  = sanitize_text_field( mb_substr( (string) ( $body['page'] ?? '' ), 0, 300 ) );
	$lines = array();
	foreach ( $form['fields'] as $name => $def ) {
		if ( '' !== $values[ $name ] ) {
			$lines[] = $def['label'] . ': ' . ( 'message' === $name ? "\n" . $values[ $name ] : $values[ $name ] );
		}
	}
	if ( '' !== $page ) {
		$lines[] = 'Sent from: ' . ( '' !== frontend_url() ? frontend_url() : '' ) . $page;
	}
	$text = implode( "\n", $lines );
	$who  = '' !== ( $values['name'] ?? '' ) ? $values['name'] . ' (' . ( $values['email'] ?? '' ) . ')' : (string) ( $values['email'] ?? '' );

	$entry = wp_insert_post(
		array(
			'post_type'    => 'cn_form_entry',
			'post_status'  => 'private',
			'post_title'   => $form['label'] . ': ' . $who,
			'post_content' => $text,
			'meta_input'   => array( '_cn_form' => $key, '_cn_fields' => wp_json_encode( $values ), '_cn_page' => $page ),
		)
	);

	$mailed = null;
	if ( '' !== (string) $form['to'] ) {
		$headers = array();
		if ( ! empty( $values['email'] ) ) {
			$headers[] = 'Reply-To: ' . ( '' !== ( $values['name'] ?? '' ) ? str_replace( array( '"', "\r", "\n" ), '', $values['name'] ) . ' ' : '' ) . '<' . $values['email'] . '>';
		}
		$mailed = wp_mail( $form['to'], $form['subject'], $text, $headers );
	}

	$crm = null;
	$message = (string) $form['success'];
	if ( ! empty( $form['crm'] ) && ! empty( $values['email'] ) && function_exists( 'FluentCrmApi' ) ) {
		$double  = ! empty( $form['crm']['double_optin'] );
		$contact = \FluentCrmApi( 'contacts' )->createOrUpdate(
			array(
				'email'  => $values['email'],
				'status' => $double ? 'pending' : 'subscribed',
				'lists'  => (array) $form['crm']['lists'],
				'tags'   => (array) $form['crm']['tags'],
			)
		);
		if ( $contact && $double && 'pending' === $contact->status ) {
			$contact->sendDoubleOptinEmail();
			$message = (string) ( $form['confirm'] ?? $message );
		}
		$crm = (bool) $contact;
	}

	do_action( 'clientnav_headless_form_submitted', $key, $values, (int) $entry, $r );
	return array( 'ok' => true, 'message' => $message, 'entry' => is_wp_error( $entry ) ? 0 : (int) $entry, 'mailed' => $mailed, 'crm' => $crm );
}

/**
 * What the front end's search needs: every public entry in short form.
 * An entry is left out when it carries the meta _cn_search_exclude (or the filter says so).
 */
add_action(
	'rest_api_init',
	static function () {
		// A site whose front end searches another way (a news site asks /posts?search=) leaves the index switched off.
		if ( search_index_on() ) {
			register_rest_route( REST_NS, '/search-index', array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\door_allows', 'callback' => __NAMESPACE__ . '\\rest_search_index' ) );
		}
	}
);

const SEARCH_INDEX_KEPT = 'clientnav_headless_search_index';

/**
 * The index reads every entry's text, so it is worked out once and kept: for ten minutes, or until something
 * public is saved (refresh.php drops it). Anyone can ask for it, and asking again costs nothing.
 */
function rest_search_index(): array {
	$kept = get_transient( SEARCH_INDEX_KEPT );
	if ( is_array( $kept ) && isset( $kept['items'] ) ) {
		return $kept;
	}
	$q = new \WP_Query(
		array(
			'post_type'      => public_types(),
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => 1000,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => '_cn_search_exclude', 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	// What a kind of entry is called in the results when it has no label of its own.
	$labels = (array) apply_filters( 'clientnav_headless_search_labels', array( 'post' => 'Guides', 'page' => 'Explore' ) );
	$items  = array();
	foreach ( $q->posts as $n => $post ) {
		if ( is_kept( (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ) ) || ! apply_filters( 'clientnav_headless_search_include', true, $post ) ) {
			continue;
		}
		$words = '';
		foreach ( parse_blocks( $post->post_content ) as $block ) {
			$words .= ' ' . block_words( $block );
		}
		$words = plain( $words );
		$type  = 'post' === $post->post_type ? 'post' : 'page';
		// A page can carry its own label, short text, extra words and place in the list (meta _clientnav_search_*).
		$own   = static fn( string $key ) => (string) get_post_meta( $post->ID, '_clientnav_search_' . $key, true );
		$text  = $own( 'text' );
		foreach ( array( '_prregister_description', '_cn_description', 'rank_math_description' ) as $key ) {
			$text = '' !== $text ? $text : (string) get_post_meta( $post->ID, $key, true );
		}
		$text    = '' !== $text ? $text : ( has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( $words, 24, '...' ) );
		$terms   = 'post' === $type ? implode( ' ', wp_get_post_terms( $post->ID, array( 'category', 'post_tag' ), array( 'fields' => 'names' ) ) ) : '';
		$order   = $own( 'order' );
		$items[] = array(
			'type'     => $type,
			'title'    => plain( get_the_title( $post ) ),
			'path'     => to_path( (string) get_permalink( $post ) ),
			'category' => '' !== $own( 'category' ) ? $own( 'category' ) : (string) ( $labels[ $type ] ?? '' ),
			'text'     => plain( $text ),
			'keywords' => mb_substr( trim( $terms . ' ' . $own( 'keywords' ) . ' ' . $words ), 0, 4000 ),
			'order'    => '' !== $order ? (int) $order : 1000 + $n,
			'date'     => mysql_to_rfc3339( $post->post_date_gmt ) . 'Z',
		);
	}
	usort( $items, static fn( $a, $b ) => $a['order'] <=> $b['order'] );
	$out = array( 'items' => $items );
	set_transient( SEARCH_INDEX_KEPT, $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/** The words of a block and of the blocks inside it: its text values and its own HTML. */
function block_words( array $block ): string {
	$out = implode( ' ', array_filter( array_map( static fn( $v ) => is_string( $v ) && ! preg_match( '#^(https?://|/|\#|tel:|mailto:)#', $v ) ? $v : '', $block['attrs'] ?? array() ) ) ) . ' ' . ( $block['innerHTML'] ?? '' );
	foreach ( $block['innerBlocks'] ?? array() as $child ) {
		$out .= ' ' . block_words( $child );
	}
	return $out;
}
