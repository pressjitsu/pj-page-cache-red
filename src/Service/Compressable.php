<?php

namespace RedisPageCache\Service;

interface Compressable
{
    public function compress(string $content): string;
    
    public function decompress(string $compressed): string;
}
