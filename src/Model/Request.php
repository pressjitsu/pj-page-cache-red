<?php
/**
 * Created by PhpStorm.
 * User: balint
 * Date: 2017. 03. 25.
 * Time: 14:35
 */

namespace RedisPageCache\Model;


class Request
{
    private $uri;
    private $host;
    private $https;
    private $method;
    private $unique = [];
    private $cookies;
    private $ignoreRequestKeys = array('utm_source', 'utm_medium', 'utm_term', 'utm_content', 'utm_campaign');
    const IGNORECOOKIES = array('wordpress_test_cookie');

    public function __construct(string $requestURI = null, string $host = null, string $https = null, string $method = null, array $cookies = null)
    {
        if (null === $requestURI) {
            $this->uri = $_SERVER['REQUEST_URI'];
        } else {
            $this->uri = $requestURI;
        }

        $this->uri = $this->parseRequestURI($this->uri);

        if (null === $host) {
            $this->host = $_SERVER['HTTP_HOST'];
        } else {
            $this->host = $host;
        }

        if (null === $https) {
            $this->https = !empty($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : null;
        } else {
            $this->https = $https;
        }
        
        if (null === $method) {
            $this->method = $_SERVER['REQUEST_METHOD'];
        } else {
            $this->method = $method;
        }
        
        if (null === $cookies) {
            $this->cookies = $this->parseCookies($_COOKIE);
        } else {
            $this->cookies = $this->parseCookies($cookies);
        }

        // Make sure requests with Authorization: headers are unique.
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $$this->unique['pj-auth-header'] = $_SERVER['HTTP_AUTHORIZATION'];
        }
    }
    
    private function parseRequestURI($requestUri)
    {
        // Prefix the request URI with a host to avoid breaking on requests that start
        // with a // which parse_url() would treat as containing the hostname.
        $requestUri = 'http://null'. $requestUri;
        $parsed = parse_url($requestUri);
        if (!empty($parsed['query'])) {
            $query = $this->removeQueryArgs($parsed['query']);
        }

        $request_uri = !empty($parsed['path']) ? $parsed['path'] : '';
        if (!empty($query)) {
            $request_uri .= '?'.$query;
        }

        return $request_uri;
    }
    
    private function removeQueryArgs(string $query): string
    {
        $regex = '#^(?:'.implode('|', array_map('preg_quote', $this->ignoreRequestKeys)).')(?:=|$)#i';
        $query = explode('&', $query);
        foreach ($query as $key => $value) {
            if (preg_match($regex, $value)) {
                unset($query[$key]);
            }
        }

        $queryString = implode('&', $query);

        return $queryString;
    }
    
    private function parseCookies(array $cookies): array
    {
        foreach ($cookies as $key => $value) {
            if (in_array(strtolower($key), self::IGNORECOOKIES)) {
                unset($cookies[$key]);
                continue;
            }

            // Skip cookies beginning with _
            if (substr($key, 0, 1) === '_') {
                unset($cookies[$key]);
                continue;
            }
        }

        return $cookies;
    }
    
    public function getArray(): array
    {
        return [
            'request' => $this->uri,
            'host' => $this->host,
            'https' => $this->https,
            'method' => $this->method,
            'unique' => $this->unique,
            'cookies' => $this->cookies,
        ];
    }

    public function getHash() :string
    {
        return md5(serialize($this->getArray()));
    }

    /**
     * Essentially an md5 cache for domain.com/path?query used to
     * bust caches by URL when needed.
     */
    public function getUrlHash($url = false)
    {
        if (!$url) {
            return md5($_SERVER['HTTP_HOST'] ?? ''.$this->parseRequestURI($_SERVER['REQUEST_URI'] ?? ''));
        }

        $parsed = parse_url($url);
        $request_uri = !empty($parsed['path']) ? $parsed['path'] : '';
        if (!empty($parsed['query'])) {
            $request_uri .= '?'.$parsed['query'];
        }

        return md5($parsed['host'].$this->parseRequestURI($request_uri));
    }

    /**
     * @return string
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * @param string $uri
     * @return Request
     */
    public function setUri(string $uri): Request
    {
        $this->uri = $uri;

        return $this;
    }


}