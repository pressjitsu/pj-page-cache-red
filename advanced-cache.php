<?php
use RedisPageCache\CacheManagerFactory;

if (!defined(VENDOR)) {
    define('VENDOR', '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor');
}

require_once(VENDOR . '/autoload.php');

$cacheManager = CacheManagerFactory::getManager();
$cacheManager->cache_init();
