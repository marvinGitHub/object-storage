<?php

namespace melia\ObjectStorage\File;

use melia\ObjectStorage\Exception\InvalidBufferSizeException;
use melia\ObjectStorage\Exception\IOException;

class Reader implements ReaderInterface
{
    const DEFAULT_BUFFER_SIZE = 256;
    private int $bufferSize = Reader::DEFAULT_BUFFER_SIZE;

    /**
     * @throws InvalidBufferSizeException
     */
    public function __construct(?int $bufferSize = null)
    {
        if (null === $bufferSize) {
            $bufferSize = static::DEFAULT_BUFFER_SIZE;
        }
        $this->setBufferSize($bufferSize);
    }

    /**
     * Sets the buffer size.
     *
     * @param int $bufferSize The size of the buffer, must be greater than 0.
     * @return void
     * @throws InvalidBufferSizeException If the provided buffer size is less than or equal to 0.
     */
    public function setBufferSize(int $bufferSize): void
    {
        if (0 >= $bufferSize) {
            throw new InvalidBufferSizeException('Buffer size must be greater than 0.');
        }
        $this->bufferSize = $bufferSize;
    }

    /**
     * @throws IOException
     */
    public function read(string $filename): string
    {
        if (!file_exists($filename)) {
            throw new IOException('File does not exist: ' . $filename);
        }

        $handle = fopen($filename, 'rb');
        if ($handle === false) {
            throw new IOException('Unable to open file for reading: ' . $filename);
        }

        $content = '';
        while (!feof($handle)) {
            $chunk = fread($handle, $this->bufferSize);
            if ($chunk === false) {
                fclose($handle);
                throw new IOException('Read error occurred while reading from file: ' . $filename);
            }
            $content .= $chunk;
        }

        if (false === fclose($handle)) {
            throw new IOException('Unable to close file: ' . $filename);
        }

        return $content;
    }
}