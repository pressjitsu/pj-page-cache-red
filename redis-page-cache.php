<?php
/**
 * Plugin Name: Redis Page Cache
 * Plugin URI: https://pressjitsu.com
 * Description: Redis-backed full page caching plugin for WordPress.
 * Version: 0.8.3
 * License: GPLv3 or later
 *
 * To install create a symbolic link to the advanced-cache.php dropin from
 * your wp-content directory and enable page caching in your wp-config.php file.
 *
 * Refer to the following URL for further instructions:
 *
 * https://github.com/pressjitsu/redis-page-cache/
 *
 * Please keep this helper plugin active for update notifications and future
 * helper and CLI functionality.
 */

defined( 'RPC_PLUGIN_PATH' ) ? null : define( 'RPC_PLUGIN_PATH', realpath( plugin_dir_path( __FILE__ ) ) . '/' );

// Adding functions.php file
require_once( RPC_PLUGIN_PATH . 'functions.php' );


/**
 * Do this while activating the plugin
 */
function redis_process_activation() {
	// Copying the advanced-cache.php file to wp-content
	if ( function_exists( 'redis_copy_advanced_cache_file' ) ) {
		redis_copy_advanced_cache_file();
	}

	// Adding WP_CACHE constant to wp-config.php file
	if ( function_exists( 'set_redis_wp_cache_define' ) ) {
		set_redis_wp_cache_define( true );
	}
}
register_activation_hook( __FILE__, 'redis_process_activation' );


/**
 * Do this while deactivating the plugin
 */
function redis_process_deactivation() {
	$errors = array();

	// check if wp-config.php is writable?
	if ( ! redis_direct_filesystem()->is_writable( redis_find_wpconfig_path() ) ) {
		$errors[] = 'wpconfig';
	}

	if ( count( $errors ) ) {
		// TODO inform user about the error
		wp_safe_redirect( wp_get_referer() );
		die();
	}

	// Setting WP_CACHE constant to false in wp-config.php.
	set_redis_wp_cache_define( false );

	// Delete advanced-cache.php.
	wp_delete_file( WP_CONTENT_DIR . '/advanced-cache.php' );
}
register_deactivation_hook( __FILE__, 'redis_process_deactivation' );


/**
 * Adding option to the toolbar to clear the cache up
 */
function redis_toolbar_option() {
	global $wp_admin_bar;

	$cache_clear_url = add_query_arg( 'clear_cache', 1 );

	$args = array(
		'id'    => 'clear_cache',
		'title' => __( 'Purge Redis Page Cache', 'redis_page_cache' ),
		'href'  => $cache_clear_url,
	);
	$wp_admin_bar->add_menu( $args );

}
add_action( 'wp_before_admin_bar_render', 'redis_toolbar_option', 999 );

// Clearing up the cache
if ( isset( $_GET['clear_cache'] ) ) {
	add_action( 'init', 'mmk_redis_clear_cache' );
	function mmk_redis_clear_cache() {
		$_redis = Redis_Page_Cache::get_redis();
		$_redis->flushDB();
	}
}
