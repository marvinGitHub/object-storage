<?php

namespace melia\ObjectStorage\File;

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\File\IO\AdapterAwareTrait;
use melia\ObjectStorage\File\IO\RealAdapter;

class Reader implements ReaderInterface
{
    use AdapterAwareTrait;

    public function __construct()
    {
        $this->setIOAdapter(new RealAdapter());
    }

    /**
     * @throws IOException
     */
    public function read(string $filename): string
    {
        /* don't use file_exists since we just try to read from a file */
        $data = $this->getIOAdapter()->fileGetContents($filename);
        if (false === $data) {
            throw new IOException('Unable to read file: ' . $filename);
        }
        return $data;
    }
}