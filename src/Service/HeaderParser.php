<?php

namespace RedisPageCache\Service;

class HeaderParser
{
    private $headers;
    private $cookies = [];

    public function __construct(array $header = null)
    {
        if ($header === null) {
            $this->headers = headers_list();
        } else {
            $this->headers = $header;
        }
    }

    public function __invoke()
    {
        foreach ($this->headers as $header) {
            list($key, $value) = explode(':', $header, 2);
            $value = trim($value);

            // For set-cookie headers make sure we're not passing through the
            // ignored cookies for this request, but if we encounter a non-ignored
            // cookie being set, then don't cache this request at all.
            if (strtolower($key) == 'set-cookie') {
                $cookie = explode(';', $value, 2);
                $cookie = trim($cookie[0]);
                $cookies = [];
                parse_str($cookie, $cookies);
                $this->cookies = $cookies;            }

            // Never store X-Pj-Cache-* headers in cache.
            if (strpos(strtolower($key), 'x-pj-cache') !== false) {
                continue;
            }

            $this->headers[] = $header;
        }
        
        return $this;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @param array $headers
     * @return HeaderParser
     */
    public function setHeaders($headers)
    {
        $this->headers = $headers;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getCookies()
    {
        return $this->cookies;
    }

    /**
     * @param mixed $cookies
     * @return HeaderParser
     */
    public function setCookies($cookies)
    {
        $this->cookies = $cookies;

        return $this;
    }
    
}