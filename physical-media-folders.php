<?php
/**
 * Plugin Name:       Physical Media Folders
 * Plugin URI:        https://adambalee.com/plugins/physical-media-folders
 * Description:       Organize your media library into real folders on the server. Moves files physically, updates database paths, rewrites content URLs, and creates 301 redirects.
 * Version:           1.1.15
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Adam Balee
 * Author URI:        https://adambalee.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       physical-media-folders
 *
 * @package Physical_Media_Folders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PMF_VERSION', '1.1.15' );
define( 'PMF_PLUGIN_FILE', __FILE__ );
define( 'PMF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PMF_PLUGIN_DIR . 'includes/class-pmf-folders.php';
require_once PMF_PLUGIN_DIR . 'includes/class-pmf-mover.php';
require_once PMF_PLUGIN_DIR . 'includes/class-pmf-redirects.php';
require_once PMF_PLUGIN_DIR . 'includes/class-pmf-media-library.php';
require_once PMF_PLUGIN_DIR . 'includes/class-pmf-uploads.php';

if ( is_admin() ) {
	require_once PMF_PLUGIN_DIR . 'includes/class-pmf-admin.php';
	require_once PMF_PLUGIN_DIR . 'includes/class-pmf-ajax.php';
}

/**
 * Default plugin settings.
 *
 * @return array
 */
function pmf_default_settings() {
	return array(
		'rewrite_content'       => 1,
		'create_redirects'      => 1,
		'update_guid'           => 1,
		'default_upload_folder' => '',
	);
}

/**
 * Get a plugin setting.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function pmf_get_setting( $key ) {
	$settings = wp_parse_args( (array) get_option( 'pmf_settings', array() ), pmf_default_settings() );
	return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
}

/**
 * Plugin activation: create the redirects table.
 */
function pmf_activate() {
	PMF_Redirects::create_table();
	add_option( 'pmf_settings', pmf_default_settings() );
}
register_activation_hook( __FILE__, 'pmf_activate' );

/**
 * Boot the plugin.
 */
function pmf_init() {
	PMF_Redirects::instance();
	PMF_Media_Library::instance();
	PMF_Uploads::instance();

	if ( is_admin() ) {
		PMF_Admin::instance();
		PMF_Ajax::instance();
	}
}
add_action( 'plugins_loaded', 'pmf_init' );
