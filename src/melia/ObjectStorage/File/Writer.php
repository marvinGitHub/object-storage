<?php

namespace melia\ObjectStorage\File;

use melia\ObjectStorage\Exception\IOException;

class Writer implements WriterInterface
{
    /**
     * @throws IOException
     */
    public function atomicWrite(string $filename, ?string $data = null, bool $createDirectoryIfNotExist = false): void
    {
        if ($createDirectoryIfNotExist) {
            $directory = new Directory(Directory::getDirectoryName($filename));
            $directory->createIfNotExists();
        }

        /* do not use file_put_contents() here, because it does not support atomic writes */
        $file = fopen($filename, 'w+');

        if (false === $file) {
            throw new IOException('Unable to open file for writing: ' . $filename);
        }

        if (false === rewind($file)) {
            throw new IOException('Unable to rewind file: ' . $filename);
        }

        if (false === fwrite($file, $data ?? '')) {
            throw new IOException('Unable to write to file: ' . $filename);
        }

        if (false === fflush($file)) {
            throw new IOException('Unable to flush file: ' . $filename);
        }

        if (false === $position = ftell($file)) {
            throw new IOException('Unable to get file position: ' . $filename);
        }

        if (false === ftruncate($file, $position)) {
            throw new IOException('Unable to truncate file: ' . $filename);
        }

        if (false === fclose($file)) {
            throw new IOException('Unable to close file: ' . $filename);
        }
    }
}