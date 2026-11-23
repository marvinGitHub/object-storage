<?php

namespace melia\ObjectStorage\File;

trait FilesAwareTrait
{
    protected ?FilesInterface $files = null;

    /**
     * The files, see FilesInterface; unless set, a default is created once, so its configuration (e.g.
     * setSyncEnabled()) applies to later writes.
     */
    public function getFiles(): FilesInterface
    {
        return $this->files ??= new Files();
    }

    public function setFiles(FilesInterface $files): void
    {
        $this->files = $files;
    }
}
