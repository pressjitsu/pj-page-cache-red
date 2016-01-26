# Redis Page Cache for WordPress

A Redis-backed full page caching plugin for WordPress, extremely flexible and fast. Requires a running [Redis server http://redis.io/] and the [PHP Redis PECL https://github.com/phpredis/phpredis] extension.

## Requirements

Make sure you have a running Redis server and the PECL PHP Redis extension installed and active. Both can be found in packages in most Linux distributions. For example on Debian/Ubuntu:

```
sudo apt-get install redis-server php5-redis
```

After installing the Redis PECL extension, make sure you restart your PHP server.

Make sure your Redis server has enough memory allocated to store your cached pages. The plugin compresses cached pages using gzip to lower memory usage. We recommend anywhere from 16 mb allocated just for page caching. Increase according to your hit-rate. We also recommend disabling flushing Redis cache to disk, and the `allkeys-lru` eviction policy to ensure the server can make more room for new cached pages by evicting older ones. Here is a sample extract from the redis.conf file:

```
maxmemory 16m
maxmemory-policy allkeys-lru
```

Don't forget to restart the Redis server after making changes to the configuration file.

## Installing the WordPress Plugin

Install and activate this plugin like you normally would into wp-content/uploads/redis-page-cache and create a symbolic link from your wp-content directory, to the advanced-cache.php file in the plugin folder:

```
cd /path/to/wp-content
ln -s plugins/redis-page-cache/advanced-cache.php advanced-cache.php
```

Enable page caching in your WordPress wp-config.php file with a constant:

```
define( 'WP_CACHE', true );
```

Try visiting your site in incognito mode or cURL, you should see an X-Pj-Cache- header:

```
curl -v https://example.org -o /dev/null
< X-Pj-Cache-Status: hit
```

That's it!

## Configuring the Plugin

This plugin does not have a settings UI or anything like that. All configuration is done strictly from a PHP file through the `$redis_page_cache_config` global. Create a redis-page-cache-config.php and place it next to your wp-config.php file in your WordPress install. Use the following code in wp-config.php to include this file during runtime:

```
// After the ABSPATH definition, but prior to loading wp-settings.php
require_once( ABSPATH . 'redis-page-cache-config.php' );
```

The contents of your file can define the plugin configuration settings:

```
$redis_page_cache_config = array();

// Change the cache time-to-live to 10 minutes
$redis_page_cache_config['ttl'] = 600;

// Ignore/strip these cookies from any request to increase cachability.
$redis_page_cache_config['ignore_cookies'] = array( 'wordpress_test_cookie', 'openstat_ref' );

// Ignore/strip these query variables to increase cachability.
$redis_page_cache_config['ignore_request_keys'] = array( 'utm_source', 'utm_medium', ... );

// Vary the cache buckets depending on the results of a function call.
// For example, if you have any mobile plugins, you may wish to serve
// all mobile requests from a different cache bucket:

$redis_page_cache_config['unique'] = array( 'is_mobile' => my_is_mobile() );

// There are some other configuration options you may wish to adjust. You can
// find them all by looking at the contents of the advanced-cache.php file.
```

## Purging Cache

By default, this plugin will expire posts (pages, cpt) whenever they are published or updated, including the front page and any RSS feeds. You may also choose to expire certain URLs or cache flags at certain other events. For example:

```
// Expire cache by post ID (argument can be an array of post IDs):
Redis_Page_Cache::clear_cache_by_post_id( $post->ID );

// Expire cache by URL (argument can be an array of URLs):
Redis_Page_Cache::clear_cache_by_url( 'https://example.org/secret-page/' );

// Expire cache by flag (argument can be an array):
Redis_Page_Cache::clear_cache_by_flag( array( 'special-flag' ) );
```

Wait, what the heck are flags?

Redis Page Cache stores a set of flags with each cached item. These flags allow the plugin to better target cached entries when flushing. For example, a single post can have multiple URLs (cache buckets, request variables, etc.) and thus, multiple cache keys:

```
https://example.org/?p=123
https://example.org/post-slug/
https://example.org/post-slug/page/2/
https://example.org/post-slug/?show_comments=1
```

These URLs will have unique cache keys and contents, but Redis Page Cache will flag them with a post ID, so you can easily purge all three items if you know the flag:

```
$post_id = 123;
$flag = sprintf( 'post:%d:%d', get_current_blog_id(), $post_id );

Redis_Page_Cache::clear_cache_by_flag( $flag );
```

You can add your own custom flags to requests too:

```
// Flag all single posts with a tag called my-special-tag:
if ( is_single() && has_tag( 'my-special-tag' ) ) {
    Redis_Page_Cache::flag( 'my-special-tag' );
}

// And whenever you need to:
Redis_Page_Cache::clear_cache_by_flag( 'my-special-tag' );
```

## Support

If you need help installing and configuring this plugin, feel free to reach out to us via e-mail: support@pressjitsu.com.
