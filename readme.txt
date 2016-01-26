=== Redis Page Cache ===
Contributors: pressjitsu, soulseekah
Tags: cache, caching, performance, redis
Requires at least: 4.4
Tested up to: 4.4
Stable tag: 0.8
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A Redis-backed full page caching plugin for WordPress, extremely flexible and fast.

== Description ==

A Redis-backed full page caching plugin for WordPress, extremely flexible and fast. Requires a running [Redis server](http://redis.io/) and the [PHP Redis PECL](https://github.com/phpredis/phpredis) extension.

For an installation and configuration guide please visit the [full documentation](https://github.com/pressjitsu/pj-page-cache).

== Installation ==

1. Make sure you have a running Redis server and the Redis PECL extension installed
1. Upload the plugin files to the `/wp-content/plugins/redis-page-cache` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress
1. Create a symbolic link from wp-content/advanced-cache.php to wp-content/plugins/redis-page-cache/advanced-cache.php
1. Enable WP_CACHE in your wp-config.php file

For an installation and configuration guide please visit the [full documentation](https://github.com/pressjitsu/pj-page-cache).

== Changelog ==

= 0.8 =
* Initial public release.
