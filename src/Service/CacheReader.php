<?php
/**
 * Created by PhpStorm.
 * User: balint
 * Date: 2017. 03. 25.
 * Time: 15:45
 */

namespace RedisPageCache\Service;

use RedisPageCache\Model\Request;
use RedisPageCache\Model\KeyFactory;


class CacheReader
{
    private $debug = false;

    public function __construct(Request $request, $redisClient = \Redis)
    {
        $this->request = $request;
        $this->redisClient = $redisClient;
        if (!$this->redisClient) {
            throw new \Exception('Redis has gone.');
        }
    }

    public function checkRequest()
    {
        // @TODO move this back to cache manager

        if ($this->debug) {
            // $this->debug_data = array('request_hash' => $this->request->getHash());
        }


        if ($this->debug) {
            header('X-Pj-Cache-Key: ' . $this->request->getHash());
        }

        $redis = $this->redisClient;
        if (!$redis) {
            throw new \Exception('Redis client is null (is it running? is the config OK?)');
        }

        // Look for an existing cache entry by request hash.
        $defKey = KeyFactory::getKey(KeyFactory::TYPE_DEFAULT, $this->request);
        $lockKey = KeyFactory::getKey(KeyFactory::TYPE_LOCK, $this->request);

        list($cache, $lock) = $redis->mGet(
            array(
                $defKey->get(),
                $lockKey->get(),
            )
        );

        return [
            'cache' => $cache ? $this->safeDeSerialize($cache) : null,
            'lock' => $lock ? $this->safeDeSerialize($lock) : null,
        ];
    }

    public function safeDeSerialize(string $data)
    {
        return unserialize(base64_decode($data));
    }

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * @param bool $debug
     * @return CacheReader
     */
    public function setDebug(bool $debug): CacheReader
    {
        $this->debug = $debug;

        return $this;
    }


}