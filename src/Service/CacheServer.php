<?php

namespace RedisPageCache\Service;

use RedisPageCache\Model\CachedPage;

class CacheServer
{
    private $cache = [];

    public function __construct(CachedPage $cache)
    {
        $this->cache = $cache;
    }

    public function __invoke()
    {
        // Output cached status code.
        if (! empty($this->cache->getStatus())) {
            http_response_code($this->cache->getStatus());
        }

        // Output cached headers.
        if (! empty($this->cache->getHeaders())) {
            foreach ($this->cache->getHeaders() as $header) {
                header($header);
            }
        }

        echo $this->cache->getOutput();
    }
}
