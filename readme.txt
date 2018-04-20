=== Redis Page Cache ===
Contributors: pressjitsu, soulseekah
Tags: cache, caching, performance, redis
Requires at least: 4.4
Tested up to: 4.9.5
Stable tag: 0.8.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A Redis-backed full page caching plugin for WordPress, extremely flexible and fast.

== Description ==

A Redis-backed full page caching plugin for WordPress, extremely flexible and fast. Requires a running [Redis server](http://redis.io/) and the [PHP Redis PECL](https://github.com/phpredis/phpredis) extension.

= Features =

* Serves full cached pages from memory
* Caches redirects, 404s and other response codes
* Just-in-time cache expiry/regeneration
* Cache status headers for monitoring hit rate
* Smart and flexible cache invalidation
* Serves stale cache during regeneration
* Configurable list of ignored cookies and request variables

For an installation and configuration guide please visit the [full documentation on GitHub](https://github.com/pressjitsu/pj-page-cache-red). If you need any assistance please reach out to [Pressjitsu](https://pressjitsu.com) via live chat or e-mail, or open a new thread in the WordPress.org support forums.

== Installation ==

1. Make sure you have a running Redis server and the Redis PECL extension installed
1. Upload the plugin files to the `/wp-content/plugins/redis-page-cache` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress
1. Create a symbolic link from wp-content/advanced-cache.php to wp-content/plugins/redis-page-cache/advanced-cache.php
1. Enable WP_CACHE in your wp-config.php file

For an installation and configuration guide please visit the [full documentation](https://github.com/pressjitsu/pj-page-cache-red).

== Changelog ==

= 0.8.3 =
* Introduce _COOKIE whitelisting and max TTLs
* Handle wordpress_test_cookie on login screen
* Fix add_action WordPress 4.7 compatibility
* Fix missing variable warning (props bookt-jacob)

= 0.8.2 =
* Fix missing $ introduced in 0.8.1

= 0.8.1 =
* Add more debug headers
* Delete cached entries on post update by default, instead of expiring them
* Add configuration options for database selection and Redis authentication
* Don't cache 5xx errors

= 0.8 =
* Initial public release.
