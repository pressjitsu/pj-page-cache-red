<?php
use RedisPageCache\CacheManagerFactory;

if (!defined('VENDOR')) {
    define('VENDOR', ABSPATH . '..' . DIRECTORY_SEPARATOR . 'vendor');
}

require_once(VENDOR . '/autoload.php');

$cacheManager = CacheManagerFactory::getManager();
$cacheManager->cache_init();
