<?php

namespace RedisPageCache\Service;

use RedisPageCache\Service\Compressable;

class GzipCompressor implements Compressable
{
    public function __construct()
    {
        if (!function_exists('gzuncompress')) {
            throw new \Exception('No gzuncompress method exists. Enable Zlip or disable compression in Redis Page Cache');
        }

        if (!function_exists('gzcompress')) {
            throw new \Exception('No gzcompress method exists. Enable Zlip it or disable compression in Redis Page Cache');
        }
    }

    public function compress(string $content): string
    {
        return gzcompress($content);
    }

    public function deCompress(string $compressed): string
    {
        return gzuncompress($compressed);
    }
}
