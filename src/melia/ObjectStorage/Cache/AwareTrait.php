<?php

namespace melia\ObjectStorage\Cache;

use Psr\SimpleCache\CacheInterface;

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