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

    public function getFromWithScores(int $from)
    {
        error_log(print_r($from, true), 4);
        $this->flagsFrom = $this->redisClient->zRangeByScore($this->key, $from, '+inf', array( 'withscores' => true ));

        //error_log(print_r($this->flagsFrom, true), 4);
        
        return $this->flagsFrom;
    }

    public function update()
    {
        // error_log('update flags');
        if (empty($this->contents)) {
            error_log('no flags');
            return;
        }
        // redis> ZADD myzset 2 "two" 3 "three"
        $flags = array_unique($this->contents);
        $timestamp = time();
        $this->redisClient->multi();
        $args = [];
        foreach ($flags as $url) {
            $args[$url] = $timestamp;
        }
        $this->redisClient->zAdd($this->key, $args);
        $this->redisClient->expire($this->key, $this->ttl);
        
        // remove expired???
        $this->redisClient->zRemRangeByScore($this->key, '-inf', $timestamp - $this->ttl);
        $results = $this->redisClient->exec();
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
        if (empty($flags)) {
            return false;
        };
        return count(array_intersect($flags, array_keys($this->flagsFrom))) > 0;
    }
}
