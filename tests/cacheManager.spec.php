<?php

use RedisPageCache\CacheManager;
use RedisPageCache\CacheManagerFactory;

use RedisClient\ClientFactory;

describe('CacheManagerFactory', function() {
  it('should create a cache manager with autowired Redis package client', function() {
    $redisClient = ClientFactory::create([
      'server' => '127.0.0.1:6379', // or 'unix:///tmp/redis.sock'
      'timeout' => 2,
    ]);
    $cacheManager = CacheManagerFactory::getManager();
    assert(method_exists(CacheManagerFactory::class, 'getManager'), 'no getManager static method exists');
    $injectedRedis = $cacheManager->getRedisClient();
    assert(get_class($injectedRedis) === 'RedisClient\RedisClient' , 'no injected redis found');
  });
});