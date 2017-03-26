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

$link = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'advanced-cache.php';
DEFINE('RPC_LINK', $link);
function rpc_activate()
{
    // link advanced-cache.php into wp-content dirname
    $target = __DIR__ .  DIRECTORY_SEPARATOR . 'advanced-cache.php';
    error_log($target, 4);
    error_log(RPC_LINK, 4);
    if (!symlink($target, RPC_LINK)) {
        exit('Could not symlink the plugin');
    }
}

function rpc_deactivate()
{
    // delete 
    unlink(RPC_LINK);
}

