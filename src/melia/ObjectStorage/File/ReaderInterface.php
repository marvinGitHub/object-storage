<?php

namespace melia\ObjectStorage\File;

interface ReaderInterface
{
    public function read(string $filename): string;
}