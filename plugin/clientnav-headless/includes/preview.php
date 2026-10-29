<?php
namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

/**
 * Previews on the front end.
 *
 * A signed, short-lived token proves "a signed-in user who may edit entry N asked to see it":
 *   base64url( JSON claims ) . base64url( HMAC-SHA256( first part, shared secret ) )
 * Claims: pid (entry), uid (user), scope ("preview" for the Preview button, "live" for the live window), exp.
 * The front end checks the token with the same secret; without a secret of 32+ characters nothing is signed.
 *
 * Draft preview: the Preview button opens <front end>/api/preview?token=..., and the front end reads the
 * unsaved state back from /preview on the content door with that token. No extra user or password is needed.
 */
function b64url( string $raw ): string {
	return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}

function sign_token( array $claims ): string {
	$secret = setting( 'secret' );
	if ( strlen( $secret ) < 32 ) {
		return '';
	}
	$payload = b64url( (string) wp_json_encode( $claims ) );
	return $payload . '.' . b64url( hash_hmac( 'sha256', $payload, $secret, true ) );
}

/** The claims of a valid token of the given scope, or null. */
function read_token( string $token, string $scope ): ?array {
	$secret = setting( 'secret' );
	$parts  = explode( '.', $token );
	if ( strlen( $secret ) < 32 || 2 !== count( $parts ) || ! hash_equals( b64url( hash_hmac( 'sha256', $parts[0], $secret, true ) ), $parts[1] ) ) {
		return null;
	}
	$claims = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	if ( ! is_array( $claims ) || ( $claims['scope'] ?? '' ) !== $scope || (int) ( $claims['exp'] ?? 0 ) < time() || empty( $claims['pid'] ) ) {
		return null;
	}
	return $claims;
}

function preview_token( int $post_id, string $scope, int $ttl ): string {
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return '';
	}
	return sign_token( array( 'pid' => $post_id, 'uid' => get_current_user_id(), 'scope' => $scope, 'exp' => time() + $ttl ) );
}

add_filter(
	'preview_post_link',
	static function ( $link, $post ) {
		if ( '' === frontend_url() || ! in_array( $post->post_type, public_types(), true ) ) {
			return $link;
		}
		$token = preview_token( (int) $post->ID, 'preview', 10 * MINUTE_IN_SECONDS );
		return '' === $token ? $link : add_query_arg( 'token', rawurlencode( $token ), frontend_url() . '/api/preview/' );
	},
	10,
	2
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			REST_NS,
			'/preview',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn( \WP_REST_Request $r ) => null !== read_token( (string) $r['token'], 'preview' ),
				'callback'            => __NAMESPACE__ . '\\rest_preview',
			)
		);
		register_rest_route(
			REST_NS,
			'/live',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn( \WP_REST_Request $r ) => null !== live_claims( $r ),
				'callback'            => __NAMESPACE__ . '\\rest_live',
			)
		);
	}
);

/** The entry as its editor last left it: the newest autosave if there is one, otherwise the saved state. */
function rest_preview( \WP_REST_Request $r ) {
	door_with( asked_with( $r ) );
	$claims = read_token( (string) $r['token'], 'preview' );
	$post   = $claims ? get_post( (int) $claims['pid'] ) : null;
	if ( ! $post || ! in_array( $post->post_type, public_types(), true ) ) {
		return new \WP_REST_Response( array( 'type' => 'none' ), 404 );
	}
	$auto = wp_get_post_autosave( $post->ID, (int) $claims['uid'] );
	if ( $auto && strtotime( $auto->post_modified_gmt ) > strtotime( $post->post_modified_gmt ) ) {
		$post               = clone $post;
		$post->post_title   = $auto->post_title;
		$post->post_content = $auto->post_content;
		$post->post_excerpt = $auto->post_excerpt;
	}
	return preview_answer( $post );
}

/** An entry drawn for a preview, every block marked with where it sits in the editor, so a click can select it there. */
function preview_answer( \WP_Post $post ): \WP_REST_Response {
	$post               = clone $post;
	$post->post_content = mark_paths( $post->post_content );
	add_filter( 'render_block', __NAMESPACE__ . '\\mark_block', PHP_INT_MAX, 2 );
	try {
		$entry = entry( $post );
	} finally {
		remove_filter( 'render_block', __NAMESPACE__ . '\\mark_block', PHP_INT_MAX );
	}
	$response = new \WP_REST_Response( array( 'type' => 'entry', 'status' => $post->post_status, 'entry' => $entry ) );
	$response->header( 'Cache-Control', 'no-store, private' );
	return $response;
}

/**
 * POST /live { token, title, content }: the editor's unsaved blocks drawn as the entry they belong to, for a front
 * end that has no block components of its own (the starter's /live-preview/ page). The window's "live" token says
 * which entry and who; that person must still be allowed to edit it. Nothing is saved.
 */
const LIVE_MAX = 1048576;

function live_claims( \WP_REST_Request $r ): ?array {
	$claims = read_token( (string) $r['token'], 'live' );
	return $claims && user_can( (int) $claims['uid'], 'edit_post', (int) $claims['pid'] ) ? $claims : null;
}

function rest_live( \WP_REST_Request $r ) {
	door_with( asked_with( $r ) );
	$claims  = live_claims( $r );
	$post    = $claims ? get_post( (int) $claims['pid'] ) : null;
	$content = (string) $r['content'];
	if ( ! $post || ! in_array( $post->post_type, public_types(), true ) ) {
		return new \WP_REST_Response( array( 'type' => 'none' ), 404 );
	}
	if ( strlen( $content ) > LIVE_MAX ) {
		return refused( 'Too much to draw at once.' );
	}
	$post             = clone $post;
	$post->post_title = sanitize_text_field( (string) ( $r['title'] ?? $post->post_title ) );
	// The words a person typed into the editor are drawn as WordPress would save them for that person.
	$post->post_content = user_can( (int) $claims['uid'], 'unfiltered_html' ) ? $content : wp_kses_post( $content );
	return preview_answer( $post );
}

/**
 * Click-to-edit marks, for previews only. A block's place in the editor is its path: the index among its
 * siblings at each level, "0.2.1". WordPress's editor leaves out text between blocks that is only white space,
 * so it is not counted here either. A block carries its path as the attribute cnhPath while the preview is drawn
 * (mark_block puts it on the first tag of the block's HTML as data-cnh-block, and blocks() hands it out as "path");
 * text outside any block (classic content) is wrapped in an element that carries it.
 */
function mark_paths( string $content ): string {
	if ( '' === trim( $content ) ) {
		return $content;
	}
	$walk = static function ( array $blocks, string $above ) use ( &$walk ): array {
		$i = 0;
		foreach ( $blocks as $k => $block ) {
			if ( empty( $block['blockName'] ) ) {
				if ( '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					continue;
				}
				// On lines of its own, so WordPress's paragraphs (wpautop) form inside it and not across it.
				$html                         = '<div data-cnh-block="' . esc_attr( $above . $i ) . '" style="display:contents">' . "\n\n" . trim( $block['innerHTML'] ) . "\n\n" . '</div>';
				$blocks[ $k ]['innerHTML']    = $html;
				$blocks[ $k ]['innerContent'] = array( $html );
			} else {
				$blocks[ $k ]['attrs']['cnhPath'] = $above . $i;
				$blocks[ $k ]['innerBlocks']      = $walk( $block['innerBlocks'] ?? array(), $above . $i . '.' );
			}
			++$i;
		}
		return $blocks;
	};
	return serialize_blocks( $walk( parse_blocks( $content ), '' ) );
}

function mark_block( $html, $block ) {
	$path = $block['attrs']['cnhPath'] ?? null;
	if ( ! is_string( $path ) || '' === trim( (string) $html ) || ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
		return $html;
	}
	$tags = new \WP_HTML_Tag_Processor( (string) $html );
	// The innermost block keeps the tag when a block has no element of its own around its children.
	if ( $tags->next_tag() && null === $tags->get_attribute( 'data-cnh-block' ) ) {
		$tags->set_attribute( 'data-cnh-block', $path );
		return $tags->get_updated_html();
	}
	return $html;
}

/** A fresh Preview address for the editor's live preview: a token lives ten minutes, an editing session longer. */
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			REST_NS,
			'/preview-link',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn( \WP_REST_Request $r ) => current_user_can( 'edit_post', (int) $r['post'] ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$post = get_post( (int) $r['post'] );
					$url  = $post ? get_preview_post_link( $post ) : '';
					$ours = '' !== frontend_url() && 0 === strpos( (string) $url, frontend_url() );
					$out  = new \WP_REST_Response( array( 'url' => $ours ? $url : '' ) );
					$out->header( 'Cache-Control', 'no-store, private' );
					return $out;
				},
			)
		);
	}
);

/**
 * Live preview: the editor opens <front end>/live-preview/ in a window and sends it the unsaved blocks as
 * they are typed (see src/live.jsx). This hands the editor the front end's address and a token for that window.
 */
add_action(
	'enqueue_block_editor_assets',
	static function () {
		$post = get_post();
		if ( ! $post || '' === frontend_url() || ! wp_script_is( 'clientnav-headless-editor', 'registered' ) || ! in_array( $post->post_type, public_types(), true ) ) {
			return;
		}
		wp_enqueue_script( 'clientnav-headless-editor' );
		wp_add_inline_script(
			'clientnav-headless-editor',
			'window.clientnavHeadless = ' . wp_json_encode( array( 'frontend' => frontend_url(), 'liveToken' => preview_token( (int) $post->ID, 'live', 2 * HOUR_IN_SECONDS ), 'blocks' => blocks_on(), 'livePage' => get_option( 'clientnav_headless_last_check' )['live_page'] ?? null ) ) . ';',
			'before'
		);
	}
);
