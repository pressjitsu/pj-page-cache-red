<?php

namespace RedisPageCache;

use RedisPageCache\Service\Compressable;
use RedisPageCache\Service\WPCompat;
use RedisPageCache\Redis\Flags;
use RedisPageCache\Service\CacheServer;
use RedisPageCache\Model\CachedPage;


class CacheManager
{
    private $wp;
    private $redisClient;

    private $compressor;

    private $ttl = 3;
    private $max_ttl = 3600;
    private $unique = array();
    private $headers = array();
    private $ignore_cookies = array( 'wordpress_test_cookie' );
    private $ignore_request_keys = array( 'utm_source', 'utm_medium', 'utm_term', 'utm_content', 'utm_campaign' );
    private $whitelist_cookies = null;
    private $bail_callback = false;
    private $debug = false;
    private $gzip = false;

    private $lock = false;
    private $cache = false;
    private $request_hash = '';
    private $debug_data = false;
    private $fcgi_regenerate = false;

    // Flag requests and expire/delete them efficiently.
    private $expireFlags;
    private $deleteFlags;
    
    public function __construct(WPCompat $wp, Compressable $compressor, $redisClient = \Redis)
    {
        $this->wp = $wp;
        $this->redisClient = $redisClient;
        $this->compressor = $compressor;
        $this->expireFlags = new Flags('pjc-expired-flags', $this->redisClient);
        $this->deleteFlags = new Flags('pjc-deleted-flags', $this->redisClient);
    }

    public function getRedisClient()
    {
        return $this->redisClient;
    }

    /**
     * Runs during advanced-cache.php
     */
    public function cacheInit()
    {
        // Clear caches in bulk at the end.
        register_shutdown_function(array( $this, 'maybe_clear_caches' ));

        $this->wp
            ->addAction('clean_post_cache', [$this, 'clean_post_cache'])
            ->addAction('transition_post_status', [$this, 'transition_post_status'], 10, 3)
            ->addAction('template_redirect', [$this, 'template_redirect'], 100);

        // Parse configuration.
        $this->maybe_user_config();

        // Make sure TEST_COOKIE is always set on a wp-login.php POST request
        $this->wp->setTestCookieOnLoginReq();

        // Some things just don't need to be cached.
        if ($this->maybe_bail()) {
            if ($this->debug) {
                header('X-Pj-Cache-Bail: bail');
            }
            return;
        }

        // Clean up request variables.
        $this->clean_request();

        // are there something in the cache?
        $requestHash = array(
            'request' => $this->parse_request_uri($_SERVER['REQUEST_URI']),
            'host' => ! empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '',
            'https' => ! empty($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : '',
            'method' => $_SERVER['REQUEST_METHOD'],
            'unique' => $this->unique,
            'cookies' => $this->parse_cookies($_COOKIE),
        );
        $results = $this->checkRequestInCache($requestHash);
        $cache = $results['cache'];
        $lock =  $results['lock'];
        
        error_log(print_r($cache, true), 4);

        $cacheStatus = is_array($cache) ? 'cached' : 'miss';

        header('X-Pj-Cache-Status: ' . $cacheStatus);
        // Something is in cache.
        $this->responseFromCache($cache ?: []);

        // Cache it, smash it.
        ob_start(array( $this, 'outputBuffer' ));
    }

    private function responseFromCache(array $cache)
    {
        if (empty($cache)) {
            return;
        }

        // error_log('van cache', 4);
        if ($this->debug) {
            header('X-Pj-Cache-Time: ' . $cache['updated']);
            header('X-Pj-Cache-Flags: ' . implode(' ', $cache['flags']));
        }

        $this->expireFlags->getFromWithScores($cache['updated'] + $this->ttl);
        $this->deleteFlags->getFromWithScores($cache['updated']);

        $serve_cache = true;

        // flags are saved as ordered set and beside the actual page data
        // expiration has the url and a timestamp of expiration in the ordered set
        // this way if the url is in the expiration 
        $expired = $this->expireFlags->includes($cache['flags']);
        $deleted = $this->deleteFlags->includes($cache['flags']);

        // Cache is outdated or set to expire.
        if ($expired && !$deleted) {
            error_log('expired !deleted');
            // If it's not locked, lock it for regeneration and don't serve from cache.
            if (! $lock) {
                $lock = $this->redisSet(sprintf('pjc-%s-lock', $this->request_hash), true, array( 'nx', 'ex' => 30 ));
                if ($lock) {
                    if ($this->can_fcgi_regenerate()) {
                        // Well, actually, if we can serve a stale copy but keep the process running
                        // to regenerate the cache in background without affecting the UX, that will be great!
                        $serve_cache = true;
                        $this->fcgi_regenerate = true;
                    } else {
                        $serve_cache = false;
                    }
                }
            }
        }

        if (!$deleted && $cache['gzip']) {
            error_log('!deleted and gzipped');
            if ($this->gzip) {
                if ($this->debug) {
                    header('X-Pj-Cache-Gzip: true');
                }

                $cache['output'] = $this->compressor->deCompress($cache['output']);
            } else {
                $serve_cache = false;
            }
        }
        error_log('servce cache? '. $serve_cache);
        // serve the cached page
        if ($serve_cache) {
            // If we're regenareting in background, let everyone know.
            $status = ($this->fcgi_regenerate) ? 'expired' : 'hit';
            header('X-Pj-Cache-Status: ' . $status);
            if ($this->debug) {
                header(sprintf('X-Pj-Cache-Expires: %d', $this->ttl - (time() - $cache['updated'])));
            }
            // Actually send headers and echo content

            $cachedResponse = new CacheServer($cache);
            $cachedResponse();

            // If we can regenerate in the background, do it.
            if ($this->fcgi_regenerate) {
                fastcgi_finish_request();
                pj_sapi_headers_clean();
            } else {
                error_log('exit lesz');
                exit;
            }
        }
    }


    private function redisSet(string $key, string $value, array $ttl = [])
    {
        $redis = $this->getRedisClient();
        error_log("redis set called, key: " . $key, 4);
        return $redis->set($key, $value, count($ttl) > 0 ? $ttl : null);
    }
    public function checkRequestInCache($request_hash)
    {
        // Make sure requests with Authorization: headers are unique.
        if (! empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $request_hash['unique']['pj-auth-header'] = $_SERVER['HTTP_AUTHORIZATION'];
        }

        if ($this->debug) {
            $this->debug_data = array( 'request_hash' => $request_hash );
        }

        // Convert to an actual hash.
        $this->request_hash = $this->generateHashFomRequestParams($request_hash);

        if ($this->debug) {
            header('X-Pj-Cache-Key: ' . $this->request_hash);
        }

        $redis = $this->getRedisClient();
        if (! $redis) {
            throw new \Exception('Redis client is null (is it running? is the config OK?)');
        }

        // Look for an existing cache entry by request hash.
        list($cache, $lock) = $redis->mGet(array(
            $this->keyFromHash($this->request_hash),
            $this->keyFromHash($this->request_hash, 'lock'),
        ));

        return [
            'cache' => $cache ? $this->safeDeSerialize($cache) : null,
            'lock' => $lock ? $this->safeDeSerialize($lock) : null
            ];
    }

    public function generateHashFomRequestParams(array $requestParams) :string
    {
        return md5(serialize($requestParams));
    }

    public function keyFromHash(string $hash, string $type = 'cache') :string
    {
        if ($type === 'cache') {
            return sprintf('pjc-%s', $hash);
        }

        return sprintf('pjc-%s-lock', $hash);
    }

    /**
     * Returns true if we can regenerate the request in background.
     */
    private function can_fcgi_regenerate()
    {
        return (php_sapi_name() == 'fpm-fcgi' && function_exists('fastcgi_finish_request') && function_exists('pj_sapi_headers_clean'));
    }


    /**
     * Take a request uri and remove ignored request keys.
     */
    private function parse_request_uri($request_uri)
    {
        // Prefix the request URI with a host to avoid breaking on requests that start
        // with a // which parse_url() would treat as containing the hostname.
        $request_uri = 'http://null' . $request_uri;
        $parsed = parse_url($request_uri);

        if (! empty($parsed['query'])) {
            $query = $this->remove_query_args($parsed['query'], $this->ignore_request_keys);
        }

        $request_uri = ! empty($parsed['path']) ? $parsed['path'] : '';
        if (! empty($query)) {
            $request_uri .= '?' . $query;
        }

        return $request_uri;
    }

    /**
     * Take some cookies and remove ones we don't care about.
     */
    private function parse_cookies($cookies)
    {
        foreach ($cookies as $key => $value) {
            if (in_array(strtolower($key), $this->ignore_cookies)) {
                unset($cookies[ $key ]);
                continue;
            }

            // Skip cookies beginning with _
            if (substr($key, 0, 1) === '_') {
                unset($cookies[ $key ]);
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
    private function remove_query_args($query_string, $args)
    {
        $regex = '#^(?:' . implode('|', array_map('preg_quote', $args)) . ')(?:=|$)#i';
        $query = explode('&', $query_string);
        foreach ($query as $key => $value) {
            if (preg_match($regex, $value)) {
                unset($query[ $key ]);
            }
        }

        $query_string = implode('&', $query);
        return $query_string;
    }

    /**
     * Clean up the current request variables.
     */
    private function clean_request()
    {
        // Strip ETag and If-Modified-Since headers.
        unset($_SERVER['HTTP_IF_NONE_MATCH']);
        unset($_SERVER['HTTP_IF_MODIFIED_SINCE']);

        // Remove ignored query vars.
        if (! empty($_SERVER['QUERY_STRING'])) {
            $_SERVER['QUERY_STRING'] = $this->remove_query_args($_SERVER['QUERY_STRING'], $this->ignore_request_keys);
        }

        if (! empty($_SERVER['REQUEST_URI']) && false !== strpos($_SERVER['REQUEST_URI'], '?')) {
            $parts = explode('?', $_SERVER['REQUEST_URI'], 2);
            $_SERVER['REQUEST_URI'] = $parts[0];
            $query_string = $this->remove_query_args($parts[1], $this->ignore_request_keys);
            if (! empty($query_string)) {
                $_SERVER['REQUEST_URI'] .= '?' . $query_string;
            }
        }

        foreach ($this->ignore_request_keys as $key) {
            unset($_GET[ $key ]);
            unset($_REQUEST[ $key ]);
        }

        // If we have a whitelist set, clear out everything that does not
        // match the list, unless we're in the wp-admin (but not in admin-ajax.php).
        if (! empty($this->whitelist_cookies) && (! $this->wp->isAdmin() || $this->wp->isAdminAjax())) {
            foreach ($_COOKIE as $key => $value) {
                $whitelist = false;

                foreach ($this->whitelist_cookies as $part) {
                    if (strpos($key, $part) === 0) {
                        $whitelist = true;
                        break;
                    }
                }

                if (! $whitelist) {
                    unset($_COOKIE[ $key ]);
                }
            }
        }
    }

    /**
     * Check some conditions where pages should never be cached or served from cache.
     */
    private function maybe_bail()
    {

        // Allow an external configuration file to append to the bail method.
        if ($this->bail_callback && is_callable($this->bail_callback)) {
            $callback_result = call_user_func($this->bail_callback);
            if (is_bool($callback_result)) {
                return $callback_result;
            }
        }

        // Don't cache CLI requests
        if (php_sapi_name() == 'cli') {
            return true;
        }

        // Don't cache POST requests.
        if (strtolower($_SERVER['REQUEST_METHOD']) == 'post') {
            return true;
        }

        if ($this->ttl < 1) {
            return true;
        }

        foreach ($_COOKIE as $key => $value) {
            $key = strtolower($key);

            // Don't cache anything if these cookies are set.
            foreach (array( 'wp', 'wordpress', 'comment_author' ) as $part) {
                if (strpos($key, $part) === 0 && ! in_array($key, $this->ignore_cookies)) {
                    return true;
                }
            }
        }

        return false; // Don't bail.
    }

    /**
     * Parse config from pj-user-config.php or $redis_page_cache_config global.
     */
    private function maybe_user_config()
    {
        global $redis_page_cache_config;
        $pj_user_config = function_exists('pj_user_config') ? pj_user_config() : array();

        $keys = array(
            'redis_host',
            'redis_port',
            'redis_auth',
            'redis_db',

            'ttl',
            'unique',
            'ignore_cookies',
            'ignore_request_keys',
            'whitelist_cookies',
            'bail_callback',
            'debug',
        );

        foreach ($keys as $key) {
            if (isset($pj_user_config['page_cache'][ $key ])) {
                $this->key = $pj_user_config['page_cache'][ $key ];
            } elseif (isset($redis_page_cache_config[ $key ])) {
                $this->key = $redis_page_cache_config[ $key ];
            }
        }
    }

    /**
     * Runs when the output buffer stops.
     */
    public function outputBuffer($output)
    {
        $cache = true;

        $cachedPage = new CachedPage($output);
        $cachedPage
            ->setStatus(http_response_code());
        
        // Don't cache 5xx errors.
        if ($data['status'] >= 500) {
            $cache = false;
        }

        $data['flags'] = array_merge($this->expireFlags->getAll(), $this->deleteFlags->getAll());
        $data['flags'][] = 'url:' . $this->get_url_hash();
        $data['flags'] = array_unique($data['flags']);

        // Compression.
        if ($this->gzip) {
            $cachedPage->setOutput($this->compressor->compress($data['output']));
            $data['gzip'] = true;
        }

        // Clean up headers he don't want to store.
        foreach (headers_list() as $header) {
            list($key, $value) = explode(':', $header, 2);
            $value = trim($value);

            // For set-cookie headers make sure we're not passing through the
            // ignored cookies for this request, but if we encounter a non-ignored
            // cookie being set, then don't cache this request at all.

            if (strtolower($key) == 'set-cookie') {
                $cookie = explode(';', $value, 2);
                $cookie = trim($cookie[0]);
                $cookie = wp_parse_args($cookie);

                foreach ($cookie as $cookie_key => $cookie_value) {
                    if (! in_array(strtolower($cookie_key), $this->ignore_cookies)) {
                        $cache = false;
                        break;
                    }
                }

                continue;
            }

            // Never store X-Pj-Cache-* headers in cache.
            if (strpos(strtolower($key), 'x-pj-cache') !== false) {
                continue;
            }

            $data['headers'][] = $header;
        }

        if ($this->debug) {
            $data['debug'] = $this->debug_data;
        }

        $data['updated'] = time();
        //error_log(print_r($data, true), 4);
        $data = $this->safeSerialize($data);
        if ($cache || $this->fcgi_regenerate) {
            $redis = $this->getRedisClient();
            if (! $redis) {
                throw new \Exception('Redis has gone.');
            }
                
            $redis->multi();
            if ($cache) {
                $key = sprintf('pjc-%s', $this->request_hash);
                // Okay to cache.
                $this->redisSet($key, $data);
            } else {
                // Not okay, so delete any stale entry.
                $redis->del($key);
            }

            $redis->del(sprintf('pjc-%s-lock', $this->request_hash));
            $redis->exec();
        }

        // If this is a background task there's no need to return anything.
        if ($this->fcgi_regenerate) {
            return;
        }

        return $output;
    }

    public function safeSerialize(array $data)
    {
        return base64_encode(serialize($data));
    }

    public function safeDeSerialize(string $data)
    {
        return unserialize(base64_decode($data));
    }

    /**
     * Essentially an md5 cache for domain.com/path?query used to
     * bust caches by URL when needed.
     */
    private function get_url_hash($url = false)
    {
        if (! $url) {
            return md5($_SERVER['HTTP_HOST'] ?? '' . $this->parse_request_uri($_SERVER['REQUEST_URI'] ?? ''));
        }

        $parsed = parse_url($url);
        $request_uri = ! empty($parsed['path']) ? $parsed['path'] : '';
        if (! empty($parsed['query'])) {
            $request_uri .= '?' . $parsed['query'];
        }

        return md5($parsed['host'] . $this->parse_request_uri($request_uri));
    }

    /**
     * Schedule an expiry on transition of published posts.
     */
    public function transition_post_status($new_status, $old_status, $post)
    {
        if ($new_status != 'publish' && $old_status != 'publish') {
            return;
        }

        $this->clear_cache_by_post_id($post->ID, false);
    }

    /**
     * Runs during template_redirect, steals some post ids and flag our caches.
     */
    public function template_redirect()
    {
        $blog_id = get_current_blog_id();

        if (is_singular()) {
            $this->flag(sprintf('post:%d:%d', $blog_id, get_queried_object_id()));
        }

        if (is_feed()) {
            $this->flag(sprintf('feed:%d', $blog_id));
        }
    }

    /**
     * A post has changed so attempt to clear some cached pages.
     */
    public function clean_post_cache($post_id)
    {
        $post = get_post($post_id);
        if (empty($post->post_status) || $post->post_status != 'publish') {
            return;
        }

        $this->clear_cache_by_post_id($post_id, false);
    }

    /**
     * Add a flag to this request.
     *
     * @param string $flag Keep these short and unique, don't overuse.
     */
    public function flag($flag)
    {
        $this->flags[] = $flag;
    }

    /**
     * Clear cache by URLs.
     *
     * @param string|array $urls A string or array of URLs to flush.
     * @param bool $expire Expire cache by default, or delete if set to false.
     */
    public function clear_cache_by_url($urls, $expire = true)
    {
        if (is_string($urls)) {
            $urls = array( $urls );
        }

        foreach ($urls as $url) {
            $flag = 'url:' . $this->get_url_hash($url);

            if ($expire) {
                $this->expireFlags->add($flag);
            } else {
                $this->deleteFlags->add($flag);
            }
        }
    }

    /**
     * Clear cache by flag or flags.
     *
     * @param string|array $flags A string or array of flags to expire.
     * @param bool $expire Expire cache by default, or delete if set to false.
     */
    public function clear_cache_by_flag($flags, $expire = true)
    {
        if (is_string($flags)) {
            $flags = array( $flags );
        }

        foreach ($flags as $flag) {
            if ($expire) {
                $this->expireFlags->add($flag);
            } else {
                $this->deleteFlags->add($flag);
            }
        }
    }

    /**
     * Runs during shutdown, set some flags to expire.
     */
    public function maybe_clear_caches()
    {
        error_log('maybe clear cache'); 
        $this->expireFlags->update();
        $this->deleteFlags->update();
    }

    /**
     * Expire caches by post id.
     *
     * @param int $post_id The post ID to expire.
     * @param bool $expire Expire cache by default, or delete if set to false.
     */
    public function clear_cache_by_post_id($post_id, $expire = true)
    {
        $blog_id = get_current_blog_id();
        $home = get_option('home');

        // Todo, perhaps flag these and expire by home:blog_id flag.
        $this->clear_cache_by_url(array(
            trailingslashit($home),
            $home,
        ), $expire);

        $this->clear_cache_by_flag(array(
            sprintf('post:%d:%d', $blog_id, $post_id),
            sprintf('feed:%d', $blog_id),
        ), $expire);
    }

    public function getRequestHash() :string
    {
        return $this->request_hash;
    }

    public function setRequestHash(string $hash) :CacheManager
    {
        $this->request_hash = $hash;

        return $this;
    }

    /**
     * @return boolean
     */
    public function isDebug()
    {
        return $this->debug;
    }

    /**
     * @param boolean $debug
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    public function setGzip(bool $value): CacheManager
    {
        $this->gzip = $value;

        return $this;
    }
}
