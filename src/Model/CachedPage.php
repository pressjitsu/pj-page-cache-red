<?php

namespace RedisPageCache\Model;

class CachedPage
{
    private $output;
    private $headers;
    private $flags;
    private $status;
    private $gzip = false;

    public function __construct(string $output, string $httpStatus)
    {
        $this->status = $httpStatus;
        $this->output = $output;
    }


    public function getOutput()
    {
        return $output;
    }
    public function setOutput(string $output): CachedPage
    {
        $this->output = $output;

        return $this;
    }
    public function getHeaders()
    {
        return $this->headers;
    }
    public function setHeaders($headers): CachedPage
    {
        $this->headers = $headers;

        return $this;
    }
    public function getFlags()
    {
        return $this->flags;
    }
    public function setFlags(array $flags): CachedPage
    {
        $this->flags = $flags;

        return $this;
    }
    public function getStatus()
    {
        return $this->status;
    }
    public function setStatus(string $status): CachedPage
    {
        $this->status = $status;

        return $this;
    }
}
