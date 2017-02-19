<?php

use RedisPageCache\CacheManager;
use RedisPageCache\CacheManagerFactory;

use RedisClient\ClientFactory;

describe('CacheManager', function() {
  beforeEach(function(){
    $this->cacheManager = CacheManagerFactory::getManager();
  });

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

  it('should return empty results when checks something missing from cache', function(){
    // are there something in the cache?
		$requestHash = array(
			'request' => 'no-redis-cache-test.html' . md5(microtime(true)),
			'host' => '',
			'https' => '',
			'method' => 'GET',
			'unique' => [],
			'cookies' => [],
		);
		$result = $this->cacheManager->checkRequestInCache($requestHash);
    assert($result === [ "cache" => null, "lock" => null ], 'Resulting array is not [ "cache" => null, "lock" => null ]');
  });
  
  it('should generate a hash from request array', function(){
    $hash = $this->cacheManager->generateHashFomRequestParams(['request' => 'nothing.html']);
    assert($hash === "3e4c887c1c83086ac1766700ba0e2384", "Hash is not match");
  });

  it('should create cache and lock keys from hash', function(){
    $result = $this->cacheManager->keyFromHash("3e4c887c1c83086ac1766700ba0e2384");
    assert($result === "pjc-3e4c887c1c83086ac1766700ba0e2384", 'cache key generation failed');

    $result = $this->cacheManager->keyFromHash("3e4c887c1c83086ac1766700ba0e2384", "lock");
    assert($result === "pjc-3e4c887c1c83086ac1766700ba0e2384-lock", 'lock cache key generation failed');
  });
  
  it('should return results when checks something available in cache', function(){
    $redis = $this->cacheManager->getRedisClient();
    $redis->set("pjc-3e4c887c1c83086ac1766700ba0e2384", "result in redis");

    $result = $this->cacheManager->checkRequestInCache(['request' => 'nothing.html']);
    
    assert(is_array($result), "result is not an array");
    assert(array_key_exists("cache", $result), "result array doesn't have a cache key");
    assert($result["cache"] === "result in redis", 'Result is not [ "cache" => "result in redis", "lock" => null ]');
    assert($result["lock"] === null, '$result["lock"] !== null');
  });

});


