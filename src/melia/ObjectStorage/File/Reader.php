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
        if (!file_exists($filename)) {
            throw new IOException('File does not exist: ' . $filename);
        }

        $handle = fopen($filename, 'rb');
        if ($handle === false) {
            throw new IOException('Unable to open file for reading: ' . $filename);
        }

        $size = filesize($filename);
        $content = fread($handle, $size);
        fclose($handle);

        if ($content === false) {
            throw new IOException('Unable to read from file: ' . $filename);
        }

        return $content;
    }
}