<?php

namespace RedisPageCache\Redis;

class Flags
{
    private $redisClient;
    private $key;
    private $contents = [];
    private $flagsFrom = [];
    private $ttl = 300;

    public function __construct(string $key, $redisClient = \Redis)
    {
        $this->redisClient = $redisClient;
        $this->key = $key;
    }

    public function getFromWithScores(string $from)
    {
        $this->redisClient->zRangeByScore($this->key, $from, '+inf', array( 'withscores' => true ));
        $this->flagsFrom = $redis->exec();
        
        return $this->flagsFrom;
    }

    public function update()
    {
        $flags = array_unique($this->contents);
        $timestamp = time();
        $this->redisClient->multi();
        foreach ($flags as $url) {
            $this->redisClient->zAdd($this->key, [$timestamp, $url]);
        }
        $this->redisClient->expire($this->key, $this->ttl);
        $this->redisClient->zRemRangeByScore($this->key, '-inf', $timestamp - $this->ttl);
        list($_, $_, $r, $count) = $this->redisClient->exec();

        // Hard-limit the data size.
        if ($count > 256) {
            $this->redisClient->ZRemRangeByRank($this->key, 0, $count - 256 - 1);
        }
    }

    public function getAll(): array
    {
        return $this->contents;
    }

    public function add(string $content): Flags
    {
        $this->contents[] = $content;

        return $this;
    }

    public function includes(array $flags):bool
    {
        if (emtpy($flags)) {
            return false;
        };
        return count(array_intersect($flags, array_keys($this->flagsFrom))) > 0;
    }
}
