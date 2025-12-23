<?php

namespace melia\ObjectStorage\File;

class Helper
{
    /**
     * Moves a file from a source location to a target directory.
     * Optionally, a new name for the file can be provided.
     *
     * @param string $source The path to the source file.
     * @param string $targetDir The target directory where the file will be moved.
     * @param string|null $fileName Optional. The new name for the file in the target directory.
     *                               Defaults to the basename of the source file.
     * @return bool Returns true on success or false on failure.
     */
    public static function move(string $source, string $targetDir, ?string $fileName = null): bool
    {
        $fileName = $fileName ?? basename($source);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        return rename($source, rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName);
    }
}