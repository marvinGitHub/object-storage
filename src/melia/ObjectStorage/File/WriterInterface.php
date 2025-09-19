<?php

namespace melia\ObjectStorage\File;

interface WriterInterface
{

    public function atomicWrite(string $filename, ?string $data = null): void;
}
