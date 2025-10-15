<?php

namespace melia\ObjectStorage\Metadata\Cache;

use Psr\SimpleCache\CacheInterface;

trait AwareTrait
{
    protected ?CacheInterface $metadataCache = null;

    /**
     * Retrieves the metadata cache instance.
     *
     * @return CacheInterface|null The metadata cache instance or null if not set.
     */
    public function getMetadataCache(): ?CacheInterface
    {
        return $this->metadataCache;
    }

    /**
     * Sets the metadata cache instance.
     *
     * @param CacheInterface|null $metadataCache The metadata cache instance to set, or null to unset it.
     * @return void
     */
    public function setMetadataCache(?CacheInterface $metadataCache): void
    {
        $this->metadataCache = $metadataCache;
    }
}