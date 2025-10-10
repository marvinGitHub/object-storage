<?php

namespace melia\ObjectStorage\SPL;

class SplObjectStorage extends \SplObjectStorage
{
    public function clear(): void
    {
        $this->removeAll($this);
    }
}