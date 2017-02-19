<?php

use RedisPageCache\CacheManager;
use RedisPageCache\CacheManagerFactory;

use RedisClient\ClientFactory;

describe('CacheManager', function() {
  it('should accept redis client as dependency', function() {
    $redisClient = ClientFactory::create([
      'server' => '127.0.0.1:6379', // or 'unix:///tmp/redis.sock'
      'timeout' => 2,
    ]);
    $cacheManager = new CacheManager($redisClient);
    // 
    assert(method_exists($cacheManager, 'getRedisClient'), 'no getRedisClient method exists');
    assert($cacheManager->getRedisClient() === $redisClient, 'injected redis is not the same as it was');
  });
});


