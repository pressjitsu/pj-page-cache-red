<?php

use RedisPageCache\CacheManager;
use RedisPageCache\CacheManagerFactory;
use RedisPageCache\Redis\Flags;

use RedisClient\ClientFactory;


describe('Flags', function () {
    it('should create an expired flag', function () {
        $redisClient = ClientFactory::create([
        'server' => '127.0.0.1:6379', // or 'unix:///tmp/redis.sock'
        'timeout' => 2,
        ]);
        $expireFlag = new Flags('pjc-expired-flags', $redisClient);
        
        // flag: url:/about
        $expireFlag->add('/about');
        $expireFlag->add('/luca');
        $expireFlag->update();

        $expireds = $expireFlag->getFromWithScores(timestamp());
        error_log(print_r($expireds, true), 4);

    });
});
