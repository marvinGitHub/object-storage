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
        if (false === file_exists($filename)) {
            throw new IOException('File does not exist: ' . $filename);
        }

        $data = file_get_contents($filename);
        if (false === $data) {
            throw new IOException('Unable to read file: ' . $filename);
        }
        return $data;
    }
}