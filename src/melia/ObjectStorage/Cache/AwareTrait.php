<?php

namespace melia\ObjectStorage\Cache;

trait AwareTrait
{
    protected ?CacheInterface $cache = null;
    public function getCache(): ?CacheInterface
    {
        return $this->cache;
    }
    public function setCache(?CacheInterface $cache): void
    {
        $this->cache = $cache;
    }
}