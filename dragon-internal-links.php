<?php
/**
 * Plugin Name: Dragon Internal Links
 * Plugin URI: https://dragoncore.ltd/plugins/dragon-internal-links
 * Description: Find internal linking opportunities, detect orphan content, and improve your site's SEO structure.
 * Version: 1.1.11
 * Author: Dragon Core
 * Author URI: https://dragoncore.ltd
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dragon-internal-links
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.0
 */

namespace DragonInternalLinks;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'DRAGONINTERNALLINKS_VERSION', '1.1.11' );
define( 'DRAGONINTERNALLINKS_PLUGIN_FILE', __FILE__ );
define( 'DRAGONINTERNALLINKS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DRAGONINTERNALLINKS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DRAGONINTERNALLINKS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Load plugin classes
require_once DRAGONINTERNALLINKS_PLUGIN_DIR . 'includes/class-crypto.php';
require_once DRAGONINTERNALLINKS_PLUGIN_DIR . 'includes/class-plugin.php';
require_once DRAGONINTERNALLINKS_PLUGIN_DIR . 'includes/class-admin.php';
require_once DRAGONINTERNALLINKS_PLUGIN_DIR . 'includes/class-scanner.php';
require_once DRAGONINTERNALLINKS_PLUGIN_DIR . 'includes/class-relevance.php';
require_once DRAGONINTERNALLINKS_PLUGIN_DIR . 'includes/class-ai-ranker.php';
require_once DRAGONINTERNALLINKS_PLUGIN_DIR . 'includes/class-analyzer.php';
require_once DRAGONINTERNALLINKS_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once DRAGONINTERNALLINKS_PLUGIN_DIR . 'includes/class-linker.php';
require_once DRAGONINTERNALLINKS_PLUGIN_DIR . 'includes/class-ajax.php';

/**
 * Plugin activation hook
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 */
function dragoninternallinks_activate( $network_wide = false ) {
	Plugin::activate( (bool) $network_wide );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\dragoninternallinks_activate' );

/**
 * Plugin deactivation hook
 *
 * @param bool $network_wide Whether the plugin is being network-deactivated.
 */
function dragoninternallinks_deactivate( $network_wide = false ) {
	Plugin::deactivate( (bool) $network_wide );
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\dragoninternallinks_deactivate' );

/**
 * Load bundled translations.
 */
function dragoninternallinks_load_textdomain() {
	load_plugin_textdomain( 'dragon-internal-links', false, dirname( DRAGONINTERNALLINKS_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\dragoninternallinks_load_textdomain', 0 );

/**
 * Initialize the plugin
 */
function dragoninternallinks_init() {
	Plugin::get_instance();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\dragoninternallinks_init' );

/**
 * Add settings link to plugin row
 *
 * @param array $links Plugin action links.
 * @return array Modified links.
 */
function dragoninternallinks_plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		admin_url( 'tools.php?page=dragon-internal-links&tab=settings' ),
		__( 'Settings', 'dragon-internal-links' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . DRAGONINTERNALLINKS_PLUGIN_BASENAME, __NAMESPACE__ . '\dragoninternallinks_plugin_action_links' );
