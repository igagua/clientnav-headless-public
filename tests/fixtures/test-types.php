<?php
// Test content model, loaded as a must-use plugin in the throwaway WordPress: a type with its own list,
// taxonomies and extra fields, the way a news site has them.
add_action(
	'init',
	static function () {
		register_post_type( 'cn_explainer', array( 'public' => true, 'has_archive' => 'explainers', 'rewrite' => array( 'slug' => 'explainers' ), 'label' => 'Explainers', 'description' => 'Background, explained.', 'show_in_rest' => true, 'supports' => array( 'title', 'editor', 'author', 'custom-fields', 'excerpt' ) ) );
		register_taxonomy( 'cn_explainer_topic', 'cn_explainer', array( 'public' => true, 'hierarchical' => true, 'rewrite' => array( 'slug' => 'explainer-topic' ), 'show_in_rest' => true ) );
		register_taxonomy( 'cn_hidden', 'post', array( 'public' => false ) );
		register_post_meta( 'cn_explainer', 'last_reviewed', array( 'single' => true, 'type' => 'string', 'show_in_rest' => true ) );
		register_post_meta( 'cn_explainer', 'key_points', array( 'single' => true, 'type' => 'string', 'show_in_rest' => true ) );
		register_post_meta( 'cn_explainer', '_internal_note', array( 'single' => true, 'type' => 'string', 'show_in_rest' => true ) );
		register_post_meta( 'cn_explainer', 'not_shown', array( 'single' => true, 'type' => 'string', 'show_in_rest' => false ) );
	}
);
