<?php 
defined( 'ABSPATH' ) || die( 'Ooops!' );

/**
 * Instanciate the filesystem class
 *
 * @return object WP_Filesystem_Direct instance
 */
function redis_direct_filesystem() {
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	return new WP_Filesystem_Direct( new StdClass() );
}


/**
 * Added or set the value of the WP_CACHE constant
 *
 * @param bool $turn_on The value of WP_CACHE constant.
 * @return void
 */
function set_redis_wp_cache_define( $turn_on = true ) {
	// If WP_CACHE is already define, return to get a coffee.
	if ( $turn_on && defined( 'WP_CACHE' ) && WP_CACHE ) {
		return;
	}

	// Get path of the config file OR return if not found or writable
	$config_file_path = redis_find_wpconfig_path();
	if ( ! $config_file_path ) {
		return;
	}

	// Get content of the config file.
	$config_file = file( $config_file_path );

	// Get the value of WP_CACHE constant.
	$turn_on = $turn_on ? 'true' : 'false';

	// Lets find out if the constant WP_CACHE is defined or not.
	$is_wp_cache_exist = false;

	// Get WP_CACHE constant define.
	$constant = "define('WP_CACHE', $turn_on); // Added by Redis Page Cache" . "\r\n";

	foreach ( $config_file as &$line ) {
		if ( ! preg_match( '/^define\(\s*\'([A-Z_]+)\',(.*)\)/', $line, $match ) ) {
			continue;
		}

		if ( 'WP_CACHE' === $match[1] ) {
			$is_wp_cache_exist = true;
			$line              = $constant;
		}
	}
	unset( $line ); // just clearing

	// If the constant does not exist, create it.
	if ( ! $is_wp_cache_exist ) {
		array_shift( $config_file );
		array_unshift( $config_file, "<?php\r\n", $constant );
	}

	// Insert the constant in wp-config.php file.
	$handle = @fopen( $config_file_path, 'w' );
	foreach ( $config_file as $line ) {
		@fwrite( $handle, $line );
	}

	@fclose( $handle );

	// Update the writing permissions of wp-config.php file.
	$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
	redis_direct_filesystem()->chmod( $config_file_path, $chmod );
}


/**
 * Copies the file to destination
 *
 * @return bool
 */
function redis_copy_advanced_cache_file() {
	$source = RPC_PLUGIN_PATH . 'advanced-cache.php';
	$destination = WP_CONTENT_DIR . '/advanced-cache.php';
	if( ! copy($source, $destination) ) {
		return false;
	}

	return true;
}


/**
 * Searching the correct wp-config.php file, support one level up in file tree
 *
 * @return string|bool The path of wp-config.php file or false if not found or permissions not writable
 */
function redis_find_wpconfig_path() {
	$config_file_name = 'wp-config';
	$config_file      = ABSPATH . $config_file_name . '.php';
	$config_file_alt  = dirname( ABSPATH ) . '/' . $config_file_name . '.php';

	if ( redis_direct_filesystem()->exists( $config_file ) && redis_direct_filesystem()->is_writable( $config_file ) ) {
		return $config_file;
	} elseif ( redis_direct_filesystem()->exists( $config_file_alt ) && redis_direct_filesystem()->is_writable( $config_file_alt ) && ! redis_direct_filesystem()->exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
		return $config_file_alt;
	}

	// No writable file found.
	return false;
}
