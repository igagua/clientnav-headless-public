<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * What a form asks, so a front end can draw it with its own markup:
 *   GET /forms/<number>   a Fluent Forms form: fields, button, words after sending, its honeypot name, Turnstile's site key
 *   GET /forms/<name>     one of this plugin's own forms (inquiry, newsletter, or those added with clientnav_headless_forms)
 *
 * Sending a Fluent Forms form stays Fluent Forms' own work: the front end's server passes the visitor's answers to
 * admin-ajax.php (action fluentform_submit), with Client Navigator Anti-Spam's signature when that is on, so
 * validation, Turnstile, the saved entry, the notice and the CRM rules all run as on any WordPress page (proven on
 * a dental practice site). Answers are not kept: a form is small and an editor's change must show at once.
 */
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			REST_NS,
			'/forms/(?P<form>[a-z0-9-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => __NAMESPACE__ . '\\door_allows',
				'callback'            => __NAMESPACE__ . '\\rest_form_described',
			)
		);
	}
);

function rest_form_described( \WP_REST_Request $r ) {
	$key  = (string) $r['form'];
	$made = preg_match( '/^\d+$/', $key ) ? fluent_form( (int) $key ) : own_form( $key );
	return $made ? $made : new \WP_REST_Response( array( 'error' => 'No such form.' ), 404 );
}

/** One of this plugin's own forms, described the same way as a Fluent Forms one. */
function own_form( string $key ): ?array {
	$form = forms()[ $key ] ?? null;
	if ( ! $form ) {
		return null;
	}
	$fields = array();
	foreach ( (array) $form['fields'] as $name => $def ) {
		$type     = (string) ( $def['type'] ?? ( 'message' === $name ? 'textarea' : 'text' ) );
		$fields[] = array( 'name' => $name, 'label' => (string) $def['label'], 'placeholder' => '', 'required' => ! empty( $def['required'] ), 'type' => $type, 'auto' => 'email' === $type ? 'email' : ( 'name' === $name ? 'name' : 'off' ) );
	}
	return array(
		'id'      => $key,
		'title'   => (string) $form['label'],
		'fields'  => $fields,
		'button'  => 'Send',
		'thanks'  => (string) $form['success'],
		'trap'    => '',
		'sitekey' => '',
		'send'    => array( 'via' => 'door', 'url' => rest_url( REST_NS . '/forms/' . $key ) ),
		// The check fields to add in the browser (names and key; nothing secret), or null while Anti-Spam's headless mode is off.
		'antispam' => function_exists( 'clientnav_antispam_frontend_config' ) ? \clientnav_antispam_frontend_config() : null,
	);
}

/** A published Fluent Forms form, or null when there is none (or Fluent Forms is not there). */
function fluent_form( int $id ): ?array {
	global $wpdb;
	if ( $id < 1 || ! defined( 'FLUENTFORM' ) ) {
		return null;
	}
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, title, form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id = %d AND status = 'published'", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$raw = $row ? json_decode( (string) $row->form_fields, true ) : null;
	if ( ! is_array( $raw ) ) {
		return null;
	}
	$settings = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = %d AND meta_key = 'formSettings' LIMIT 1", $id ) ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$button   = '';
	foreach ( (array) ( $raw['fields'] ?? array() ) as $part ) {
		if ( 'custom_submit_button' === ( $part['element'] ?? '' ) ) {
			$button = plain( (string) ( $part['settings']['button_ui']['text'] ?? '' ) );
		}
	}
	$turnstile = (array) get_option( '_fluentform_turnstile_details', array() );
	$out       = array(
		'id'      => (int) $row->id,
		'title'   => plain( (string) $row->title ),
		'fields'  => fluent_fields( (array) ( $raw['fields'] ?? array() ) ),
		'button'  => '' !== $button ? $button : plain( (string) ( $raw['submitButton']['settings']['button_ui']['text'] ?? 'Send' ) ),
		'thanks'  => plain( (string) ( is_array( $settings ) ? ( $settings['confirmation']['messageToShow'] ?? '' ) : '' ) ),
		// Fluent Forms refuses a submission unless this hidden field arrives, and arrives empty.
		'trap'    => (string) apply_filters( 'fluentform/honeypot_name', 'item_' . (int) $row->id . '__fluent_sf', (int) $row->id ),
		// Public by design: the browser needs it to show the check.
		'sitekey' => (string) ( $turnstile['siteKey'] ?? '' ),
		'send'    => array( 'via' => 'fluentform', 'url' => admin_url( 'admin-ajax.php' ), 'action' => 'fluentform_submit', 'form_id' => (int) $row->id ),
	);
	return (array) apply_filters( 'clientnav_headless_form', $out, $id, $raw );
}

/** The fields of a list of Fluent Forms parts, columns flattened. */
function fluent_fields( array $raw ): array {
	$out = array();
	foreach ( $raw as $part ) {
		if ( 'container' === ( $part['element'] ?? '' ) ) {
			foreach ( (array) ( $part['columns'] ?? array() ) as $column ) {
				$out = array_merge( $out, fluent_fields( (array) ( $column['fields'] ?? array() ) ) );
			}
			continue;
		}
		$one = fluent_field( (array) $part );
		if ( $one ) {
			$out[] = $one;
		}
	}
	return $out;
}

/** One field as a front end draws it; null for parts that are not a field (or a kind not known here: filter clientnav_headless_form). */
function fluent_field( array $raw ): ?array {
	$element = (string) ( $raw['element'] ?? '' );
	$attr    = (array) ( $raw['attributes'] ?? array() );
	$set     = (array) ( $raw['settings'] ?? array() );
	$name    = (string) ( $attr['name'] ?? '' );
	$base    = array(
		'name'        => $name,
		'label'       => plain( (string) ( $set['label'] ?? '' ) ),
		'placeholder' => plain( (string) ( $attr['placeholder'] ?? '' ) ),
		'required'    => ! empty( $set['validation_rules']['required']['value'] ),
	);
	switch ( $element ) {
		case 'input_name':
			$parts = array();
			foreach ( (array) ( $raw['fields'] ?? array() ) as $key => $sub ) {
				if ( ! empty( $sub['settings']['visible'] ) ) {
					$parts[] = array(
						'name'        => $name . '[' . $key . ']',
						'label'       => plain( (string) ( $sub['settings']['label'] ?? '' ) ),
						'placeholder' => plain( (string) ( $sub['attributes']['placeholder'] ?? '' ) ),
						'required'    => ! empty( $sub['settings']['validation_rules']['required']['value'] ),
						'type'        => 'text',
						'auto'        => 'first_name' === $key ? 'given-name' : ( 'last_name' === $key ? 'family-name' : 'additional-name' ),
					);
				}
			}
			return $parts ? array( 'type' => 'group', 'fields' => $parts ) : null;
		case 'input_text':
			$phone = (bool) preg_match( '/phone|mobile/i', $name . ' ' . $base['label'] );
			return $base + array( 'type' => $phone ? 'tel' : 'text', 'auto' => $phone ? 'tel' : ( preg_match( '/name/i', $name . ' ' . $base['label'] ) ? 'name' : 'off' ) );
		case 'phone':
			return $base + array( 'type' => 'tel', 'auto' => 'tel' );
		case 'input_email':
			return $base + array( 'type' => 'email', 'auto' => 'email' );
		case 'input_number':
			return $base + array( 'type' => 'number', 'auto' => 'off' );
		case 'input_url':
			return $base + array( 'type' => 'url', 'auto' => 'url' );
		case 'textarea':
			return $base + array( 'type' => 'textarea' );
		case 'input_date':
			return $base + array( 'type' => 'date' );
		case 'input_hidden':
			return array( 'name' => $name, 'type' => 'hidden', 'value' => plain( is_string( $attr['value'] ?? null ) ? $attr['value'] : '' ) );
		case 'select':
		case 'input_checkbox':
		case 'input_radio':
			$options = array();
			foreach ( (array) ( $set['advanced_options'] ?? array() ) as $option ) {
				$options[] = array( 'label' => plain( (string) ( $option['label'] ?? '' ) ), 'value' => (string) ( $option['value'] ?? '' ) );
			}
			return $base + array( 'type' => 'select' === $element ? 'select' : ( 'input_radio' === $element ? 'radio' : 'checks' ), 'options' => $options );
		case 'terms_and_condition':
		case 'gdpr_agreement':
			return $base + array( 'type' => 'consent', 'html' => localize_html( wp_kses( (string) ( $set['tnc_html'] ?? ( $set['label'] ?? '' ) ), array( 'a' => array( 'href' => true ), 'strong' => array(), 'em' => array() ) ) ) );
		case 'custom_html':
			$html = localize_html( wp_kses( (string) ( $set['html_codes'] ?? '' ), array( 'p' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'br' => array(), 'a' => array( 'href' => true ) ) ) );
			return '' !== trim( wp_strip_all_tags( $html ) ) ? array( 'type' => 'note', 'html' => $html ) : null;
	}
	return null;
}
