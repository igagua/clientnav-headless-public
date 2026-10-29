<?php
// Runs inside the throwaway WordPress (wp eval-file): forms described for a front end (the plugin's own and Fluent
// Forms ones), and the plugin's own forms judged by Client Navigator Anti-Spam (a stand-in here) before anything is kept.
$pass = 0;
$fail = 0;
$is   = function ( string $name, $ok, $got = null ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		return;
	}
	++$fail;
	echo "FAIL  $name" . ( null === $got ? '' : ': ' . substr( wp_json_encode( $got ), 0, 300 ) ) . "\n";
};
$get = function ( string $route ) {
	$r = rest_do_request( new WP_REST_Request( 'GET', '/clientnav-headless/v1' . $route ) );
	return array( $r->get_status(), json_decode( (string) wp_json_encode( $r->get_data() ), true ) );
};

// The plugin's own form, described.
list( $st, $d ) = $get( '/forms/inquiry' );
$types = array_column( $d['fields'] ?? array(), 'type', 'name' );
$is( 'own form: its fields, sent to the door', 200 === $st && 'email' === ( $types['email'] ?? '' ) && 'textarea' === ( $types['message'] ?? '' ) && 'door' === ( $d['send']['via'] ?? '' ), $d );
list( $st ) = $get( '/forms/nope' );
$is( 'an unknown form: nothing', 404 === $st, $st );

// A Fluent Forms form, from its own tables (Fluent Forms itself is not installed here).
global $wpdb;
define( 'FLUENTFORM', true );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fluentform_forms ( id INTEGER PRIMARY KEY, title TEXT, form_fields TEXT, status TEXT )" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fluentform_form_meta ( id INTEGER PRIMARY KEY, form_id INTEGER, meta_key TEXT, value TEXT )" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}fluentform_forms" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}fluentform_form_meta" );
$fields = array(
	'fields'       => array(
		array( 'element' => 'input_name', 'attributes' => array( 'name' => 'names' ), 'fields' => array(
			'first_name'  => array( 'attributes' => array( 'placeholder' => 'First' ), 'settings' => array( 'label' => 'First name', 'visible' => true, 'validation_rules' => array( 'required' => array( 'value' => true ) ) ) ),
			'middle_name' => array( 'settings' => array( 'label' => 'Middle', 'visible' => false ) ),
			'last_name'   => array( 'settings' => array( 'label' => 'Last name', 'visible' => true ) ),
		) ),
		array( 'element' => 'container', 'columns' => array(
			array( 'fields' => array( array( 'element' => 'input_email', 'attributes' => array( 'name' => 'email', 'placeholder' => 'you@example.com' ), 'settings' => array( 'label' => 'Email', 'validation_rules' => array( 'required' => array( 'value' => true ) ) ) ) ) ),
			array( 'fields' => array( array( 'element' => 'input_text', 'attributes' => array( 'name' => 'phone' ), 'settings' => array( 'label' => 'Phone number' ) ) ) ),
		) ),
		array( 'element' => 'select', 'attributes' => array( 'name' => 'office' ), 'settings' => array( 'label' => 'Office', 'advanced_options' => array( array( 'label' => 'South Gate', 'value' => 'south-gate' ), array( 'label' => 'Downey', 'value' => 'downey' ) ) ) ),
		array( 'element' => 'custom_html', 'settings' => array( 'html_codes' => '<p>We answer within <strong>one day</strong>.</p><script>alert(1)</script>' ) ),
		array( 'element' => 'custom_submit_button', 'settings' => array( 'button_ui' => array( 'text' => 'Book a visit' ) ) ),
	),
	'submitButton' => array( 'settings' => array( 'button_ui' => array( 'text' => 'Submit' ) ) ),
);
$wpdb->insert( $wpdb->prefix . 'fluentform_forms', array( 'id' => 7, 'title' => 'Book &amp; visit', 'form_fields' => wp_json_encode( $fields ), 'status' => 'published' ) );
$wpdb->insert( $wpdb->prefix . 'fluentform_forms', array( 'id' => 8, 'title' => 'Draft form', 'form_fields' => wp_json_encode( $fields ), 'status' => 'unpublished' ) );
$wpdb->insert( $wpdb->prefix . 'fluentform_form_meta', array( 'form_id' => 7, 'meta_key' => 'formSettings', 'value' => wp_json_encode( array( 'confirmation' => array( 'messageToShow' => '<p>Thank you, we will call you.</p>' ) ) ) ) );
update_option( '_fluentform_turnstile_details', array( 'siteKey' => '0x4AAA-public-site-key', 'secretKey' => 'never-shown' ) );
list( $st, $d ) = $get( '/forms/7' );
$f = $d['fields'] ?? array();
$is( 'Fluent form: title, button, words after sending', 200 === $st && 'Book & visit' === ( $d['title'] ?? '' ) && 'Book a visit' === ( $d['button'] ?? '' ) && 'Thank you, we will call you.' === ( $d['thanks'] ?? '' ), $d );
$is( 'Fluent form: a name in its visible parts, columns flattened, phone known as tel', 'group' === ( $f[0]['type'] ?? '' ) && array( 'names[first_name]', 'names[last_name]' ) === array_column( $f[0]['fields'] ?? array(), 'name' ) && true === ( $f[0]['fields'][0]['required'] ?? null ) && 'email' === ( $f[1]['type'] ?? '' ) && 'tel' === ( $f[2]['type'] ?? '' ), $f );
$is( 'Fluent form: choices and a note, the note cleaned', 'select' === ( $f[3]['type'] ?? '' ) && 'downey' === ( $f[3]['options'][1]['value'] ?? '' ) && 'note' === ( $f[4]['type'] ?? '' ) && false === strpos( (string) ( $f[4]['html'] ?? '' ), 'script' ), array( $f[3] ?? null, $f[4] ?? null ) );
$is( 'Fluent form: the honeypot name, the public Turnstile key only, and how to send it', 'item_7__fluent_sf' === ( $d['trap'] ?? '' ) && '0x4AAA-public-site-key' === ( $d['sitekey'] ?? '' ) && false === strpos( wp_json_encode( $d ), 'never-shown' ) && 'fluentform_submit' === ( $d['send']['action'] ?? '' ), $d['send'] ?? null );
list( $st ) = $get( '/forms/8' );
$is( 'an unpublished Fluent form: nothing', 404 === $st, $st );
delete_option( '_fluentform_turnstile_details' );

// The plugin's own form, judged by Anti-Spam first (a stand-in for the Connector's guard).
eval( 'class ClientNav_AntiSpam_Log { public static $page = ""; } class ClientNav_AntiSpam_Guard { public static $seen = null; public static function refuse( $type, $fields ) { self::$seen = array( $type, $fields ); return "" !== ( $fields["cnas_h_test"] ?? "" ) ? "This looks automated." : null; } }' );
$saved = get_option( 'clientnav_headless' );
update_option( 'clientnav_headless', array_merge( is_array( $saved ) ? $saved : array(), array( 'secret' => str_repeat( 'f', 40 ), 'inquiry_to' => 'office@example.test' ) ) );
add_filter( 'pre_wp_mail', '__return_true' );
$post = function ( array $fields ) {
	$q = new WP_REST_Request( 'POST', '/clientnav-headless/v1/forms/inquiry' );
	$q->set_header( 'authorization', 'Bearer ' . str_repeat( 'f', 40 ) );
	$q->set_header( 'content-type', 'application/json' );
	$q->set_body( (string) wp_json_encode( array( 'fields' => $fields, 'ip' => '203.0.113.' . wp_rand( 1, 250 ), 'page' => '/contact/' ) ) );
	$r = rest_do_request( $q );
	return array( $r->get_status(), json_decode( (string) wp_json_encode( $r->get_data() ), true ) );
};
$entries = static fn() => (int) ( new WP_Query( array( 'post_type' => 'cn_form_entry', 'post_status' => 'private', 'fields' => 'ids', 'posts_per_page' => -1 ) ) )->found_posts;
$before  = $entries();
$good    = array( 'name' => 'Ann Visitor', 'company' => 'Visitor Co', 'email' => 'ann@example.test', 'message' => 'Hello', 'cnas_k' => 'k', 'cnas_t' => '4200', 'cnas_i' => '1', 'cnas_h_test' => '' );
list( $st, $d ) = $post( array( 'cnas_h_test' => 'http://spam.example' ) + $good );
$is( 'Anti-Spam refuses: 422 with its words, and nothing is kept', 422 === $st && 'This looks automated.' === ( $d['error'] ?? '' ) && $entries() === $before, array( $st, $d ) );
$is( 'Anti-Spam was asked as "headless", with the check fields and the page', 'headless' === ( ClientNav_AntiSpam_Guard::$seen[0] ?? '' ) && '4200' === ( ClientNav_AntiSpam_Guard::$seen[1]['cnas_t'] ?? '' ) && '/contact/' === ClientNav_AntiSpam_Log::$page, ClientNav_AntiSpam_Guard::$seen );
list( $st, $d ) = $post( $good );
$is( 'Anti-Spam lets it through: kept and answered as before', 200 === $st && true === ( $d['ok'] ?? false ) && $entries() === $before + 1, array( $st, $d ) );
// Connector 0.46 and later: this plugin vouches for the request itself; Anti-Spam's own check is a function.
eval( 'function clientnav_antispam_check( $fields, $context ) { $GLOBALS["cnh_vouch"] = array( $fields, $context ); return "" !== ( $fields["cnas_h_test"] ?? "" ) ? array( "ok" => false, "checked" => true, "message" => "Refused by the check." ) : array( "ok" => true, "checked" => true, "message" => "" ); } function clientnav_antispam_frontend_config() { return array( "key" => "public-key", "trap" => "cnas_h_test" ); }' );
ClientNav_AntiSpam_Guard::$seen = null;
$before = $entries();
list( $st, $d ) = $post( array( 'cnas_h_test' => 'filled' ) + $good );
$is( 'vouching: the check function refuses, nothing kept, the old guard not asked', 422 === $st && 'Refused by the check.' === ( $d['error'] ?? '' ) && $entries() === $before && null === ClientNav_AntiSpam_Guard::$seen, array( $st, $d ) );
$is( 'vouching: it is given the visitor address and page', '/contact/' === ( $GLOBALS['cnh_vouch'][1]['page'] ?? '' ) && 0 === strpos( (string) ( $GLOBALS['cnh_vouch'][1]['ip'] ?? '' ), '203.0.113.' ), $GLOBALS['cnh_vouch'][1] ?? null );
list( $st, $d ) = $post( $good );
$is( 'vouching: allowed, kept', 200 === $st && $entries() === $before + 1, array( $st, $d ) );
list( $st, $d ) = $get( '/forms/inquiry' );
$is( 'the form description hands the front end Anti-Spam\'s public check settings', 'cnas_h_test' === ( $d['antispam']['trap'] ?? '' ), $d['antispam'] ?? null );
remove_filter( 'pre_wp_mail', '__return_true' );
false === $saved ? delete_option( 'clientnav_headless' ) : update_option( 'clientnav_headless', $saved );

echo "$pass passed, $fail failed\n";
