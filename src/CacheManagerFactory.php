<?php

namespace RedisPageCache;

use RedisClient\ClientFactory;
use RedisPageCache\Service\GzipCompressor;

class CacheManagerFactory
{
    public static function getManager()
    {
        $redisClient = ClientFactory::create([
            'server' => '127.0.0.1:6379',
            'timeout' => 2,
        ]);
        
        $cacheManager = new CacheManager(new GzipCompressor, $redisClient);
        return $cacheManager;
    }
}
