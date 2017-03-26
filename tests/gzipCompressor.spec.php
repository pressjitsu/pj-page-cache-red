<?php

use RedisPageCache\Service\GzipCompressor;


describe('GzipCompressor', function () {
    it('should compress a string with gzip when Zlib is enabled', function () {
        $compressor = new GzipCompressor;
        
        $compressed = $compressor->compress("test string");
        assert(base64_encode($compressed) === 'eJwrSS0uUSguKcrMSwcAGsAEeA==', 'Compress failed');

        $deCompressed = $compressor->deCompress($compressed);
        assert(base64_encode($deCompressed) === 'dGVzdCBzdHJpbmc=', 'Decompress failed');
    });
});
