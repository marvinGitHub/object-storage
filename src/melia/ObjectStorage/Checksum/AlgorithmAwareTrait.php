<?php

namespace melia\ObjectStorage\Checksum;

trait AlgorithmAwareTrait
{
    private string $checksumAlgorithm = 'crc32b';

    /**
     * Retrieves the checksum algorithm used.
     *
     * @return string The name of the checksum algorithm.
     */
    public function getChecksumAlgorithm(): string
    {
        return $this->checksumAlgorithm;
    }

    /**
     * Sets the checksum algorithm to be used.
     *
     * @param string $checksumAlgorithm The name of the checksum algorithm to set.
     * @return void
     */
    public function setChecksumAlgorithm(string $checksumAlgorithm): void
    {
        $this->checksumAlgorithm = $checksumAlgorithm;
    }
}
