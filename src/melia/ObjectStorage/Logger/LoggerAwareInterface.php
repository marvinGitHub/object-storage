<?php

namespace melia\ObjectStorage\Logger;

interface LoggerAwareInterface
{
    public function getLogger(): ?LoggerInterface;
}