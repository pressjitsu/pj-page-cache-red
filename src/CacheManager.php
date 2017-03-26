<?php

namespace RedisPageCache;

use RedisPageCache\Model\KeyFactory;
use RedisPageCache\Model\Request;
use RedisPageCache\Service\CacheReader;
use RedisPageCache\Service\CacheWriter;
use RedisPageCache\Service\Compressable;
use RedisPageCache\Service\HeaderParser;
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

    private $whitelist_cookies = null;
    private $bail_callback = false;
    private $debug = false;
    private $gzip = false;

    private $request;
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
        register_shutdown_function(array($this, 'maybe_clear_caches'));

        $this->wp
            ->addAction('clean_post_cache', [$this, 'clean_post_cache'])
            ->addAction('transition_post_status', [$this, 'transition_post_status'], 10, 3)
            ->addAction('template_redirect', [$this, 'template_redirect'], 100);

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
        $request = new Request(
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_HOST'],
            $_SERVER['HTTPS'],
            $_SERVER['REQUEST_METHOD'],
            $_COOKIE
        );
        $this->setRequest($request);
        $cacheReader = new CacheReader($this->request, $this->redisClient);
        $results = $cacheReader->checkRequest();
        $cache = $results['cache'];
        $lock = $results['lock'];

        error_log(print_r($cache, true), 4);

        $cacheStatus = is_array($cache) ? 'cached' : 'miss';

        header('X-Pj-Cache-Status: '.$cacheStatus);
        // Something is in cache.
        $this->responseFromCache($cache ?: [], $lock);

        // Cache it, smash it.
        ob_start(array($this, 'outputBuffer'));
    }

    // This is important for tests, please keep it
    public function setRequest(Request $request)
    {
        $this->request = $request;
    }

    private function responseFromCache(array $cache, $lock)
    {
        if (empty($cache)) {
            return;
        }

        // error_log('van cache', 4);
        if ($this->debug) {
            header('X-Pj-Cache-Time: '.$cache['updated']);
            header('X-Pj-Cache-Flags: '.implode(' ', $cache['flags']));
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
            if (!$lock) {
                $lock = $this->redisSet(sprintf('pjc-%s-lock', $this->request_hash), true, array('nx', 'ex' => 30));
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
        error_log('servce cache? '.$serve_cache);
        // serve the cached page
        if ($serve_cache) {
            // If we're regenareting in background, let everyone know.
            $status = ($this->fcgi_regenerate) ? 'expired' : 'hit';
            header('X-Pj-Cache-Status: '.$status);
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

    /**
     * Returns true if we can regenerate the request in background.
     */
    private function can_fcgi_regenerate()
    {
        return (php_sapi_name() == 'fpm-fcgi' && function_exists('fastcgi_finish_request') && function_exists(
                'pj_sapi_headers_clean'
            ));
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
        if (!empty($_SERVER['QUERY_STRING'])) {
            $_SERVER['QUERY_STRING'] = $this->remove_query_args($_SERVER['QUERY_STRING'], $this->ignore_request_keys);
        }

        if (!empty($_SERVER['REQUEST_URI']) && false !== strpos($_SERVER['REQUEST_URI'], '?')) {
            $parts = explode('?', $_SERVER['REQUEST_URI'], 2);
            $_SERVER['REQUEST_URI'] = $parts[0];
            $query_string = $this->remove_query_args($parts[1], $this->ignore_request_keys);
            if (!empty($query_string)) {
                $_SERVER['REQUEST_URI'] .= '?'.$query_string;
            }
        }

        foreach ($this->ignore_request_keys as $key) {
            unset($_GET[$key]);
            unset($_REQUEST[$key]);
        }

        // If we have a whitelist set, clear out everything that does not
        // match the list, unless we're in the wp-admin (but not in admin-ajax.php).
        if (!empty($this->whitelist_cookies) && (!$this->wp->isAdmin() || $this->wp->isAdminAjax())) {
            foreach ($_COOKIE as $key => $value) {
                $whitelist = false;

                foreach ($this->whitelist_cookies as $part) {
                    if (strpos($key, $part) === 0) {
                        $whitelist = true;
                        break;
                    }
                }

                if (!$whitelist) {
                    unset($_COOKIE[$key]);
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
            foreach (array('wp', 'wordpress', 'comment_author') as $part) {
                if (strpos($key, $part) === 0 && !in_array($key, $this->ignore_cookies)) {
                    return true;
                }
            }
        }

        return false; // Don't bail.
    }

    /**
     * Runs when the output buffer stops.
     * @param $output
     * @return string
     */
    public function outputBuffer($output): string
    {
        error_log('request ' . print_r($this->request, true), 4);
        $responseCode = http_response_code();
        // Don't cache 5xx errors.
        if ($responseCode >= 500) {
            return $output;
        }

        $cachedPage = new CachedPage($output, $responseCode);

        $cachedPage->setFlags(
            array_merge(
                $this->expireFlags->getAll(),
                $this->deleteFlags->getAll(),
                ['url:'.$this->request->getUri()]
            )
        );

        // Compression.
        if ($this->gzip) {
            $cachedPage
                ->setOutput($this->compressor->compress($cachedPage->getOutput()))
                ->setGzip(true);
        }

        // Clean up headers he don't want to store.
        $headerParser = new HeaderParser();
        $headerParser();
        $cookies = $headerParser->getCookies();

        /** @var TYPE_NAME $cachedPage */
        $cacheWriter = new CacheWriter($cachedPage, $this->request, $this->redisClient);
        error_log('cached page ' . print_r($cachedPage, true), 4);
        // Ignore requests with cookie = don't cache
        if (in_array(Request::IGNORECOOKIES, $cookies)) {
            error_log('remove from cache: ' . $cacheWriter->getKey()->get(), 4);
            $cacheWriter->remove();
            return $output;
        }

        if ($this->debug) {
            $cachedPage->setDebugData($this->debug_data);
        }

        if ($this->fcgi_regenerate) {

            $cacheWriter->add();
        }
        error_log('not added to cache: ' . $cacheWriter->getKey()->get(), 4);
        return $output;
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
     * Clear cache by URLs.
     *
     * @param string|array $urls A string or array of URLs to flush.
     * @param bool $expire Expire cache by default, or delete if set to false.
     */
    public function clear_cache_by_url($urls, $expire = true)
    {
        if (is_string($urls)) {
            $urls = array($urls);
        }

        foreach ($urls as $url) {
            $flag = 'url:'.$this->get_url_hash($url);

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
            $flags = array($flags);
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
        $this->clear_cache_by_url(
            array(
                trailingslashit($home),
                $home,
            ),
            $expire
        );

        $this->clear_cache_by_flag(
            array(
                sprintf('post:%d:%d', $blog_id, $post_id),
                sprintf('feed:%d', $blog_id),
            ),
            $expire
        );
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

    /**
     * @return bool
     */
    public function isFcgiRegenerate(): bool
    {
        return $this->fcgi_regenerate;
    }

    /**
     * @param bool $fcgi_regenerate
     * @return CacheManager
     */
    public function setFcgiRegenerate(bool $fcgi_regenerate): CacheManager
    {
        $this->fcgi_regenerate = $fcgi_regenerate;

        return $this;
    }


}
