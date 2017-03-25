<?php

namespace RedisPageCache\Model;

class CachedPage
{
    private $output;
    private $headers;
    private $flags;
    private $status;
    private $gzip = false;
    private $updated;
    private $debugData;

    public function __construct(string $output, string $httpStatus)
    {
        $this->status = $httpStatus;
        $this->output = $output;
        $this->updated = time();
    }


    public function getOutput(): string
    {
        return $this->output;
    }
    
    public function setOutput(string $output): CachedPage
    {
        $this->output = $output;

        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    public function setHeaders(array $headers): CachedPage
    {
        $this->headers = $headers;

        return $this;
    }

    public function addHeader(string $header): CachedPage
    {
        $this->headers[] = $header;

        return $this;
    }
    
    public function getFlags(): array
    {
        return $this->flags;
    }

    public function setFlags(array $flags): CachedPage
    {
        $uniqueFlags = array_unique($flags);
        $this->flags = $uniqueFlags;
    
        return $this;
    }

    public function addFlag($flag): CachedPage
    {
        if (in_array($this->flags, $flag)) {
            return $this;
        }
        $this->flags[] = $flag;

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

    public function setGzip(bool $value): CachedPage
    {
        $this->gzip = $value;

        return $this;
    }

    public function isGzip(): bool
    {
        return $this->getGzip();
    }

    /**
     * @return mixed
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * @param mixed $updated
     * @return CachedPage
     */
    public function setUpdated($updated)
    {
        $this->updated = $updated;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getDebugData()
    {
        return $this->debugData;
    }

    /**
     * @param mixed $debugData
     * @return CachedPage
     */
    public function setDebugData($debugData)
    {
        $this->debugData = $debugData;

        return $this;
    }

    // This will used when serialized
    public function __sleep()
    {
        return [
            'output', 'headers', 'status', 'flags', 'updated', 'debugData'
        ];
    }

}
