<?php

namespace RedisPageCache\Service;

class CacheServer
{
    private $cache = [];

    public function __construct(array $cache)
    {
        $this->cache = $cache;
    }

    public function __invoke()
    {
        // Output cached status code.
        if (! empty($this->cache['status'])) {
            http_response_code($this->cache['status']);
        }

        // Output cached headers.
        if (is_array($this->cache['headers']) && ! empty($this->cache['headers'])) {
            foreach ($this->cache['headers'] as $header) {
                header($header);
            }
        }

        echo $this->cache['output'];
    }
}
