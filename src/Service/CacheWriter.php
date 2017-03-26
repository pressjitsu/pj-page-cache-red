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
    private $safeSerialized;
    private $ttl = 300;

    public function __construct(CachedPage $cachedPage, Request $request, $redisClient = \Redis)
    {
        $this->cachedPage = $cachedPage;
        $this->request = $request;
        $this->redisClient = $redisClient;
        if (!$this->redisClient) {
            throw new \Exception('Redis has gone.');
        }
        $this->key = KeyFactory::getKey(KeyFactory::TYPE_DEFAULT, $this->request);
        $this->safeSerialized = $this->safeSerialize();
    }
    
    public function add()
    {
        $this->redisClient->multi();
        // Okay to cache.
        error_log('serialized' . print_r($this->safeSerialized, true), 4);
        $this->redisClient->set($this->key->get(), $this->safeSerialized, [$this->ttl]);
        $this->finalize();
    }
    
    public function remove()
    {
        $this->redisClient->multi();
        $this->redisClient->del($this->key->get());
        $this->finalize();
    }
    
    private function finalize()
    {
        $lockKey = KeyFactory::getKey(KeyFactory::TYPE_LOCK, $this->request);
        $this->redisClient->del($lockKey->get());
        $this->redisClient->exec();
    }

    public function safeSerialize()
    {
        return base64_encode(serialize($this->cachedPage));
    }

    /**
     * @return string
     */
    public function getSafeSerialized(): string
    {
        return $this->safeSerialized;
    }

    /**
     * @param string $safeSerialized
     * @return CacheWriter
     */
    public function setSafeSerialized(string $safeSerialized): CacheWriter
    {
        $this->safeSerialized = $safeSerialized;

        return $this;
    }

    /**
     * @return \RedisPageCache\Model\Keyable
     */
    public function getKey(): \RedisPageCache\Model\Keyable
    {
        return $this->key;
    }



}