<?php
use RedisPageCache\CacheManagerFactory;

$cacheManager = CacheManagerFactory::getManager();
$cacheManager->cache_init();
