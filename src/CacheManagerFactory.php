<?php

namespace RedisPageCache;

use RedisClient\ClientFactory;

class CacheManagerFactory 
{
  public static function getManager() {
     $redisClient = ClientFactory::create([
      'server' => '127.0.0.1:6379', 
      'timeout' => 2,
    ]);
    $cacheManager = new CacheManager($redisClient);
    return $cacheManager;
  }
}
