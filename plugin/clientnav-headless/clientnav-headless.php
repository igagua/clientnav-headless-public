<?php
/**
 * Plugin Name: Client Navigator Headless
 * Description: Makes WordPress the content office of a separate front end: a read-only content door (REST), page blocks drawn in the editor as on the site, menus for the front end, and a refresh call when something is published.
 * Version:           0.26.2
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Client Navigator
 * License: GPL-2.0-or-later
 * Text Domain: clientnav-headless
 */

namespace ClientNav\Headless;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.26.2';
const REST_NS = 'clientnav-headless/v1';

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/kept.php';
require_once __DIR__ . '/includes/door.php';
require_once __DIR__ . '/includes/check.php';
require_once __DIR__ . '/includes/visitors.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/refresh.php';
require_once __DIR__ . '/includes/blocks.php';
require_once __DIR__ . '/includes/preview.php';
require_once __DIR__ . '/includes/forms.php';
require_once __DIR__ . '/includes/pairing.php';
require_once __DIR__ . '/includes/feed.php';
require_once __DIR__ . '/includes/archives.php';
require_once __DIR__ . '/includes/layouts.php';
require_once __DIR__ . '/includes/podcast.php';
require_once __DIR__ . '/includes/abilities.php';
require_once __DIR__ . '/includes/fluentforms.php';
require_once __DIR__ . '/includes/elementor.php';
require_once __DIR__ . '/includes/lang.php';

add_action(
	'after_setup_theme',
	static function () {
		// The front end draws these; the theme on this WordPress does not need to.
		register_nav_menus(
			array(
				'clientnav_primary' => __( 'Front end: main menu', 'clientnav-headless' ),
				'clientnav_utility' => __( 'Front end: top bar', 'clientnav-headless' ),
				'clientnav_footer'  => __( 'Front end: footer columns', 'clientnav-headless' ),
				'clientnav_legal'   => __( 'Front end: footer bottom line', 'clientnav-headless' ),
			)
		);
	}
);
