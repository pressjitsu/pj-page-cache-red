<?php

use RedisPageCache\CacheManager;
use RedisPageCache\CacheManagerFactory;
use RedisPageCache\Service\CacheServer;

use RedisClient\ClientFactory;


describe('Cache Server', function () {
    it('should serve a request from a cache array', function () {
        $redisClient = ClientFactory::create([
        'server' => '127.0.0.1:6379', // or 'unix:///tmp/redis.sock'
        'timeout' => 2,
        ]);

        $cachedResponse = new CacheServer(['ouptut' => 'cached content']);
        $cachedResponse();
        assert($expireds == ['url:/about' => time(), 'url:/luca' => time()], 'expired flags failed');
    });
});
