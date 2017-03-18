<?php

namespace RedisPageCache;

use RedisClient\ClientFactory;
use RedisPageCache\Service\GzipCompressor;
use RedisPageCache\Service\WPCompat;

class CacheManagerFactory
{
    public static function getManager()
    {
        $redisClient = ClientFactory::create([
            'server' => '127.0.0.1:6379',
            'timeout' => 2,
        ]);
        
        $cacheManager = new CacheManager(new WPCompat, new GzipCompressor, $redisClient);
        return $cacheManager;
    }
}
