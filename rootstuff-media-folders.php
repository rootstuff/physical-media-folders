<?php
/**
 * Plugin Name:       Rootstuff Media Folders
 * Plugin URI:        https://adambalee.com/plugins/rootstuff-media-folders
 * Description:       Organize your media library into real folders on the server. Moves files physically, updates database paths, rewrites content URLs, and creates 301 redirects.
 * Version:           1.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Adam Balee
 * Author URI:        https://adambalee.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rootstuff-media-folders
 *
 * @package Rootstuff_Media_Folders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RSMF_VERSION', '1.2.1' );
define( 'RSMF_PLUGIN_FILE', __FILE__ );
define( 'RSMF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RSMF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once RSMF_PLUGIN_DIR . 'includes/class-rsmf-folders.php';
require_once RSMF_PLUGIN_DIR . 'includes/class-rsmf-mover.php';
require_once RSMF_PLUGIN_DIR . 'includes/class-rsmf-redirects.php';
require_once RSMF_PLUGIN_DIR . 'includes/class-rsmf-media-library.php';
require_once RSMF_PLUGIN_DIR . 'includes/class-rsmf-uploads.php';

if ( is_admin() ) {
	require_once RSMF_PLUGIN_DIR . 'includes/class-rsmf-admin.php';
	require_once RSMF_PLUGIN_DIR . 'includes/class-rsmf-ajax.php';
}

/**
 * Default plugin settings.
 *
 * @return array
 */
function rsmf_default_settings() {
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
function rsmf_get_setting( $key ) {
	$settings = wp_parse_args( (array) get_option( 'rsmf_settings', array() ), rsmf_default_settings() );
	return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
}

/**
 * Plugin activation: create the redirects table.
 */
function rsmf_activate() {
	RSMF_Redirects::create_table();
	add_option( 'rsmf_settings', rsmf_default_settings() );
}
register_activation_hook( __FILE__, 'rsmf_activate' );

/**
 * Boot the plugin.
 */
function rsmf_init() {
	RSMF_Redirects::instance();
	RSMF_Media_Library::instance();
	RSMF_Uploads::instance();

	if ( is_admin() ) {
		RSMF_Admin::instance();
		RSMF_Ajax::instance();
	}
}
add_action( 'plugins_loaded', 'rsmf_init' );
