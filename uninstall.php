<?php
/**
 * Uninstall Rootstuff Media Folders.
 *
 * Removes the settings option and the redirects table. Files and folders
 * in the uploads directory are intentionally left untouched.
 *
 * @package Rootstuff_Media_Folders
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'rsmf_settings' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dropping the plugin's own table on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rsmf_redirects" );
