<?php

namespace melia\ObjectStorage\File;

use melia\ObjectStorage\Exception\IOException;

class Reader implements ReaderInterface
{
    /**
     * @throws IOException
     */
    public function read(string $filename): string
    {
        /* don't use file_exists since we just try to read from a file */
        $data = @file_get_contents($filename);
        if (false === $data) {
            throw new IOException('Unable to read file: ' . $filename);
        }
        return $data;
    }
}