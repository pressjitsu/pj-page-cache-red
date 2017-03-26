<?php

use RedisPageCache\CacheManager;
use RedisPageCache\CacheManagerFactory;
use RedisPageCache\Service\GzipCompressor;
use RedisPageCache\Service\WPCompat;
use RedisPageCache\Model\KeyFactory;


use RedisClient\ClientFactory;

describe('CacheManager', function () {
    beforeEach(function () {
        $this->cacheManager = CacheManagerFactory::getManager();
        $this->redisClient =  $redisClient = ClientFactory::create([
            'server' => '127.0.0.1:6379', // or 'unix:///tmp/redis.sock'
            'timeout' => 2,
        ]);
    });

    it('should accept redis client as dependency', function () {
        $cacheManager = new CacheManager(new WPCompat, new GzipCompressor, $this->redisClient);
        //
        assert(method_exists($cacheManager, 'getRedisClient'), 'no getRedisClient method exists');
        assert($cacheManager->getRedisClient() === $this->redisClient, 'injected redis is not the same as it was');
    });

    it('should return empty results when checks something missing from cache', function () {
        // are there something in the cache?
        $request = new \RedisPageCache\Model\Request(
            'no-redis-cache-test.html' . md5(microtime(true)),
            '',
            '',
            'GET',
            [ 'test_cookie' => 'content of test cookie' ],
            []
        );
        $cacheReader = new \RedisPageCache\Service\CacheReader($request, $this->redisClient);
        $result = $cacheReader->checkRequest();
        assert($result === [ "cache" => null, "lock" => null ], 'Resulting array is not [ "cache" => null, "lock" => null ]');
    });
  
    it('should generate a hash from request array', function () {
        $request = new \RedisPageCache\Model\Request(
            'nothing.html',
            '',
            '',
            'GET'
            );
        $hash = $request->getHash();
        var_dump($hash);
        assert($hash === "4ebad7e867ffa267c3c91ed43371aa2d", "Hash does not match");
    });

    it('should create cache and lock keys from hash', function () {
        $request = new \RedisPageCache\Model\Request(
            'nothing.html',
            '',
            '',
            'GET'
        );
        $defaultKey = KeyFactory::getKey(KeyFactory::TYPE_DEFAULT, $request);
        $result = $defaultKey->get();
        assert($result === "pjc-4ebad7e867ffa267c3c91ed43371aa2d", 'cache key generation failed');
        $lockKey = KeyFactory::getKey(KeyFactory::TYPE_LOCK, $request);
        $result = $lockKey->get();
        assert($result === "pjc-lock-4ebad7e867ffa267c3c91ed43371aa2d", 'lock cache key generation failed');
    });
  
    it('should return results when checks something available in cache', function () {
        $redis = $this->redisClient;
        $request = new \RedisPageCache\Model\Request(
            'nothing.html',
            '',
            '',
            'GET'
        );
        $cachedPage = new \RedisPageCache\Model\CachedPage('result in redis', 200);
        $cacheWriter = new \RedisPageCache\Service\CacheWriter($cachedPage, $request, $redis);
        $cacheWriter->add();

        $cacheReader = new \RedisPageCache\Service\CacheReader($request, $redis);
        $result = $cacheReader->checkRequest();

        assert(is_array($result), "result is not an array");
        assert(array_key_exists("cache", $result), "result array doesn't have a cache key");

        $loadedCachedPage = $result["cache"];
        assert($loadedCachedPage->getOutput() === $cachedPage->getOutput(), 'Result is not the same as cachedPage');
        assert($result["lock"] === null, '$result["lock"] !== null');
    });

    it('should add example content to the redis cache (with gzip)', function () {
        // remove if exists
        $redis = $this->redisClient;

        $request = new \RedisPageCache\Model\Request(
            'example.com/page/nothing.html',
            '',
            '',
            'GET'
        );
        // We need a brand new manager
        $this->cacheManager = CacheManagerFactory::getManager();
        $this->cacheManager->setGzip(true);
        $this->cacheManager->setRequest($request);
        $defaultKey = KeyFactory::getKey(KeyFactory::TYPE_DEFAULT, $request);
        $key = $defaultKey->get();
        // remove from Redis if exists
        $redis->del($key);
        $this->cacheManager->setFcgiRegenerate(true);
        $this->cacheManager->outputBuffer("example content");
  
        $rawResult = $redis->get($key);
        var_dump('rawResult for key: ' . $key, $rawResult);
        assert($rawResult !== null, 'Raw result is null, probably not saved');

        $cacheReader = new \RedisPageCache\Service\CacheReader($request, $redis);
        $results = $cacheReader->checkRequest();
        $compressor = new GzipCompressor();
        assert(is_array($results), "result is not an array");
        assert(property_exists($results['cache'], 'output'), "result doesn't have an 'output' property");

        assert($compressor->deCompress($results['cache']->getOutput()) === "example content", '$result::output is not "result in redis"');
    });
     it('should add example content to the redis cache without gzip', function () {
        $this->cacheManager->setGzip(false);
        
        $key = "pjc-5bcc4473510ee608f6d3395d47f067b8";
        // remove if exists
        $redis = $this->cacheManager->getRedisClient();
        $redis->del($key);
        $hash = $this->cacheManager->generateHashFomRequestParams(['request' => 'nothing2.html']);
        $this->cacheManager->setRequestHash($hash); // temporary
        $this->cacheManager->outputBuffer("example content");
        
        $redis = $this->cacheManager->getRedisClient();
        $rawResult = $redis->get($key);
        $result = $this->cacheManager->safeDeSerialize($rawResult);
        assert(is_array($result), "result is not an array");
        assert(array_key_exists("output", $result), "result array doesn't have an 'output' key");
        
        assert($result["output"] === "example content", '$result["output"] is not "example content"');
     });
});
