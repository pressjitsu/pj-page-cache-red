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

register_activation_hook(__FILE__, 'rpc_activate');
register_deactivation_hook(__FILE__, 'rpc_deactivate');

$target = content_url() . DIRECTORY_SEPARATOR . 'advanced-cache.php';

function rpc_activate() {
  // link advanced-cache.php into wp-content dirname  
  $link = __DIR__ .  DIRECTORY_SEPARATOR . 'src/advanced-cache.php';
  symlink($target, $link);
}

function rpc_deactivate() {
  // delete 
  unlink($target);
}

