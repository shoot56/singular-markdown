<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Singular_Markdown
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'singular_markdown_options' );
delete_option( 'flolive_md_options' );

$upload = wp_upload_dir();
if ( empty( $upload['error'] ) ) {
	$dirs = array( 'singular-markdown-cache', 'flolive-md-cache' );
	foreach ( $dirs as $subdir ) {
		$dir = trailingslashit( $upload['basedir'] ) . $subdir;
		if ( is_dir( $dir ) ) {
			$files = glob( trailingslashit( $dir ) . '*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						wp_delete_file( $file );
					}
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
