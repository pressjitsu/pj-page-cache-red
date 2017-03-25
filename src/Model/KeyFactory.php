<?php
/**
 * Created by PhpStorm.
 * User: balint
 * Date: 2017. 03. 25.
 * Time: 15:12
 */

namespace RedisPageCache\Model;

use RedisPageCache\Model\Keyable;
use RedisPageCache\Model\DefaultKey;
use RedisPageCache\Model\LockKey;

class KeyFactory
{
    static $TYPE_DEFAULT = 0;
    static $TYPE_LOCK = 1;
    
    static function getKey(int $type, Request $request): Keyable
    {
        switch ($type) {
            case self::TYPE_DEFAULT:
                $object = new DefaultKey($request);
                break;
            case self::$TYPE_LOCK:
                $object = new LockKey($request);
                break;
            default: 
                throw new \Exception('Missing key type: ' . $type);
        }
        
        return $object;
    }
}