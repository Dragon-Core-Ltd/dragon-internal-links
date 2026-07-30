<?php
/**
 * Plugin Name: Dragon Internal Links
 * Plugin URI: https://dragoncore.ltd/plugins/dragon-internal-links
 * Description: Find internal linking opportunities, detect orphan content, and improve your site's SEO structure.
 * Version: 1.0.0
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
define( 'DIL_VERSION', '1.0.0' );
define( 'DIL_PLUGIN_FILE', __FILE__ );
define( 'DIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DIL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Load plugin classes
require_once DIL_PLUGIN_DIR . 'includes/class-plugin.php';
require_once DIL_PLUGIN_DIR . 'includes/class-admin.php';
require_once DIL_PLUGIN_DIR . 'includes/class-scanner.php';
require_once DIL_PLUGIN_DIR . 'includes/class-analyzer.php';
require_once DIL_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once DIL_PLUGIN_DIR . 'includes/class-ajax.php';

/**
 * Plugin activation hook
 */
function dil_activate() {
	Plugin::activate();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\dil_activate' );

/**
 * Plugin deactivation hook
 */
function dil_deactivate() {
	Plugin::deactivate();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\dil_deactivate' );

/**
 * Initialize the plugin
 */
function dil_init() {
	Plugin::get_instance();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\dil_init' );

/**
 * Add settings link to plugin row
 *
 * @param array $links Plugin action links.
 * @return array Modified links.
 */
function dil_plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		admin_url( 'tools.php?page=dragon-internal-links&tab=settings' ),
		__( 'Settings', 'dragon-internal-links' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . DIL_PLUGIN_BASENAME, __NAMESPACE__ . '\dil_plugin_action_links' );
