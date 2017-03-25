<?php
/**
 * Created by PhpStorm.
 * User: balint
 * Date: 2017. 03. 25.
 * Time: 8:04
 */

namespace RedisPageCache\Service;


use RedisPageCache\Model\CachedPage;
use RedisPageCache\Model\Request;
use RedisPageCache\Model\KeyFactory;

class CacheWriter
{
    private $cachedPage;
    private $key;
    private $request;

    public function __construct(CachedPage $cachedPage, Request $request, $redisClient = \Redis)
    {
        $this->cachedPage = $cachedPage;
        $this->request = $request;
        $this->redisClient = $redisClient;
        if (!$this->redisClient) {
            throw new \Exception('Redis has gone.');
        }
        $this->key = KeyFactory::getKey(KeyFactory::$TYPE_DEFAULT, $this->request);
    }
    
    public function add()
    {
        $this->redisClient->multi();
        // Okay to cache.
        $this->redisSet($this->key, $this->safeSerialize($this->cachedPage));
        $this->finalize();
    }
    
    public function remove()
    {
        $this->redisClient->multi();
        $this->redisClient->del($this->key);
        $this->finalize();
    }
    
    private function finalize()
    {
        $this->redisClient->del(KeyFactory::getKey(KeyFactory::$TYPE_LOCK, $this->request));
        $this->redisClient->exec();
    }

    public function safeSerialize($data)
    {
        return base64_encode(serialize($data));
    }

    private function redisSet(string $key, string $value, array $ttl = [])
    {
        $redis = $this->redisClient;
        error_log("redis set called, key: ".$key, 4);

        return $redis->set($key, $value, count($ttl) > 0 ? $ttl : null);
    }
}