<?php
/**
 * Redis Cache Dropin for WordPress
 *
 * Create a symbolic link to this file from your wp-content directory and
 * enable page caching in your wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) )
	die();

class Redis_Page_Cache {
	private static $redis;
	private static $redis_host = '127.0.0.1';
	private static $redis_port = 6379;
	private static $redis_db = 0;
	private static $redis_auth = '';

	private static $ttl = 300;
	private static $unique = array();
	private static $headers = array();
	private static $ignore_cookies = array( 'wordpress_test_cookie' );
	private static $ignore_request_keys = array( 'utm_source', 'utm_medium', 'utm_term', 'utm_content', 'utm_campaign' );
	private static $bail_callback = false;
	private static $debug = false;
	private static $gzip = true;

	private static $lock = false;
	private static $cache = false;
	private static $request_hash = '';
	private static $debug_data = false;
	private static $fcgi_regenerate = false;

	// Flag requests and expire/delete them efficiently.
	private static $flags = array();
	private static $flags_expire = array();
	private static $flags_delete = array();

	/**
	 * Runs during advanced-cache.php
	 */
	public static function cache_init() {
		// Clear caches in bulk at the end.
		register_shutdown_function( array( __CLASS__, 'maybe_clear_caches' ) );

		header( 'X-Pj-Cache-Status: miss' );

		// Filters are not yet available, so hi-jack the $wp_filter global to add our actions.
		$GLOBALS['wp_filter']['clean_post_cache'][10]['pj-page-cache'] = array(
			'function' => array( __CLASS__, 'clean_post_cache' ), 'accepted_args' => 1 );
		$GLOBALS['wp_filter']['transition_post_status'][10]['pj-page-cache'] = array(
			'function' => array( __CLASS__, 'transition_post_status' ), 'accepted_args' => 3 );
		$GLOBALS['wp_filter']['template_redirect'][100]['pj-page-cache'] = array(
			'function' => array( __CLASS__, 'template_redirect' ), 'accepted_args' => 1 );

		// Parse configuration.
		self::maybe_user_config();

		// Some things just don't need to be cached.
		if ( self::maybe_bail() )
			return;

		// Clean up request variables.
		self::clean_request();

		$request_hash = array(
			'request' => self::parse_request_uri( $_SERVER['REQUEST_URI'] ),
			'host' => ! empty( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '',
			'https' => ! empty( $_SERVER['HTTPS'] ) ? $_SERVER['HTTPS'] : '',
			'method' => $_SERVER['REQUEST_METHOD'],
			'unique' => self::$unique,
			'cookies' => self::parse_cookies( $_COOKIE ),
		);

		// Make sure requests with Authorization: headers are unique.
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$request_hash['unique']['pj-auth-header'] = $_SERVER['HTTP_AUTHORIZATION'];
		}

		if ( self::$debug ) {
			self::$debug_data = array( 'request_hash' => $request_hash );
		}

		// Convert to an actual hash.
		self::$request_hash = md5( serialize( $request_hash ) );
		unset( $request_hash );

		if ( self::$debug ) {
			header( 'X-Pj-Cache-Key: ' . self::$request_hash );
		}

		$redis = self::get_redis();
		if ( ! $redis )
			return;

		// Look for an existing cache entry by request hash.
		list( $cache, $lock ) = $redis->mGet( array(
			sprintf( 'pjc-%s', self::$request_hash ),
			sprintf( 'pjc-%s-lock', self::$request_hash ),
		) );

		// Something is in cache.
		if ( is_array( $cache ) && ! empty( $cache ) ) {
			$serve_cache = true;

			if ( self::$debug ) {
				header( 'X-Pj-Cache-Time: ' . $cache['updated'] );
				header( 'X-Pj-Cache-Flags: ' . implode( ' ', $cache['flags'] ) );
			}

			$redis->multi();
			$redis->zRangeByScore( 'pjc-expired-flags', $cache['updated'], '+inf', array( 'withscores' => true ) );
			$redis->zRangeByScore( 'pjc-deleted-flags', $cache['updated'], '+inf', array( 'withscores' => true ) );
			list( $expired_flags, $deleted_flags ) = $redis->exec();

			$expired = $cache['updated'] + self::$ttl < time();

			if ( ! empty( $cache['flags'] ) ) {
				// Check whether any flags have been deleted.
				if ( ! empty( $deleted_flags ) &&
					count( array_intersect( $cache['flags'], array_keys( $deleted_flags ) ) ) > 0 ) {
					$deleted = true;
					$serve_cache = false;
				}

				// Check whether any flags have been expired.
				if ( ! $expired && ! $deleted && ! empty( $expired_flags ) &&
					count( array_intersect( $cache['flags'], array_keys( $expired_flags ) ) ) > 0 ) {
					$expired = true;
				}
			}

			// Cache is outdated or set to expire.
			if ( $expired && $serve_cache ) {

				// If it's not locked, lock it for regeneration and don't serve from cache.
				if ( ! $lock ) {
					$lock = $redis->set( sprintf( 'pjc-%s-lock', self::$request_hash ), true, array( 'nx', 'ex' => 30 ) );
					if ( $lock ) {
						if ( self::can_fcgi_regenerate() ) {
							// Well, actually, if we can serve a stale copy but keep the process running
							// to regenerate the cache in background without affecting the UX, that will be great!
							$serve_cache = true;
							self::$fcgi_regenerate = true;
						} else {
							$serve_cache = false;
						}
					}
				}
			}

			if ( $serve_cache && $cache['gzip'] ) {
				if ( function_exists( 'gzuncompress' ) && self::$gzip ) {
					if ( self::$debug ) {
						header( 'X-Pj-Cache-Gzip: true' );
					}

					$cache['output'] = gzuncompress( $cache['output'] );
				} else {
					$serve_cache = false;
				}
			}

			if ( $serve_cache ) {

				// If we're regenareting in background, let everyone know.
				$status = ( self::$fcgi_regenerate ) ? 'expired' : 'hit';
				header( 'X-Pj-Cache-Status: ' . $status );

				if ( self::$debug )
					header( sprintf( 'X-Pj-Cache-Expires: %d', self::$ttl - ( time() - $cache['updated'] ) ) );

				// Output cached status code.
				if ( ! empty( $cache['status'] ) )
					http_response_code( $cache['status'] );

				// Output cached headers.
				if ( is_array( $cache['headers'] ) && ! empty( $cache['headers'] ) )
					foreach ( $cache['headers'] as $header )
						header( $header );

				echo $cache['output'];

				// If we can regenerate in the background, do it.
				if ( self::$fcgi_regenerate ) {
					fastcgi_finish_request();
					pj_sapi_headers_clean();

				} else {
					exit;
				}
			}
		}

		// Cache it, smash it.
		ob_start( array( __CLASS__, 'output_buffer' ) );
	}

	/**
	 * Returns true if we can regenerate the request in background.
	 */
	private static function can_fcgi_regenerate() {
		return ( php_sapi_name() == 'fpm-fcgi' && function_exists( 'fastcgi_finish_request' ) && function_exists( 'pj_sapi_headers_clean' ) );
	}

	/**
	 * Initialize and/or return a Redis object.
	 */
	public static function get_redis() {
		if ( isset( self::$redis ) )
			return self::$redis;

		self::$redis = false;

		if ( ! class_exists( 'Redis' ) )
			return self::$redis;

		$redis = new Redis;
		$connect = $redis->connect( self::$redis_host, self::$redis_port );

		if ( ! empty( self::$redis_auth ) )
			$redis->auth( self::$redis_auth );

		if ( ! empty( self::$redis_db ) )
			$redis->select( self::$redis_db );

		if ( true === $connect ) {
			$redis->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP );
			self::$redis = $redis;
		}

		return self::$redis;
	}

	/**
	 * Take a request uri and remove ignored request keys.
	 */
	private static function parse_request_uri( $request_uri ) {
		// Prefix the request URI with a host to avoid breaking on requests that start
		// with a // which parse_url() would treat as containing the hostname.
		$request_uri = 'http://null' . strtolower($request_uri);
		$parsed = parse_url( $request_uri );

		if ( ! empty( $parsed['query'] ) )
			$query = self::remove_query_args( $parsed['query'], self::$ignore_request_keys );

		$request_uri = ! empty( $parsed['path'] ) ? $parsed['path'] : '';
		if ( ! empty( $query ) )
			$request_uri .= '?' . $query;

		return $request_uri;
	}

	/**
	 * Take some cookies and remove ones we don't care about.
	 */
	private static function parse_cookies( $cookies ) {
		foreach ( $cookies as $key => $value ) {
			if ( in_array( strtolower( $key ), self::$ignore_cookies ) ) {
				unset( $cookies[ $key ] );
				continue;
			}

			// Skip cookies beginning with _
			if ( substr( $key, 0, 1 ) === '_' ) {
				unset( $cookies[ $key ] );
				continue;
			}
		}

		return $cookies;
	}

	/**
	 * Remove query arguments from a query string.
	 *
	 * @param string $query_string The input query string, such as foo=bar&baz=qux
	 * @param array $args An array of keys to remove.
	 *
	 * @return string The resulting query string.
	 */
	private static function remove_query_args( $query_string, $args ) {
		$regex = '#^(?:' . implode( '|', array_map( 'preg_quote', $args ) ) . ')(?:=|$)#i';
		$query = explode( '&', $query_string );
		foreach ( $query as $key => $value )
			if ( preg_match( $regex, $value ) )
				unset( $query[ $key ] );

		$query_string = implode( '&', $query );
		return $query_string;
	}

	/**
	 * Clean up the current request variables.
	 */
	private static function clean_request() {
		// Strip ETag and If-Modified-Since headers.
		unset( $_SERVER['HTTP_IF_NONE_MATCH'] );
		unset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );

		// Remove ignored query vars.
		if ( ! empty( $_SERVER['QUERY_STRING'] ) )
			$_SERVER['QUERY_STRING'] = self::remove_query_args( $_SERVER['QUERY_STRING'], self::$ignore_request_keys );

		if ( ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( $_SERVER['REQUEST_URI'], '?' ) ) {
			$parts = explode( '?', $_SERVER['REQUEST_URI'], 2 );
			$_SERVER['REQUEST_URI'] = $parts[0];
			$query_string = self::remove_query_args( $parts[1], self::$ignore_request_keys );
			if ( ! empty( $query_string ) )
				$_SERVER['REQUEST_URI'] .= '?' . $query_string;
		}

		foreach ( self::$ignore_request_keys as $key ) {
			unset( $_GET[ $key ] );
			unset( $_REQUEST[ $key ] );
		}

		// @todo: Maybe remove $_COOKIE and related.
	}

	/**
	 * Check some conditions where pages should never be cached or served from cache.
	 */
	private static function maybe_bail() {

		// Allow an external configuration file to append to the bail method.
		if ( self::$bail_callback && is_callable( self::$bail_callback ) ) {
			$callback_result = call_user_func( self::$bail_callback );
			if ( is_bool( $callback_result ) )
				return $callback_result;
		}

		// Don't cache CLI requests
		if ( php_sapi_name() == 'cli' )
			return true;

		// Don't cache POST requests.
		if ( strtolower( $_SERVER['REQUEST_METHOD'] ) == 'post' )
			return true;

		if ( self::$ttl < 1 )
			return true;

		foreach ( $_COOKIE as $key => $value ) {
			$key = strtolower( $key );

			// Don't cache anything if these cookies are set.
			foreach ( array( 'wp', 'wordpress', 'comment_author' ) as $part ) {
				if ( strpos( $key, $part ) === 0 && ! in_array( $key, self::$ignore_cookies ) ) {
					return true;
				}
			}
		}

		return false; // Don't bail.
	}

	/**
	 * Parse config from pj-user-config.php or $redis_page_cache_config global.
	 */
	private static function maybe_user_config() {
		global $redis_page_cache_config;
		$pj_user_config = function_exists( 'pj_user_config' ) ? pj_user_config() : array();

		$keys = array(
			'redis_host',
			'redis_port',
			'redis_auth',
			'redis_db',

			'ttl',
			'unique',
			'ignore_cookies',
			'ignore_request_keys',
			'bail_callback',
			'debug',
			'gzip',
		);

		foreach ( $keys as $key ) {
			if ( isset( $pj_user_config['page_cache'][ $key ] ) ) {
				self::$$key = $pj_user_config['page_cache'][ $key ];
			} elseif ( isset( $redis_page_cache_config[ $key ] ) ) {
				self::$$key = $redis_page_cache_config[ $key ];
			}
		}
	}

	/**
	 * Runs when the output buffer stops.
	 */
	public static function output_buffer( $output ) {
		$cache = true;

		$data = array(
			'output' => $output,
			'headers' => array(),
			'flags' => array(),
			'status' => http_response_code(),
			'gzip' => false,
		);

		// Don't cache 5xx errors.
		if ( $data['status'] >= 500 )
			$cache = false;

		$data['flags'] = self::$flags;
		$data['flags'][] = 'url:' . self::get_url_hash();
		$data['flags'] = array_unique( $data['flags'] );

		// Compression.
		if ( self::$gzip && function_exists( 'gzcompress' ) ) {
			$data['output'] = gzcompress( $data['output'] );
			$data['gzip'] = true;
		}

		// Clean up headers he don't want to store.
		foreach ( headers_list() as $header ) {
			list( $key, $value ) = explode( ':', $header, 2 );
			$value = trim( $value );

			// For set-cookie headers make sure we're not passing through the
			// ignored cookies for this request, but if we encounter a non-ignored
			// cookie being set, then don't cache this request at all.

			if ( strtolower( $key ) == 'set-cookie' ) {
				$cookie = explode( ';', $value, 2 );
				$cookie = trim( $cookie[0] );
				$cookie = wp_parse_args( $cookie );

				foreach ( $cookie as $cookie_key => $cookie_value ) {
					if ( ! in_array( strtolower( $cookie_key ), self::$ignore_cookies ) ) {
						$cache = false;
						break;
					}
				}

				continue;
			}

			// Never store X-Pj-Cache-* headers in cache.
			if ( strpos( strtolower( $key ), 'x-pj-cache' ) !== false )
				continue;

			$data['headers'][] = $header;
		}

		if ( self::$debug ) {
			$data['debug'] = self::$debug_data;
		}

		$data['updated'] = time();

		if ( $cache || self::$fcgi_regenerate ) {
			$redis = self::get_redis();
			if ( ! $redis )
				return $output;

			$redis = $redis->multi();

			if ( $cache ) {
				// Okay to cache.
				$redis->set( sprintf( 'pjc-%s', self::$request_hash ), $data );
			} else {
				// Not okay, so delete any stale entry.
				$redis->delete( sprintf( 'pjc-%s', self::$request_hash ) );
			}

			$redis->delete( sprintf( 'pjc-%s-lock', self::$request_hash ) );
			$redis->exec();
		}

		// If this is a background task there's no need to return anything.
		if ( self::$fcgi_regenerate )
			return;

		return $output;
	}

	/**
	 * Essentially an md5 cache for domain.com/path?query used to
	 * bust caches by URL when needed.
	 */
	private static function get_url_hash( $url = false ) {
		if ( ! $url )
			return md5( $_SERVER['HTTP_HOST'] . self::parse_request_uri( $_SERVER['REQUEST_URI'] ) );

		$parsed = parse_url( $url );
		$request_uri = ! empty( $parsed['path'] ) ? $parsed['path'] : '';
		if ( ! empty( $parsed['query'] ) )
			$request_uri .= '?' . $parsed['query'];

		return md5( $parsed['host'] . self::parse_request_uri( $request_uri ) );
	}

	/**
	 * Schedule an expiry on transition of published posts.
	 */
	public static function transition_post_status( $new_status, $old_status, $post ) {
		if ( $new_status != 'publish' && $old_status != 'publish' )
			return;

		self::clear_cache_by_post_id( $post->ID, false );
	}

	/**
	 * Runs during template_redirect, steals some post ids and flag our caches.
	 */
	public static function template_redirect() {
		$blog_id = get_current_blog_id();

		if ( is_singular() )
			self::flag( sprintf( 'post:%d:%d', $blog_id, get_queried_object_id() ) );

		if ( is_feed() )
			self::flag( sprintf( 'feed:%d', $blog_id ) );
	}

	/**
	 * A post has changed so attempt to clear some cached pages.
	 */
	public static function clean_post_cache( $post_id ) {
		$post = get_post( $post_id );
		if ( empty( $post->post_status ) || $post->post_status != 'publish' )
			return;

		self::clear_cache_by_post_id( $post_id, false );
	}

	/**
	 * Add a flag to this request.
	 *
	 * @param string $flag Keep these short and unique, don't overuse.
	 */
	public static function flag( $flag ) {
		self::$flags[] = $flag;
	}

	/**
	 * Clear cache by URLs.
	 *
	 * @param string|array $urls A string or array of URLs to flush.
	 * @param bool $expire Expire cache by default, or delete if set to false.
	 */
	public static function clear_cache_by_url( $urls, $expire = true ) {
		if ( is_string( $urls ) )
			$urls = array( $urls );

		foreach ( $urls as $url ) {
			$flag = 'url:' . self::get_url_hash( $url );

			if ( $expire ) {
				self::$flags_expire[] = $flag;
			} else {
				self::$flags_delete[] = $flag;
			}
		}
	}

	/**
	 * Clear cache by flag or flags.
	 *
	 * @param string|array $flags A string or array of flags to expire.
	 * @param bool $expire Expire cache by default, or delete if set to false.
	 */
	public static function clear_cache_by_flag( $flags, $expire = true ) {
		if ( is_string( $flags ) )
			$flags = array( $flags );

		foreach ( $flags as $flag ) {
			if ( $expire ) {
				self::$flags_expire[] = $flag;
			} else {
				self::$flags_delete[] = $flag;
			}
		}
	}

	/**
	 * Runs during shutdown, set some flags to expire.
	 */
	public static function maybe_clear_caches() {
		$sets = array();

		if ( ! empty( self::$flags_expire ) )
			$sets['pjc-expired-flags'] = self::$flags_expire;

		if ( ! empty( self::$flags_delete ) )
			$sets['pjc-deleted-flags'] = self::$flags_delete;

		if ( empty( $sets ) )
			return;

		$redis = self::get_redis();
		if ( ! $redis )
			return;

		foreach ( $sets as $key => $flags ) {
			$flags = array_unique( $flags );
			$timestamp = time();
			$args = array( $key );

			foreach ( $flags as $flag )
				array_push( $args, $timestamp, $flag );

			$redis->multi();
			call_user_func_array( array( $redis, 'zAdd' ), $args );
			$redis->setTimeout( $key, self::$ttl );
			$redis->zRemRangeByScore( $key, '-inf', $timestamp - self::$ttl );
			$redis->zSize( $key );
			list( $_, $_, $r, $count ) = $redis->exec();

			// Hard-limit the data size.
			if ( $count > 256 ) {
				$redis->ZRemRangeByRank( $key, 0, $count - 256 - 1 );
			}
		}
	}

	/**
	 * Expire caches by post id.
	 *
	 * @param int $post_id The post ID to expire.
	 * @param bool $expire Expire cache by default, or delete if set to false.
	 */
	public static function clear_cache_by_post_id( $post_id, $expire = true ) {
		$blog_id = get_current_blog_id();
		$home = get_option( 'home' );

		// Todo, perhaps flag these and expire by home:blog_id flag.
		self::clear_cache_by_url( array(
			trailingslashit( $home ),
			$home,
		), $expire );

		self::clear_cache_by_flag( array(
			sprintf( 'post:%d:%d', $blog_id, $post_id ),
			sprintf( 'feed:%d', $blog_id ),
		), $expire );
	}
}

Redis_Page_Cache::cache_init();
